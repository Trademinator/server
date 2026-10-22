<?php

namespace App\Domain\Intelligence;

use App\Domain\Features\FeatureEngine;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\FeatureSchema;
use App\Models\HumanTrainingSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use RuntimeException;

/** Independent technical KNN whose targets are authorized human candle annotations. */
final class HumanCandleKnn
{
    public const VERSION = 'm5-human-candle-knn-v2';

    public function __construct(private DatasetStore $datasets, private HumanTraining $snapshots) {}

    public function train(array $manifest, array $settings, float $deadline, ?HumanTrainingProgress $progress = null): array
    {
        $keys = array_values(array_intersect($manifest['keys'], FeatureEngine::KEYS));
        $settings = array_replace($settings, config('human_training.candle_validation', []));
        $bundle = ['version' => self::VERSION, 'mode' => 'independent_knn', 'status' => 'insufficient_candle_labels',
            'keys' => [], 'input_keys' => $keys, 'influence' => false, 'samples' => 0,
            'validation_target' => 'human_candle_annotations', 'evaluation_mode' => 'retrospective_chronological_research',
            'annotation_cutoff_ms' => now()->getTimestampMs(), 'settings' => $settings,
            'window' => KnowledgeWindow::metadata($manifest['as_of_ms']),
            'minimum_samples' => config('human_training.candle_min_samples')];
        if ($keys === []) {
            return ['bundle' => [...$bundle, 'status' => 'no_technical_features']];
        }
        $progress?->stage('loading_annotations');
        $diagnostics = [];
        $rows = $this->opinions($manifest, $keys, $bundle['annotation_cutoff_ms'], $deadline, $progress, $diagnostics);
        $this->deadline($deadline);
        $progress?->stage('annotations_loaded', ['eligible_rows' => count($rows)]);
        $bundle = [...$bundle, 'samples' => count($rows), 'class_counts' => $this->counts($rows),
            'sampling' => 'all_eligible_distinct_candles',
            'annotation_policy' => HumanCandleProjection::VERSION, 'annotation_diagnostics' => $diagnostics];
        if (count($rows) < max(5, (int) config('human_training.candle_min_samples'))) {
            return ['bundle' => $bundle];
        }
        if (count(array_filter($bundle['class_counts'])) < 2) {
            return ['bundle' => [...$bundle, 'status' => 'insufficient_action_diversity']];
        }
        $knn = $this->knn($settings);
        $progress?->stage('preparing_neighbors', ['total' => count($rows)]);
        $knn->validatePreparedRows($rows);
        $tuningStart = (int) floor(count($rows) * 0.6);
        $holdoutStart = (int) floor(count($rows) * 0.8);
        $tuning = array_slice($rows, $tuningStart, $holdoutStart - $tuningStart);
        $holdout = array_slice($rows, $holdoutStart);
        $training = $this->purge(array_slice($rows, 0, $tuningStart), $tuning[0]['decision_at_ms']);
        if ($training === []) {
            return ['bundle' => [...$bundle, 'status' => 'insufficient_history']];
        }
        $k = min((int) config('human_training.candle_k'), count($training));
        if ($k < 1) {
            throw new InvalidArgumentException('Human Candle K must be positive.');
        }
        $selected = null;
        foreach (['natural' => null, 'target_priors' => config('human_training.candle_target_weights')] as $policy => $target) {
            $progress?->stage('tuning_'.$policy, ['processed' => 0, 'total' => count($tuning), 'knowledge_rows' => count($training)]);
            $weights = ClassPriorWeights::fit($this->counts($training), $target);
            $score = $this->evaluate($knn, $training, $tuning, $k, $weights, $settings, $deadline, $progress);
            $bundle['weight_candidates'][$policy] = ['class_weights' => $weights, 'tuning' => $score];
            if ($score['eligible'] && ($selected === null || $this->rank($score) > $this->rank($selected['tuning']))) {
                $selected = ['policy' => $policy, 'target' => $target, 'tuning' => $score];
            }
        }
        $bundle = [...$bundle, 'k' => $k, 'tuning_from_ms' => $tuning[0]['decision_at_ms'],
            'holdout_from_ms' => $holdout[0]['decision_at_ms'], 'selection_passed' => $selected !== null,
            'tuning_training_labels_available_by_ms' => max(array_column($training, 'label_available_at_ms')),
            'label_provenance_sha256' => HumanTrainingSnapshot::digest(array_column($rows, 'provenance'))];
        if ($selected === null) {
            $tuningScores = array_column($bundle['weight_candidates'], 'tuning');
            $onlyInsufficient = $tuningScores !== [] && count(array_filter($tuningScores,
                static fn (array $score): bool => $score['validation_status'] === 'insufficient_evidence')) === count($tuningScores);

            return ['bundle' => [...$bundle, 'status' => $onlyInsufficient
                ? 'insufficient_directional_evidence' : 'tuning_failed']];
        }
        // Fix the policy before touching the final holdout; never try a runner-up afterward.
        $training = $this->purge(array_slice($rows, 0, $holdoutStart), $holdout[0]['decision_at_ms']);
        $progress?->stage('holdout', ['processed' => 0, 'total' => count($holdout), 'knowledge_rows' => count($training)]);
        $weights = ClassPriorWeights::fit($this->counts($training), $selected['target']);
        $score = $this->evaluate($knn, $training, $holdout, $k, $weights, $settings, $deadline, $progress);
        $bundle = [...$bundle, 'weight_policy' => $selected['policy'], 'tuning' => $selected['tuning'],
            'holdout' => $score, 'holdout_passed' => $score['eligible'],
            'holdout_training_labels_available_by_ms' => max(array_column($training, 'label_available_at_ms')),
            'status' => match ($score['validation_status']) {
                'validated' => 'validated',
                'insufficient_evidence' => 'insufficient_directional_evidence',
                default => 'holdout_failed',
            }, 'influence' => $score['eligible']];
        if (! $score['eligible']) {
            return ['bundle' => $bundle];
        }
        // Publication retains all eligible annotations, including the evaluated period.
        $progress?->stage('finalizing', ['knowledge_rows' => count($rows)]);
        $this->deadline($deadline);
        $classWeights = ClassPriorWeights::fit($this->counts($rows), $selected['target']);
        $updatedBy = max(array_column($rows, 'updated_at_ms'));
        $availableAt = max($updatedBy, max(array_column($rows, 'label_available_at_ms')));
        $trainingThrough = max(array_column($rows, 'decision_at_ms'));
        unset($training, $tuning, $holdout);
        foreach ($rows as &$row) {
            $row = [
                'decision_at_ms' => $row['decision_at_ms'], 'label_available_at_ms' => $row['label_available_at_ms'],
                'vector' => $row['vector'], 'label' => $row['label'], 'provenance' => $row['provenance'],
            ];
        }
        unset($row);
        $knowledge = $rows;
        unset($rows);

        return ['bundle' => [...$bundle,
            'class_weights' => $classWeights,
            'knowledge_rows' => count($knowledge), 'knowledge' => $knowledge,
            'labels_updated_by_ms' => $updatedBy,
            'available_at_ms' => $availableAt,
            'training_through_ms' => $trainingThrough]];
    }

