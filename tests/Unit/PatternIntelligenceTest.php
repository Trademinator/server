<?php

use App\Domain\Intelligence\PatternCatalog;
use App\Domain\Intelligence\PatternTrainer;
use App\Domain\Intelligence\ProbabilityCalibration;
use App\Domain\Intelligence\SequentialRandomForest;
use App\Domain\Research\SemanticLabels;
use Tests\Support\LeadLagFixtures;

function patternBar(int $timestamp, float $open, float $close, float $high, float $low): array
{
    return ['microtimestamp' => $timestamp, 'open' => $open, 'close' => $close, 'high' => $high, 'low' => $low, 'volume' => 1];
}

it('distinguishes causal engulfing candidates from completed and failed future outcomes', function () {
    $bar = patternBar(0, 10, 8, 10, 8);
    $history = [['candle' => $bar, 'features' => ['candle.body' => 1.0, 'candle.direction' => -1, 'trend.direction' => -1]]];
    $catalog = new PatternCatalog;

    $candidate = $catalog->candidates($history, '1m')[0];
    expect($candidate['type'])->toBe('bullish_engulfing');
    expect($candidate)->not->toHaveKey('label');

    $future = [$bar, patternBar(60000, 7, 11, 11, 7), patternBar(120000, 11, 12, 12, 11)];
    $observation = $catalog->observations($history, $future, '1m')[0];
    expect($observation['label'])->toBe('completed');
    expect($observation['label_available_at_ms'])->toBe(120000);
    $future[1] = patternBar(60000, 8, 9, 9, 8);
    expect($catalog->observations($history, $future, '1m')[0]['label'])->toBe('failed');
});

it('detects the second star stage only across contiguous closed candles', function () {
    $history = [
        ['candle' => patternBar(0, 10, 8, 10, 8), 'features' => ['candle.body' => 1.0, 'candle.direction' => -1, 'trend.direction' => -1]],
        ['candle' => patternBar(60000, 8, 8.1, 9, 7), 'features' => ['candle.body' => 0.05, 'candle.direction' => 1, 'trend.direction' => -1]],
    ];
    $catalog = new PatternCatalog;

    expect($catalog->candidates($history, '1m')[0]['stage'])->toBe(2);
    $history[1]['candle']['microtimestamp'] = 120000;
    expect($catalog->candidates($history, '1m'))->toBe([]);
});

it('calibrates probabilities monotonically with tied scores and reports proper scoring rules', function () {
    $calibration = new ProbabilityCalibration;
    $mapping = $calibration->fit([0.1, 0.1, 0.5, 0.9], ['failed', 'completed', 'failed', 'completed']);

    expect($calibration->apply(0.1, $mapping))->toBe(1 / 3);
    expect($calibration->apply(0.9, $mapping))->toBe(1.0);
    $metrics = $calibration->metrics([0.25, 0.75], ['failed', 'completed']);
    expect($metrics['brier'])->toBe(0.0625);
    expect($metrics['calibration_error'])->toBe(0.25);
});

it('labels forward Outcome slope classes without trading costs', function () {
    $labels = new SemanticLabels(2, 2, 10);
    $bottom = [patternBar(0, 11, 10, 11, 10), patternBar(60000, 10, 8, 10, 8)];
    $future = [$bottom[1], patternBar(120000, 9, 9, 9, 9), patternBar(180000, 9, 10, 10, 9)];

    expect($labels->label($bottom, $future)['action'])->toBe('super_bull');
    expect($labels->metadata()['fee_bps'])->toBe(0);
    $future[2]['close'] = 7;
    expect($labels->label($bottom, $future)['action'])->toBe('bear');
});

it('compares real Rubix classifiers on purged chronological calibration and evaluation blocks', function () {
    mt_srand(42);
    $rows = [];
    for ($i = 0; $i < 180; $i++) {
        $complete = $i % 2 === 0;
        $rows[] = ['vector' => [$complete ? 0.0 : 1.0], 'decision_at_ms' => $i * 1000,
            'patterns' => [['type' => 'bullish_engulfing', 'length' => 2, 'stage' => 1,
                'progress' => 0.5, 'similarity' => 0.8, 'label' => $complete ? 'completed' : 'failed',
                'label_available_at_ms' => ($i + 2) * 1000]]];
    }
    $trainer = new PatternTrainer(new PatternCatalog, new ProbabilityCalibration);
    $bundle = $trainer->train($rows, ['min_samples' => 50, 'min_block_rows' => 10, 'trees' => 5, 'k' => 5], microtime(true) + 20);

    $report = $bundle['report']['bullish_engulfing'];
    expect($report['status'])->toBe('validated');
    expect($report['candidates'])->toHaveKeys(['random_forest', 'weighted_knn']);
    expect($report['train_labels_available_by_ms'])->toBeLessThan($report['calibration_from_ms']);
    expect($report['calibration_labels_available_by_ms'])->toBeLessThan($report['test_from_ms']);
    $candidate = $rows[0]['patterns'][0];
    expect($trainer->predict($bundle, [0.0], [$candidate], 100)[0]['completion_probability'])->toBeNull();
    expect($trainer->predict($bundle, [0.0], [$candidate], 999999)[0]['completion_probability'])->toBeGreaterThan(0.9);
});

