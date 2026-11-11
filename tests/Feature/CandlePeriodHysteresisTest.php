<?php

use App\Domain\MarketData\CandlePeriodReevaluation;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

/** @return list<array<string, mixed>> */
function hysteresisCandles(int $startMs, string $period, int $days, float $flatShare): array
{
    $seconds = $period === '5m' ? 300 : 900;
    $count = intdiv($days * 86_400, $seconds);
    $flatCount = (int) floor($count * $flatShare);
    $candles = [];
    for ($index = 0; $index < $count; $index++) {
        $flat = $index < $flatCount;
        $buy = $index % 2 === 0;
        $candles[] = [
            'microtimestamp' => $startMs + $index * $seconds * 1000,
            'open' => $flat ? '106' : ($buy ? '101' : '111'),
            'high' => $flat ? '106' : '113',
            'low' => $flat ? '106' : '99',
            'close' => $flat ? '106' : ($buy ? '100' : '112'),
            'volume' => '5',
        ];
    }

    return $candles;
}

function hysteresisFeed(string $currentPeriod, float $recentFlat, ?float $previousFlat, bool $stalePrevious = false): MarketFeed
{
    config([
        'candle_period.periods' => ['5m', '15m'],
        'candle_period.selection_version' => 4,
        'candle_period.quality_threshold' => 0.7,
        'candle_period.minimum_coverage' => 0.8,
        'candle_period.minimum_candles' => 50,
        'candle_period.minimum_action_ratio' => 0.05,
        'candle_period.max_true_flat_ratio' => 0.10,
        'candle_period.shorter_reentry_flat_ratio' => 0.05,
        'candle_period.shorter_confirmation_windows' => 2,
        'candle_period.evaluation_days' => 1,
        'candle_period.fallback_days' => 1,
    ]);

    $exchange = Exchange::query()->create(['name' => 'Bitso', 'class' => 'bitso', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'XRP/USD', 'tick_size' => '0.001']);
    MarketSubscription::query()->create(['user_id' => User::factory()->create()->user_id,
        'market_id' => $market->market_id, 'active' => true]);
    $feed = MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => $currentPeriod]);

    $toMs = now()->getTimestampMs();
    $dayMs = 86_400_000;
    $candles = [
        '5m' => hysteresisCandles($toMs - $dayMs, '5m', 1, $recentFlat),
        '15m' => hysteresisCandles($toMs - $dayMs, '15m', 1, 0.0),
    ];
    if ($previousFlat !== null) {
        $previous = hysteresisCandles($toMs - 2 * $dayMs, '5m', 1, $previousFlat);
        $candles['5m'] = [...($stalePrevious ? array_slice($previous, 0, 60) : $previous), ...$candles['5m']];
    }

    $repository = Mockery::mock(ExchangeRepository::class);
    $repository->shouldReceive('setExchange')->andReturnNull();
    $repository->shouldReceive('periods')->andReturn(['5m' => '5m', '15m' => '15m']);
    $repository->shouldReceive('candleMarketMetadata')->andReturn(['taker' => 0.001]);
    $repository->shouldReceive('hasHistoricalData')->andReturn(true);
    app()->instance(ExchangeRepository::class, $repository);

    $tickers = Mockery::mock(TickerRepository::class);
    $tickers->shouldReceive('streamHistory')->andReturnUsing(function ($exchange, $symbol, $period, $fromMs, $toMs) use ($candles) {
        foreach ($candles[$period] ?? [] as $candle) {
            if ($candle['microtimestamp'] >= $fromMs && $candle['microtimestamp'] <= $toMs) {
                yield $candle['microtimestamp'] => $candle;
            }
        }
    });
    app()->instance(TickerRepository::class, $tickers);

    return $feed;
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10T12:00:00Z'));
});

