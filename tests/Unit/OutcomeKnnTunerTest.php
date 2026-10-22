<?php

use App\Domain\Intelligence\OutcomeKnn;
use App\Domain\Intelligence\OutcomeKnnTuner;
use App\Domain\Intelligence\TrainingRequirements;
use App\Domain\Intelligence\TrainingRows;
use App\Domain\Intelligence\WeightedKnn;
use App\Domain\Research\SemanticLabels;

function outcomeValidationSettings(): array
{
    return [
        'min_train_size' => 4,
        'test_size' => 2,
        'gap' => 0,
        'k_cap' => 9,
        'min_validation_rows' => 1,
        'min_directional_predictions' => 1,
        'min_effective_neighbors' => 1.0,
        'min_confidence' => 0.5,
        'min_semantic_precision' => 0.55,
        'min_coverage' => 0.01,
        'max_contradiction_rate' => 0.05,
        'outcome' => [
            'min_macro_f1' => 0.20,
            'min_baseline_improvement' => 0.02,
            'min_supported_predictions' => 5,
            'min_coverage' => 0.01,
        ],
    ];
}

function outcomeTuningRows(int $count = 80): array
{
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = ['vector' => [($i % 5) / 4], 'label' => SemanticLabels::OUTCOMES[$i % 5],
            'decision_at_ms' => $i * 1000, 'label_available_at_ms' => ($i + 1) * 1000];
    }

    return $rows;
}

it('preserves Outcome selection when independent K voting exceeds one candidates allowance in total', function () {
    $settings = array_replace(outcomeValidationSettings(), ['min_train_size' => 10]);
    $knn = new WeightedKnn(1.0, 1.0, 0.5);
    $rows = outcomeTuningRows();
    $expected = (new OutcomeKnnTuner($knn, new OutcomeKnn($settings)))
        ->tunePrepared($rows, $settings, microtime(true) + 10);
    $time = 0.0;
    $clock = static function () use (&$time): float {
        return $time++;
    };

    $result = (new OutcomeKnnTuner($knn, new OutcomeKnn($settings), $clock))
        ->tunePrepared($rows, $settings, 10000.0, candidateSeconds: 250.0);

    expect($time)->toBeGreaterThan(250.0);
    expect($result)->toBe($expected);
});

it('rejects Outcome tuning when one K accumulates too much time across folds', function () {
    $settings = array_replace(outcomeValidationSettings(), ['min_train_size' => 10]);
    $knn = new WeightedKnn(1.0, 1.0, 0.5);
    $rows = outcomeTuningRows();
    $time = 0.0;
    $clock = static function () use (&$time): float {
        return $time++;
    };

    expect(fn () => (new OutcomeKnnTuner($knn, new OutcomeKnn($settings), $clock))
        ->tunePrepared($rows, $settings, 10000.0, candidateSeconds: 150.0))
        ->toThrow(RuntimeException::class, 'Outcome K tuning time budget exceeded.');
});

it('rejects an Outcome evaluation that overruns on its final row', function (string $mode) {
    $times = [0.0, 0.0, 2.0];
    $clock = static function () use (&$times): float {
        return array_shift($times) ?? 2.0;
    };
    $knn = new WeightedKnn(1.0, 1.0, 0.5);
    $rows = outcomeTuningRows(6);
    $settings = outcomeValidationSettings();
    $tuner = new OutcomeKnnTuner($knn, new OutcomeKnn($settings), $clock);
    $evaluate = fn (): array => $mode === 'walk_forward'
        ? $tuner->evaluateWalkForwardPrepared($rows, $settings, 4, 1.0)
        : $tuner->evaluatePrepared(array_slice($rows, 0, 4), [$rows[5]], 4, $settings, 1.0);

    expect($evaluate)->toThrow(RuntimeException::class, 'time budget exceeded');
})->with(['walk_forward', 'holdout']);

