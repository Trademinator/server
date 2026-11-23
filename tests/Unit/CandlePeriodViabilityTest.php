<?php

use App\Domain\Intelligence\ActionAutoLabeler;
use App\Domain\MarketData\CandlePeriodViability;

function viabilityCandle(string $open, string $close, ?string $high = null, ?string $low = null): array
{
    $high ??= (string) max((float) $open, (float) $close);
    $low ??= (string) min((float) $open, (float) $close);

    return ['open' => $open, 'high' => $high, 'low' => $low, 'close' => $close, 'volume' => '1'];
}

it('requires both BUY and SELL to reach the configured share of finalized auto labels', function () {
    $candles = [
        viabilityCandle('100', '100'),
        viabilityCandle('101', '100', '102', '99'),
        viabilityCandle('100', '102', '103', '99'),
        viabilityCandle('102', '102'),
        viabilityCandle('102', '102'),
        viabilityCandle('102', '102'),
    ];

    $result = app(CandlePeriodViability::class)->evaluate($candles, 0.005, 0.01);

    expect($result['passes'])->toBeTrue()
        ->and($result['buy'])->toBe(1)
        ->and($result['sell'])->toBe(1)
        ->and($result['hold'])->toBe(2)
        ->and($result['total'])->toBe(4)
        ->and($result['buy_ratio'])->toBeGreaterThanOrEqual(0.01)
        ->and($result['sell_ratio'])->toBeGreaterThanOrEqual(0.01);
});

it('rejects a period when transaction costs leave either BUY or SELL below the viability floor', function () {
    $candles = [
        viabilityCandle('100', '100'),
        viabilityCandle('101', '100', '102', '99'),
        viabilityCandle('100', '102', '103', '99'),
        viabilityCandle('102', '102'),
        viabilityCandle('102', '102'),
        viabilityCandle('102', '102'),
    ];

    $result = app(CandlePeriodViability::class)->evaluate($candles, 0.02, 0.01);

    expect($result['passes'])->toBeFalse()
        ->and(min($result['buy_ratio'], $result['sell_ratio']))->toBeLessThan(0.01);
});

it('excludes context-only endpoints from finalized viability counts', function (int $count, int $expectedTotal) {
    $candles = array_fill(0, $count, [
        ...viabilityCandle('100', '100'), 'action' => 'hold',
    ]);
    $result = app(CandlePeriodViability::class)->evaluate($candles, 0.005);

    expect($result['hold'])->toBe($expectedTotal)
        ->and($result['total'])->toBe($expectedTotal)
        ->and($result['buy'])->toBe(0)
        ->and($result['sell'])->toBe(0)
        ->and($result['passes'])->toBeFalse()
        ->and($candles)->toHaveCount($count);
    if ($candles !== []) {
        expect($candles[0])->not->toHaveKey('action')
            ->and($candles[$count - 1])->not->toHaveKey('action');
    }
})->with([[0, 0], [1, 0], [2, 0], [3, 1], [6, 4]]);

it('does not tag either endpoint of a rising or falling sequence', function (bool $rising) {
    $candles = [];
    for ($index = 0; $index < 8; $index++) {
        $open = $rising ? 100 + $index : 100 - $index;
        $close = $rising ? $open + 1 : $open - 1;
        $candles[] = viabilityCandle((string) $open, (string) $close);
    }
    app(CandlePeriodViability::class)->evaluate($candles, 0.005);
    expect($candles[0])->not->toHaveKey('action')
        ->and($candles[7])->not->toHaveKey('action')
        ->and(array_column($candles, 'action'))->not->toBeEmpty();
})->with(['rising' => [true], 'falling' => [false]]);

it('measures fee rejections without a fixed ten percent veto', function (int $count, int $tested) {
    $candles = [];
    for ($i = 0; $i < $count; $i++) {
        $candles[] = $i % 2 === 1
            ? viabilityCandle('101', '100', '113', '99')
            : viabilityCandle('111', '112', '113', '99');
    }
    $candles[2] = viabilityCandle('99.1', '100.1', '113', '99');
    $result = app(CandlePeriodViability::class)->evaluate($candles, 0.001, 0.02);

    expect($result['reversals_tested'])->toBe($tested)
        ->and($result['reversals_rejected'])->toBe(1)
        ->and($result['rejected_reversal_ratio'])->toBe(1 / $tested)
        ->and($result['passes'])->toBeTrue();
})->with([[15, 10], [14, 9]]);

it('does not treat missing reversal evidence as a perfect zero rejection ratio', function () {
    $candles = array_fill(0, 5, viabilityCandle('100', '100'));
    $result = app(CandlePeriodViability::class)->evaluate($candles, 0.001, 0);

    expect($result['reversals_tested'])->toBe(0)
        ->and($result['rejected_reversal_ratio'])->toBeNull()
        ->and($result['passes'])->toBeFalse();
});

it('measures finalized directional swing quantiles and fee surplus with the training labeler', function (float $fee) {
    $candles = [
        viabilityCandle('100', '100'),
        viabilityCandle('101', '100', '104', '98'),
        viabilityCandle('101', '102', '104', '98'),
        viabilityCandle('100', '99', '104', '98'),
        viabilityCandle('102', '103', '104', '98'),
        viabilityCandle('103', '103'),
        viabilityCandle('103', '103'),
        viabilityCandle('103', '103'),
    ];
    $labeler = app(ActionAutoLabeler::class);
    $expected = $labeler->labels(array_map(fn (array $candle): array => $labeler->compact($candle), $candles), $fee);
    $result = app(CandlePeriodViability::class)->evaluate($candles, $fee);

    expect($candles)->toBe($expected);
    expect($result['swings']['count'])->toBe(3);
    expect($result['swings']['p25'])->toEqualWithDelta(0.024705882352941, 1e-12);
    expect($result['swings']['p50'])->toEqualWithDelta(0.029411764705882, 1e-12);
    expect($result['swings']['mean'])->toEqualWithDelta(0.029938601703308, 1e-12);
    if ($fee === 0.0) {
        expect($result['swings']['fee_margin_p25'])->toBeNull();
        expect($result['swings']['fee_margin_p50'])->toBeNull();
    } else {
        expect($result['swings']['round_trip_fee'])->toBe(0.01);
        expect($result['swings']['fee_margin_p25'])->toEqualWithDelta(1.4705882352941, 1e-10);
        expect($result['swings']['fee_margin_p50'])->toEqualWithDelta(1.9411764705882, 1e-10);
    }
})->with([0.005, 0.0]);

it('does not create an action swing or count context-only labels across a candle gap', function () {
    $candles = [];
    foreach ([0, 3_600_000] as $start) {
        foreach ([['100', '100'], ['101', '100'], ['101', '102'], ['102', '102'], ['102', '102'], ['102', '102']] as $i => [$open, $close]) {
            $candles[] = [...viabilityCandle($open, $close, '103', '99'), 'microtimestamp' => $start + $i * 60_000];
        }
    }
    $result = app(CandlePeriodViability::class)->evaluate($candles, 0.005, 0.02, '1m');

    expect($result['swings']['count'])->toBe(2);
    expect($result['swings']['mean'])->toEqualWithDelta(0.02, 1e-12);
    expect($result['buy'])->toBe(2);
    expect($result['sell'])->toBe(2);
    foreach ([0, 5, 6, 11] as $index) {
        expect($candles[$index])->not->toHaveKey('action');
    }
});