it('does not reenter the shorter period when only the newest window meets the 5% cap', function () {
    $feed = hysteresisFeed('15m', 0.04, 0.08);
    $evaluation = app(CandlePeriodReevaluation::class);

    // Repeating the same evaluation cannot manufacture a second confirmation.
    $first = $evaluation->evaluate($feed, true);
    $second = $evaluation->evaluate($feed, true);

    expect($first['status'])->toBe('unchanged')
        ->and($second['status'])->toBe('unchanged')
        ->and($feed->fresh()->selected_period)->toBe('15m');
    $reentryFailure = collect($first['attempts'])->first(fn ($attempt) => ($attempt['confirmation_window'] ?? null) === 2);
    expect($reentryFailure['status'])->toBe('flat_failed')
        ->and($reentryFailure['true_flat_ratio'])->toBeGreaterThan(0.05);
});

it('reenters 5m when both independent windows satisfy flat, quality and action gates', function () {
    $feed = hysteresisFeed('15m', 0.04, 0.03);
    $result = app(CandlePeriodReevaluation::class)->evaluate($feed, true);

    expect($result['status'])->toBe('would_switch')
        ->and($result['selected_period'])->toBe('5m')
        ->and($result['window_days'])->toBe(1)
        ->and($feed->fresh()->selected_period)->toBe('15m');
    $olderWindow = collect($result['attempts'])->first(fn ($attempt) => ($attempt['confirmation_window'] ?? null) === 2);
    expect($olderWindow['status'])->toBe('passed')
        ->and($olderWindow['true_flat_ratio'])->toBeLessThanOrEqual(0.05);
});

it('rejects 5m when the latest flats exceed 10%, even if a combined two-day sample would pass', function () {
    $feed = hysteresisFeed('5m', 0.12, 0.0);
    $result = app(CandlePeriodReevaluation::class)->evaluate($feed);

    expect($result['status'])->toBe('switched')
        ->and($result['selected_period'])->toBe('15m')
        ->and($feed->fresh()->selected_period)->toBe('15m');
    $first = collect($result['attempts'])->first(fn ($attempt) => $attempt['period'] === '5m');
    expect($first['status'])->toBe('flat_failed')
        ->and($first['true_flat_ratio'])->toBeGreaterThan(0.10);
    expect(collect($result['attempts'])->where('period', '5m'))->toHaveCount(1);
});

it('does not queue backfills or change the period when a confirmation window is absent in dry-run', function () {
    $feed = hysteresisFeed('15m', 0.04, null);
    $result = app(CandlePeriodReevaluation::class)->evaluate($feed, true);

    expect($result['status'])->toBe('needs_backfill')
        ->and($result['selected_period'])->toBeNull()
        ->and($feed->fresh()->selected_period)->toBe('15m');
    $last = collect($result['attempts'])->first(fn ($attempt) => ($attempt['confirmation_window'] ?? null) === 2);
    expect($last['confirmation_window'])->toBe(2)
        ->and($last['status'])->toBe('needs_backfill');
});

it('prints the flat ratio and every evaluated window in a dry run', function () {
    hysteresisFeed('15m', 0.04, 0.08);
    $exit = Artisan::call('trademinator:evaluate-candle-period', ['--exchange' => 'bitso',
        '--pair' => 'XRP/USD', '--dry-run' => true]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('True flats')
        ->toContain('Flat cap')
        ->toContain('flat_failed')
        ->toContain('2: 1d');
});

it('rejects stale earlier confirmation windows even when their available candles look good', function () {
    $feed = hysteresisFeed('15m', 0.04, 0.0, true);
    $result = app(CandlePeriodReevaluation::class)->evaluate($feed, true);

    expect($result['status'])->toBe('unchanged')
        ->and($feed->fresh()->selected_period)->toBe('15m');
    $older = collect($result['attempts'])->first(fn ($attempt) => ($attempt['confirmation_window'] ?? null) === 2);
    expect($older['status'])->toBe('stale_sample');
});
