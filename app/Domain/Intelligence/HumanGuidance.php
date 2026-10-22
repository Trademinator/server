<?php

namespace App\Domain\Intelligence;

use App\Domain\Features\FeatureEngine;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\FeatureSchema;
use App\Domain\Research\SemanticLabels;
use App\Models\HumanTrainingSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Human Outcome Training submodel.
 *
 * Human labels never replace algorithmic Outcome labels. This KNN is trained
 * independently and its prediction is blended later by HumanTrainingWeight.
 */
final class HumanGuidance
{
    public const VERSION = 'm5-human-outcome-knn-v1';

    public function __construct(private DatasetStore $datasets, private HumanTraining $snapshots) {}

    public function train(array $manifest, array $settings, float $deadline): array
    {
        $keys = $manifest['keys'];
        $bundle = [
            'version' => self::VERSION,
            'status' => 'insufficient_outcome_training',
            'influence' => false,
            'samples' => 0,
            'input_keys' => $keys,
            'minimum_samples' => (int) config('human_training.min_samples'),
            'settings' => $settings,
            'annotation_cutoff_ms' => now()->getTimestampMs(),
            'evaluation_mode' => 'retrospective_human_outcome_training',
        ];
        if (! OptionalGuidance::enabled('trend')) {
            return ['bundle' => [...$bundle, 'status' => 'outcome_training_disabled']];
        }

        $rows = $this->opinions($manifest, $bundle['annotation_cutoff_ms'], $deadline);
        $this->checkDeadline($deadline);
        $bundle['samples'] = count($rows);
        $bundle['class_counts'] = array_fill_keys(SemanticLabels::OUTCOMES, 0);
        foreach ($rows as $row) {
            $bundle['class_counts'][$row['label']]++;
        }
        if (count($rows) < max(5, (int) config('human_training.min_samples'))) {
            return ['bundle' => $bundle];
        }
        if (count(array_filter($bundle['class_counts'])) < 2) {
            return ['bundle' => [...$bundle, 'status' => 'insufficient_outcome_diversity']];
        }

        $knn = new WeightedKnn($settings['max_distance'], $settings['min_effective_neighbors'], $settings['min_confidence']);
        $knn->validatePreparedRows($rows);
        $k = min(max(1, (int) config('human_training.k')), count($rows));
        $availableAt = max(array_column($rows, 'updated_at_ms'));
        foreach ($rows as &$row) {
            $this->checkDeadline($deadline);
            $row = [
                'decision_at_ms' => $row['decision_at_ms'],
                'label_available_at_ms' => $row['label_available_at_ms'],
                'vector' => $row['vector'],
                'label' => $row['label'],
                'provenance' => $row['provenance'],
            ];
        }
        unset($row);
        $this->checkDeadline($deadline);

        return ['bundle' => [...$bundle,
            'status' => 'validated',
            'influence' => true,
            'k' => $k,
            'knowledge_rows' => count($rows),
            'knowledge' => $rows,
            'available_at_ms' => $availableAt,
            'training_through_ms' => max(array_column($rows, 'decision_at_ms')),
        ]];
    }

    public function predict(array $bundle, array $payload, int $asOfMs, ?iterable $knowledge = null): array
    {
        if (! OptionalGuidance::enabled('trend')) {
            return OutcomeKnn::abstain('outcome_training_disabled');
        }
        if (($bundle['version'] ?? null) !== self::VERSION) {
            return OutcomeKnn::abstain('human_outcome_model_version_mismatch');
        }
        if (! ($bundle['influence'] ?? false)) {
            return OutcomeKnn::abstain($bundle['status'] ?? 'human_outcome_model_unavailable');
        }
        if (($bundle['available_at_ms'] ?? PHP_INT_MAX) >= $asOfMs) {
            return OutcomeKnn::abstain('no_post_annotation_outcome');
        }

        $vector = FeatureSchema::vector($payload, $bundle['input_keys']);
        if ($vector === null) {
            return OutcomeKnn::abstain('missing_human_outcome_features');
        }
        $settings = $bundle['settings'] ?? config('intelligence.knn');
        $neighbors = new WeightedKnn($settings['max_distance'], $settings['min_effective_neighbors'], $settings['min_confidence']);
        $prepared = NormalizedVector::from($vector, $bundle['input_keys']);
        $nearest = $neighbors->neighborsIterable($knowledge ?? ($bundle['knowledge'] ?? []), $prepared, $bundle['k'], $asOfMs);

        return (new OutcomeKnn($settings))->vote($nearest, $bundle['k']);
    }

