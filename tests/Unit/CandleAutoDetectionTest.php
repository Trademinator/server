<?php

use App\Traits\CandleAutoDetection;

function candleAutoDetector(): object
{
    return new class
    {
        use CandleAutoDetection;

        public function reserveAutoHolds(array &$tickers): array
        {
            return $this->candle_auto_mark_hold_candidates($tickers);
        }
    };
}

function autoCandle(string $open, string $close, string $high = '12', string $low = '8'): array
{
    return ['open' => $open, 'high' => $high, 'low' => $low, 'close' => $close, 'volume' => '1'];
}

it('preserves the broad-label then prune ordering and keeps only the last consecutive action', function () {
    $tickers = [
        autoCandle('10', '9'), autoCandle('10', '9'), autoCandle('10', '9'), autoCandle('10', '9'),
        autoCandle('9', '10'), autoCandle('9', '10'), autoCandle('9', '10'),
    ];
    $detector = candleAutoDetector();
    $detector->candle_anatomy($tickers);
    $detector->mark_all_blacks_and_whites($tickers);
    expect(array_column($tickers, 'action'))->not->toBeEmpty();
    $detector->remove_consequitive_actions($tickers);

    $buys = array_keys(array_filter($tickers, fn (array $ticker): bool => ($ticker['action'] ?? null) === 'buy'));
    expect($buys)->toBe([3]);
});

it('uses only the transaction-cost floor and retains the previous BUY when SELL fails', function () {
    $tickers = [
        [...autoCandle('101', '100'), 'action' => 'buy'],
        [...autoCandle('100', '100.05'), 'action' => 'sell'],
        autoCandle('100', '100'), autoCandle('100', '100'),
    ];

    candleAutoDetector()->remove_unprofitable_transactions($tickers, '0.001');

    expect($tickers[0]['action'])->toBe('buy')
        ->and($tickers[1])->not->toHaveKey('action');
});

it('requires movement strictly greater than twice the one-side taker fee', function () {
    $atFloor = [
        [...autoCandle('101', '100'), 'action' => 'buy'],
        [...autoCandle('100', '100.2'), 'action' => 'sell'],
    ];
    candleAutoDetector()->remove_unprofitable_transactions($atFloor, '0.001');
    expect($atFloor[0]['action'])->toBe('buy')
        ->and($atFloor[1])->not->toHaveKey('action');

    $aboveFloor = [
        [...autoCandle('101', '100'), 'action' => 'buy'],
        [...autoCandle('100', '100.21'), 'action' => 'sell'],
    ];
    candleAutoDetector()->remove_unprofitable_transactions($aboveFloor, '0.001');
    expect($aboveFloor[0]['action'])->toBe('buy')
        ->and($aboveFloor[1]['action'])->toBe('sell');
});

it('keeps SELL BUY zigzags whose close movement exceeds twice the taker fee', function () {
    $tickers = [
        [...autoCandle('99', '100', '105', '95'), 'action' => 'sell'],
        [...autoCandle('100', '99.5', '105', '95'), 'action' => 'buy'],
    ];

    candleAutoDetector()->remove_zigzags($tickers, '0.002');

    expect($tickers[0]['action'])->toBe('sell')
        ->and($tickers[1]['action'])->toBe('buy');
});

it('removes the BUY candidate at exactly twice the taker fee and keeps the SELL pivot', function () {
    $tickers = [
        [...autoCandle('99', '100'), 'action' => 'sell'],
        [...autoCandle('100', '99.6'), 'action' => 'buy'],
    ];

    candleAutoDetector()->remove_zigzags($tickers, '0.002');

    expect($tickers[0]['action'])->toBe('sell')
        ->and($tickers[1])->not->toHaveKey('action');
});

it('keeps the previous BUY as pivot when an intermediate SELL fails the fee floor', function () {
    $tickers = [
        [...autoCandle('101', '100'), 'action' => 'buy'],
        [...autoCandle('99', '100.2'), 'action' => 'sell'],
        [...autoCandle('101', '100.1'), 'action' => 'buy'],
        [...autoCandle('100', '101'), 'action' => 'sell'],
    ];

    candleAutoDetector()->remove_unprofitable_transactions($tickers, '0.002');

    expect($tickers[0]['action'])->toBe('buy')
        ->and($tickers[1])->not->toHaveKey('action')
        ->and($tickers[2])->not->toHaveKey('action')
        ->and($tickers[3]['action'])->toBe('sell');
});

