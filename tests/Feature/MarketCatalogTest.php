<?php

use App\Domain\MarketData\MarketCatalog;
use App\Models\Exchange;
use App\Models\User;
use App\Repositories\ExchangeRepository;

it('offers readable exchange names, spot pairs and only supported periods', function () {
    $exchange = Exchange::query()->create(['name' => 'kraken', 'class' => 'kraken', 'config' => '{}']);
    $user = User::factory()->create();
    $repository = Mockery::mock(ExchangeRepository::class);
    $repository->shouldReceive('setExchange')->once()->with(Mockery::type(Exchange::class), [], Mockery::on(fn (User $actor): bool => $actor->is($user)));
    $repository->shouldReceive('describe')->once()->andReturn([
        'name' => 'Kraken', 'timeframes' => ['15m' => '15m', '1m' => '1m', '9m' => '9m'],
        'precisionMode' => \ccxt\TICK_SIZE,
    ]);
    $repository->shouldReceive('spotMarkets')->once()->andReturn([
        'BTC/USD' => ['spot' => true, 'precision' => ['price' => 0.01]],
        'ETH/USDT' => ['spot' => true, 'precision' => ['price' => 0.001]],
        'BTC/USD:USD' => ['spot' => false, 'precision' => ['price' => 0.1]],
    ]);
    app()->instance(ExchangeRepository::class, $repository);

    $this->actingAs($user)->get(route('markets.index'))
        ->assertOk()->assertSee('<option value="kraken"', false)->assertSee('Kraken');
    $this->actingAs($user)->getJson(route('markets.options', 'kraken'))
        ->assertOk()->assertJsonPath('symbols.0.value', 'BTC/USD')
        ->assertJsonPath('symbols.0.tick_size', '0.01')
        ->assertJsonPath('periods.0.value', '1m')
        ->assertJsonPath('periods.1.label', '15 minutes')
        ->assertJsonMissing(['value' => 'BTC/USD:USD'])
        ->assertJsonMissing(['value' => '9m']);
});

it('uses the highest known taker fee for conservative training and economic evaluation', function () {
    expect(MarketCatalog::takerFee('0.0015', 'ndax'))->toBe(0.002)
        ->and(MarketCatalog::takerFee(null, 'ndax'))->toBe(0.002)
        ->and(MarketCatalog::takerFee('0.004', 'cryptocom'))->toBe(0.005)
        ->and(MarketCatalog::takerFee(null, 'cryptocom'))->toBe(0.005)
        ->and(MarketCatalog::takerFee('0.0026', 'kraken'))->toBe(0.008)
        ->and(MarketCatalog::takerFee(null, 'kraken'))->toBe(0.008)
        ->and(MarketCatalog::takerFee('0.0015', 'poloniex'))->toBe(0.002)
        ->and(MarketCatalog::takerFee(null, 'poloniex'))->toBe(0.002)
        ->and(MarketCatalog::takerFee('0.003', 'poloniex'))->toBe(0.003)
        ->and(MarketCatalog::takerFee('0.01', 'kraken'))->toBe(0.01);
});

it('does not return market metadata to visitors', function () {
    $this->get(route('markets.options', 'kraken'))->assertRedirect(route('login'));
});

it('interprets CCXT price precision without confusing it with minimum price or a candle period', function () {
    expect(MarketCatalog::tickSize(0.00000001, \ccxt\TICK_SIZE))->toBe('0.00000001')
        ->and(MarketCatalog::tickSize(2, \ccxt\DECIMAL_PLACES))->toBe('0.01')
        ->and(MarketCatalog::tickSize(8, \ccxt\SIGNIFICANT_DIGITS))->toBeNull();
});

it('preserves decimal and scientific tick sizes without binary digits', function (int|float|string $precision, string $expected) {
    expect(MarketCatalog::tickSize($precision, \ccxt\TICK_SIZE))->toBe($expected);
})->with([
    'float tenth' => [0.1, '0.1'],
    'float scientific notation' => [3e-8, '0.00000003'],
    'exact scientific string' => ['1.23456789012345678e-1', '0.123456789012345678'],
]);
