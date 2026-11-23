<?php

use App\Domain\MarketData\CandleGaps;
use App\Domain\MarketData\CandleSyncPages;
use App\Domain\MarketData\MarketDataSynchronizer;
use App\Domain\Operations\ActionLog;
use App\Jobs\SyncMarketCandles;
use App\Models\Exchange;
use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

it('syncs a long interval in bounded overlapping windows', function () {
    $exchange = new Exchange(['name' => 'Test', 'class' => 'kraken']);
    $repository = Mockery::mock(ExchangeRepository::class);
    $tickers = Mockery::mock(TickerRepository::class);
    $repository->shouldReceive('findByClass')->times(3)->with('kraken')->andReturn(new Collection([$exchange]));
    $repository->shouldReceive('setExchange')->times(3)->with($exchange, [], null, 'BTC/USD');
    $repository->shouldReceive('periods')->times(3)->andReturn(['1m' => '1m']);
    $repository->shouldReceive('prepareCandleMarket')->times(3)->with('BTC/USD');
    foreach ([[0, 599], [420, 1019], [840, 1200]] as [$from, $to]) {
        $repository->shouldReceive('fetch')->once()->with('BTC/USD', '1m', $from, $to, 10)->andReturn([]);
        $tickers->shouldReceive('timestamps')->once()->with('kraken', 'BTC/USD', '1m', $from * 1000, $to * 1000)->andReturn([]);
    }

    app()->instance(TickerRepository::class, $tickers);
    app()->instance(MarketDataSynchronizer::class, new MarketDataSynchronizer($repository, $tickers, new CandleGaps, app(ActionLog::class)));

    $status = Artisan::call('trademinator:sync-ohlcv', [
        'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
        '--from' => '1970-01-01 00:00:00 UTC', '--to' => '1970-01-01 00:20:00 UTC', '--page-size' => '10',
    ]);

    expect($status)->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['pages'])->toBe(3);
});

it('queues the next bounded page only after the current page succeeds', function () {
    $exchange = new Exchange(['name' => 'Test', 'class' => 'kraken']);
    $repository = Mockery::mock(ExchangeRepository::class);
    $tickers = Mockery::mock(TickerRepository::class);
    $repository->shouldReceive('findByClass')->once()->with('kraken')->andReturn(new Collection([$exchange]));
    $repository->shouldReceive('setExchange')->once()->with($exchange, [], null, 'BTC/USD');
    $repository->shouldReceive('periods')->once()->andReturn(['1m' => '1m']);
    $repository->shouldReceive('prepareCandleMarket')->once()->with('BTC/USD');
    $repository->shouldReceive('fetch')->once()->with('BTC/USD', '1m', 0, 599, 10)->andReturn([]);
    $tickers->shouldReceive('timestamps')->once()->with('kraken', 'BTC/USD', '1m', 0, 599_000)->andReturn([]);
    $synchronizer = new MarketDataSynchronizer($repository, $tickers, new CandleGaps, app(ActionLog::class));

    Bus::fake();
    (new SyncMarketCandles('kraken', 'BTC/USD', '1m', 0, 1200, false, false, 10))
        ->onConnection('redis')->onQueue('repairs')->handle($synchronizer, $tickers, new CandleSyncPages);

    Bus::assertDispatched(SyncMarketCandles::class, fn (SyncMarketCandles $job): bool => $job->from === 420 && $job->to === 1200 && $job->pageSize === 10 && $job->connection === 'redis' && $job->queue === 'repairs');
});

it('queues only the first job and refuses an inline queue connection', function () {
    Bus::fake();
    $arguments = [
        'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
        '--from' => '1970-01-01 00:00:00 UTC', '--to' => '1970-01-01 00:20:00 UTC',
        '--page-size' => '10', '--queue' => true,
    ];

    config(['queue.default' => 'sync']);
    expect(Artisan::call('trademinator:sync-ohlcv', $arguments))->toBe(1);
    Bus::assertNotDispatched(SyncMarketCandles::class);

    config(['queue.default' => 'database']);
    expect(Artisan::call('trademinator:sync-ohlcv', $arguments))->toBe(0);
    Bus::assertDispatchedTimes(SyncMarketCandles::class, 1);
    Bus::assertDispatched(SyncMarketCandles::class, fn (SyncMarketCandles $job): bool => $job->from === 0 && $job->to === 1200 && $job->pageSize === 10);
});