    public function predict(array $bundle, array $payload, int $asOfMs, ?iterable $knowledge = null): array
    {
        if (! OptionalGuidance::enabled('candle')) {
            return WeightedKnn::abstain('candle_training_disabled');
        }
        if (($bundle['version'] ?? null) !== self::VERSION) {
            return WeightedKnn::abstain('candle_model_version_mismatch');
        }
        if (! ($bundle['influence'] ?? false)) {
            return WeightedKnn::abstain($bundle['status'] ?? 'candle_model_unavailable');
        }
        if (($bundle['available_at_ms'] ?? PHP_INT_MAX) >= $asOfMs) {
            return WeightedKnn::abstain('no_post_annotation_candle');
        }
        $vector = FeatureSchema::vector($payload, $bundle['input_keys']);
        if ($vector === null) {
            return WeightedKnn::abstain('missing_technical_features');
        }
        $knn = $this->knn($bundle['settings']);
        $neighbors = $knn->neighborsIterable(
            $knowledge ?? ($bundle['knowledge'] ?? []), NormalizedVector::from($vector, $bundle['input_keys']),
            $bundle['k'], $asOfMs
        );

        return $knn->vote($neighbors, $bundle['k'], $this->voteWeights($bundle['class_weights']));
    }

    /** Read-only eligibility audit; uses exactly the same loader as training. */
    public function audit(array $manifest, float $deadline, ?HumanTrainingProgress $progress = null): array
    {
        $keys = array_values(array_intersect($manifest['keys'], FeatureEngine::KEYS));
        if ($keys === []) {
            throw new InvalidArgumentException('No technical inputs in the selected dataset.');
        }
        $diagnostics = [];
        $rows = $this->opinions($manifest, $keys, now()->getTimestampMs(), $deadline, $progress, $diagnostics);

        return ['annotation_policy' => HumanCandleProjection::VERSION, 'input_keys' => $keys,
            'window' => KnowledgeWindow::metadata($manifest['as_of_ms']),
            'samples' => count($rows), 'class_counts' => $this->counts($rows),
            'minimum_samples' => config('human_training.candle_min_samples'),
            'annotation_diagnostics' => $diagnostics, 'validation_performed' => false];
    }

