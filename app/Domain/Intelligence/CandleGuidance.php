<?php

namespace App\Domain\Intelligence;

use App\Domain\Research\DatasetStore;
use App\Models\HumanTrainingSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Rubix\ML\Classifiers\KNearestNeighbors;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Datasets\Unlabeled;
use RuntimeException;

/** Per-candle human actions are auxiliary inputs. Objective market outcomes remain the evaluation target. */
final class CandleGuidance
{
    public const VERSION = 'm4.4-candle-guidance-v3';

    public const READABLE_VERSIONS = ['m4.4-candle-guidance-v2', self::VERSION];

    public function compare(array $manifest, array $rows, array $settings, float $deadline): array
    {
        $bundle = ['version' => self::VERSION, 'status' => 'insufficient_candle_labels', 'keys' => [],
            'optional' => true, 'samples' => 0, 'training_samples' => 0,
            'class_counts' => array_fill_keys(CandleTraining::ACTIONS, 0),
            'training_class_counts' => array_fill_keys(CandleTraining::ACTIONS, 0),
            'evaluation_mode' => 'retrospective_chronological_research',
            'annotation_cutoff_ms' => now()->getTimestampMs(), 'influence' => false];
        if (! OptionalGuidance::enabled('candle') || count($rows) < 5) {
            $bundle['status'] = ! OptionalGuidance::enabled('candle') ? 'disabled' : 'insufficient_history';

            return ['bundle' => $bundle];
        }
        $prefixEnd = (int) floor(count($rows) * 0.4);
        $later = array_slice($rows, $prefixEnd);
        $cutoff = $later[0]['decision_at_ms'];
        $prefix = array_values(array_filter(array_slice($rows, 0, $prefixEnd),
            fn (array $row): bool => $row['label_available_at_ms'] < $cutoff));
        $rawOpinions = $this->opinions($manifest, $prefix, $bundle['annotation_cutoff_ms']);
        $audit = TrainingRowAudit::inspect($rawOpinions);
        $opinions = $audit['rows'];
        unset($audit['rows']);
        $counts = $this->classCounts($opinions);
        $bundle = [...$bundle, 'samples' => count($rawOpinions), 'class_counts' => $this->classCounts($rawOpinions),
            'training_samples' => count($opinions), 'training_class_counts' => $counts, 'deduplication' => $audit,
            'sampling' => 'all_eligible_distinct_candles', 'minimum_samples' => config('human_training.candle_min_samples')];
        if (count($opinions) < config('human_training.candle_min_samples')) {
            return ['bundle' => $bundle];
        }
        if (count(array_filter($counts)) < 2) {
            $bundle['status'] = 'insufficient_action_diversity';

            return ['bundle' => $bundle];
        }
        $this->deadline($deadline);
        $k = min(config('human_training.candle_k'), count($opinions));
        $estimator = new KNearestNeighbors($k, true);
        $estimator->train(new Labeled(array_map(RubixFeatures::vector(...), array_column($opinions, 'vector')),
            array_column($opinions, 'action')));
        $bundle = [...$bundle, 'estimator' => $estimator, 'k' => $k,
            'input_keys' => $manifest['keys'],
            'keys' => array_map(fn (string $action): string => 'candle_human.'.$action, CandleTraining::ACTIONS),
            'training_through_ms' => max(array_column($opinions, 'decision_at_ms')),
            'training_outcomes_available_by_ms' => max(array_column($opinions, 'label_available_at_ms')),
            'labels_updated_by_ms' => max(array_column($opinions, 'updated_at_ms')),
            'downstream_from_ms' => $cutoff,
            'label_provenance_sha256' => HumanTrainingSnapshot::digest(array_column($opinions, 'provenance'))];

        $tuner = new KnnTuner(new WeightedKnn($settings['max_distance'], $settings['min_effective_neighbors'], $settings['min_confidence']));
        $machine = $this->tuneOnly($later, $settings, $tuner, $deadline);
        $rawShares = [];
        foreach ($later as $row) {
            $this->deadline($deadline);
            $rawShares[] = $estimator->proba(new Unlabeled([
                RubixFeatures::vector(array_slice($row['vector'], 0, count($manifest['keys']))),
            ]))[0];
        }
        $policies = [
            'natural' => ClassPriorWeights::fit($counts),
            'target_priors' => ClassPriorWeights::fit($counts, config('human_training.candle_target_weights', ClassPriorWeights::TARGET)),
        ];
        $selected = null;
        foreach ($policies as $policy => $classWeights) {
            $combined = [];
            foreach ($later as $index => $row) {
                $this->deadline($deadline);
                $shares = array_values(ClassPriorWeights::apply($rawShares[$index], $classWeights));
                $combined[] = [...$row,
                    'feature_weights' => [...($row['feature_weights'] ?? array_fill(0, count($row['vector']), 1.0)), ...array_fill(0, count($shares), 1.0)],
                    'vector' => [...$row['vector'], ...$shares]];
            }
            // No final holdout is evaluated until the policy has been fixed.
            $candidate = $this->tuneOnly($combined, $settings, $tuner, $deadline);
            $improved = $this->improves($machine['selected_score'], $candidate['selected_score']);
            $bundle['weight_candidates'][$policy] = ['class_weights' => $classWeights,
                'selection' => $candidate['selection'], 'selected_score' => $candidate['selected_score'],
                'tuning_improved' => $improved];
            if ($improved && ($selected === null || $this->betterTuning($candidate['selected_score'], $selected['selected_score']))) {
                $selected = [...$candidate, 'rows' => $combined, 'policy' => $policy, 'class_weights' => $classWeights];
            }
        }

        $machine['holdout'] = $this->holdout($machine, $settings, $tuner, $deadline);
        $bundle['minimum_precision_gain'] = config('human_training.candle_min_precision_gain');
        $bundle['holdout_from_ms'] = $machine['cutoff'];
        $bundle['selection_passed'] = $selected !== null;
        $bundle['holdout_passed'] = false;
        $bundle['comparison'] = [
            'baseline_without_candle' => ['selection' => $machine['selection'], 'holdout' => $machine['holdout']],
            'candle_human_only' => ['holdout' => null, 'production_eligible' => false],
            'combined' => ['selection' => $selected['selection'] ?? null, 'holdout' => null],
        ];
        if ($selected === null) {
            $bundle['status'] = 'tuning_did_not_improve';
            unset($bundle['estimator']);
            $bundle['keys'] = [];

            return ['bundle' => $bundle];
        }

        $bundle['weight_policy'] = $selected['policy'];
        $bundle['class_weights'] = $selected['class_weights'];
        $selected['holdout'] = $this->holdout($selected, $settings, $tuner, $deadline);
        $bundle['comparison']['combined']['holdout'] = $selected['holdout'];
        $accepted = $this->improves($machine['holdout'], $selected['holdout']);
        $bundle['holdout_passed'] = $accepted;
        $bundle['influence'] = $accepted;
        $bundle['status'] = $accepted ? 'validated' : 'holdout_did_not_improve';

        $humanPredictions = [];
        foreach ($machine['test'] as $row) {
            $this->deadline($deadline);
            $shares = $this->features($bundle, array_slice($row['vector'], 0, count($manifest['keys'])));
            $votes = ['buy' => $shares[0], 'hodl' => $shares[1], 'sell' => $shares[2]];
            arsort($votes);
            $values = array_values($votes);
            $supported = $values[0] >= $settings['min_confidence'] && $values[0] - $values[1] > 1e-12;
            $humanPredictions[] = ['action' => $supported ? array_key_first($votes) : 'hodl',
                'confidence' => $supported ? $values[0] : 0.0,
                'reason' => $supported ? 'supported' : 'weak_consensus'];
        }
        $bundle['comparison']['candle_human_only']['holdout'] = $tuner->evaluatePredictions($machine['test'], $humanPredictions, $settings);
        if (! $accepted) {
            // Never try the runner-up after looking at the final holdout.
            unset($bundle['estimator']);
            $bundle['keys'] = [];

            return ['bundle' => $bundle];
        }

        return ['bundle' => $bundle, ...$selected];
    }

