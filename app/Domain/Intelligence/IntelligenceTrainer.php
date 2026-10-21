<?php

namespace App\Domain\Intelligence;

use App\Domain\Features\FeatureEngine;
use App\Domain\Operations\ActionLog;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\FeatureSchema;
use App\Domain\Research\SemanticLabels;
use Closure;
use Generator;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;

final class IntelligenceTrainer
{
    // Rubix 3 changes numeric feature typing and serialized estimator state.
    public const VERSION = 'm6-outcome-action-rubix-weighted-knn-v8';

    public function __construct(
        private DatasetStore $datasets,
        private ModelStore $models,
        private PatternTrainer $patterns,
        private LeadLagIntelligence $leadLag,
        private AutomaticPatternAblation $patternAblation,
        private HumanCandleKnn $candleKnn,
        private HumanGuidance $humanOutcome,
        private CoinGeckoInsight $coinGeckoInsight,
    ) {}

    public function train(string $dataset, ?float $deadline = null, ?string $generation = null,
        array $buildPerformance = [], array $schemaSelection = [], ?Closure $beforePublish = null): array
    {
        $buildPerformance['started_at'] ??= now()->toIso8601String();
        $buildPerformance['started_monotonic_ns'] ??= hrtime(true);
        $buildPerformance['stages'] ??= [];

        $datasetLoadStarted = hrtime(true);
        [$manifest, $datasetRows] = $this->datasets->open($dataset);
        $buildPerformance['stages']['dataset_ms'] = (int) ($buildPerformance['stages']['dataset_ms'] ?? 0)
            + $this->elapsedMs($datasetLoadStarted);
        if ($manifest['feature_version'] !== FeatureEngine::VERSION
            || $manifest['label_definition']['version'] !== SemanticLabels::VERSION) {
            throw new InvalidArgumentException('Intelligence requires current Outcome labels; build fresh knowledge.');
        }
        // The previous Full manifest contained 27 KNN columns. It is never
        // legal to train a newly named Full model from that frozen vector.
        if (! in_array($manifest['schema'] ?? null, ['core', 'technical', 'enhanced', 'full', 'custom'], true)
            || ! FeatureSchema::modelCompatible(['outcome' => ['schema' => $manifest['schema']],
                'keys' => $manifest['keys'] ?? []])) {
            throw new InvalidArgumentException('Obsolete intelligence feature schema; rebuild the dataset before training.');
        }
        if ($manifest['as_of_ms'] > now()->getTimestampMs()) {
            throw new InvalidArgumentException('Knowledge cutoff cannot be in the future.');
        }

        $window = KnowledgeWindow::metadata($manifest['as_of_ms']);
        $snapshotRows = count($datasetRows);
        $ageExcluded = 0;
        $rows = TrainingRows::from($this->projectRows($datasetRows, $manifest, $window['from_ms'], $ageExcluded));
        if (count($rows) === 0) {
            throw new InvalidArgumentException('No eligible history within INTELLIGENCE_MAX_MODEL_AGE_DAYS.');
        }

        $rowAudit = ['input_rows' => count($rows), 'unique_rows' => count($rows), 'duplicates' => 0];
        $settings = config('intelligence.knn');
        $settings['outcome'] = config('intelligence.outcome');
        $deadline ??= microtime(true) + config('intelligence.max_seconds');
        $publicationDeadline = OptionalGuidance::publicationDeadline($deadline);
        $lock = Cache::lock('trademinator:intelligence:'.ModelStore::marketKey(
            $manifest['exchange'], $manifest['symbol'], $manifest['period']
        ), TrainingRuntime::lockSeconds());
        if (! $lock->get()) {
            throw new RuntimeException('Intelligence training is already running for this market and period.');
        }

        try {
            $patternSettings = config('intelligence.patterns');
            $sourceRows = count($rows);
            $patternBundle = ['models' => [], 'report' => [], 'version' => PatternCatalog::VERSION];

            $stageStarted = hrtime(true);
            if ($patternSettings['enabled']) {
                $outcomePatternRows = $rows->filter(
                    fn (array $row): bool => in_array($row['label'] ?? null, SemanticLabels::OUTCOMES, true)
                );
                $patternRows = $outcomePatternRows->slice(0, (int) floor(count($outcomePatternRows) * 0.4))
                    ->map(static function (array $row) use ($datasetRows): array {
                        $row['patterns'] = $datasetRows->at($row['_dataset_position'])['patterns'];

                        return $row;
                    });
                if (count($patternRows) > 0) {
                    $patternBundle = $this->patterns->train($patternRows, $patternSettings, $deadline);
                }
                unset($patternRows, $outcomePatternRows);
            }
            $buildPerformance['stages']['patterns_ms'] = $this->elapsedMs($stageStarted);

            $stageStarted = hrtime(true);
            $leadLag = $this->leadLag->prepare($manifest, $rows, $deadline);
            $buildPerformance['stages']['lead_lag_ms'] = $this->elapsedMs($stageStarted);
            $leadLagBundle = $leadLag['bundle'];
            unset($leadLag['bundle']);
            // Patterns remain trained and reported, but are intentionally excluded from every KNN feature vector.
            $candidatePatternKeys = [];
            $patternKnownAt = null;
            $technicalRows = $rows;
            unset($rows);

            $patternTreeSpools = [];
            foreach ($patternBundle['models'] as &$patternModel) {
                $estimator = $patternModel['estimator'] ?? null;
                if ($estimator instanceof SequentialRandomForest) {
                    $patternTreeSpools[] = ['estimator' => $estimator, 'spools' => $estimator->spillTrees()];
                }
            }
            unset($patternModel, $estimator);

            $leadLagSeries = $leadLag['series'];
            unset($leadLag['series']);
            $technicalLeadLagExcluded = $this->applyLeadLag(
                $technicalRows, $leadLagBundle, $leadLagSeries
            );
            $stackedRows = null;
            unset($leadLagSeries, $leadLag);

            $knn = new WeightedKnn(
                $settings['max_distance'], $settings['min_effective_neighbors'], $settings['min_confidence']
            );
            $knn->validatePreparedRows($technicalRows);

            $stageStarted = hrtime(true);
            $actionFallbackRows = $technicalRows;
            $outcome = $this->trainOutcome(
                $technicalRows, $stackedRows, $candidatePatternKeys, $patternKnownAt,
                $settings, $knn, $deadline
            );
            $rows = count($outcome['rows']) > 0 ? $outcome['rows'] : $actionFallbackRows;
            $patternKeys = $outcome['pattern_keys'];
            $patternAblation = $outcome['pattern_ablation'];
            $leadLagExcluded = $technicalLeadLagExcluded;
            unset($outcome['rows'], $technicalRows, $stackedRows, $actionFallbackRows);
            $buildPerformance['stages']['knn_tuning_ms'] = $this->elapsedMs($stageStarted);
            // OutcomeKnnTuner currently performs its final holdout inside the same bounded pass.
            // Retain the historical stage key for operational/report compatibility.
            $buildPerformance['stages']['holdout_ms'] = 0;

            $stageStarted = hrtime(true);
            $action = $this->trainAction($rows, $settings, $knn, $deadline);
            $buildPerformance['stages']['action_knn_ms'] = $this->elapsedMs($stageStarted);

            $stageStarted = hrtime(true);
            $humanOutcome = ($manifest['outcome_available'] ?? true)
                ? $this->humanOutcome->train($manifest, $settings, $publicationDeadline)
                : ['bundle' => [
                    'version' => HumanGuidance::VERSION,
                    'status' => 'outcome_horizon_unavailable',
                    'influence' => false,
                    'samples' => 0,
                ]];
            $buildPerformance['stages']['human_guidance_ms'] = $this->elapsedMs($stageStarted);

            $stageStarted = hrtime(true);
            $progress = new HumanTrainingProgress(app(ActionLog::class), $manifest);
            $humanAction = OptionalGuidance::compare(
                'candle', HumanCandleKnn::VERSION, $publicationDeadline,
                fn (float $auxiliaryDeadline): array => $this->candleKnn->train(
                    $manifest, $settings, $auxiliaryDeadline, $progress
                ),
                $progress
            );
            $humanAction['bundle']['mode'] = 'human_action_knn';

            // Optional CoinGecko evidence is trained independently. Do not let
            // missing/stale context or forest errors suppress Core publication.
            $insightStarted = hrtime(true);
            $insightDeadline = min($publicationDeadline - 5.0, microtime(true) + 30.0);
            try {
                $contextRows = $rows->map(static function (array $row) use ($datasetRows): array {
                    $source = $datasetRows->at($row['_dataset_position']);
                    $row['context_features'] = $source['context_features'] ?? [];
                    $row['context_snapshot_id'] = $source['source']['context_snapshot_id'] ?? null;

                    return $row;
                });
                $coingeckoInsight = $this->coinGeckoInsight->train($contextRows, $manifest['schema'], $insightDeadline);
            } catch (\Throwable $error) {
                app(ActionLog::class)->write('coingecko.insight.failed', [
                    'reason' => $error::class, 'outcome' => 'skipped',
                ]);
                $coingeckoInsight = ['bundle' => [
                    'version' => CoinGeckoInsight::VERSION, 'optional' => true,
                    'status' => 'optional_training_error', 'influence' => false,
                    'input_keys' => [], 'snapshots' => 0,
                ]];
            } finally {
                unset($contextRows, $datasetRows);
            }
            $buildPerformance['stages']['coingecko_insight_ms'] = $this->elapsedMs($insightStarted);
            $buildPerformance['stages']['candle_guidance_ms'] = $this->elapsedMs($stageStarted);

            $outcomeAlgorithmicReady = $outcome['selection']['k'] !== null
                && ($outcome['holdout']['eligible'] ?? false);
            $actionAlgorithmicReady = $action['selection']['k'] !== null
                && ($action['holdout']['eligible'] ?? false);
            $outcomeHumanReady = (bool) ($humanOutcome['bundle']['influence'] ?? false);
            $actionHumanReady = (bool) ($humanAction['bundle']['influence'] ?? false);

            $outcomeReady = $outcomeAlgorithmicReady || $outcomeHumanReady;
            $actionReady = $actionAlgorithmicReady || $actionHumanReady;
            $ready = $outcomeReady && $actionReady;

            $availableAt = $rows->max('label_available_at_ms');
            foreach ([$humanOutcome['bundle'], $humanAction['bundle']] as $humanBundle) {
                if (($humanBundle['influence'] ?? false) && isset($humanBundle['available_at_ms'])) {
                    $availableAt = max($availableAt, (int) $humanBundle['available_at_ms']);
                }
            }

            foreach ($patternTreeSpools as &$spilledForest) {
                $spilledForest['estimator']->restoreTrees($spilledForest['spools']);
                $spilledForest['spools'] = [];
            }
            unset($spilledForest);

            $knowledge = $rows->map(static function (array $row): array {
                return [
                    'decision_at_ms' => $row['decision_at_ms'],
                    'label_available_at_ms' => $row['label_available_at_ms'],
                    'vector' => $row['vector'],
                    'label' => $row['label'],
                    'action_label' => $row['action_label'],
                    'action_label_available_at_ms' => $row['action_label_available_at_ms'] ?? null,
                    'feature_weights' => $row['feature_weights'] ?? [],
                ];
            });
            unset($rows);

            $artifact = [
                'validation_version' => self::VERSION,
                'generation_key' => $generation,
                'dataset_id' => $dataset,
                'exchange' => $manifest['exchange'],
                'symbol' => $manifest['symbol'],
                'period' => $manifest['period'],
                'feature_version' => FeatureEngine::VERSION,
                'knn_schema_version' => FeatureSchema::VERSION,
                'normalization' => NormalizedVector::VERSION,
                'knn_engine' => 'rubix-ml-3.1-weighted',
                'keys' => $manifest['keys'],
                'pattern_keys' => $patternKeys,
                'patterns' => $patternBundle,
                'coingecko_insight' => [
                    ...$coingeckoInsight['bundle'],
                    'estimator' => $coingeckoInsight['estimator'] ?? null,
                ],
                'lead_lag' => $leadLagBundle,
                'lead_lag_keys' => $leadLagBundle['keys'],
                'outcome' => [
                    'status' => $outcomeReady ? 'ready' : 'abstaining',
                    'reason' => $outcomeReady ? 'validated' : $outcome['reason'],
                    'algorithmic' => [
                        'status' => $outcomeAlgorithmicReady ? 'ready' : 'abstaining',
                        'reason' => $outcome['reason'],
                        'k' => $outcome['selection']['k'],
                        'selection' => $outcome['selection'],
                        'holdout' => $outcome['holdout'],
                    ],
                    'human' => $humanOutcome['bundle'],
                    'schema' => $manifest['schema'],
                    'schema_selection' => $schemaSelection ?: [
                        'requested_schema' => $manifest['schema'],
                        'effective_schema' => $manifest['schema'],
                        'fallback_policy' => 'none',
                        'reason' => 'frozen_dataset',
                    ],
                    'validation_target' => 'five_class_forward_outcome',
                    'pattern_ablation' => $patternAblation,
                    'history_status' => $outcome['selection']['folds'] === [] ? 'insufficient_tuning_history' : 'tuning_evaluated',
                    'holdout_from_ms' => $outcome['holdout_from_ms'],
                    'holdout_training_labels_available_by_ms' => $outcome['holdout_training_labels_available_by_ms'],
                ],
                'action' => [
                    'status' => $actionReady ? 'ready' : 'abstaining',
                    'reason' => $actionReady ? 'validated' : $action['reason'],
                    'algorithmic' => [
                        'status' => $actionAlgorithmicReady ? 'ready' : 'abstaining',
                        'reason' => $action['reason'],
                        'k' => $action['selection']['k'],
                        'selection' => $action['selection'],
                        'holdout' => $action['holdout'],
                        'samples' => $action['samples'],
                        'validation_basis' => $action['validation_basis'] ?? 'finalized_historical_action_labels',
                    ],
                    'human' => $humanAction['bundle'],
                    'validation_target' => 'buy_hold_sell_action_labels',
                ],
                // Retain these bundle keys for private artifact persistence compatibility.
                'human_guidance' => $humanOutcome['bundle'],
                'human_keys' => [],
                'candle_guidance' => $humanAction['bundle'],
                'candle_keys' => [],
                'label_definition' => $manifest['label_definition'],
                'outcome_available' => $manifest['outcome_available'] ?? true,
                'action_label_analysis' => $manifest['action_label_analysis'] ?? null,
                'trained_as_of_ms' => $manifest['as_of_ms'],
                'available_at_ms' => $availableAt,
                'source_rows_sha256' => $manifest['rows_sha256'],
                'status' => $ready ? 'ready' : 'abstaining',
                'reason' => $ready ? 'validated'
                    : (! $outcomeReady ? 'outcome_knn_unavailable' : 'action_knn_unavailable'),
                'k' => $outcome['selection']['k'],
                'action_k' => $action['selection']['k'],
                'settings' => $settings,
                'pattern_settings' => $patternSettings,
                'training_data' => [
                    'window' => $window,
                    'snapshot_rows' => $snapshotRows,
                    'age_excluded_rows' => $ageExcluded,
                    'deduplication' => $rowAudit,
                    'schema' => $manifest['schema'],
                    'source_rows' => $sourceRows,
                    'usable_rows' => count($knowledge),
                    'action_rows' => $action['samples'],
                    'lead_lag_excluded_rows' => $leadLagExcluded,
                    'skipped' => $manifest['skipped'] ?? [],
                    'reconstruction' => $manifest['reconstruction'] ?? [],
                    'outcome_tuning_rows' => $outcome['training_rows'],
                    'outcome_holdout_rows' => $outcome['holdout_rows'],
                    'action_tuning_rows' => $action['training_rows'],
                    'action_holdout_rows' => $action['holdout_rows'],
                ],
                // Legacy report aliases point to Outcome validation only.
                'selection' => $outcome['selection'],
                'holdout' => $outcome['holdout'],
                'holdout_from_ms' => $outcome['holdout_from_ms'],
                'holdout_training_labels_available_by_ms' => $outcome['holdout_training_labels_available_by_ms'],
                'action_selection' => $action['selection'],
                'action_holdout' => $action['holdout'],
                'knowledge_rows' => count($knowledge),
                'knowledge' => $knowledge,
                'build_performance' => $buildPerformance,
            ];

            if (microtime(true) > $publicationDeadline) {
                throw new RuntimeException('Intelligence training time budget exceeded before publication.');
            }
            if (! $lock->isOwnedByCurrentProcess()) {
                throw new RuntimeException('Intelligence training lock ownership was lost before publication.');
            }
            $beforePublish?->__invoke();

            return $this->models->save($artifact);
        } finally {
            if (isset($patternTreeSpools)) {
                foreach ($patternTreeSpools as $spilledForest) {
                    $spilledForest['estimator']->closeSpools($spilledForest['spools']);
                }
            }
            $lock->release();
        }
    }

