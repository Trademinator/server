<?php

namespace App\Domain\Intelligence;

use App\Domain\Research\WalkForward;
use InvalidArgumentException;
use RuntimeException;

final class KnnTuner
{
    public function __construct(private WeightedKnn $knn) {}

    public function tune(array $rows, array $settings, float $deadline): array
    {
        $rows = $this->knn->prepareRows($rows);

        return $this->tunePrepared($rows, $settings, $deadline);
    }

    public function tunePrepared(array|TrainingRows $rows, array $settings, float $deadline): array
    {
        $trainSize = $settings['min_train_size'];
        $minimum = max(1, (int) floor($this->knn->minEffective) + 1);
        $maximum = min($settings['k_cap'], $trainSize, count($rows));
        if ($maximum < $minimum) {
            return ['k' => null, 'k_min' => $minimum, 'k_max' => $maximum, 'folds' => [], 'candidates' => [],
                'selection' => 'semantic_precision_then_confidence_coverage_stability',
                'reason' => 'no_k_can_satisfy_effective_neighbor_floor'];
        }
        $initialCandidates = array_values(array_unique(array_filter([5, 9, 17, 33, 65, $maximum],
            fn (int $k): bool => $k >= $minimum && $k <= $maximum)));
        sort($initialCandidates);

        $rows = TrainingRows::from($rows);

        // Score every feasible K incrementally while each neighbor list is hot.
        // The first walk-forward fold has exactly min_train_size rows, so K cannot
        // exceed that size even when later expanding folds contain much more history.
        $accumulators = [];
        foreach (range($minimum, $maximum) as $k) {
            $accumulators[$k] = $this->newAccumulator($k);
        }

        $folds = [];
        foreach ((new WalkForward)->rowFolds($rows, $trainSize, $settings['test_size'], $settings['gap'], true, null) as $fold) {
            $training = $fold['train'];
            $folds[] = ['fold' => $fold['fold'], 'train_rows' => count($training),
                'labels_available_by_ms' => $training->max('label_available_at_ms'),
                'test_from_ms' => $fold['test'][0]['decision_at_ms']];
            foreach ($fold['test'] as $row) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('K tuning time budget exceeded; reduce INTELLIGENCE_MAX_MODEL_AGE_DAYS or increase the build time budget.');
                }
                $neighbors = $this->knn->neighborsPrepared($training, $row['vector'], $maximum,
                    $row['decision_at_ms'], $row['feature_weights'] ?? []);
                foreach ($accumulators as $k => &$accumulator) {
                    $this->accumulate($accumulator, $row, $this->knn->vote($neighbors, $k), $fold['fold']);
                }
                unset($accumulator);
            }
            unset($training);
        }

        $allReports = [];
        foreach ($accumulators as $k => $accumulator) {
            $allReports[$k] = $this->finishAccumulator($accumulator, $settings);
        }
        $initialReports = array_intersect_key($allReports, array_flip($initialCandidates));
        $best = $this->best($initialReports);
        $center = $best ?? $maximum;
        $selected = array_values(array_unique([...$initialCandidates,
            ...range(max($minimum, $center - 3), min($maximum, $center + 3))]));
        sort($selected);
        $reports = array_intersect_key($allReports, array_flip($selected));
        ksort($reports);

        $winner = $this->best($reports);
        $insufficient = array_filter($reports,
            static fn (array $candidate): bool => $candidate['validation_status'] === 'insufficient_evidence');
        $reason = $winner !== null ? 'validated'
            : (count($insufficient) === count($reports) ? 'insufficient_directional_evidence' : 'no_eligible_k');

        return ['k' => $winner, 'k_min' => $minimum, 'k_max' => $maximum, 'folds' => $folds,
            'candidates' => array_values($reports), 'selection' => 'wilson_lower_then_semantic_precision_confidence_stability',
            'reason' => $reason];
    }

    public function evaluateWalkForwardPrepared(array|TrainingRows $rows, array $settings, int $k, float $deadline): array
    {
        $rows = TrainingRows::from($rows);
        $accumulator = $this->newAccumulator($k);
        $folds = [];
        foreach ((new WalkForward)->rowFolds($rows, $settings['min_train_size'], $settings['test_size'], $settings['gap'], true, null) as $fold) {
            $training = $fold['train'];
            $folds[] = ['fold' => $fold['fold'], 'train_rows' => count($training),
                'labels_available_by_ms' => $training->max('label_available_at_ms'),
                'test_from_ms' => $fold['test'][0]['decision_at_ms']];
            foreach ($fold['test'] as $row) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('K tuning time budget exceeded; reduce INTELLIGENCE_MAX_MODEL_AGE_DAYS or increase the build time budget.');
                }
                $neighbors = $this->knn->neighborsPrepared($training, $row['vector'], $k,
                    $row['decision_at_ms'], $row['feature_weights'] ?? []);
                $this->accumulate($accumulator, $row, $this->knn->vote($neighbors, $k), $fold['fold']);
            }
            unset($training);
        }

        return ['report' => $this->finishAccumulator($accumulator, $settings), 'folds' => $folds];
    }

    public function evaluate(array $training, array $test, int $k, array $settings, float $deadline): array
    {
        $training = $this->knn->prepareRows($training);
        $test = $this->knn->prepareRows($test);

        return $this->evaluatePrepared($training, $test, $k, $settings, $deadline);
    }

    public function evaluatePrepared(array|TrainingRows $training, array|TrainingRows $test, int $k, array $settings, float $deadline): array
    {
        $accumulator = $this->newAccumulator($k);
        foreach ($test as $row) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('KNN evaluation time budget exceeded.');
            }
            $neighbors = $this->knn->neighborsPrepared($training, $row['vector'], $k,
                $row['decision_at_ms'], $row['feature_weights'] ?? []);
            $this->accumulate($accumulator, $row, $this->knn->vote($neighbors, $k), 1);
            $accumulator['trace'][] = [
                'decision_at_ms' => $row['decision_at_ms'],
                'actual' => $row['label'],
                'predicted' => $accumulator['last_prediction']['action'],
                'supported' => $accumulator['last_prediction']['reason'] === 'supported',
                'reason' => $accumulator['last_prediction']['reason'],
                'confidence' => $accumulator['last_prediction']['confidence'],
            ];
            if (count($accumulator['trace']) > 180) {
                array_shift($accumulator['trace']);
            }
        }

        unset($accumulator['last_prediction']);

        return $this->finishAccumulator($accumulator, $settings);
    }

    private function newAccumulator(int $k): array
    {
        return ['k' => $k, 'evaluated' => 0, 'supported' => 0, 'abstained' => 0,
            'directional' => 0, 'correct' => 0, 'supported_holds' => 0, 'correct_holds' => 0,
            'contradictions' => 0, 'confidence' => 0.0, 'folds' => [], 'trace' => [],
            // Confusion includes only supported predictions, never fail-closed abstentions.
            'confusion' => array_fill_keys(['buy', 'hodl', 'sell'], array_fill_keys(['buy', 'hodl', 'sell'], 0)),
            'abstentions_by_label' => array_fill_keys(['buy', 'hodl', 'sell'], 0),
            // Classification disagreements; these are NOT demonstrated financial losses.
            'classification_errors' => array_fill_keys([
                'buy_on_sell', 'sell_on_buy', 'buy_on_hold', 'sell_on_hold',
                'hold_on_buy', 'hold_on_sell',
            ], 0)];
    }

    private function accumulate(array &$state, array $row, array $result, int $fold): void
    {
        $state['last_prediction'] = $result;
        $label = $row['label'] ?? null;
        $labelAvailable = $row['label_available_at_ms'] ?? null;
        if (! in_array($label, ['buy', 'hodl', 'sell'], true)
            || ! is_int($labelAvailable) || $labelAvailable <= ($row['decision_at_ms'] ?? PHP_INT_MAX)) {
            throw new InvalidArgumentException('Action holdout requires finalized historical action labels.');
        }
        $state['evaluated']++;
        $state['folds'][$fold] ??= ['correct' => 0, 'directional' => 0];
        if (($result['reason'] ?? null) !== 'supported') {
            $state['abstained']++;
            $state['abstentions_by_label'][$label]++;

            return;
        }

        $action = $result['action'] ?? null;
        if (! in_array($action, ['buy', 'hodl', 'sell'], true)) {
            throw new InvalidArgumentException('Supported Action predictions must be BUY, HOLD or SELL.');
        }
        $state['supported']++;
        $state['confusion'][$label][$action]++;
        if ($action === 'hodl') {
            $state['supported_holds']++;
            $state['correct_holds'] += (int) ($label === 'hodl');
        } else {
            $state['directional']++;
            $state['confidence'] += $result['confidence'];
            $matches = $action === $label;
            $state['correct'] += (int) $matches;
            $state['folds'][$fold]['directional']++;
            $state['folds'][$fold]['correct'] += (int) $matches;
            $top = (bool) ($row['semantic_top'] ?? $row['semantic']['top'] ?? false);
            $bottom = (bool) ($row['semantic_bottom'] ?? $row['semantic']['bottom'] ?? false);
            // Preserve the existing semantic contradiction gate: opposite pivot only.
            $state['contradictions'] += (int) (($action === 'buy' && $top) || ($action === 'sell' && $bottom));
        }

        $error = match (true) {
            $action === 'buy' && $label === 'sell' => 'buy_on_sell',
            $action === 'sell' && $label === 'buy' => 'sell_on_buy',
            $action === 'buy' && $label === 'hodl' => 'buy_on_hold',
            $action === 'sell' && $label === 'hodl' => 'sell_on_hold',
            $action === 'hodl' && $label === 'buy' => 'hold_on_buy',
            $action === 'hodl' && $label === 'sell' => 'hold_on_sell',
            default => null,
        };
        if ($error !== null) {
            $state['classification_errors'][$error]++;
        }
    }

    private function finishAccumulator(array $state, array $settings): array
    {
        unset($state['last_prediction']);
        $directional = $state['directional'];
        $precision = $directional ? $state['correct'] / $directional : 0;
        $coverage = $state['evaluated'] ? $directional / $state['evaluated'] : 0;
        $contradictionRate = $directional ? $state['contradictions'] / $directional : 0;
        $rates = array_map(fn (array $fold): float => $fold['directional'] ? $fold['correct'] / $fold['directional'] : 0, $state['folds']);
        $stability = $rates ? 1 - (max($rates) - min($rates)) : 0;
        $errors = $state['classification_errors'];
        $directionalOnHold = $errors['buy_on_hold'] + $errors['sell_on_hold'];
        $oppositeActions = $errors['buy_on_sell'] + $errors['sell_on_buy'];

        $report = ['k' => $state['k'], 'evaluated' => $state['evaluated'], 'directional' => $directional,
            'correct' => $state['correct'], 'supported' => $state['supported'], 'abstained' => $state['abstained'],
            'supported_holds' => $state['supported_holds'], 'correct_holds' => $state['correct_holds'],
            'supported_hold_precision' => $state['supported_holds']
                ? $state['correct_holds'] / $state['supported_holds'] : 0.0,
            'abstentions_by_label' => $state['abstentions_by_label'],
            'classification_errors' => $errors,
            'opposite_action_predictions' => $oppositeActions,
            'directional_predictions_on_hold' => $directionalOnHold,
            'directional_predictions_on_hold_rate' => $directional ? $directionalOnHold / $directional : 0.0,
            'semantic_precision' => $precision, 'contradiction_rate' => $contradictionRate,
            // Observed frequency is useful to diagnose rarity, NOT a minimum trading quota.
            'coverage' => $coverage, 'mean_confidence' => $directional ? $state['confidence'] / $directional : 0,
            'stability' => $stability, 'confusion' => $state['confusion'],
            'accounting_version' => 'action-supported-vs-abstain-v1',
            'evaluation_basis' => 'finalized_historical_action_labels'];

        return [...$report, ...ActionKnnValidation::assess($report, $settings)];
    }

    private function score(array $cases, int $k, array $settings): array
    {
        // The auxiliary and automatic paths must use identical accounting.
        $state = $this->newAccumulator($k);
        foreach ($cases as $case) {
            $this->accumulate($state, $case['row'],
                $case['result'] ?? $this->knn->vote($case['neighbors'], $k), (int) $case['fold']);
        }

        return $this->finishAccumulator($state, $settings);
    }

    private function best(array $reports): ?int
    {
        $eligible = array_values(array_filter($reports, fn (array $report): bool => $report['eligible']));
        // Rank only candidates that passed every evidence/quality gate. Prefer
        // the statistically stronger candidate, not the most frequent trader.
        usort($eligible, fn (array $a, array $b): int => [
            $b['directional_wilson_95']['lower'], $b['semantic_precision'], $b['mean_confidence'],
            $b['stability'], -$b['k'],
        ] <=> [
            $a['directional_wilson_95']['lower'], $a['semantic_precision'], $a['mean_confidence'],
            $a['stability'], -$a['k'],
        ]);

        return $eligible[0]['k'] ?? null;
    }

    /** Score an auxiliary classifier against the same finalized historical action labels. */
    public function evaluatePredictions(array $rows, array $predictions, array $settings): array
    {
        if (count($rows) !== count($predictions)) {
            throw new InvalidArgumentException('Action holdout requires one prediction per finalized row.');
        }
        $cases = [];
        foreach ($rows as $i => $row) {
            $cases[] = ['row' => $row, 'fold' => 1, 'result' => $predictions[$i]];
        }

        return $this->score($cases, 0, $settings);
    }
}
