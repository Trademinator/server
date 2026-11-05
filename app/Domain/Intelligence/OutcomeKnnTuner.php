<?php

namespace App\Domain\Intelligence;

use App\Domain\Research\SemanticLabels;
use App\Domain\Research\WalkForward;
use Closure;

final class OutcomeKnnTuner
{
    private const TUNING_TIMEOUT = 'Outcome K tuning time budget exceeded.';

    public function __construct(private WeightedKnn $neighbors, private OutcomeKnn $outcomes, private ?Closure $clock = null) {}

    public function tunePrepared(array|TrainingRows $rows, array $settings, float $deadline, ?float $candidateSeconds = null): array
    {
        $budget = new KnnCandidateBudget($deadline, $candidateSeconds, self::TUNING_TIMEOUT, $this->clock);
        $trainSize = $settings['min_train_size'];
        $minimum = max(1, (int) floor($this->neighbors->minEffective) + 1);
        $maximum = min($settings['k_cap'], $trainSize, count($rows));
        if ($maximum < $minimum) {
            $budget->checkpointShared();

            return ['k' => null, 'k_min' => $minimum, 'k_max' => $maximum, 'folds' => [], 'candidates' => [],
                'selection' => 'supported_macro_f1_then_baseline_improvement_accuracy_confidence_coverage',
                'reason' => 'no_k_can_satisfy_effective_neighbor_floor'];
        }
        $initialCandidates = array_values(array_unique(array_filter([5, 9, 17, 33, 65, $maximum],
            fn (int $k): bool => $k >= $minimum && $k <= $maximum)));
        sort($initialCandidates);

        $rows = TrainingRows::from($rows);

        $reports = [];
        $budget->checkpointShared();
        foreach (range($minimum, $maximum) as $k) {
            $reports[$k] = $this->emptyReport($k);
            $budget->checkpointCandidate($k);
        }

        $folds = [];
        foreach ((new WalkForward)->rowFolds($rows, $trainSize, $settings['test_size'], $settings['gap'], true, null) as $fold) {
            $training = $fold['train'];
            $baseline = $this->majorityLabel($training);
            $folds[] = ['fold' => $fold['fold'], 'train_rows' => count($training),
                'labels_available_by_ms' => $training->max('label_available_at_ms'),
                'test_from_ms' => $fold['test'][0]['decision_at_ms']];
            foreach ($fold['test'] as $row) {
                $budget->checkpointShared();
                $neighbors = $this->neighbors->neighborsPrepared($training, $row['vector'], $maximum,
                    $row['decision_at_ms'], $row['feature_weights'] ?? []);
                $budget->checkpointShared();
                foreach ($reports as $k => &$report) {
                    $this->accumulate($report, $row, $this->outcomes->vote($neighbors, $k), $baseline);
                    $budget->checkpointCandidate($k);
                }
                unset($report);
            }
            unset($training);
        }

        $budget->checkpointShared();
        foreach ($reports as $k => &$report) {
            $report = $this->finish($report, $settings);
            $budget->checkpointCandidate($k);
        }
        unset($report);
        $initialReports = array_intersect_key($reports, array_flip($initialCandidates));
        $best = $this->best($initialReports);
        $center = $best ?? $maximum;
        $selected = array_values(array_unique([...$initialCandidates,
            ...range(max($minimum, $center - 3), min($maximum, $center + 3))]));
        sort($selected);
        $reports = array_intersect_key($reports, array_flip($selected));
        ksort($reports);

        $result = ['k' => $this->best($reports), 'k_min' => $minimum, 'k_max' => $maximum, 'folds' => $folds,
            'candidates' => array_values($reports),
            'selection' => 'supported_macro_f1_then_baseline_improvement_accuracy_confidence_coverage'];
        $budget->checkpointShared();

        return $result;
    }

    public function evaluateWalkForwardPrepared(array|TrainingRows $rows, array $settings, int $k, float $deadline): array
    {
        $budget = new KnnCandidateBudget($deadline, null, self::TUNING_TIMEOUT, $this->clock);
        $rows = TrainingRows::from($rows);
        $state = $this->emptyReport($k);
        $folds = [];
        foreach ((new WalkForward)->rowFolds($rows, $settings['min_train_size'], $settings['test_size'], $settings['gap'], true, null) as $fold) {
            $training = $fold['train'];
            $baseline = $this->majorityLabel($training);
            $folds[] = ['fold' => $fold['fold'], 'train_rows' => count($training),
                'labels_available_by_ms' => $training->max('label_available_at_ms'),
                'test_from_ms' => $fold['test'][0]['decision_at_ms']];
            foreach ($fold['test'] as $row) {
                $budget->checkpointShared();
                $nearest = $this->neighbors->neighborsPrepared($training, $row['vector'], $k,
                    $row['decision_at_ms'], $row['feature_weights'] ?? []);
                $this->accumulate($state, $row, $this->outcomes->vote($nearest, $k), $baseline);
                $budget->checkpointShared();
            }
            unset($training);
        }

        $result = ['report' => $this->finish($state, $settings), 'folds' => $folds];
        $budget->checkpointShared();

        return $result;
    }

