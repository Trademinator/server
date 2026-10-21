<?php

namespace App\Domain\Intelligence;

use App\Domain\Features\ContextFeatures;
use App\Domain\Research\SemanticLabels;
use Rubix\ML\Classifiers\ClassificationTree;
use Rubix\ML\Classifiers\RandomForest;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Datasets\Unlabeled;

/**
 * Optional, independent five-class context advisor.
 *
 * Input columns are exclusively CoinGecko observations frozen alongside an
 * otherwise Core/Technical semantic dataset. No context column enters KNN.
 */
final class CoinGeckoInsight
{
    public const VERSION = 'coingecko-random-forest-v1';

    // Do not use price_deviation until provider and exchange samples are
    // independently aligned in time. Other columns are selected on TRAIN data.
    private const CANDIDATE_KEYS = [
        'context.global_regime', 'context.btc_dominance',
        'context.btc_dominance_change', 'context.activity',
        'context.activity_deviation', 'context.category_momentum',
        'context.market_cap_share', 'context.volume_share',
    ];

    /** @param iterable<array<string, mixed>> $rows */
    public function train(iterable $rows, string $schema, float $deadline): array
    {
        $bundle = [
            'version' => self::VERSION, 'optional' => true, 'status' => 'disabled',
            'algorithm' => 'rubix_random_forest', 'influence' => false,
            'input_keys' => [], 'snapshots' => 0, 'holdout' => [],
        ];

        if (! config('intelligence.coingecko_insight.enabled')) {
            return ['bundle' => $bundle];
        }
        // Core/Technical provide baseline-only KNN. Enhanced/Full request an
        // independent optional advisor, never additional KNN dimensions.
        if (! in_array($schema, ['enhanced', 'full'], true)) {
            return ['bundle' => [...$bundle, 'status' => 'profile_without_context']];
        }
        if (microtime(true) >= $deadline) {
            return ['bundle' => [...$bundle, 'status' => 'optional_budget_exhausted']];
        }

        $unique = [];
        foreach ($rows as $row) {
            $id = $row['context_snapshot_id'] ?? null;
            if (! is_string($id) || $id === ''
                || ! in_array($row['label'] ?? null, SemanticLabels::OUTCOMES, true)) {
                continue;
            }
            // A context snapshot can feed multiple adjacent candles, but it
            // must contribute no more than one target to this auxiliary forest.
            $unique[$id] = $row;
        }
        $unique = array_values($unique);
        usort($unique, fn (array $a, array $b): int => $a['decision_at_ms'] <=> $b['decision_at_ms']);
        $bundle['snapshots'] = count($unique);
        $minimum = max(40, (int) config('intelligence.coingecko_insight.min_snapshots', 192));
        if (count($unique) < $minimum) {
            return ['bundle' => [...$bundle, 'status' => 'insufficient_context_history']];
        }

        $limit = max($minimum, (int) config('intelligence.coingecko_insight.max_snapshots', 2400));
        $unique = array_slice($unique, -$limit);
        $cut = (int) floor(count($unique) * 0.8);
        $pretrain = array_slice($unique, 0, $cut);
        $keys = $this->selectKeys($pretrain);
        if (count($keys) < 3) {
            return ['bundle' => [...$bundle, 'status' => 'insufficient_context_columns']];
        }
        $bundle['input_keys'] = $keys;
        $eligible = array_values(array_filter($unique,
            fn (array $row): bool => $this->vector($row['context_features'] ?? [], $keys) !== null
        ));
        if (count($eligible) < $minimum) {
            return ['bundle' => [...$bundle, 'status' => 'insufficient_complete_context']];
        }
        $cut = (int) floor(count($eligible) * 0.8);
        $holdout = array_slice($eligible, $cut);
        $testStart = $holdout[0]['decision_at_ms'] ?? 0;
        $training = array_values(array_filter(array_slice($eligible, 0, $cut),
            fn (array $row): bool => $row['label_available_at_ms'] < $testStart
        ));

        $minHoldout = max(10, (int) config('intelligence.coingecko_insight.min_holdout', 32));
        if (count($training) < $minimum - $minHoldout || count($holdout) < $minHoldout
            || count(array_unique(array_column($training, 'label'))) < 2) {
            return ['bundle' => [...$bundle, 'status' => 'insufficient_purged_context']];
        }
        $samples = array_map(fn (array $row): array => $this->vector($row['context_features'], $keys), $training);
        $labels = array_column($training, 'label');
        $trees = max(4, min(64, (int) config('intelligence.coingecko_insight.trees', 24)));
        $depth = max(2, min(12, (int) config('intelligence.coingecko_insight.depth', 6)));
        $forest = new RandomForest(new ClassificationTree($depth, 8), $trees, 0.35, false);
        $forest->train(new Labeled($samples, $labels));

        if (microtime(true) > $deadline) {
            return ['bundle' => [...$bundle, 'status' => 'optional_budget_exhausted']];
        }
        $predictions = $forest->predict(new Unlabeled(array_map(
            fn (array $row): array => $this->vector($row['context_features'], $keys), $holdout
        )));
        $actuals = array_column($holdout, 'label');
        $majority = array_count_values($labels);
        arsort($majority);
        $baseline = array_fill(0, count($actuals), array_key_first($majority));
        $modelF1 = $this->macroF1($actuals, $predictions);
        $baselineF1 = $this->macroF1($actuals, $baseline);
        $gain = $modelF1 - $baselineF1;
        $required = (float) config('intelligence.coingecko_insight.min_f1_improvement', 0.02);
        $eligibleForInfluence = $gain >= $required;
        $bundle = [...$bundle,
            'status' => $eligibleForInfluence ? 'validated' : 'holdout_failed',
            'input_keys' => $keys,
            'snapshots' => count($eligible),
            'training_snapshots' => count($training),
            'holdout' => [
                'snapshots' => count($holdout), 'from_ms' => $testStart,
                'macro_f1' => $modelF1, 'majority_macro_f1' => $baselineF1,
                'f1_improvement' => $gain,
            ],
            'influence' => $eligibleForInfluence,
            'trees' => $trees, 'depth' => $depth,
        ];

        return ['bundle' => $bundle, 'estimator' => $eligibleForInfluence ? $forest : null];
    }