    /** @return Generator<int, array<string, mixed>> */
    private function projectRows(iterable $datasetRows, array $manifest, int $fromMs, int &$ageExcluded): Generator
    {
        foreach ($datasetRows as $position => $row) {
            if ($row['decision_at_ms'] < $fromMs) {
                $ageExcluded++;

                continue;
            }
            $outcomeLabel = $row['label'] ?? null;
            if (! isset($row['patterns'])
                || $row['label_available_at_ms'] > $manifest['as_of_ms']
                || ($outcomeLabel !== null && ! in_array($outcomeLabel, SemanticLabels::OUTCOMES, true))
                || (($row['action_label'] ?? null) !== null
                    && ! in_array($row['action_label'], ['buy', 'hodl', 'sell'], true))
                || ($outcomeLabel === null && ($row['action_label'] ?? null) === null)) {
                throw new InvalidArgumentException('Invalid Outcome/Action knowledge row.');
            }
            foreach ($row['patterns'] as $pattern) {
                if (! in_array($pattern['type'] ?? null, PatternCatalog::TYPES, true)
                    || ! in_array($pattern['label'] ?? null, ['completed', 'failed'], true)
                    || ! is_int($pattern['label_available_at_ms'] ?? null)
                    || $pattern['label_available_at_ms'] <= $row['decision_at_ms']
                    || $pattern['label_available_at_ms'] > $row['label_available_at_ms']) {
                    throw new InvalidArgumentException('Pattern outcome is outside the closed Outcome horizon.');
                }
            }
            yield [
                'decision_at_ms' => $row['decision_at_ms'],
                'label_available_at_ms' => $row['label_available_at_ms'],
                'vector' => NormalizedVector::from($row['vector'], $manifest['keys']),
                'label' => $outcomeLabel,
                'action_label' => $row['action_label'] ?? null,
                'action_label_available_at_ms' => $row['action_label_available_at_ms'] ?? null,
                '_dataset_position' => $position,
            ];
        }
    }