    /** Natural weighting wins exact tuning ties; holdout fields are never inspected. */
    private function betterTuning(array $candidate, array $selected): bool
    {
        return [$candidate['semantic_precision'], $candidate['coverage'], -$candidate['contradiction_rate'], $candidate['mean_confidence']]
            > [$selected['semantic_precision'], $selected['coverage'], -$selected['contradiction_rate'], $selected['mean_confidence']];
    }

    private function opinions(array $manifest, array $rows, int $annotationCutoff): array
    {
        if ($rows === []) {
            return [];
        }
        $trainerIds = array_map('strtolower', array_filter([...User::ownerIds(), ...config('human_training.trainer_uuids')]));
        $trainers = User::query()->whereIn('user_id', $trainerIds)
            ->get()->filter(fn (User $user): bool => Gate::forUser($user)->allows('train-intelligence'))->pluck('user_id')->all();
        if ($trainers === []) {
            return [];
        }
        $byTime = array_column($rows, null, 'decision_at_ms');
        $cutoff = CarbonImmutable::createFromTimestampMs($annotationCutoff)->format('Y-m-d H:i:s.v');
        $snapshots = HumanTrainingSnapshot::query()
            ->where('market_key', ModelStore::marketKey($manifest['exchange'], $manifest['symbol'], $manifest['period']))
            ->where('version', HumanTraining::VERSION)->whereBetween('decision_at_ms', [array_key_first($byTime), array_key_last($byTime)])
            ->whereHas('candleLabels', fn ($query) => $query->whereIn('trainer_id', $trainers))
            ->with(['candleLabels' => fn ($query) => $query->whereIn('trainer_id', $trainers)
                ->whereIn('action', CandleTraining::ACTIONS)->where('updated_at', '<=', $cutoff)->orderBy('trainer_id')])
            ->orderBy('decision_at_ms')->orderByDesc('snapshot_id')->lazy(25);
        $opinions = [];
        $rawRows = null;
        foreach ($snapshots->chunk(25) as $batch) {
            $rawRows ??= app(DatasetStore::class)->load($manifest['dataset_id'])[1];
            $compatible = app(HumanTraining::class)->compatibleSnapshotIds($manifest, $batch, $rawRows);
            foreach ($batch as $snapshot) {
                if (! isset($compatible[$snapshot->snapshot_id])) {
                    continue;
                }
                $row = $byTime[$snapshot->decision_at_ms] ?? null;
                $payload = $snapshot->verifiedPayload();
                if ($row === null || $payload['feature_version'] !== $manifest['feature_version']
                    || $payload['keys'] !== $manifest['keys'] || $payload['normalization'] !== NormalizedVector::VERSION
                    || $payload['horizon_candles'] !== $manifest['label_definition']['horizon']
                    || $payload['vector'] != array_slice($row['vector'], 0, count($manifest['keys']))
                    || ($payload['feature_sha256'] ?? null) !== ($row['source']['feature_sha256'] ?? null)) {
                    continue;
                }
                $votes = $snapshot->candleLabels->countBy('action')->sortDesc();
                $count = $snapshot->candleLabels->count();
                $top = $votes->first() ?? 0;
                if ($count < config('human_training.min_reviewers') || $top / max(1, $count) < config('human_training.min_agreement')
                    || $votes->values()->get(1) === $top) {
                    continue;
                }
                $opinions[] = ['vector' => $payload['vector'], 'action' => $votes->keys()->first(),
                    'decision_at_ms' => $row['decision_at_ms'], 'label_available_at_ms' => $row['label_available_at_ms'],
                    'updated_at_ms' => $snapshot->candleLabels->max(fn ($label) => $label->updated_at->getTimestampMs()),
                    'provenance' => ['snapshot_id' => $snapshot->snapshot_id, 'sha256' => $snapshot->sha256,
                        'labels' => $snapshot->candleLabels->map(fn ($label): array => ['id' => $label->candle_label_id,
                            'trainer_id' => $label->trainer_id, 'action' => $label->action,
                            'updated_at_ms' => $label->updated_at->getTimestampMs()])->all()]];
            }
        }

        return $opinions;
    }

