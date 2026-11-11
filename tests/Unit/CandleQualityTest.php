<?php

use App\Domain\MarketData\CandleQuality;

it('selects the shortest period meeting the candle quality threshold', function () {
    $flat = array_fill(0, 50, ['open' => '1', 'high' => '1', 'low' => '1', 'close' => '1', 'volume' => '0']);
    $moving = array_map(fn ($n) => ['open' => (string) $n, 'high' => (string) ($n + 2), 'low' => (string) ($n - 1), 'close' => (string) ($n + 1), 'volume' => '1'], range(1, 50));
    $quality = new CandleQuality;
    $selected = $quality->choose(['1m' => $flat, '5m' => $moving, '15m' => $moving], 0.01, 0.7);

    expect($selected['period'])->toBe('5m')
        ->and($selected['quality']['score'])->toBeGreaterThanOrEqual(0.7)
        ->and($quality->evaluate($flat, 0.01)['true_flat_ratio'])->toBe(1.0);
});

it('allows a nonempty quality sample without an arbitrary default candle minimum', function () {
    $candle = ['open' => 1, 'high' => 2, 'low' => 1, 'close' => 2, 'volume' => 1];
    expect((new CandleQuality)->choose(['1m' => [$candle]], 0.01))->not->toBeNull();
});

it('rejects sparse candles despite strong price movement', function () {
    $candles = array_map(fn (int $n): array => [
        'microtimestamp' => $n * 300_000,
        'open' => (string) $n, 'high' => (string) ($n + 2),
        'low' => (string) ($n - 1), 'close' => (string) ($n + 1), 'volume' => '1',
    ], range(1, 50));

    expect((new CandleQuality)->choose(['1m' => $candles], 0.01, 0.7))->toBeNull();
});

it('does not count a moving open-equals-close candle as truly flat', function () {
    $candles = array_map(fn (int $n): array => [
        'open' => (string) $n, 'high' => (string) ($n + 2),
        'low' => (string) ($n - 2), 'close' => (string) $n, 'volume' => '5',
    ], range(10, 59));

    $metrics = (new CandleQuality)->evaluate($candles, 0.01);

    expect($metrics['true_flat_ratio'])->toBe(0.0)
        ->and($metrics['score'])->toBeGreaterThan(0.7);
});

it('rejects true flats independently of an otherwise passing quality score', function () {
    $candles = [];
    for ($index = 0; $index < 100; $index++) {
        $flat = $index < 12;
        $candles[] = $flat
            ? ['open' => '100', 'high' => '100', 'low' => '100', 'close' => '100', 'volume' => '1']
            : ['open' => '100', 'high' => '110', 'low' => '99', 'close' => '109', 'volume' => '1'];
    }
    $quality = new CandleQuality;
    $diagnostics = [];

    expect($quality->choose(['5m' => $candles], 0.01, 0.7, 50, 0.8, 1.0))->not->toBeNull()
        ->and($quality->choose(['5m' => $candles], 0.01, 0.7, 50, 0.8, 0.10, $diagnostics))->toBeNull()
        ->and($diagnostics['5m']['status'])->toBe('flat_failed')
        ->and($diagnostics['5m']['true_flat_ratio'])->toBe(0.12);
});

it('does not allow an undersized recent window to evade the flat rejection', function () {
    $candles = array_fill(0, 40, [
        'open' => '100', 'high' => '105', 'low' => '99', 'close' => '104', 'volume' => '1',
    ]);
    for ($i = 0; $i < 8; $i++) {
        $candles[$i] = ['open' => '100', 'high' => '100', 'low' => '100', 'close' => '100', 'volume' => '1'];
    }
    $diagnostics = [];
    $selected = (new CandleQuality)->choose(['4h' => $candles], 0.01, 0.7, 50, 0.8, 0.10, $diagnostics);

    expect($selected)->toBeNull()
        ->and($diagnostics['4h']['status'])->toBe('flat_failed')
        ->and($diagnostics['4h']['true_flat_ratio'])->toBe(0.2);
});
