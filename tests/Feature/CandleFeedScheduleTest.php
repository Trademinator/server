<?php

use App\Domain\MarketData\CandleFeedSchedule;
use App\Domain\MarketData\CandleGaps;
use App\Domain\MarketData\CandlePeriodReevaluation;
use App\Domain\MarketData\ExchangeMetadata;
use App\Domain\MarketData\MarketDataSynchronizer;
use App\Domain\Operations\ActionLog;
use App\Jobs\BuildMarketFeatures;
use App\Jobs\CollectMarketFeed;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Queue;

it('aligns the next pull to the exchange candle close', function (string $period, string $now, string $anchor, string $expected, string $next) {
    $schedule = new CandleFeedSchedule;
    $at = strtotime($now.' UTC') * 1000;
    $last = strtotime($anchor.' UTC') * 1000;

    expect($schedule->lastClosedOpening($period, $at, $last))->toBe(strtotime($expected.' UTC') * 1000);
    expect($schedule->nextPull($period, $at, $last, true))->toBe(strtotime($next.' UTC') * 1000);
})->with([
    ['1m', '2026-10-23 12:00:05', '2026-10-23 11:59:00', '2026-10-23 11:59:00', '2026-10-23 12:01:05'],
    ['4h', '2026-10-23 13:15:00', '2026-10-23 08:00:00', '2026-10-23 08:00:00', '2026-10-23 16:00:05'],
    ['4h', '2026-10-23 16:00:02', '2026-10-23 08:00:00', '2026-10-23 12:00:00', '2026-10-23 16:00:05'],
    ['1w', '2026-10-23 13:15:00', '2026-10-12 00:00:00', '2026-10-12 00:00:00', '2026-10-26 00:00:05'],
    ['1M', '2026-02-28 12:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-03-01 00:00:05'],
    ['3M', '2026-10-23 13:15:00', '2026-07-01 00:00:00', '2026-07-01 00:00:00', '2027-01-01 00:00:05'],
]);

it('retries a missing closed candle soon without waiting a full four hour period', function () {
    $schedule = new CandleFeedSchedule;
    $at = strtotime('2026-10-23 16:01:00 UTC') * 1000;
    $anchor = strtotime('2026-10-23 08:00:00 UTC') * 1000;

    expect($schedule->nextPull('4h', $at, $anchor, false, 5, 60))
        ->toBe(strtotime('2026-10-23 16:02:00 UTC') * 1000);
    expect($schedule->nextPull('4h', $at, $anchor, false, 5, 10000))
        ->toBe(strtotime('2026-10-23 16:06:00 UTC') * 1000);
});

it('persists aligned collector eligibility and queues features for completed candles', function (bool $published, string $state, string $next) {
    $this->travelTo(now('UTC')->setDate(2026, 10, 23)->setTime(13, 15));
    config(['queue.default' => 'database', 'features.enabled' => true, 'archive.enabled' => false]);
    Queue::fake([BuildMarketFeatures::class]);
    $exchange = Exchange::query()->create(['class' => 'kraken', 'name' => 'Kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketSubscription::query()->create(['user_id' => User::factory()->create()->user_id, 'market_id' => $market->market_id, 'active' => true]);
    $feed = MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '4h',
        'status' => 'queued', 'lease_token' => 'collector-test', 'lease_until' => now()->addMinutes(15)]);
    $tickers = app(TickerRepository::class);
    $bar = static fn (string $time): array => ['microtimestamp' => strtotime($time.' UTC') * 1000,
        'open' => '100', 'high' => '102', 'low' => '99', 'close' => '101', 'volume' => '10'];
    $tickers->saveTickers('kraken', 'BTC/USD', '4h', [$bar('2026-10-23 04:00:00')]);
    $repository = Mockery::mock(ExchangeRepository::class);
    $repository->shouldReceive('findByClass')->once()->with('kraken')->andReturn(new Collection([$exchange]));
    $repository->shouldReceive('setExchange')->once();
    $repository->shouldReceive('periods')->once()->andReturn(['4h' => '4h']);
    $repository->shouldReceive('prepareCandleMarket')->once()->with('BTC/USD');
    $repository->shouldReceive('fetch')->once()->withArgs(function ($symbol, $period, $from, $to, $limit): bool {
        return $symbol === 'BTC/USD' && $period === '4h' && $to === strtotime('2026-10-23 08:00:00 UTC') && $limit === 90;
    })->andReturnUsing(function () use ($published, $tickers, $bar): array {
        if ($published) {
            $candles = [$bar('2026-10-23 08:00:00')];
            $tickers->saveTickers('kraken', 'BTC/USD', '4h', $candles);

            return $candles;
        }

        return [];
    });
    $metadata = Mockery::mock(ExchangeMetadata::class);
    $metadata->shouldReceive('assertUsable')->once()->andReturn([]);
    $synchronizer = new MarketDataSynchronizer($repository, $tickers, new CandleGaps, app(ActionLog::class));

    (new CollectMarketFeed($market->market_id, 'collector-test'))->handle(app(CandlePeriodReevaluation::class), $synchronizer, $tickers, $metadata);

    $feed->refresh();
    expect($feed->status)->toBe($state);
    expect($feed->next_pull_at->getTimestamp())->toBe(strtotime($next.' UTC'));
    expect($feed->lease_token)->toBeNull();
    Queue::assertPushed(BuildMarketFeatures::class, fn ($job) => $job->exchange === 'kraken' && $job->period === '4h');
})->with([
    [true, 'ready', '2026-10-23 16:00:05'],
    [false, 'pending', '2026-10-23 13:16:00'],
]);