    private function opinions(array $manifest, array $keys, int $annotationCutoff, float $deadline,
        ?HumanTrainingProgress $progress = null, array &$diagnostics = []): array
    {
        if ($manifest['feature_version'] !== FeatureEngine::VERSION) {
            throw new InvalidArgumentException('Human candle training requires a current target dataset.');
        }
        $marketKey = ModelStore::marketKey($manifest['exchange'], $manifest['symbol'], $manifest['period']);
        $recorded = DB::table('human_candle_labels as labels')
            ->join('human_training_snapshots as snapshots', 'snapshots.snapshot_id', '=', 'labels.snapshot_id')
            ->where('snapshots.market_key', $marketKey);
        $diagnostics = ['recorded_labels' => (clone $recorded)->count(),
            'recorded_distinct_candles' => (clone $recorded)->distinct()->count('snapshots.decision_at_ms'),
            'prefiltered_snapshots' => [], 'candidate_snapshots' => 0, 'examined_snapshots' => 0, 'source_versions' => [],
            'accepted_snapshots' => 0, 'duplicate_eligible_snapshots' => 0,
            'projected_candles' => 0, 'legacy_same_version_candles' => 0, 'excluded' => []];
        $exclude = function (string $reason) use (&$diagnostics): void {
            $diagnostics['excluded'][$reason] = ($diagnostics['excluded'][$reason] ?? 0) + 1;
        };
        $trainerIds = array_map('strtolower', array_filter([...User::ownerIds(), ...config('human_training.trainer_uuids')]));
        $trainers = User::query()->whereIn('user_id', $trainerIds)->get()
            ->filter(fn (User $user): bool => Gate::forUser($user)->allows('train-intelligence'))->pluck('user_id')->all();
        $diagnostics['authorized_trainers'] = count($trainers);
        $cutoff = CarbonImmutable::createFromTimestampMs($annotationCutoff)->format('Y-m-d H:i:s.v');
        $query = HumanTrainingSnapshot::query()->where('market_key', $marketKey)->whereHas('candleLabels');
        $before = (clone $query)->count();
        $diagnostics['recorded_snapshots'] = $before;
        $filters = [
            'snapshot_version' => fn ($query) => $query->where('version', HumanTraining::VERSION),
            'outside_window' => fn ($query) => $query->whereBetween('decision_at_ms', [KnowledgeWindow::fromMs($manifest['as_of_ms']), $manifest['as_of_ms']]),
            'unauthorized_trainer' => fn ($query) => $query->whereHas('candleLabels', fn ($labels) => $labels->whereIn('trainer_id', $trainers)),
            'after_annotation_cutoff' => fn ($query) => $query->whereHas('candleLabels', fn ($labels) => $labels
                ->whereIn('trainer_id', $trainers)->where('updated_at', '<=', $cutoff)),
        ];
        foreach ($filters as $reason => $filter) {
            $filter($query);
            $after = (clone $query)->count();
            $diagnostics['prefiltered_snapshots'][$reason] = $before - $after;
            $before = $after;
        }
        $diagnostics['candidate_snapshots'] = $before;
        $query->with(['candleLabels' => fn ($query) => $query->whereIn('trainer_id', $trainers)
            ->whereIn('action', CandleTraining::ACTIONS)->where('updated_at', '<=', $cutoff)->orderBy('trainer_id')]);
        // Page compact snapshot identities with a keyset cursor. Never materialize
        // every candidate UUID in PHP and never use OFFSET over the large payload table.
        $identityQuery = (clone $query)->reorder()
            ->select(['snapshot_id', 'dataset_id', 'decision_at_ms'])
            ->orderBy('dataset_id')->orderBy('decision_at_ms')->orderByDesc('snapshot_id');
        $diagnostics['performance'] = [
            'snapshot_selection_ms' => 0, 'snapshot_fetch_ms' => 0, 'snapshot_batches' => 0,
            'dataset_loading_ms' => 0, 'projection_ms' => 0,
        ];
        $opinions = [];
        $loadedDataset = null;
        $source = [];
        $rawRows = null;
        $legacyHistory = false;
        $cursor = null;
        $batchSize = 25;
        while (true) {
            $this->deadline($deadline);
            $selectionStarted = microtime(true);
            $page = clone $identityQuery;
            if ($cursor !== null) {
                $page->where(function ($where) use ($cursor): void {
                    $where->where('dataset_id', '>', $cursor['dataset_id'])
                        ->orWhere(function ($sameDataset) use ($cursor): void {
                            $sameDataset->where('dataset_id', $cursor['dataset_id'])
                                ->where('decision_at_ms', '>', $cursor['decision_at_ms']);
                        })
                        ->orWhere(function ($sameDecision) use ($cursor): void {
                            $sameDecision->where('dataset_id', $cursor['dataset_id'])
                                ->where('decision_at_ms', $cursor['decision_at_ms'])
                                ->where('snapshot_id', '<', $cursor['snapshot_id']);
                        });
                });
            }
            $identities = $page->limit($batchSize)->toBase()->get();
            $diagnostics['performance']['snapshot_selection_ms'] += (int) round((microtime(true) - $selectionStarted) * 1000);
            if ($identities->isEmpty()) {
                break;
            }

            $batchIds = $identities->pluck('snapshot_id')->all();
            $lastIdentity = $identities->last();
            $cursor = ['dataset_id' => $lastIdentity->dataset_id,
                'decision_at_ms' => (int) $lastIdentity->decision_at_ms, 'snapshot_id' => $lastIdentity->snapshot_id];

            $fetchStarted = microtime(true);
            $loaded = HumanTrainingSnapshot::query()
                ->select(['snapshot_id', 'dataset_id', 'decision_at_ms', 'sha256', 'payload'])
                ->whereIn('snapshot_id', $batchIds)
                ->with(['candleLabels' => fn ($labels) => $labels
                    ->select(['candle_label_id', 'snapshot_id', 'trainer_id', 'action', 'updated_at'])
                    ->whereIn('trainer_id', $trainers)->whereIn('action', CandleTraining::ACTIONS)
                    ->where('updated_at', '<=', $cutoff)->orderBy('trainer_id')])
                ->get()->keyBy('snapshot_id');
            $batch = new \Illuminate\Database\Eloquent\Collection;
            foreach ($batchIds as $snapshotId) {
                $snapshot = $loaded->get($snapshotId);
                if ($snapshot === null) {
                    throw new RuntimeException('Human candle snapshot disappeared during inspection; retry.');
                }
                $batch->push($snapshot);
            }
            $diagnostics['performance']['snapshot_fetch_ms'] += (int) round((microtime(true) - $fetchStarted) * 1000);
            $diagnostics['performance']['snapshot_batches']++;
            $this->deadline($deadline);
            foreach ($batch->groupBy('dataset_id') as $dataset => $group) {
                $this->deadline($deadline);
                if ($dataset !== $loadedDataset) {
                    $datasetStarted = microtime(true);
                    $progress?->stage('loading_dataset', ['dataset_id' => $dataset,
                        'processed' => $diagnostics['examined_snapshots']]);
                    // Reject a wrong market/horizon from its verified manifest
                    // before reading the large row artifact, but allow versions.
                    $source = $this->datasets->manifest($dataset);
                    $rawRows = null;
                    // Action Training is independent of Outcome H. The snapshot must still
                    // match its own source manifest exactly below, but a later model may use a
                    // different (or unavailable) Outcome horizon.
                    if ([$source['exchange'], $source['symbol'], $source['period']]
                        === [$manifest['exchange'], $manifest['symbol'], $manifest['period']]) {
                        [, $rawRows] = $this->datasets->open($dataset);
                        $legacyHistory = app(SnapshotRevisions::class)->historyRevision($source) === 0;
                    }
                    $loadedDataset = $dataset;
                    $diagnostics['performance']['dataset_loading_ms'] += (int) round((microtime(true) - $datasetStarted) * 1000);
                }
                $progress?->stage('validating_snapshots', ['dataset_id' => $dataset,
                    'processed' => $diagnostics['examined_snapshots'], 'eligible_rows' => count($opinions)]);
                $candidates = $projectPayloads = $legacySnapshots = $legacyRows = [];
                foreach ($group as $snapshot) {
                    $this->deadline($deadline);
                    $diagnostics['examined_snapshots']++;
                    $version = $source['feature_version'];
                    $diagnostics['source_versions'][$version] = ($diagnostics['source_versions'][$version] ?? 0) + 1;
                    $payload = $snapshot->verifiedPayload(); // Corruption is never a soft exclusion.
                    $decision = $snapshot->decision_at_ms;
                    $row = $rawRows?->findDecision($decision);
                    if ($rawRows === null) {
                        $exclude('source_market_or_horizon_mismatch');

                        continue;
                    }
                    if ($row === null || ($payload['version'] ?? null) !== HumanTraining::VERSION
                        || ($payload['exchange'] ?? null) !== $source['exchange']
                        || ($payload['symbol'] ?? null) !== $source['symbol'] || ($payload['period'] ?? null) !== $source['period']
                        || ($payload['decision_at_ms'] ?? null) !== $decision
                        || ($payload['microtimestamp'] ?? null) !== $row['microtimestamp']
                        || ($payload['feature_version'] ?? null) !== $source['feature_version']
                        || ($payload['keys'] ?? null) !== $source['keys']
                        || ($payload['normalization'] ?? null) !== NormalizedVector::VERSION
                        || ($payload['horizon_candles'] ?? null) !== $source['label_definition']['horizon']
                        || ($payload['vector'] ?? null) != NormalizedVector::from($row['vector'], $source['keys'])
                        || ($payload['features'] ?? null) != array_combine($source['keys'], $row['vector'])
                        || ($payload['feature_sha256'] ?? null) !== ($row['source']['feature_sha256'] ?? null)) {
                        $exclude('original_snapshot_mismatch');

                        continue;
                    }
                    if ($row['label_available_at_ms'] > $manifest['as_of_ms']) {
                        $exclude('immature_label');

                        continue;
                    }
                    $votes = $snapshot->candleLabels->countBy('action')->sortDesc();
                    $count = $snapshot->candleLabels->count();
                    $top = $votes->first() ?? 0;
                    if ($count < config('human_training.min_reviewers') || $top / max(1, $count) < config('human_training.min_agreement')
                        || $votes->values()->get(1) === $top) {
                        $exclude('insufficient_agreement');

                        continue;
                    }
                    $id = $snapshot->snapshot_id;
                    $candidates[$id] = ['snapshot' => $snapshot, 'row' => $row, 'action' => $votes->keys()->first()];
                    if ($legacyHistory && ! isset($payload['revision']) && ($payload['feature_sha256'] ?? null) === null) {
                        // Preserve existing source-less research fixtures/legacy behavior,
                        // but NEVER use this exception to cross a feature version/schema.
                        if ($source['feature_version'] !== $manifest['feature_version'] || array_diff($keys, $source['keys']) !== []) {
                            $exclude('unverifiable_legacy_snapshot');
                            unset($candidates[$id]);

                            continue;
                        }
                        $legacySnapshots[] = $snapshot;
                        $legacyRows[$decision] = $row;
                    } else {
                        $projectPayloads[$id] = $payload;
                    }
                }
                $projectionStarted = microtime(true);
                $projected = app(HumanCandleProjection::class)->project($manifest, $projectPayloads, $keys, $deadline);
                $diagnostics['performance']['projection_ms'] += (int) round((microtime(true) - $projectionStarted) * 1000);
                if ($legacySnapshots !== []) {
                    $compatible = $this->snapshots->compatibleSnapshotIds($source, $legacySnapshots, array_values($legacyRows));
                    foreach ($legacySnapshots as $snapshot) {
                        $id = $snapshot->snapshot_id;
                        $vector = FeatureSchema::vector($snapshot->payload, $keys);
                        $projected[$id] = ! isset($compatible[$id]) || $vector === null
                            ? ['reason' => 'incompatible_legacy_snapshot']
                            : ['vector' => NormalizedVector::from($vector, $keys), 'provenance' => ['projection_version' => 'legacy_same_version']];
                    }
                }
                foreach ($candidates as $id => $candidate) {
                    $projection = $projected[$id];
                    if (isset($projection['reason'])) {
                        $exclude($projection['reason']);

                        continue;
                    }
                    $diagnostics['accepted_snapshots']++;
                    $snapshot = $candidate['snapshot'];
                    $decision = $snapshot->decision_at_ms;
                    if (isset($opinions[$decision])) {
                        $diagnostics['duplicate_eligible_snapshots']++;
                        // Preserve the established newest-compatible-snapshot policy.
                        if (strcmp($opinions[$decision]['provenance']['snapshot_id'], $id) >= 0) {
                            continue;
                        }
                    }
                    $action = $candidate['action'];
                    $labelProvenance = $snapshot->candleLabels->map(fn ($label): array => [
                        'id' => $label->candle_label_id, 'trainer_id' => $label->trainer_id, 'action' => $label->action,
                        'updated_at_ms' => $label->updated_at->getTimestampMs(),
                    ])->all();
                    $fullProvenance = ['snapshot_id' => $id, 'sha256' => $snapshot->sha256,
                        'dataset_id' => $snapshot->dataset_id, 'dataset_rows_sha256' => $source['rows_sha256'],
                        ...$projection['provenance'], 'labels' => $labelProvenance];
                    $opinions[$decision] = ['vector' => $projection['vector'], 'label' => $action === 'hold' ? 'hodl' : $action,
                        'semantic_bottom' => $action === 'buy', 'semantic_top' => $action === 'sell',
                        'decision_at_ms' => $decision, 'label_available_at_ms' => $candidate['row']['label_available_at_ms'],
                        'updated_at_ms' => $snapshot->candleLabels->max(fn ($label) => $label->updated_at->getTimestampMs()),
                        'provenance' => ['snapshot_id' => $id, 'sha256' => $snapshot->sha256,
                            'dataset_id' => $snapshot->dataset_id, 'dataset_rows_sha256' => $source['rows_sha256'],
                            'projection_version' => $projection['provenance']['projection_version'] ?? null,
                            'feature_version' => $projection['provenance']['feature_version'] ?? $source['feature_version'],
                            'labels' => array_map(fn (array $label): array => ['id' => $label['id']], $labelProvenance),
                            'digest' => HumanTrainingSnapshot::digest($fullProvenance)]];
                    unset($labelProvenance, $fullProvenance);
                }
                $progress?->tick(['processed' => $diagnostics['examined_snapshots'], 'eligible_rows' => count($opinions)]);
            }
            unset($batch, $loaded, $identities, $batchIds);
        }
        foreach ($opinions as $opinion) {
            $metric = $opinion['provenance']['projection_version'] === 'legacy_same_version' ? 'legacy_same_version_candles' : 'projected_candles';
            $diagnostics[$metric]++;
        }
        foreach (app(IndependentHumanAnnotations::class)->opinions($manifest, 'human_action', $annotationCutoff, $deadline) as $opinion) {
            $decision = $opinion['decision_at_ms'];
            if (! isset($opinions[$decision]) || ($opinion['updated_at_ms'] ?? 0) > ($opinions[$decision]['updated_at_ms'] ?? 0)) {
                $opinions[$decision] = $opinion;
            }
        }
        ksort($opinions);
        ksort($diagnostics['excluded']);

        return array_values($opinions);
    }