it('keeps one candidate refresh lease across every bounded page and releases it at completion', function () {
    $exchange = new Exchange(['name' => 'Test', 'class' => 'kraken']);
    $repository = Mockery::mock(ExchangeRepository::class);
    $tickers = Mockery::mock(TickerRepository::class);
    $repository->shouldReceive('findByClass')->times(3)->with('kraken')->andReturn(new Collection([$exchange]));
    $repository->shouldReceive('setExchange')->times(3)->with($exchange, [], null, 'BTC/USD');
    $repository->shouldReceive('periods')->times(3)->andReturn(['1m' => '1m']);
    $repository->shouldReceive('prepareCandleMarket')->times(3)->with('BTC/USD');
    foreach ([[0, 599], [420, 1019], [840, 1200]] as [$from, $to]) {
        $repository->shouldReceive('fetch')->once()->with('BTC/USD', '1m', $from, $to, 10)->andReturn([]);
        $tickers->shouldReceive('timestamps')->once()->with('kraken', 'BTC/USD', '1m', $from * 1000, $to * 1000)->andReturn([]);
    }
    $synchronizer = new MarketDataSynchronizer($repository, $tickers, new CandleGaps, app(ActionLog::class));
    $job = (new SyncMarketCandles('kraken', 'BTC/USD', '1m', 0, 1200, false, false, 10))->onQueue('history');
    $lock = Cache::lock($job->candidateLockKey(), 86_400);
    expect($lock->get())->toBeTrue();
    $job->candidateLockOwner = $lock->owner();
    Bus::fake([SyncMarketCandles::class]);

    $job->handle($synchronizer, $tickers, new CandleSyncPages);
    $second = Bus::dispatched(SyncMarketCandles::class)->sole();
    expect($second->from)->toBe(420);
    expect($second->candidateLockOwner)->toBe($lock->owner());
    expect($lock->isOwnedByCurrentProcess())->toBeTrue();
    $second->handle($synchronizer, $tickers, new CandleSyncPages);
    $third = Bus::dispatched(SyncMarketCandles::class)->last();
    expect($third->from)->toBe(840);
    expect($third->candidateLockOwner)->toBe($lock->owner());
    $third->handle($synchronizer, $tickers, new CandleSyncPages);

    Bus::assertDispatchedTimes(SyncMarketCandles::class, 2);
    expect($lock->isOwnedByCurrentProcess())->toBeFalse();
});

it('releases failed refreshes without releasing a newer owners lease or running expired work', function () {
    $job = new SyncMarketCandles('kraken', 'BTC/USD', '1m', 0, 1200);
    $lock = Cache::lock($job->candidateLockKey(), 1);
    $lock->get();
    $job->candidateLockOwner = $lock->owner();
    $job->failed(new RuntimeException('Exchange unavailable'));
    expect($lock->isOwnedByCurrentProcess())->toBeFalse();

    $newLock = Cache::lock($job->candidateLockKey(), 86_400);
    expect($newLock->get())->toBeTrue();
    $job->failed(new RuntimeException('Old worker failed late'));
    $repository = Mockery::mock(ExchangeRepository::class);
    $repository->shouldNotReceive('fetch');
    $tickers = Mockery::mock(TickerRepository::class);
    Bus::fake([SyncMarketCandles::class]);
    $job->handle(new MarketDataSynchronizer($repository, $tickers, new CandleGaps, app(ActionLog::class)), $tickers, new CandleSyncPages);

    Bus::assertNotDispatched(SyncMarketCandles::class);
    expect($newLock->isOwnedByCurrentProcess())->toBeTrue();
});