    private function trainOutcome(TrainingRows $technicalRows, ?TrainingRows $stackedRows, array $candidatePatternKeys,
        ?int $patternKnownAt, array $settings, WeightedKnn $knn, float $deadline): array
    {
        $technicalRows = $technicalRows->filter(
            fn (array $row): bool => in_array($row['label'] ?? null, SemanticLabels::OUTCOMES, true)
        );
        if ($stackedRows !== null) {
            $stackedRows = $stackedRows->filter(
                fn (array $row): bool => in_array($row['label'] ?? null, SemanticLabels::OUTCOMES, true)
            );
        }
        [$baseTraining, $baseTest, $cutoff] = $this->splitRows($technicalRows);
        $defaultAblation = [
            'version' => AutomaticPatternAblation::VERSION,
            'selection_basis' => 'same_k_same_walk_forward_rows_final_holdout_untouched',
            'selected' => 'technical_only',
            'reason' => $candidatePatternKeys === [] ? 'no_validated_pattern_features' : 'no_comparison_k',
            'candidate_pattern_keys' => $candidatePatternKeys,
            'common_rows' => 0,
        ];
        if (count($baseTest) === 0 || count($baseTraining) < $settings['min_train_size']) {
            return $this->emptyTraining('insufficient_outcome_history', count($baseTraining), count($baseTest))
                + ['rows' => $technicalRows, 'pattern_keys' => [], 'pattern_ablation' => $defaultAblation];
        }

        $tuner = new OutcomeKnnTuner($knn, new OutcomeKnn($settings));
        $selection = $tuner->tunePrepared($baseTraining, $settings, $deadline);
        $rows = $technicalRows;
        $training = $baseTraining;
        $test = $baseTest;
        $patternKeys = [];
        $patternAblation = $defaultAblation;

        $comparison = $this->patternAblation->comparisonCandidate($selection);
        if ($stackedRows !== null && count($stackedRows) > 0 && $comparison !== null && $patternKnownAt !== null) {
            $comparisonK = $comparison['k'];
            $commonBaseTraining = $baseTraining->filter(
                fn (array $row): bool => $row['decision_at_ms'] > $patternKnownAt
            );
            $stackedTraining = $stackedRows->filter(
                fn (array $row): bool => $row['decision_at_ms'] < $cutoff
                    && $row['label_available_at_ms'] < $cutoff
            );

            if (count($commonBaseTraining) >= $settings['min_train_size']
                && count($stackedTraining) === count($commonBaseTraining)) {
                $technicalComparison = $tuner->evaluateWalkForwardPrepared(
                    $commonBaseTraining, $settings, $comparisonK, $deadline
                );
                $stackedComparison = $tuner->evaluateWalkForwardPrepared(
                    $stackedTraining, $settings, $comparisonK, $deadline
                );
                $patternAblation = $this->patternAblation->chooseAtK(
                    $technicalComparison['report'], $stackedComparison['report'],
                    $candidatePatternKeys, count($commonBaseTraining)
                );
                $patternAblation['common_tuning_rows'] = count($commonBaseTraining);

                if ($patternAblation['selected'] === 'technical_plus_patterns') {
                    $patternKeys = $candidatePatternKeys;
                    $rows = $stackedRows;
                    $training = $stackedTraining;
                    $test = $stackedRows->filter(
                        fn (array $row): bool => $row['decision_at_ms'] >= $cutoff
                    );
                    $selection = $this->replaceSelectionCandidate(
                        $selection, $stackedComparison['report'], $stackedComparison['folds'], $comparisonK
                    );
                }
            } else {
                $patternAblation['reason'] = 'insufficient_common_chronological_rows';
                $patternAblation['common_rows'] = min(count($commonBaseTraining), count($stackedTraining));
            }
        }

        $holdout = $selection['k'] === null || count($test) === 0
            ? ['eligible' => false, 'reason' => 'no_eligible_k']
            : $tuner->evaluatePrepared($training, $test, $selection['k'], $settings, $deadline);
        $reason = $selection['k'] === null ? 'no_eligible_k'
            : (($holdout['eligible'] ?? false) ? 'validated' : 'holdout_failed');

        return compact('selection', 'holdout', 'reason')
            + [
                'training_rows' => count($training),
                'holdout_rows' => count($test),
                'samples' => count($rows),
                'holdout_from_ms' => $cutoff,
                'holdout_training_labels_available_by_ms' => $training->max('label_available_at_ms'),
                'rows' => $rows,
                'pattern_keys' => $patternKeys,
                'pattern_ablation' => $patternAblation,
            ];
    }