    private function evaluate(WeightedKnn $knn, array $training, array $test, int $k, array $weights, array $settings, float $deadline,
        ?HumanTrainingProgress $progress = null): array
    {
        $predictions = [];
        foreach ($test as $row) {
            $this->deadline($deadline);
            $predictions[] = $knn->vote($knn->neighborsPrepared($training, $row['vector'], $k, $row['decision_at_ms']), $k, $this->voteWeights($weights));
            $progress?->tick(['processed' => count($predictions)]);
        }
        $this->deadline($deadline);
        $score = (new KnnTuner($knn))->evaluatePredictions($test, $predictions, $settings);
        $score['k'] = $k;
        $score['directional_annotation_agreement'] = $score['semantic_precision'];
        $score['opposite_annotation_rate'] = $score['contradiction_rate'];
        unset($score['semantic_precision'], $score['contradiction_rate']);

        return $score;
    }

    private function rank(array $score): array
    {
        return [$score['directional_wilson_95']['lower'] ?? 0.0, $score['directional_annotation_agreement'],
            -$score['opposite_annotation_rate'], $score['mean_confidence']];
    }

    private function purge(array $rows, int $cutoff): array
    {
        return array_values(array_filter($rows, fn (array $row): bool => $row['label_available_at_ms'] < $cutoff));
    }

    private function counts(array $rows): array
    {
        $counts = array_fill_keys(CandleTraining::ACTIONS, 0);
        foreach ($rows as $row) {
            $counts[$row['label'] === 'hodl' ? 'hold' : $row['label']]++;
        }

        return $counts;
    }

    private function voteWeights(array $weights): array
    {
        return ['buy' => $weights['buy'], 'hodl' => $weights['hold'], 'sell' => $weights['sell']];
    }

    private function knn(array $settings): WeightedKnn
    {
        return new WeightedKnn($settings['max_distance'], $settings['min_effective_neighbors'], $settings['min_confidence']);
    }

    private function deadline(float $deadline): void
    {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Candle guidance training time budget exceeded.');
        }
    }
}