it('excludes Outcome abstentions from the confusion matrix and scores them only through coverage', function () {
    $settings = outcomeValidationSettings();
    $training = [
        ['vector' => [0.1], 'label' => 'super_bull', 'decision_at_ms' => 1, 'label_available_at_ms' => 2],
        ['vector' => [0.9], 'label' => 'neutral', 'decision_at_ms' => 2, 'label_available_at_ms' => 3],
        ['vector' => [0.9], 'label' => 'neutral', 'decision_at_ms' => 3, 'label_available_at_ms' => 4],
        ['vector' => [0.9], 'label' => 'neutral', 'decision_at_ms' => 4, 'label_available_at_ms' => 5],
    ];
    $test = [];
    for ($i = 0; $i < 5; $i++) {
        $test[] = ['vector' => [0.1], 'label' => 'super_bull',
            'decision_at_ms' => 10 + $i, 'label_available_at_ms' => 20 + $i];
    }
    $test[] = ['vector' => [0.5], 'label' => 'super_bear', 'decision_at_ms' => 20, 'label_available_at_ms' => 30];

    $knn = new WeightedKnn(0.1, 1.0, 0.5);
    $report = (new OutcomeKnnTuner($knn, new OutcomeKnn($settings)))
        ->evaluatePrepared($training, $test, 1, $settings, microtime(true) + 10);

    expect($report['evaluated'])->toBe(6)
        ->and($report['supported'])->toBe(5)
        ->and($report['abstained'])->toBe(1)
        ->and(array_sum(array_map('array_sum', $report['confusion'])))->toBe(5)
        ->and($report['confusion']['super_bear']['neutral'])->toBe(0)
        ->and($report['supported_accuracy'])->toEqual(1.0)
        ->and($report['supported_macro_f1'])->toBe(0.2)
        ->and($report['baseline']['macro_f1'])->toBe(0.0)
        ->and($report['baseline']['improvement'])->toBe(0.2)
        ->and($report['ordinal']['exact_accuracy'])->toBe(1.0)
        ->and($report['ordinal']['within_one_class_accuracy'])->toBe(1.0)
        ->and($report['ordinal']['mean_absolute_class_error'])->toEqual(0.0)
        ->and($report['ordinal']['same_direction_accuracy'])->toBe(1.0)
        ->and($report['ordinal']['opposite_direction_rate'])->toBe(0.0)
        ->and($report['ordinal']['extreme_opposite_rate'])->toBe(0.0)
        ->and($report['eligible'])->toBeTrue();
});

it('reports ordinal Outcome error severity without changing eligibility gates', function () {
    $settings = outcomeValidationSettings();
    $settings['outcome']['min_macro_f1'] = 0.0;
    $settings['outcome']['min_baseline_improvement'] = 0.0;
    $settings['outcome']['min_supported_predictions'] = 5;
    $training = [
        ['vector' => [0.0], 'label' => 'super_bear', 'decision_at_ms' => 1, 'label_available_at_ms' => 2],
        ['vector' => [0.2], 'label' => 'bear', 'decision_at_ms' => 2, 'label_available_at_ms' => 3],
        ['vector' => [0.4], 'label' => 'neutral', 'decision_at_ms' => 3, 'label_available_at_ms' => 4],
        ['vector' => [0.6], 'label' => 'bull', 'decision_at_ms' => 4, 'label_available_at_ms' => 5],
        ['vector' => [0.8], 'label' => 'super_bull', 'decision_at_ms' => 5, 'label_available_at_ms' => 6],
    ];
    $test = [
        ['vector' => [0.2], 'label' => 'super_bear', 'decision_at_ms' => 10, 'label_available_at_ms' => 20],
        ['vector' => [0.8], 'label' => 'bear', 'decision_at_ms' => 11, 'label_available_at_ms' => 21],
        ['vector' => [0.4], 'label' => 'neutral', 'decision_at_ms' => 12, 'label_available_at_ms' => 22],
        ['vector' => [0.0], 'label' => 'bull', 'decision_at_ms' => 13, 'label_available_at_ms' => 23],
        ['vector' => [0.0], 'label' => 'super_bull', 'decision_at_ms' => 14, 'label_available_at_ms' => 24],
    ];

    $knn = new WeightedKnn(1.0, 1.0, 0.5);
    $report = (new OutcomeKnnTuner($knn, new OutcomeKnn($settings)))
        ->evaluatePrepared($training, $test, 1, $settings, microtime(true) + 10);

    expect($report['ordinal']['class_order'])->toBe(['super_bear', 'bear', 'neutral', 'bull', 'super_bull'])
        ->and($report['ordinal']['supported_predictions'])->toBe(5)
        ->and($report['ordinal']['exact_accuracy'])->toBe(0.2)
        ->and($report['ordinal']['within_one_class_accuracy'])->toBe(0.4)
        ->and($report['ordinal']['mean_absolute_class_error'])->toBe(2.2)
        ->and($report['ordinal']['same_direction_accuracy'])->toBe(0.4)
        ->and($report['ordinal']['opposite_direction_rate'])->toBe(0.6)
        ->and($report['ordinal']['extreme_opposite_rate'])->toBe(0.2)
        ->and(array_column($report['ordinal']['distance'], 'count'))->toBe([1, 1, 0, 2, 1]);
});