    private function trainAction(TrainingRows $rows, array $settings, WeightedKnn $knn, float $deadline): array
    {
        $actionRows = $rows->filter(static function (array $row): bool {
            if (! in_array($row['action_label'] ?? null, ['buy', 'hodl', 'sell'], true)) {
                return false;
            }
            // Historical reference labels must already be finalized. In real snapshots
            // Action labels have their own availability time; older fixture datasets
            // may carry only the common label_available_at_ms timestamp.
            $available = $row['action_label_available_at_ms'] ?? $row['label_available_at_ms'] ?? null;

            return is_int($available) && $available > $row['decision_at_ms'];
        })->map(static function (array $row): array {
            $label = $row['action_label'];
            $row['label'] = $label;
            $row['label_available_at_ms'] = $row['action_label_available_at_ms'] ?? $row['label_available_at_ms'];
            $row['semantic_bottom'] = $label === 'buy';
            $row['semantic_top'] = $label === 'sell';

            return $row;
        });
        $count = count($actionRows);
        if ($count < $settings['min_train_size'] + $settings['test_size']) {
            return $this->emptyTraining('insufficient_action_history', 0, 0, $count);
        }

        $testStart = (int) floor($count * 0.8);
        $test = $actionRows->slice($testStart);
        $cutoff = $test[0]['decision_at_ms'];
        $training = $actionRows->slice(0, $testStart)->filter(
            fn (array $row): bool => $row['label_available_at_ms'] < $cutoff
        );
        unset($actionRows);
        if (count($training) < $settings['min_train_size']) {
            return $this->emptyTraining('insufficient_action_history', count($training), count($test), $count);
        }

        $tuner = new KnnTuner($knn);
        $selection = $tuner->tunePrepared($training, $settings, $deadline);
        $holdout = $selection['k'] === null
            ? ['eligible' => false, 'reason' => 'no_eligible_k']
            : $tuner->evaluatePrepared($training, $test, $selection['k'], $settings, $deadline);
        $reason = $selection['k'] === null ? ($selection['reason'] ?? 'no_eligible_k')
            : match ($holdout['validation_status']) {
                'validated' => 'validated',
                'insufficient_evidence' => 'insufficient_directional_evidence',
                default => 'holdout_failed',
            };

        return compact('selection', 'holdout', 'reason')
            + ['training_rows' => count($training), 'holdout_rows' => count($test), 'samples' => $count,
                'validation_basis' => 'finalized_historical_action_labels'];
    }

