<?php

use App\Domain\MarketData\CandlePeriodReevaluation;
use App\Jobs\BackfillMarketHistory;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

use function Trademinator\Time\periods_to_seconds;

/** @return list<array<string, mixed>> */
function rankingCandles(string $period, array $lengths, int $days = 60): array
{
    $step = periods_to_seconds($period) * 1000;
    $count = (int) ($days * 86_400_000 / $step);
    $start = now()->getTimestampMs() - $days * 86_400_000;
    $candles = [];
    for ($run = 0; count($candles) < $count; $run++) {
        $up = $run % 2 === 1;
        for ($i = 0; $i < $lengths[$run % count($lengths)] && count($candles) < $count; $i++) {
            $candles[] = ['microtimestamp' => $start + count($candles) * $step,
                'open' => $up ? '111' : '101', 'close' => $up ? '112' : '100',
                'high' => '113', 'low' => '99', 'volume' => '5'];
        }
    }

    return $candles;
}

function rankingFeed(array $candles): MarketFeed
{
    config(['candle_period.periods' => array_keys($candles),
        'candle_period.evaluation_days' => 60, 'candle_period.fallback_days' => 30,
        // Old deployments may still contain this key; it must no longer impose a gate.
        'candle_period.minimum_candles' => 100,
        'candle_period.quality_threshold' => 0.7, 'candle_period.minimum_coverage' => 0.8,
        'candle_period.minimum_action_ratio' => 0.02,
        'candle_period.max_rejected_reversal_ratio' => 0.10]);
    $exchange = Exchange::query()->create(['name' => 'Binance', 'class' => 'binance', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'XRP/USDC', 'tick_size' => '0.001']);
    MarketSubscription::query()->create(['user_id' => User::factory()->create()->user_id,
        'market_id' => $market->market_id, 'active' => true]);
    $feed = MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1m']);

    $exchangeRepository = Mockery::mock(ExchangeRepository::class);
    $exchangeRepository->shouldReceive('setExchange')->andReturnNull();
    $exchangeRepository->shouldReceive('periods')->andReturn(array_combine(array_keys($candles), array_keys($candles)));
    $exchangeRepository->shouldReceive('candleMarketMetadata')->andReturn(['taker' => 0.001]);
    $exchangeRepository->shouldReceive('hasHistoricalData')->andReturn(true);
    app()->instance(ExchangeRepository::class, $exchangeRepository);

    $tickers = Mockery::mock(TickerRepository::class);
    $tickers->shouldReceive('streamHistory')->andReturnUsing(function ($exchange, $symbol, $period, $fromMs, $toMs) use ($candles) {
        foreach ($candles[$period] as $candle) {
            if ($candle['microtimestamp'] >= $fromMs && $candle['microtimestamp'] <= $toMs) {
                yield $candle['microtimestamp'] => $candle;
            }
        }
    });
    app()->instance(TickerRepository::class, $tickers);

    return $feed;
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-11T00:00:00Z'));
    Queue::fake();
});