it('keeps the lower BUY when a rejected SELL exposes consecutive BUY candidates', function () {
    $tickers = [
        [...autoCandle('101', '100'), 'action' => 'buy'],
        [...autoCandle('99', '100.2'), 'action' => 'sell'],
        [...autoCandle('100', '99.5'), 'action' => 'buy'],
        [...autoCandle('99', '100.2'), 'action' => 'sell'],
    ];

    candleAutoDetector()->remove_unprofitable_transactions($tickers, '0.002');

    expect($tickers[0])->not->toHaveKey('action')
        ->and($tickers[1])->not->toHaveKey('action')
        ->and($tickers[2]['action'])->toBe('buy')
        ->and($tickers[3]['action'])->toBe('sell');
});

it('ignores HOLD labels when selecting surviving BUY SELL pivots', function () {
    $tickers = [
        [...autoCandle('101', '100'), 'action' => 'buy'],
        [...autoCandle('100', '100'), 'action' => 'hold'],
        [...autoCandle('100', '99.5'), 'action' => 'buy'],
        [...autoCandle('100', '100'), 'action' => 'hold'],
        [...autoCandle('99', '100.2'), 'action' => 'sell'],
    ];

    candleAutoDetector()->remove_unprofitable_transactions($tickers, '0.002');

    expect($tickers[0])->not->toHaveKey('action')
        ->and($tickers[1]['action'])->toBe('hold')
        ->and($tickers[2]['action'])->toBe('buy')
        ->and($tickers[3]['action'])->toBe('hold')
        ->and($tickers[4]['action'])->toBe('sell');
});

it('keeps the highest SELL across HOLD labels when SELL candidates are consecutive', function () {
    $tickers = [
        [...autoCandle('99', '100'), 'action' => 'sell'],
        [...autoCandle('100', '100'), 'action' => 'hold'],
        [...autoCandle('100', '101'), 'action' => 'sell'],
    ];

    candleAutoDetector()->remove_consequitive_actions($tickers);

    expect($tickers[0])->not->toHaveKey('action')
        ->and($tickers[1]['action'])->toBe('hold')
        ->and($tickers[2]['action'])->toBe('sell');
});

it('reserves future HOLDs before BUY selection so a lower later BUY survives', function () {
    $tickers = [
        autoCandle('9', '10'),
        autoCandle('9', '10'),
        autoCandle('101', '100'),
        autoCandle('100.5', '100.2'),
        autoCandle('100.4', '100.1'),
        autoCandle('100', '99'),
        autoCandle('9', '10'),
        autoCandle('9', '10'),
    ];

    $detector = candleAutoDetector();
    $detector->candle_anatomy($tickers);
    $detector->reserveAutoHolds($tickers);
    $detector->mark_all_blacks_and_whites($tickers);
    $detector->remove_consequitive_actions($tickers);

    expect($tickers[2])->not->toHaveKey('action')
        ->and($tickers[3])->not->toHaveKey('action')
        ->and($tickers[5]['action'])->toBe('buy');

    $detector->hodl_middle_chains($tickers);
    expect($tickers[3]['action'])->toBe('hold');
});

it('does not let find_new_bottoms choose a candle reserved for HOLD', function () {
    $tickers = [
        [...autoCandle('9', '10'), 'action' => 'sell'],
        autoCandle('101', '99'),
        autoCandle('101', '98'),
        autoCandle('101', '97'),
        autoCandle('101', '96'),
        [...autoCandle('9', '11'), 'action' => 'sell'],
    ];

    $detector = candleAutoDetector();
    $detector->candle_anatomy($tickers);
    $detector->reserveAutoHolds($tickers);
    $detector->find_new_bottoms($tickers);

    expect($tickers[2])->not->toHaveKey('action')
        ->and($tickers[4]['action'])->toBe('buy');
});