    private function checkDeadline(float $deadline): void
    {
        TrainingRuntime::heartbeat();
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Human Outcome Training time budget exceeded.');
        }
    }

    private function opinions(array $manifest, int $annotationCutoff, float $deadline): array
    {
        $trainerIds = array_map('strtolower', array_filter([...User::ownerIds(), ...config('human_training.trainer_uuids')]));
        $trainers = User::query()->whereIn('user_id', $trainerIds)
            ->get()->filter(fn (User $user): bool => Gate::forUser($user)->allows('train-intelligence'))
            ->pluck('user_id')->all();
        if ($trainers === []) {
            return [];
        }

        [, $rawRows] = $this->datasets->open($manifest['dataset_id']);
        // The disk index is already checksum-verified. Do not decode and retain
        // every historical row: Human Outcome reviews are sparse.
        if (count($rawRows) === 0) {
            return [];
        }
        $firstDecision = $rawRows->at(0)['decision_at_ms'];
        $lastDecision = $rawRows->at(count($rawRows) - 1)['decision_at_ms'];

        $cutoff = CarbonImmutable::createFromTimestampMs($annotationCutoff)->format('Y-m-d H:i:s.v');
        $snapshots = HumanTrainingSnapshot::query()
            ->where('market_key', ModelStore::marketKey($manifest['exchange'], $manifest['symbol'], $manifest['period']))
            ->where('version', HumanTraining::VERSION)
            ->whereBetween('decision_at_ms', [$firstDecision, $lastDecision])
            ->whereHas('reviews', fn ($query) => $query->whereIn('trainer_id', $trainers))
            ->with(['reviews' => fn ($query) => $query->whereIn('trainer_id', $trainers)
                ->whereIn('label', HumanTraining::acceptedLabels())->whereNotNull('submitted_at')
                ->where('submitted_at', '<=', $cutoff)->orderBy('trainer_id')])
            ->orderBy('decision_at_ms')->orderByDesc('snapshot_id')->lazy(25);

        $opinions = [];
        foreach ($snapshots->chunk(25) as $batch) {
            $this->checkDeadline($deadline);
            $byTime = [];
            foreach ($batch as $snapshot) {
                $row = $rawRows->findDecision((int) $snapshot->decision_at_ms);
                if ($row !== null) {
                    $byTime[(int) $snapshot->decision_at_ms] = $row;
                }
            }
            $compatible = $this->snapshots->compatibleSnapshotIds($manifest, $batch, $byTime);
            foreach ($batch as $snapshot) {
                if (! isset($compatible[$snapshot->snapshot_id])) {
                    continue;
                }
                $source = $byTime[$snapshot->decision_at_ms] ?? null;
                $payload = $snapshot->verifiedPayload();
                if ($source === null || ($payload['feature_version'] ?? null) !== FeatureEngine::VERSION
                    || $payload['keys'] !== $manifest['keys']
                    || ($payload['horizon_candles'] ?? null) !== $manifest['label_definition']['horizon']) {
                    continue;
                }

                $votes = [];
                foreach ($snapshot->reviews as $review) {
                    $label = HumanTraining::normalizeLabel($review->label);
                    if (in_array($label, SemanticLabels::OUTCOMES, true)) {
                        $votes[$label] = ($votes[$label] ?? 0) + 1;
                    }
                }
                arsort($votes);
                $count = array_sum($votes);
                $top = reset($votes) ?: 0;
                $second = array_values($votes)[1] ?? 0;
                if ($count < config('human_training.min_reviewers')
                    || $top / max(1, $count) < config('human_training.min_agreement') || $top === $second) {
                    continue;
                }

                $opinions[$snapshot->decision_at_ms] = [
                    'decision_at_ms' => (int) $source['decision_at_ms'],
                    'label_available_at_ms' => (int) $source['label_available_at_ms'],
                    'updated_at_ms' => $snapshot->reviews->max(fn ($review) => $review->submitted_at->getTimestampMs()),
                    'vector' => $payload['vector'], // Snapshot vectors are already normalized and verified.
                    'label' => array_key_first($votes),
                    'provenance' => ['snapshot_id' => $snapshot->snapshot_id, 'sha256' => $snapshot->sha256,
                        'review_ids' => $snapshot->reviews->pluck('review_id')->all()],
                ];
            }
        }
        foreach (app(IndependentHumanAnnotations::class)->opinions($manifest, 'human_outcome', $annotationCutoff, $deadline) as $opinion) {
            $decision = $opinion['decision_at_ms'];
            if (! isset($opinions[$decision]) || ($opinion['updated_at_ms'] ?? 0) > ($opinions[$decision]['updated_at_ms'] ?? 0)) {
                $opinions[$decision] = $opinion;
            }
        }
        ksort($opinions);

        return array_values($opinions);
    }
}