    public function evaluatePrepared(array|TrainingRows $training, array|TrainingRows $test, int $k, array $settings, float $deadline): array
    {
        $budget = new KnnCandidateBudget($deadline, null, 'Outcome KNN evaluation time budget exceeded.', $this->clock);
        $state = $this->emptyReport($k);
        $baseline = $this->majorityLabel($training);
        $trace = [];
        foreach ($test as $row) {
            $budget->checkpointShared();
            $neighbors = $this->neighbors->neighborsPrepared($training, $row['vector'], $k,
                $row['decision_at_ms'], $row['feature_weights'] ?? []);
            $prediction = $this->outcomes->vote($neighbors, $k);
            $this->accumulate($state, $row, $prediction, $baseline);
            $trace[] = [
                'decision_at_ms' => $row['decision_at_ms'],
                'actual' => $row['label'],
                'predicted' => $prediction['outcome'],
                'supported' => $prediction['reason'] === 'supported',
                'reason' => $prediction['reason'],
                'confidence' => $prediction['confidence'],
            ];
            if (count($trace) > 180) {
                array_shift($trace);
            }
            $budget->checkpointShared();
        }

        $result = [...$this->finish($state, $settings), 'trace' => $trace];
        $budget->checkpointShared();

        return $result;
    }

    private function emptyReport(int $k): array
    {
        $matrix = array_fill_keys(SemanticLabels::OUTCOMES,
            array_fill_keys(SemanticLabels::OUTCOMES, 0));

        return ['k' => $k, 'evaluated' => 0, 'supported' => 0, 'correct' => 0, 'confidence' => 0.0,
            'confusion' => $matrix, 'baseline_confusion' => $matrix];
    }

    private function accumulate(array &$state, array $row, array $prediction, string $baseline): void
    {
        $actual = $row['label'];
        $state['evaluated']++;
        if ($prediction['reason'] !== 'supported') {
            return;
        }

        $predicted = $prediction['outcome'];
        $state['confusion'][$actual][$predicted]++;
        $state['baseline_confusion'][$actual][$baseline]++;
        $state['supported']++;
        $state['correct'] += (int) ($actual === $predicted);
        $state['confidence'] += $prediction['confidence'];
    }

    private function finish(array $state, array $settings): array
    {
        $coverage = $state['evaluated'] ? $state['supported'] / $state['evaluated'] : 0.0;
        $accuracy = $state['supported'] ? $state['correct'] / $state['supported'] : 0.0;
        [$macroF1, $perClass] = $this->classificationMetrics($state['confusion']);
        [$baselineMacroF1, $baselinePerClass] = $this->classificationMetrics($state['baseline_confusion']);
        $baselineCorrect = array_sum(array_map(
            fn (string $class): int => $state['baseline_confusion'][$class][$class],
            SemanticLabels::OUTCOMES
        ));
        $baselineAccuracy = $state['supported'] ? $baselineCorrect / $state['supported'] : 0.0;
        $outcome = $settings['outcome'] ?? [];
        $minimumMacroF1 = (float) ($outcome['min_macro_f1'] ?? 0.20);
        $minimumImprovement = (float) ($outcome['min_baseline_improvement'] ?? 0.02);
        $minimumSupported = max(5, (int) ($outcome['min_supported_predictions'] ?? 25));
        $minimumCoverage = (float) ($outcome['min_coverage'] ?? 0.01);
        $improvement = $macroF1 - $baselineMacroF1;

        $gates = [
            'validation_rows' => $state['evaluated'] >= $settings['min_validation_rows'],
            'supported_predictions' => $state['supported'] >= $minimumSupported,
            'macro_f1' => $macroF1 >= $minimumMacroF1,
            'baseline_improvement' => $improvement >= $minimumImprovement,
            'coverage' => $coverage >= $minimumCoverage,
        ];

        $ordinal = $this->ordinalMetrics($state['confusion']);
        unset($state['baseline_confusion']);

        return [...$state, 'abstained' => $state['evaluated'] - $state['supported'],
            'accuracy' => $accuracy, 'supported_accuracy' => $accuracy,
            'macro_f1' => $macroF1, 'supported_macro_f1' => $macroF1,
            'semantic_precision' => $macroF1, 'per_class' => $perClass, 'ordinal' => $ordinal,
            'coverage' => $coverage,
            'baseline' => [
                'strategy' => 'training_majority_class_on_same_supported_rows',
                'accuracy' => $baselineAccuracy,
                'macro_f1' => $baselineMacroF1,
                'per_class' => $baselinePerClass,
                'improvement' => $improvement,
                'minimum_improvement' => $minimumImprovement,
            ],
            'thresholds' => [
                'min_macro_f1' => $minimumMacroF1,
                'min_baseline_improvement' => $minimumImprovement,
                'min_supported_predictions' => $minimumSupported,
                'min_coverage' => $minimumCoverage,
            ],
            'mean_confidence' => $state['supported'] ? $state['confidence'] / $state['supported'] : 0.0,
            'stability' => 1.0, 'gates' => $gates,
            'failed_gates' => array_keys(array_filter($gates, fn (bool $passed): bool => ! $passed)),
            'eligible' => ! in_array(false, $gates, true)];
    }