it('ranks every eligible period by median, then P95, then the shortest period', function (array $fourHour, array $daily, string $winner) {
    $feed = rankingFeed(['4h' => rankingCandles('4h', $fourHour), '1d' => rankingCandles('1d', $daily)]);
    $result = app(CandlePeriodReevaluation::class)->evaluate($feed, true);

    expect($result['selected_period'])->toBe($winner)
        ->and(array_column($result['attempts'], 'status'))->toBe(['passed', 'passed'])
        ->and(array_column($result['attempts'], 'days'))->toBe([60, 60])
        ->and($feed->fresh()->selected_period)->toBe('1m')
        ->and(DB::table('candle_period_selections')->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'higher median beats the first passing period' => [[2], [3], '1d'],
    'P95 resolves equal medians' => [[2], [2, 2, 2, 2, 4], '1d'],
    'daily alternating candles lose dynamically' => [[4], [1], '4h'],
    'shortest wins an exact tie' => [[2], [2], '4h'],
]);

it('allows 60 daily candles and one-candle runs without persisting redundant candle data', function () {
    $feed = rankingFeed(['1d' => rankingCandles('1d', [1])]);
    $result = app(CandlePeriodReevaluation::class)->evaluate($feed);

    expect($result['status'])->toBe('switched')
        ->and($result['current_period'])->toBe('1m')
        ->and($feed->fresh()->selected_period)->toBe('1d')
        ->and($result['run_p50'])->toBe(1.0)
        ->and($result['run_p95'])->toBe(1.0);
    $audit = json_decode(DB::table('candle_period_selections')->sole()->quality, true);
    expect($audit['sample_size'])->toBe(60)
        ->and(array_keys($audit['runs']))->toBe(['count', 'p50', 'p95'])
        ->and($audit['economic']['rejected_reversal_ratio'])->toBe(0)
        ->and($audit)->not->toHaveKeys(['candles', 'attempts', 'compare', 'consecutive', 'histogram'])
        ->and(DB::table('market_features')->count())->toBe(0);
});

it('prefers fewer fee rejections when both run percentiles tie', function () {
    $shorter = rankingCandles('4h', [1]);
    $shorter[3]['open'] = '99.1';
    $shorter[3]['close'] = '100.1';
    $feed = rankingFeed(['4h' => $shorter, '1d' => rankingCandles('1d', [1])]);
    $result = app(CandlePeriodReevaluation::class)->evaluate($feed, true);

    expect($result['selected_period'])->toBe('1d')
        ->and($result['attempts'][0]['status'])->toBe('passed')
        ->and($result['attempts'][0]['rejected_reversal_ratio'])->toBeGreaterThan(0)
        ->and($result['attempts'][1]['rejected_reversal_ratio'])->toBe(0);
});

it('rejects excessive insufficient-movement reversals despite enough final BUY and SELL labels', function () {
    $noisy = rankingCandles('4h', [1]);
    foreach (range(3, 143, 2) as $index) {
        $noisy[$index]['open'] = '99.1';
        $noisy[$index]['close'] = '100.1';
    }
    $feed = rankingFeed(['4h' => $noisy, '1d' => rankingCandles('1d', [1])]);
    $result = app(CandlePeriodReevaluation::class)->evaluate($feed, true);

    expect($result['selected_period'])->toBe('1d')
        ->and($result['attempts'][0]['status'])->toBe('reversal_failed')
        ->and($result['attempts'][0]['buy_ratio'])->toBeGreaterThan(0.02)
        ->and($result['attempts'][0]['sell_ratio'])->toBeGreaterThan(0.02);
});

it('compares all fallback candidates over 90 days rather than mixing window lengths', function (bool $recentFlatFailure) {
    $samples = ['4h' => rankingCandles('4h', [4], 90), '1d' => rankingCandles('1d', [2], 90)];
    foreach ($samples as $period => &$candles) {
        // The recent repeated closes fail a higher quality floor; older, distinct closes lift the 90-day sample.
        $older = intdiv(count($candles), 3);
        for ($i = 0; $i < $older; $i++) {
            $candles[$i]['close'] = (string) ((float) $candles[$i]['close'] + $i * 0.001);
        }
        if ($recentFlatFailure && $period === '4h') {
            for ($i = $older; $i < $older + 44; $i++) {
                foreach (['open', 'high', 'low', 'close'] as $key) {
                    $candles[$i][$key] = '106';
                }
            }
        }
    }
    unset($candles);
    $feed = rankingFeed($samples);
    config(['candle_period.quality_threshold' => 0.85]);
    $result = app(CandlePeriodReevaluation::class)->evaluate($feed, true);

    expect($result['window_days'])->toBe(90)
        ->and($result['selected_period'])->toBe($recentFlatFailure ? '1d' : '4h')
        ->and(array_column($result['attempts'], 'days'))->toBe($recentFlatFailure ? [60, 60, 90] : [60, 60, 90, 90]);
    if ($recentFlatFailure) {
        expect($result['attempts'][0]['status'])->toBe('flat_failed');
    }
})->with([false, true]);

it('evaluates the remaining candidates but retains the selection until missing history is resolved', function (bool $dryRun) {
    $feed = rankingFeed(['4h' => [], '1d' => rankingCandles('1d', [2])]);
    config(['queue.default' => 'database']);
    $result = app(CandlePeriodReevaluation::class)->evaluate($feed, $dryRun);

    expect($result['status'])->toBe($dryRun ? 'needs_backfill' : 'pending')
        ->and(array_column($result['attempts'], 'status'))->toBe(['needs_backfill', 'passed'])
        ->and($result['selected_period'])->toBeNull()
        ->and($feed->fresh()->selected_period)->toBe('1m')
        ->and(DB::table('candle_period_selections')->count())->toBe(0);
    if ($dryRun) {
        Queue::assertNothingPushed();
        expect(DB::table('market_history_backfills')->count())->toBe(0);
    } else {
        expect(DB::table('market_history_backfills')->where('period', '4h')->exists())->toBeTrue();
        Queue::assertPushed(BackfillMarketHistory::class, 1);
    }
})->with([true, false]);