    private function classCounts(array $opinions): array
    {
        $counts = array_fill_keys(CandleTraining::ACTIONS, 0);
        foreach ($opinions as $opinion) {
            if (isset($counts[$opinion['action']])) {
                $counts[$opinion['action']]++;
            }
        }

        return $counts;
    }

    private function tuneOnly(array $rows, array $settings, KnnTuner $tuner, float $deadline): array
    {
        $start = (int) floor(count($rows) * 0.8);
        $test = array_slice($rows, $start);
        $cutoff = $test[0]['decision_at_ms'];
        $training = array_values(array_filter(array_slice($rows, 0, $start), fn (array $row): bool => $row['label_available_at_ms'] < $cutoff));
        $selection = $tuner->tune($training, $settings, $deadline);
        $score = collect($selection['candidates'])->firstWhere('k', $selection['k']);

        return ['selection' => $selection, 'selected_score' => $score,
            'test' => $test, 'training' => $training, 'cutoff' => $cutoff];
    }

    private function holdout(array $candidate, array $settings, KnnTuner $tuner, float $deadline): array
    {
        return $candidate['selection']['k'] === null
            ? $tuner->evaluatePredictions($candidate['test'], array_fill(0, count($candidate['test']), WeightedKnn::abstain('no_eligible_k')), $settings)
            : $tuner->evaluate($candidate['training'], $candidate['test'], $candidate['selection']['k'], $settings, $deadline);
    }

    public function improves(?array $machine, ?array $combined): bool
    {
        return ($combined['eligible'] ?? false)
            && $combined['semantic_precision'] >= ($machine['semantic_precision'] ?? 0) + max(0.000001, config('human_training.candle_min_precision_gain'))
            && $combined['coverage'] >= ($machine['coverage'] ?? 0)
            && $combined['contradiction_rate'] <= ($machine['contradiction_rate'] ?? config('intelligence.knn.max_contradiction_rate'));
    }

    /** Ordered human-action shares, not calibrated probabilities of price movement or profit. */
    public function features(array $bundle, array $vector): array
    {
        $probabilities = $bundle['estimator']->proba(new Unlabeled([RubixFeatures::vector($vector)]))[0];

        if (($bundle['version'] ?? self::VERSION) === 'm4.4-candle-guidance-v2') {
            return array_map(fn (string $action): float => (float) ($probabilities[$action] ?? 0.0), CandleTraining::ACTIONS);
        }

        return array_values(ClassPriorWeights::apply($probabilities, $bundle['class_weights'] ?? array_fill_keys(CandleTraining::ACTIONS, 1.0)));
    }

    private function deadline(float $deadline): void
    {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Candle guidance training time budget exceeded.');
        }
    }
}