    /** @param array<string, mixed> $bundle */
    public function predict(array $bundle, array $payload, ?RandomForest $forest): array
    {
        $unavailable = fn (string $reason): array => [
            'reason' => $reason, 'outcome' => 'neutral',
            'votes' => array_fill_keys(SemanticLabels::OUTCOMES, 0.0),
            'confidence' => 0.0,
        ];
        if (! config('intelligence.coingecko_insight.enabled')) {
            return $unavailable('disabled');
        }
        if (($bundle['version'] ?? null) !== self::VERSION || ($bundle['status'] ?? null) !== 'validated'
            || $forest === null) {
            return $unavailable($bundle['status'] ?? 'model_unavailable');
        }
        if (empty($payload['context_snapshot_id'])) {
            return $unavailable('missing_context_snapshot');
        }
        $vector = $this->vector($payload['features'] ?? [], $bundle['input_keys'] ?? []);
        if ($vector === null) {
            return $unavailable('missing_context_features');
        }
        $scores = $forest->proba(new Unlabeled([$vector]))[0] ?? [];
        $votes = array_fill_keys(SemanticLabels::OUTCOMES, 0.0);
        foreach ($scores as $label => $probability) {
            if (! array_key_exists($label, $votes) || ! is_numeric($probability)
                || ! is_finite((float) $probability) || $probability < 0) {
                return $unavailable('invalid_probability');
            }
            $votes[$label] = (float) $probability;
        }
        $sum = array_sum($votes);
        if ($sum <= 0 || ! is_finite($sum)) {
            return $unavailable('invalid_probability');
        }
        $votes = array_map(fn (float $value): float => $value / $sum, $votes);
        arsort($votes);

        return ['reason' => 'supported', 'outcome' => array_key_first($votes),
            'votes' => $votes, 'confidence' => reset($votes),
            'context_snapshot_id' => $payload['context_snapshot_id']];
    }

    private function selectKeys(array $training): array
    {
        $count = count($training);
        if ($count === 0) {
            return [];
        }
        $keys = [];
        foreach (self::CANDIDATE_KEYS as $key) {
            $valid = 0;
            foreach ($training as $row) {
                $value = $row['context_features'][$key] ?? null;
                $valid += (int) (is_numeric($value) && is_finite((float) $value)
                    && (float) $value >= 0 && (float) $value <= 1);
            }
            if ($valid / $count >= 0.85) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    private function vector(array $features, array $keys): ?array
    {
        if ($keys === [] || array_diff($keys, ContextFeatures::KEYS) !== []) {
            return null;
        }
        $vector = [];
        foreach ($keys as $key) {
            $value = $features[$key] ?? null;
            if (! is_numeric($value) || ! is_finite((float) $value) || $value < 0 || $value > 1) {
                return null;
            }
            $vector[] = (float) $value;
        }

        return $vector;
    }

    private function macroF1(array $actual, array $predicted): float
    {
        $total = 0.0;
        foreach (SemanticLabels::OUTCOMES as $label) {
            $tp = $fp = $fn = 0;
            foreach ($actual as $index => $expected) {
                $guess = $predicted[$index] ?? '';
                $tp += (int) ($expected === $label && $guess === $label);
                $fp += (int) ($expected !== $label && $guess === $label);
                $fn += (int) ($expected === $label && $guess !== $label);
            }
            $denominator = 2 * $tp + $fp + $fn;
            $total += $denominator === 0 ? 0.0 : 2 * $tp / $denominator;
        }

        return $total / count(SemanticLabels::OUTCOMES);
    }
}