    private function emptyTraining(string $reason, int $trainingRows = 0, int $holdoutRows = 0, int $samples = 0): array
    {
        return [
            'selection' => ['k' => null, 'folds' => [], 'candidates' => []],
            'holdout' => ['eligible' => false, 'reason' => $reason],
            'reason' => $reason,
            'training_rows' => $trainingRows,
            'holdout_rows' => $holdoutRows,
            'samples' => $samples,
            'holdout_from_ms' => null,
            'holdout_training_labels_available_by_ms' => 0,
        ];
    }

    private function splitRows(TrainingRows $rows): array
    {
        $testStart = (int) floor(count($rows) * 0.8);
        $test = $rows->slice($testStart);
        $cutoff = $test[0]['decision_at_ms'] ?? 0;
        $training = $rows->slice(0, $testStart)->filter(
            fn (array $row): bool => $row['label_available_at_ms'] < $cutoff
        );

        return [$training, $test, $cutoff];
    }

    /**
     * Enrich one record at a time and release the original temporary row store.
     * DatasetStore guarantees strictly increasing decision timestamps, so
     * all pre-validation rows form a prefix of the array.
     */
    private function applyLeadLag(TrainingRows &$rows, array $bundle, array $series): int
    {
        if ($bundle['keys'] === []) {
            return 0;
        }

        $excluded = 0;
        $count = count($rows);
        while ($excluded < $count && $rows[$excluded]['decision_at_ms'] <= $bundle['available_at_ms']) {
            $excluded++;
        }
        $sourceRows = $rows->slice($excluded);
        $rows = TrainingRows::from((function () use ($sourceRows, $bundle, $series): Generator {
            foreach ($sourceRows as $row) {
                $evidence = $this->leadLag->features($bundle, $series, $row['decision_at_ms']);
                $row['feature_weights'] = array_fill(0, count($row['vector']), 1.0);
                foreach ($evidence['weights'] as $weight) {
                    $row['feature_weights'][] = $weight;
                }
                foreach ($evidence['vector'] as $value) {
                    $row['vector'][] = $value;
                }
                yield $row;
            }
        })());

        return $excluded;
    }

    private function replaceSelectionCandidate(array $selection, array $candidate, array $folds, int $k): array
    {
        $replaced = false;
        foreach ($selection['candidates'] as &$existing) {
            if (($existing['k'] ?? null) === $k) {
                $existing = $candidate;
                $replaced = true;
                break;
            }
        }
        unset($existing);
        if (! $replaced) {
            $selection['candidates'][] = $candidate;
            usort($selection['candidates'], fn (array $a, array $b): int => $a['k'] <=> $b['k']);
        }
        $selection['k'] = $k;
        $selection['folds'] = $folds;
        $selection['selection'] = 'pattern_ablation_same_k_then_holdout';

        return $selection;
    }

    private function elapsedMs(int $startedNs): int
    {
        return (int) round((hrtime(true) - $startedNs) / 1_000_000);
    }
}
