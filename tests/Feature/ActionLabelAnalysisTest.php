<?php

use App\Domain\Intelligence\ActionAutoLabeler;
use App\Domain\Intelligence\ActionLabelAnalysis;
use App\Domain\Intelligence\PublishedTakerFee;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\MarketData\MarketCatalog;
use App\Domain\Operations\ActionContext;
use App\Domain\Operations\ActionLog;
use App\Repositories\TickerRepository;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

function alternatingHistory(int $count, ?int $skip = null): array
{
    $rows = [];
    $start = 1_700_000_000_000;
    for ($i = 0; $i < $count; $i++) {
        if ($skip !== null && $i === $skip) {
            continue;
        }
        $up = $i % 2 === 0;
        $rows[$start + $i * 60_000] = [
            'open' => $up ? '100' : '110',
            'high' => '111',
            'low' => '99',
            'close' => $up ? '110' : '100',
            'volume' => '10',
        ];
    }

    return $rows;
}

function actionAnalysisFor(array $history): ActionLabelAnalysis
{
    $repository = Mockery::mock(TickerRepository::class);
    $repository->shouldReceive('streamHistory')->once()->andReturnUsing(function () use ($history): Generator {
        foreach ($history as $timestamp => $candle) {
            yield $timestamp => $candle;
        }
    });
    $catalog = app(MarketCatalog::class);

    return new ActionLabelAnalysis(
        $repository,
        new ActionAutoLabeler,
        new PublishedTakerFee($catalog),
        new CandleTimeframe,
    );
}

beforeEach(function () {
    config([
        'intelligence.max_model_age_days' => 366,
        'intelligence.min_horizon_distance_observations' => 30,
        'exchange_fees.taker_overrides.kraken.rate' => 0.0,
    ]);
});

it('requires thirty valid d observations but uses every available observation', function () {
    $insufficient = actionAnalysisFor(alternatingHistory(33))->analyze('kraken', 'BTC/USD', '1m', 1_800_000_000_000);
    $valid = actionAnalysisFor(alternatingHistory(100))->analyze('kraken', 'BTC/USD', '1m', 1_800_000_000_000);

    expect($insufficient['distance_observations'])->toBeLessThan(30)
        ->and($insufficient['horizon'])->toBeNull()
        ->and($insufficient['status'])->toBe('insufficient_distance_observations')
        ->and($valid['distance_observations'])->toBeGreaterThan(30)
        ->and($valid['distance_frequencies'][1])->toBe($valid['distance_observations'])
        ->and($valid['horizon'])->toBe(1)
        ->and($valid['status'])->toBe('validated');
});

it('never lets a d observation cross a missing candle', function () {
    $analysis = actionAnalysisFor(alternatingHistory(100, 50))
        ->analyze('kraken', 'BTC/USD', '1m', 1_800_000_000_000);

    expect($analysis['gaps'])->toBe(1)
        ->and($analysis['contiguous_runs'])->toBe(2)
        ->and($analysis['distance_max'])->toBe(1)
        ->and($analysis['horizon'])->toBe(1);
});

it('retains weighted distance statistics for sparse pivots and both median positions', function (array $pivots, array $expected) {
    config(['intelligence.min_horizon_distance_observations' => 1]);
    $history = [];
    $start = 1_700_000_000_000;
    $last = max(array_keys($pivots)) + 3;
    for ($i = 0; $i <= $last; $i++) {
        $action = $pivots[$i] ?? null;
        $history[$start + $i * 60_000] = [
            'open' => $action === 'buy' ? '110' : ($action === 'sell' ? '100' : '105'),
            'close' => $action === 'buy' ? '100' : ($action === 'sell' ? '110' : '105'),
            'high' => $action === null ? '105' : '111',
            'low' => $action === null ? '105' : '99', 'volume' => '1',
        ];
    }

    $analysis = actionAnalysisFor($history)->analyze('kraken', 'BTC/USD', '1m', $start + $last * 60_000);

    expect($analysis)->toMatchArray($expected);
})->with([
    'odd median' => [[1 => 'buy', 3 => 'sell', 7 => 'buy', 10 => 'sell'], [
        'distance_frequencies' => [2 => 1, 3 => 1, 4 => 1], 'distance_observations' => 3,
        'distance_mean' => 3, 'distance_median' => 3.0, 'distance_min' => 2, 'distance_max' => 4, 'horizon' => 3,
    ]],
    'even median between buckets' => [[1 => 'buy', 3 => 'sell', 8 => 'buy'], [
        'distance_frequencies' => [2 => 1, 5 => 1], 'distance_observations' => 2,
        'distance_mean' => 3.5, 'distance_median' => 3.5, 'distance_min' => 2, 'distance_max' => 5, 'horizon' => 4,
    ]],
    'repeated distances retain their weight' => [[1 => 'buy', 2 => 'sell', 3 => 'buy', 4 => 'sell', 13 => 'buy'], [
        'distance_frequencies' => [1 => 3, 9 => 1], 'distance_observations' => 4,
        'distance_mean' => 3, 'distance_median' => 1, 'distance_min' => 1, 'distance_max' => 9, 'horizon' => 3,
    ]],
]);

it('keeps label availability tied to the next opposite pivot and leaves the unfinished swing unavailable', function () {
    $start = 1_700_000_000_000;

    $analysis = actionAnalysisFor(alternatingHistory(7))->analyze('kraken', 'BTC/USD', '1m', $start + 6 * 60_000);

    expect($analysis['_action_labels'])->toBe([
        $start + 60_000 => 'buy', $start + 120_000 => 'sell',
        $start + 180_000 => 'buy', $start + 240_000 => 'sell',
    ]);
    expect($analysis['_action_available_at_ms'])->toBe([
        $start + 60_000 => $start + 180_000,
        $start + 120_000 => $start + 240_000,
        $start + 180_000 => $start + 300_000,
    ]);
});

it('reports no distances or labels when the requested history is empty', function () {
    $analysis = actionAnalysisFor([])->analyze('kraken', 'BTC/USD', '1m', 1_800_000_000_000);

    expect($analysis)->toMatchArray([
        'status' => 'insufficient_distance_observations', 'distance_observations' => 0,
        'distance_frequencies' => [], 'distance_min' => null, 'distance_max' => null,
        'distance_mean' => null, 'distance_median' => null, 'horizon' => null,
        'contiguous_runs' => 0, '_action_labels' => [], '_action_available_at_ms' => [],
    ]);
});

it('records the market and run size before labeling the candle history', function () {
    $handler = new TestHandler;
    app()->instance(ActionLog::class, new ActionLog(new Logger('test-actions', [$handler]), app(ActionContext::class)));

    actionAnalysisFor(alternatingHistory(7))->analyze('kraken', 'BTC/USD', '1m', 1_800_000_000_000);

    $records = array_map(fn ($record): array => json_decode($record->message, true), $handler->getRecords());
    expect($records)->toHaveCount(2);
    expect($records[0])->toMatchArray(['event' => 'intelligence.action_labels.started',
        'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m', 'stage' => 'action_labeling']);
    expect($records[1])->toMatchArray(['event' => 'intelligence.action_labels.run',
        'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m', 'rows' => 7]);
    expect($records[1]['memory_bytes'])->toBeGreaterThan(0);
});