it('labels only completely flat dojis as HOLD in the doji cleanup pass', function () {
    $tickers = [
        autoCandle('10', '10', '10', '10'), // Left context only, even when completely flat.
        autoCandle('10', '10', '11', '9'),
        autoCandle('10', '10', '10', '10'),
        autoCandle('10', '10', '10', '10'), // Right context only.
    ];
    $detector = candleAutoDetector();
    $detector->candle_anatomy($tickers);
    $detector->hodl_all_dojis($tickers);

    expect($tickers[0])->not->toHaveKey('action')
        ->and($tickers[1])->not->toHaveKey('action')
        ->and($tickers[2]['action'])->toBe('hold')
        ->and($tickers[3])->not->toHaveKey('action');
});

it('removes endpoint actions and reservations without removing their candle context', function (int $count) {
    $tickers = array_fill(0, $count, [
        ...autoCandle('10', '10', '10', '10'),
        'action' => 'hold', '_auto_hold_doji' => true, '_auto_hold_middle' => true,
    ]);
    $original = $tickers;
    $detector = candleAutoDetector();
    $detector->unlabel_endpoints($tickers);

    expect($tickers)->toHaveCount($count);
    foreach ($tickers as $index => $ticker) {
        if ($index === 0 || $index === $count - 1) {
            expect($ticker)->toBe(autoCandle('10', '10', '10', '10'));
        } else {
            expect($ticker)->toBe($original[$index]);
        }
    }
    $once = $tickers;
    $detector->unlabel_endpoints($tickers);
    expect($tickers)->toBe($once);
})->with([0, 1, 2, 3, 8]);

it('clears stale endpoint actions before they can influence surviving trade pivots', function (string $action) {
    $tickers = [
        [...autoCandle('100', '99'), 'action' => $action],
        autoCandle('101', '100'),
        autoCandle('100', '102'),
        autoCandle('102', '103'),
        [...autoCandle('102', '103'), 'action' => $action],
    ];
    $detector = candleAutoDetector();
    $detector->candle_anatomy($tickers);
    $detector->mark_all_blacks_and_whites($tickers);
    $detector->remove_consequitive_actions($tickers);
    $detector->remove_unprofitable_transactions($tickers, '0.005');

    expect($tickers[0])->not->toHaveKey('action')
        ->and($tickers[1]['action'])->toBe('buy')
        ->and($tickers[2]['action'])->toBe('sell')
        ->and($tickers[4])->not->toHaveKey('action');
})->with(['buy', 'sell', 'hold']);

it('does not let reserved HOLD passes restore endpoint labels', function () {
    $tickers = array_fill(0, 6, [
        ...autoCandle('10', '10', '10', '10'),
        'action' => 'buy', '_auto_hold_doji' => true, '_auto_hold_middle' => true,
    ]);
    $detector = candleAutoDetector();
    $detector->hodl_all_dojis($tickers);
    expect($tickers[0])->not->toHaveKey('action')
        ->and($tickers[5])->not->toHaveKey('action');
    $detector->hodl_middle_chains($tickers);
    expect($tickers[0])->not->toHaveKey('action')
        ->and($tickers[5])->not->toHaveKey('action')
        ->and(array_column(array_slice($tickers, 1, 4), 'action'))->toBe(array_fill(0, 4, 'hold'));
});

it('counts only opposite candidates tested by the first fee pass without changing labels', function () {
    $tickers = [
        ['close' => '100', 'action' => 'buy'],
        ['close' => '100.2', 'action' => 'sell'],
        ['close' => '100', 'action' => 'hold'],
        ['close' => '99', 'action' => 'buy'],
        ['close' => '102', 'action' => 'sell'],
        ['close' => '100', 'action' => 'buy'],
    ];
    $ordinary = $tickers;
    $counts = ['tested' => 0, 'rejected' => 0];
    $detector = candleAutoDetector();
    $detector->remove_unprofitable_transactions($ordinary, '0.001');
    $detector->remove_unprofitable_transactions($tickers, '0.001', null, $counts);
    $detector->remove_zigzags($tickers, '0.001');

    expect($counts)->toBe(['tested' => 3, 'rejected' => 1]);
    expect($tickers)->toBe($ordinary);
});
