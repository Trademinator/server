<?php

use App\Domain\MarketData\CandlePeriodRuns;

function runCandles(array $directions): array
{
    return array_map(static fn (int $direction, int $index): array => [
        'microtimestamp' => $index * 60_000, 'open' => '100', 'close' => (string) (100 + $direction),
    ], $directions, array_keys($directions));
}

it('measures completed runs once each and excludes both censored window edges', function () {
    $candles = runCandles([1, 1, -1, -1, -1, -1, -1, 1, 1, 1, -1, -1, -1, -1, 1, 1]);
    $original = $candles;
    $result = (new CandlePeriodRuns)->evaluate($candles, '1m');

    expect($result)->toBe(['count' => 3, 'p50' => 4.0, 'p95' => 4.9]);
    expect($candles)->toBe($original);
});

it('keeps alternating one-candle runs eligible for dynamic ranking', function () {
    expect((new CandlePeriodRuns)->evaluate(runCandles([1, -1, 1, -1, 1, -1]), '1m'))
        ->toBe(['count' => 4, 'p50' => 1.0, 'p95' => 1.0]);
});

it('does not bridge directional runs across neutral candles', function () {
    expect((new CandlePeriodRuns)->evaluate(runCandles([0, 1, 1, 0, 1, 1, 0, -1, -1, 0]), '1m'))
        ->toBe(['count' => 3, 'p50' => 2.0, 'p95' => 2.0]);
});

it('does not invent complete runs across missing candle data', function () {
    $candles = runCandles([0, 1, 1, 1, 1, 0]);
    foreach ([3, 4, 5] as $index) {
        $candles[$index]['microtimestamp'] += 60_000;
    }

    expect((new CandlePeriodRuns)->evaluate($candles, '1m'))
        ->toBe(['count' => 0, 'p50' => null, 'p95' => null]);
});

it('preserves run lengths across bounded calculation batches', function () {
    $candles = runCandles([0, ...array_fill(0, 1200, 1), ...array_fill(0, 5, -1), 0]);
    expect((new CandlePeriodRuns)->evaluate($candles, '1m'))
        ->toBe(['count' => 2, 'p50' => 602.5, 'p95' => 1140.25]);
});

it('reports unavailable percentiles when no complete run was observed', function () {
    expect((new CandlePeriodRuns)->evaluate(runCandles([1, 1, 1, -1, -1]), '1m'))
        ->toBe(['count' => 0, 'p50' => null, 'p95' => null]);
});
