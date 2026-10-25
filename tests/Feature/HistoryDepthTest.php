<?php

use App\Domain\MarketData\HistoryDepth;

it('uses the larger of the complete validation requirement and configured minimum calendar depth', function () {
    $depth = app(HistoryDepth::class);
    $until = strtotime('2026-09-30 00:00:00 UTC') * 1000;
    config(['history_backfill.minimum_days' => 7, 'history_backfill.depth_probe_candles' => 12,
        'intelligence.knn.min_train_size' => 250, 'intelligence.knn.test_size' => 100,
        'intelligence.lookback' => 20, 'intelligence.horizon' => 12]);

    expect($depth->requiredStartMs('5m', $until))->toBe($until - 7 * 86_400_000);
    expect($depth->requiredStartMs('1h', $until))->toBe($until - 500 * 3_600_000);

    $probe = $depth->depthProbe('5m', $until);
    expect($probe['from'])->toBe($until - 7 * 86_400_000)
        ->and($probe['until'] - $probe['from'])->toBe(12 * 5 * 60_000)
        ->and($probe['limit'])->toBe(12);
});

it('recognizes whether stored candle span reaches the required training depth', function () {
    $depth = app(HistoryDepth::class);
    $latest = strtotime('2026-09-30 00:00:00 UTC') * 1000;
    config(['history_backfill.minimum_days' => 7,
        'intelligence.knn.min_train_size' => 250, 'intelligence.knn.test_size' => 100,
        'intelligence.lookback' => 20, 'intelligence.horizon' => 12]);

    expect($depth->spanIsSufficient('5m', $latest - 8 * 86_400_000, $latest))->toBeTrue()
        ->and($depth->spanIsSufficient('5m', $latest - 3 * 86_400_000, $latest))->toBeFalse();
});

it('requests enough four-hour candles for validation without waiting for new candles or limiting training history', function () {
    config(['history_backfill.minimum_days' => 7,
        'intelligence.max_model_age_days' => 14,
        'intelligence.knn.min_train_size' => 250, 'intelligence.knn.test_size' => 100,
        'intelligence.knn.min_validation_rows' => 50, 'intelligence.knn.gap' => 0,
        'intelligence.lookback' => 20, 'intelligence.horizon' => 12]);
    $until = strtotime('2026-09-30 00:00:00 UTC') * 1000;

    expect(app(HistoryDepth::class)->requiredStartMs('4h', $until))
        ->toBe($until - 500 * 14_400_000);
});