it('uses the memory-bounded forest for pattern candidates', function () {
    mt_srand(42);
    $rows = [];
    for ($i = 0; $i < 180; $i++) {
        $complete = $i % 2 === 0;
        $rows[] = ['vector' => [$complete ? 0.0 : 1.0], 'decision_at_ms' => $i * 1000,
            'patterns' => [['type' => 'bullish_engulfing', 'length' => 2, 'stage' => 1,
                'progress' => 0.5, 'similarity' => 0.8, 'label' => $complete ? 'completed' : 'failed',
                'label_available_at_ms' => ($i + 2) * 1000]]];
    }

    $bundle = (new PatternTrainer(new PatternCatalog, new ProbabilityCalibration))
        ->train($rows, ['min_samples' => 50, 'min_block_rows' => 10, 'trees' => 5, 'k' => 5], microtime(true) + 20);

    if (($bundle['report']['bullish_engulfing']['selected'] ?? null) === 'random_forest') {
        expect($bundle['models']['bullish_engulfing']['estimator'])->toBeInstanceOf(SequentialRandomForest::class);
    } else {
        expect($bundle['report']['bullish_engulfing']['candidates'])->toHaveKey('random_forest');
    }
});

it('trains and predicts three-candle patterns with integer endpoints decoded from JSON', function (string $type) {
    mt_srand(42);
    $rows = [];
    for ($i = 0; $i < 180; $i++) {
        $complete = $i % 2 === 0;
        $rows[] = ['vector' => [$complete ? 0 : 1], 'decision_at_ms' => $i * 1000,
            'patterns' => [['type' => $type, 'length' => 3, 'stage' => 2,
                'progress' => 2 / 3, 'similarity' => 1, 'label' => $complete ? 'completed' : 'failed',
                'label_available_at_ms' => ($i + 2) * 1000]]];
    }
    $trainer = new PatternTrainer(new PatternCatalog, new ProbabilityCalibration);

    $bundle = $trainer->train($rows, ['min_samples' => 50, 'min_block_rows' => 10, 'trees' => 5, 'k' => 5], microtime(true) + 20);
    $restored = unserialize(serialize($bundle));

    expect($restored['report'][$type]['status'])->toBe('validated');
    expect($trainer->predict($restored, [0], [$rows[0]['patterns'][0]], 999999)[0]['completion_probability'])
        ->toBeGreaterThan(0.9);
})->with(['morning_star', 'evening_star']);

it('chooses the pattern algorithm before inspecting final evaluation labels', function () {
    $rows = [];
    for ($i = 0; $i < 400; $i++) {
        $x = LeadLagFixtures::noise($i);
        $y = LeadLagFixtures::noise($i, 'pattern');
        $rows[] = ['vector' => [($x + 0.01) * 50, ($y + 0.01) * 50], 'decision_at_ms' => $i * 1000,
            'patterns' => [['type' => 'bullish_engulfing', 'length' => 2, 'stage' => 1,
                'progress' => 0.5, 'similarity' => 0.8, 'label' => $x * $y > 0 ? 'completed' : 'failed',
                'label_available_at_ms' => ($i + 2) * 1000]]];
    }
    $trainer = new PatternTrainer(new PatternCatalog, new ProbabilityCalibration);
    $settings = ['min_samples' => 50, 'min_block_rows' => 10, 'trees' => 1, 'k' => 15];
    mt_srand(42);
    $before = $trainer->train($rows, $settings, microtime(true) + 20)['report']['bullish_engulfing'];
    foreach ($rows as $i => &$row) {
        if ($i >= 320) {
            $row['patterns'][0]['label'] = $row['patterns'][0]['label'] === 'completed' ? 'failed' : 'completed';
        }
    }
    unset($row);
    mt_srand(42);

    $after = $trainer->train($rows, $settings, microtime(true) + 20)['report']['bullish_engulfing'];

    expect($after['candidate_selected_before_test'])->toBe($before['candidate_selected_before_test']);
    expect($before['calibration_labels_available_by_ms'])->toBeLessThan($before['selection_from_ms']);
    expect($before['selection_labels_available_by_ms'])->toBeLessThan($before['test_from_ms']);
    foreach (['random_forest', 'weighted_knn'] as $algorithm) {
        expect($after['candidates'][$algorithm]['selection_metrics'])->toBe($before['candidates'][$algorithm]['selection_metrics']);
        expect($after['candidates'][$algorithm]['metrics'])->not->toBe($before['candidates'][$algorithm]['metrics']);
    }
});