    private function classificationMetrics(array $confusion): array
    {
        $perClass = [];
        foreach (SemanticLabels::OUTCOMES as $class) {
            $tp = $confusion[$class][$class];
            $predicted = array_sum(array_column($confusion, $class));
            $actual = array_sum($confusion[$class]);
            $precision = $predicted ? $tp / $predicted : 0.0;
            $recall = $actual ? $tp / $actual : 0.0;
            $f1 = ($precision + $recall) > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;
            $perClass[$class] = [
                'precision' => $precision,
                'recall' => $recall,
                'f1' => $f1,
                'support' => $actual,
                'predicted' => $predicted,
            ];
        }

        return [array_sum(array_column($perClass, 'f1')) / count($perClass), $perClass];
    }

    private function ordinalMetrics(array $confusion): array
    {
        $positions = array_flip(SemanticLabels::OUTCOMES);
        $distanceCounts = array_fill(0, count(SemanticLabels::OUTCOMES), 0);
        $total = $exact = $withinOne = $sameDirection = $oppositeDirection = $extremeOpposite = 0;
        $absoluteError = 0;

        foreach (SemanticLabels::OUTCOMES as $actual) {
            foreach (SemanticLabels::OUTCOMES as $predicted) {
                $count = (int) $confusion[$actual][$predicted];
                if ($count === 0) {
                    continue;
                }

                $actualPosition = $positions[$actual] - 2;
                $predictedPosition = $positions[$predicted] - 2;
                $distance = abs($actualPosition - $predictedPosition);
                $total += $count;
                $absoluteError += $distance * $count;
                $distanceCounts[$distance] += $count;
                $exact += $distance === 0 ? $count : 0;
                $withinOne += $distance <= 1 ? $count : 0;

                $actualDirection = $actualPosition <=> 0;
                $predictedDirection = $predictedPosition <=> 0;
                $sameDirection += $actualDirection === $predictedDirection ? $count : 0;
                $oppositeDirection += $actualDirection !== 0
                    && $predictedDirection !== 0
                    && $actualDirection !== $predictedDirection ? $count : 0;
                $extremeOpposite += ($actualPosition === -2 && $predictedPosition === 2)
                    || ($actualPosition === 2 && $predictedPosition === -2) ? $count : 0;
            }
        }

        $rate = fn (int $count): float => $total > 0 ? $count / $total : 0.0;

        return [
            'class_order' => SemanticLabels::OUTCOMES,
            'supported_predictions' => $total,
            'exact_accuracy' => $rate($exact),
            'within_one_class_accuracy' => $rate($withinOne),
            'mean_absolute_class_error' => $total > 0 ? $absoluteError / $total : 0.0,
            'same_direction_accuracy' => $rate($sameDirection),
            'opposite_direction_rate' => $rate($oppositeDirection),
            'extreme_opposite_rate' => $rate($extremeOpposite),
            'distance' => array_map(
                fn (int $count, int $distance): array => [
                    'distance' => $distance,
                    'count' => $count,
                    'rate' => $rate($count),
                ],
                $distanceCounts,
                array_keys($distanceCounts),
            ),
        ];
    }

    private function majorityLabel(iterable $rows): string
    {
        $counts = array_fill_keys(SemanticLabels::OUTCOMES, 0);
        foreach ($rows as $row) {
            if (isset($counts[$row['label'] ?? null])) {
                $counts[$row['label']]++;
            }
        }
        $winner = SemanticLabels::OUTCOMES[0];
        foreach (SemanticLabels::OUTCOMES as $class) {
            if ($counts[$class] > $counts[$winner]) {
                $winner = $class;
            }
        }

        return $winner;
    }

    private function best(array $reports): ?int
    {
        $eligible = array_values(array_filter($reports, fn (array $report): bool => $report['eligible']));
        usort($eligible, fn (array $a, array $b): int => [
            $b['macro_f1'], $b['accuracy'], $b['mean_confidence'], $b['coverage'], -$b['k'],
        ] <=> [
            $a['macro_f1'], $a['accuracy'], $a['mean_confidence'], $a['coverage'], -$a['k'],
        ]);

        return $eligible[0]['k'] ?? null;
    }
}
