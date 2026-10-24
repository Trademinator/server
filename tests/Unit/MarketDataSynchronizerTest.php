<?php

use App\Domain\MarketData\CandleGaps;
use App\Domain\MarketData\MarketDataSynchronizer;
use App\Domain\Operations\ActionContext;
use App\Domain\Operations\ActionLog;
use App\Models\Exchange;
use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Log\NullLogger;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('revisits the last candle and reports an observed gap', function () {
    $exchange = new Exchange(['name' => 'Demo', 'class' => 'kraken']);
    $exchanges = Mockery::mock(ExchangeRepository::class);
    $tickers = Mockery::mock(TickerRepository::class);
    $exchanges->shouldReceive('findByClass')->once()->with('kraken')->andReturn(new Collection([$exchange]));
    $exchanges->shouldReceive('setExchange')->once()->with($exchange, [], null, 'BTC/USD');
    $exchanges->shouldReceive('periods')->once()->andReturn(['1m' => '1m']);
    $exchanges->shouldReceive('prepareCandleMarket')->once()->with('BTC/USD');
    $tickers->shouldReceive('latestTimestamp')->once()->with('kraken', 'BTC/USD', '1m')->andReturn(60_000);
    $exchanges->shouldReceive('fetch')->once()->with('BTC/USD', '1m', 60, 180, 100)->andReturn([['microtimestamp' => 60_000]]);
    $tickers->shouldReceive('timestamps')->once()->with('kraken', 'BTC/USD', '1m', 0, 180_000)->andReturn([0, 60_000, 180_000]);

    $result = (new MarketDataSynchronizer($exchanges, $tickers, new CandleGaps, new ActionLog(new NullLogger, new ActionContext)))
        ->sync('kraken', 'BTC/USD', '1m', 0, 180, true);

    expect($result)->toBe(['fetched' => 1, 'repaired' => 0, 'missing_ranges' => 1]);
});