it('requires Outcome to beat a chronological majority-class baseline', function () {
    $settings = outcomeValidationSettings();
    $training = [
        ['vector' => [0.1], 'label' => 'neutral', 'decision_at_ms' => 1, 'label_available_at_ms' => 2],
        ['vector' => [0.1], 'label' => 'neutral', 'decision_at_ms' => 2, 'label_available_at_ms' => 3],
        ['vector' => [0.1], 'label' => 'neutral', 'decision_at_ms' => 3, 'label_available_at_ms' => 4],
        ['vector' => [0.9], 'label' => 'super_bull', 'decision_at_ms' => 4, 'label_available_at_ms' => 5],
    ];
    $test = [];
    for ($i = 0; $i < 5; $i++) {
        $test[] = ['vector' => [0.1], 'label' => 'neutral',
            'decision_at_ms' => 10 + $i, 'label_available_at_ms' => 20 + $i];
    }

    $knn = new WeightedKnn(0.1, 1.0, 0.5);
    $report = (new OutcomeKnnTuner($knn, new OutcomeKnn($settings)))
        ->evaluatePrepared($training, $test, 1, $settings, microtime(true) + 10);

    expect($report['supported_macro_f1'])->toBe($report['baseline']['macro_f1'])
        ->and($report['baseline']['improvement'])->toBe(0.0)
        ->and($report['gates']['macro_f1'])->toBeTrue()
        ->and($report['gates']['baseline_improvement'])->toBeFalse()
        ->and($report['failed_gates'])->toContain('baseline_improvement')
        ->and($report['eligible'])->toBeFalse();
});

it('renders Outcome-specific readiness gates instead of Action semantic-precision gates', function () {
    $settings = outcomeValidationSettings();
    $metrics = [
        'evaluated' => 100,
        'supported' => 20,
        'supported_macro_f1' => 0.31,
        'coverage' => 0.20,
        'baseline' => ['improvement' => 0.08],
    ];

    $gates = TrainingRequirements::outcomeGates($metrics, $settings, $settings['outcome']);

    expect(array_column($gates, 'label'))->toBe([
        'Evaluated rows',
        'Supported Outcome predictions',
        'Supported Outcome Macro-F1',
        'Improvement over Outcome baseline',
        'Outcome coverage',
    ])->and(collect($gates)->every(fn (array $gate): bool => $gate['passed']))->toBeTrue();
});

it('preserves Outcome tuning and holdout reports with sliced and filtered training views', function () {
    $source = [];
    for ($id = 0; $id < 64; $id++) {
        $source[] = ['id' => $id, 'vector' => [($id % 5) / 4], 'feature_weights' => [1.0],
            'label' => SemanticLabels::OUTCOMES[$id % 5], 'decision_at_ms' => $id * 1000,
            'label_available_at_ms' => ($id + ($id % 4 === 0 ? 9 : 3)) * 1000];
    }
    $eligible = static fn (array $row): bool => $row['id'] % 7 !== 0;
    $expected = array_values(array_filter(array_slice($source, 3, 54), $eligible));
    $views = TrainingRows::from($source)->slice(3, 54)->filter($eligible);
    $settings = array_replace(outcomeValidationSettings(), ['min_train_size' => 10, 'test_size' => 7, 'gap' => 2]);
    $tuner = new OutcomeKnnTuner(new WeightedKnn(1.0, 1.0, 0.5), new OutcomeKnn($settings));

    expect($tuner->tunePrepared($views, $settings, microtime(true) + 10))
        ->toBe($tuner->tunePrepared($expected, $settings, microtime(true) + 10));
    expect($tuner->evaluateWalkForwardPrepared($views, $settings, 4, microtime(true) + 10))
        ->toBe($tuner->evaluateWalkForwardPrepared($expected, $settings, 4, microtime(true) + 10));
    expect($tuner->evaluatePrepared($views->slice(0, 20), $views->slice(30), 4, $settings, microtime(true) + 10))
        ->toBe($tuner->evaluatePrepared(array_slice($expected, 0, 20), array_slice($expected, 30), 4, $settings, microtime(true) + 10));
});
