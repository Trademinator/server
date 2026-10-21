<?php

namespace App\Domain\Intelligence;

use Generator;
use Rubix\ML\Classifiers\ClassificationTree;
use Rubix\ML\Classifiers\KNearestNeighbors;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Datasets\Unlabeled;
use RuntimeException;

final class PatternTrainer
{
    public function __construct(private PatternCatalog $catalog, private ProbabilityCalibration $calibration) {}

    public function train(iterable $rows, array $settings, float $deadline): array
    {
        $rows = TrainingRows::from($rows);
        $models = $reports = [];
        foreach (PatternCatalog::TYPES as $type) {
            $samples = TrainingRows::from((function () use ($rows, $type): Generator {
                foreach ($rows as $row) {
                    foreach ($row['patterns'] as $candidate) {
                        if ($candidate['type'] === $type) {
                            yield ['vector' => $this->catalog->vector($row['vector'], $candidate),
                                'label' => $candidate['label'], 'decision_at_ms' => $row['decision_at_ms'],
                                'label_available_at_ms' => $candidate['label_available_at_ms']];
                        }
                    }
                }
            })());
            $count = count($samples);
            if ($count < $settings['min_samples']) {
                $reports[$type] = ['status' => 'insufficient_samples', 'samples' => $count];
                unset($samples);

                continue;
            }
            $calibrationStart = (int) floor($count * 0.6);
            $testStart = (int) floor($count * 0.8);
            $calibrationCutoff = $samples[$calibrationStart]['decision_at_ms'];
            $testCutoff = $samples[$testStart]['decision_at_ms'];
            $train = $samples->slice(0, $calibrationStart)->filter(
                fn (array $row): bool => $row['label_available_at_ms'] < $calibrationCutoff
            );
            $calibrate = $samples->slice($calibrationStart, $testStart - $calibrationStart)->filter(
                fn (array $row): bool => $row['label_available_at_ms'] < $testCutoff
            );
            $test = $samples->slice($testStart);
            $selectStart = (int) floor(count($calibrate) / 2);
            $select = $calibrate->slice($selectStart);
            $selectionCutoff = $select[0]['decision_at_ms'] ?? 0;
            $calibrate = $calibrate->slice(0, $selectStart)->filter(
                fn (array $row): bool => $row['label_available_at_ms'] < $selectionCutoff
            );
            unset($samples);
            $trainLabels = $this->labelCounts($train);
            $calibrationLabels = $this->labelCounts($calibrate);
            if (min(count($train), count($calibrate), count($select), count($test)) < $settings['min_block_rows']
                || count($trainLabels) < 2
                || count($calibrationLabels) < 2) {
                $reports[$type] = ['status' => 'insufficient_chronological_classes', 'samples' => $count];
                unset($train, $calibrate, $select, $test);

                continue;
            }
            $learners = [
                'random_forest' => new SequentialRandomForest(new ClassificationTree(10, 3), $settings['trees'], 0.8),
                'weighted_knn' => new KNearestNeighbors(min($settings['k'], count($train)), true),
            ];
            $baseRate = ($trainLabels['completed'] ?? 0) / count($train);
            $baseline = $this->calibration->metrics(array_fill(0, count($test), $baseRate), $this->column($test, 'label'));
            $candidates = [];
            foreach ($learners as $name => $learner) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Pattern training time budget exceeded.');
                }
                $learner->train(new Labeled($this->column($train, 'vector'), $this->column($train, 'label')));
                $raw = array_map(fn (array $p): float => $p['completed'] ?? 0.0,
                    $learner->proba(new Unlabeled($this->column($calibrate, 'vector'))));
                $mapping = $this->calibration->fit($raw, $this->column($calibrate, 'label'));
                $rawSelect = array_map(fn (array $p): float => $p['completed'] ?? 0.0,
                    $learner->proba(new Unlabeled($this->column($select, 'vector'))));
                $selectionMetrics = $this->calibration->metrics(
                    array_map(fn (float $p): float => $this->calibration->apply($p, $mapping), $rawSelect), $this->column($select, 'label'));
                $rawTest = array_map(fn (array $p): float => $p['completed'] ?? 0.0,
                    $learner->proba(new Unlabeled($this->column($test, 'vector'))));
                $calibrated = array_map(fn (float $p): float => $this->calibration->apply($p, $mapping), $rawTest);
                $candidates[$name] = ['algorithm' => $name, 'estimator' => $learner, 'calibration' => $mapping,
                    'selection_metrics' => $selectionMetrics,
                    'metrics' => $this->calibration->metrics($calibrated, $this->column($test, 'label')),
                    'raw_metrics' => $this->calibration->metrics($rawTest, $this->column($test, 'label')),
                    'available_at_ms' => $test->max('label_available_at_ms')];
                unset($raw, $rawSelect, $rawTest, $calibrated);
            }
            uasort($candidates, fn (array $a, array $b): int => [$a['selection_metrics']['brier'], $a['selection_metrics']['log_loss'], $a['selection_metrics']['calibration_error']]
                <=> [$b['selection_metrics']['brier'], $b['selection_metrics']['log_loss'], $b['selection_metrics']['calibration_error']]);
            $winner = reset($candidates);
            $eligible = $winner['metrics']['brier'] < $baseline['brier'];
            if ($eligible) {
                $models[$type] = $winner;
            }
            $reports[$type] = [
                'status' => $eligible ? 'validated' : 'no_improvement_over_prior',
                'selected' => $eligible ? $winner['algorithm'] : null,
                'candidate_selected_before_test' => $winner['algorithm'],
                'selection_rows' => count($select), 'selection_from_ms' => $select[0]['decision_at_ms'],
                'selection_labels_available_by_ms' => $select->max('label_available_at_ms'),
                'samples' => $count, 'train_rows' => count($train), 'calibration_rows' => count($calibrate),
                'test_rows' => count($test),
                'train_labels_available_by_ms' => $train->max('label_available_at_ms'),
                'calibration_from_ms' => $calibrate[0]['decision_at_ms'],
                'calibration_labels_available_by_ms' => $calibrate->max('label_available_at_ms'),
                'test_from_ms' => $test[0]['decision_at_ms'], 'prior_baseline' => $baseline,
                'candidates' => array_map(function (array $candidate): array {
                    unset($candidate['estimator'], $candidate['calibration']);

                    return $candidate;
                }, $candidates),
            ];
            unset($train, $calibrate, $select, $test, $learners, $candidates, $learner, $winner, $mapping);
        }

        return ['models' => $models, 'report' => $reports, 'version' => PatternCatalog::VERSION];
    }

    /** @return array<string, int> */
    private function labelCounts(iterable $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['label']] = ($counts[$row['label']] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return list<mixed> */
    private function column(iterable $rows, string $field): array
    {
        $values = [];
        foreach ($rows as $row) {
            $values[] = $row[$field];
        }

        return $values;
    }

    public function predict(array $bundle, array $vector, array $candidates, int $asOfMs): array
    {
        $result = [];
        foreach ($candidates as $candidate) {
            $model = $bundle['models'][$candidate['type']] ?? null;
            $available = $model !== null && $model['available_at_ms'] < $asOfMs;
            $probability = null;
            if ($available) {
                $raw = $model['estimator']->proba(new Unlabeled([$this->catalog->vector($vector, $candidate)]))[0]['completed'] ?? 0.0;
                $probability = $this->calibration->apply($raw, $model['calibration']);
            }
            $result[] = [
                'type' => $candidate['type'], 'length' => $candidate['length'], 'stage' => $candidate['stage'],
                'progress' => $candidate['progress'], 'similarity' => $candidate['similarity'],
                'completion_probability' => $probability,
                'reason' => $available ? 'calibrated' : 'no_prior_validated_model',
                'algorithm' => $available ? $model['algorithm'] : null,
            ];
        }

        return $result;
    }

    public function featureKeys(array $bundle): array
    {
        $keys = [];
        foreach (array_keys($bundle['models']) as $type) {
            $keys[] = 'pattern.'.$type.'.probability';
            $keys[] = 'pattern.'.$type.'.present';
        }

        return $keys;
    }

    public function features(array $bundle, array $predictions): array
    {
        $byType = array_column($predictions, null, 'type');
        $vector = [];
        foreach (array_keys($bundle['models']) as $type) {
            $probability = $byType[$type]['completion_probability'] ?? null;
            $vector[] = $probability ?? 0.5;
            $vector[] = $probability === null ? 0.0 : 1.0;
        }

        return $vector;
    }
}
