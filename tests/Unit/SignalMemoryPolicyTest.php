<?php

use App\Domain\Intelligence\SignalMemoryPolicy;

it('waits for small first moves and accumulates net directional displacement', function (string $side) {
    $policy = new SignalMemoryPolicy;
    $prices = $side === 'buy'
        ? ['2.050', '2.045', '2.040', '2.030', '2.025', '2.000', '1.995']
        : ['2.050', '2.055', '2.060', '2.070', '2.075', '2.100', '2.105'];
    $previous = $side === 'buy' ? '2.055' : '2.045';
    $anchor = null;
    $emitted = [];
    foreach ($prices as $index => $price) {
        $result = $policy->decide($side, $index + 1, $price, $previous, '0.020', '0.020', $anchor);
        $emitted[] = $result['emit'];
        $anchor = $result['anchor'];
        $previous = $price;
        if ($index === 3) {
            expect(bccomp($result['accumulated'], '0.020', 24))->toBe(0)
                ->and($result['emit'])->toBeFalse();
        }
        if ($index === 4) {
            expect($result['path'])->toBe('accumulated_displacement')
                ->and($anchor['t0_price'])->toBe($price);
        }
    }
    expect($emitted)->toBe([false, false, false, false, true, true, false]);
})->with(['buy', 'sell']);

it('uses previous ATR for the immediate test and saves current ATR at the new reference', function () {
    $result = (new SignalMemoryPolicy)->decide('buy', 1, '1.950', '2.000', '0.060', '0.040', null);
    expect($result['emit'])->toBeTrue()->and($result['path'])->toBe('immediate_displacement')
        ->and($result['threshold'])->toBe('0.040')->and($result['anchor']['atr_threshold'])->toBe('0.060');
});

it('does not move t0 or lower its saved threshold while waiting', function () {
    $policy = new SignalMemoryPolicy;
    $first = $policy->decide('buy', 1, '2.000', '2.005', '0.020', '0.020', null);
    $next = $policy->decide('buy', 2, '1.985', '1.985', '0.005', '0.005', $first['anchor']);
    expect($next['emit'])->toBeFalse()->and($next['anchor']['t0_ms'])->toBe(1)
        ->and($next['anchor']['tn_ms'])->toBe(2)->and($next['threshold'])->toBe('0.020');
});

it('does not add absolute price travel or treat a worse price as improvement', function () {
    $policy = new SignalMemoryPolicy;
    $anchor = $policy->decide('buy', 1, '2.000', '2.005', '0.040', '0.040', null)['anchor'];
    $previous = '2.000';
    foreach (['2.015', '2.000', '2.015', '2.000', '2.015'] as $index => $price) {
        $result = $policy->decide('buy', $index + 2, $price, $previous, '0.040', '0.040', $anchor);
        expect($result['emit'])->toBeFalse();
        $anchor = $result['anchor'];
        $previous = $price;
    }
    expect(SignalMemoryPolicy::greater($result['accumulated'], '0'))->toBeFalse();
});

it('starts a new anchor for an opposite-direction proposal', function () {
    $policy = new SignalMemoryPolicy;
    $anchor = $policy->decide('buy', 1, '2.000', '2.005', '0.020', '0.020', null)['anchor'];
    $result = $policy->decide('sell', 2, '1.900', '1.905', '0.020', '0.020', $anchor);
    expect($result['emit'])->toBeFalse()->and($result['path'])->toBe('first_candidate')
        ->and($result['anchor']['direction'])->toBe('sell')->and($result['anchor']['t0_ms'])->toBe(2);
});

it('compares very small prices without a binary floating-point equality error', function () {
    $policy = new SignalMemoryPolicy;
    $equal = $policy->decide('buy', 1, '0.000000010000000000000000',
        '0.000000010000000000000002', '0.000000000000000000000002', '0.000000000000000000000002', null);
    expect($equal['emit'])->toBeFalse();
    $larger = $policy->decide('buy', 2, '0.000000009999999999999999',
        '0.000000010000000000000002', '0.000000000000000000000002', '0.000000000000000000000002', null);
    expect($larger['emit'])->toBeTrue();
});

it('keeps the reviewed fast path independent of the previous emitted price', function () {
    $policy = new SignalMemoryPolicy;
    $anchor = $policy->decide('buy', 1, '2.000', '2.100', '0.020', '0.020', null)['anchor'];
    // The extra "better than last emission" restriction was not in the approved diagram.
    $result = $policy->decide('buy', 2, '2.050', '2.100', '0.020', '0.020', $anchor);
    expect($result['emit'])->toBeTrue()->and($result['path'])->toBe('immediate_displacement');
});

it('rejects zero and negative ATR instead of interpreting missing volatility as permission', function (string $atr) {
    (new SignalMemoryPolicy)->decide('buy', 1, '2.000', '2.005', $atr, '0.020', null);
})->with(['0', '-0.01'])->throws(InvalidArgumentException::class);
