<?php

use App\Domain\MarketData\CandlePeriodReevaluation;
use App\Domain\MarketData\ExchangeMetadata;
use App\Domain\MarketData\ExchangeMetadataBuilder;
use App\Domain\MarketData\MarketCatalog;
use App\Domain\MarketData\MarketCatalogException;
use App\Domain\MarketData\MarketDataSynchronizer;
use App\Jobs\CollectMarketFeed;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\Cache;

function temporaryExchangeRegistry(string $path): ExchangeMetadata
{
    return new class($path) extends ExchangeMetadata
    {
        public function __construct(private readonly string $path) {}

        public function runtimePath(): string
        {
            return $this->path;
        }
    };
}

it('covers every installed adapter and distinguishes market discovery from candle authentication', function () {
    $metadata = app(ExchangeMetadata::class)->all();
    $ids = ccxt\Exchange::$exchanges;
    sort($ids);
    $actual = array_keys($metadata);
    sort($actual);
    expect($actual)->toBe($ids)->and($metadata)->toHaveCount(count($ids));
    $counts = array_count_values(array_column(array_column($metadata, 'access'), 'state'));
    expect($counts)->toEqual(['public' => 7, 'authentication_required' => 2, 'unknown' => 96])
        ->and($metadata['alpaca']['access'])->toMatchArray(['markets' => 'authentication_required', 'candles' => 'public', 'state' => 'authentication_required'])
        ->and($metadata['luno']['access'])->toMatchArray(['markets' => 'public', 'candles' => 'authentication_required'])
        ->and($metadata['binance']['access'])->toMatchArray(['state' => 'public', 'reason' => 'source_reviewed'])
        ->and($metadata['cryptocom']['access'])->toMatchArray(['state' => 'public', 'reason' => 'source_reviewed'])
        ->and($metadata['indodax']['access'])->toMatchArray(['state' => 'unknown', 'reason' => 'source_changed'])
        ->and(array_filter($metadata, ExchangeMetadata::eligible(...)))->toHaveCount(8);
    $reviews = ExchangeMetadataBuilder::read(resource_path('data/ccxt-access-reviews.json'));
    foreach ($metadata as $id => $entry) {
        expect($entry['access'])->toBe(ExchangeMetadataBuilder::classify($entry, $reviews['exchanges'][$id] ?? null));
    }
});

it('offers reviewed Binance and Crypto.com spot markets without requiring credentials', function () {
    $registry = app(ExchangeMetadata::class);
    foreach (['binance' => 'Binance', 'cryptocom' => 'Crypto.com'] as $id => $name) {
        $exchange = Exchange::query()->create(['class' => $id, 'name' => $name, 'config' => '{}']);
        expect($registry->assertUsable($exchange)['access']['state'])->toBe('public');
    }

    $this->actingAs(User::factory()->create())->get(route('markets.index'))
        ->assertOk()->assertSee('<option value="binance"', false)
        ->assertSee('<option value="cryptocom"', false);
});

it('ignores an obsolete runtime snapshot and rejects changed source even with populated caches', function (string $exchangeId) {
    $path = tempnam(sys_get_temp_dir(), 'ccxt-registry-');
    $bundle = ExchangeMetadataBuilder::read(resource_path('data/ccxt-exchanges.json'));
    $registry = temporaryExchangeRegistry($path);
    app()->instance(ExchangeMetadata::class, $registry);
    $exchange = Exchange::query()->create(['class' => $exchangeId, 'name' => $exchangeId, 'config' => '{}']);
    try {
        ExchangeMetadataBuilder::write($path, [...$bundle, 'ccxt_reference' => 'obsolete']);
        expect($registry->all()[$exchangeId]['access']['state'])->toBe('public');
        $this->actingAs(User::factory()->create())->get(route('markets.index'))
            ->assertOk()->assertSee('<option value="'.$exchangeId.'"', false);
        Cache::put(MarketCatalog::PAIRS_CACHE_PREFIX.$exchange->exchange_id, ['symbols' => [['value' => 'BTC/USD']], 'periods' => []], 300);
        $bundle['exchanges'][$exchangeId]['source_files']['php/'.$exchangeId.'.php'] = str_repeat('0', 64);
        ExchangeMetadataBuilder::write($path, $bundle);
        $repository = Mockery::mock(ExchangeRepository::class);
        $repository->shouldNotReceive('setExchange');
        app()->instance(ExchangeRepository::class, $repository);
        expect($registry->all()[$exchangeId]['access']['reason'])->toBe('source_changed');
        $this->get(route('markets.index'))->assertOk()->assertDontSee('<option value="'.$exchangeId.'"', false);
        $this->getJson(route('markets.options', $exchangeId))->assertStatus(422)->assertJsonPath('code', 'access_unknown');
        $this->post(route('markets.store'), ['exchange' => $exchangeId, 'symbol' => 'BTC/USD'])->assertSessionHasErrors('exchange');
        expect(MarketSubscription::query()->count())->toBe(0);
    } finally {
        unlink($path);
    }
})->with(['kraken', 'binance', 'cryptocom']);

it('shows authentication-required choices but stops Luno before fetching public pairs without credentials', function () {
    Exchange::query()->create(['class' => 'luno', 'name' => 'Luno', 'config' => '{}']);
    $repository = Mockery::mock(ExchangeRepository::class);
    $repository->shouldNotReceive('setExchange');
    app()->instance(ExchangeRepository::class, $repository);
    $this->actingAs(User::factory()->create())->get(route('markets.index'))
        ->assertOk()->assertSee('Luno (API access required)');
    $this->getJson(route('markets.options', 'luno'))->assertStatus(422)->assertJsonPath('code', 'authentication_required');
});

it('hides and rejects an exchange removed from CCXT while retaining its subscriptions', function () {
    $exchange = Exchange::query()->create(['class' => 'removed_adapter', 'name' => 'Retired Exchange', 'config' => '{}']);
    $user = User::factory()->create();
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    $subscription = MarketSubscription::query()->create(['user_id' => $user->user_id, 'market_id' => $market->market_id, 'active' => true]);
    $this->actingAs($user)->get(route('markets.index'))->assertOk()->assertSee('Retired Exchange')->assertSee('BTC/USD')
        ->assertDontSee('<option value="removed_adapter"', false);
    $this->getJson(route('markets.options', 'removed_adapter'))->assertStatus(422)->assertJsonPath('code', 'exchange_removed');
    expect($subscription->fresh()->active)->toBeTrue()->and($exchange->fresh())->not->toBeNull();
});

it('refreshes twice without replacing settings users or removed exchange history and supports a read-only check', function () {
    $path = sys_get_temp_dir().'/ccxt-refresh-'.bin2hex(random_bytes(8)).'.json';
    $registry = temporaryExchangeRegistry($path);
    app()->instance(ExchangeMetadata::class, $registry);
    $bundle = ExchangeMetadataBuilder::read(resource_path('data/ccxt-exchanges.json'));
    $builder = Mockery::mock(ExchangeMetadataBuilder::class);
    $builder->shouldReceive('build')->times(3)->andReturn($bundle);
    app()->instance(ExchangeMetadataBuilder::class, $builder);
    $user = User::factory()->create();
    $exchange = Exchange::query()->create(['class' => 'kraken', 'name' => 'My Kraken', 'config' => '{"timeout":43210}']);
    $removed = Exchange::query()->create(['class' => 'removed_adapter', 'name' => 'Retired', 'config' => '{}']);
    $before = $exchange->fresh()->getAttributes();
    $userBefore = $user->fresh()->getAttributes();
    try {
        $this->artisan('trademinator:refresh-exchanges', ['--check' => true, '--json' => true])->assertSuccessful();
        expect(is_file($path))->toBeFalse()->and(Exchange::query()->count())->toBe(2);
        expect($exchange->fresh()->getAttributes())->toBe($before);
        $before['region_prior'] = 'US';
        unset($before['updated_at']);
        foreach ([1, 2] as $iteration) {
            $this->artisan('trademinator:refresh-exchanges')->assertSuccessful();
            expect(Exchange::query()->count())->toBe(count(ccxt\Exchange::$exchanges) + 1)
                ->and(array_diff_key($exchange->fresh()->getAttributes(), ['updated_at' => true]))->toBe($before)
                ->and($user->fresh()->getAttributes())->toBe($userBefore)
                ->and($removed->fresh())->not->toBeNull()
                ->and($registry->all()['kraken']['access']['state'])->toBe('public');
        }
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('pauses unreviewed feeds before network access and preserves the active subscription', function () {
    $exchange = Exchange::query()->create(['class' => 'kraken', 'name' => 'Kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    $subscription = MarketSubscription::query()->create(['user_id' => User::factory()->create()->user_id, 'market_id' => $market->market_id, 'active' => true]);
    $feed = MarketFeed::query()->create(['market_id' => $market->market_id, 'lease_token' => 'lease', 'lease_until' => now()->addMinutes(15)]);
    $metadata = Mockery::mock(ExchangeMetadata::class);
    $metadata->shouldReceive('assertUsable')->once()->andThrow(new MarketCatalogException('access_unknown', 'Pending access review.'));
    $repository = Mockery::mock(ExchangeRepository::class);
    $repository->shouldNotReceive('setExchange');
    app()->instance(ExchangeRepository::class, $repository);
    (new CollectMarketFeed($market->market_id, 'lease'))->handle(
        app(CandlePeriodReevaluation::class), app(MarketDataSynchronizer::class),
        Mockery::mock(TickerRepository::class), $metadata);
    expect($feed->fresh()->status)->toBe('blocked')->and($feed->fresh()->lease_token)->toBeNull()
        ->and($feed->fresh()->last_error)->toBe('Pending access review.')
        ->and($feed->fresh()->next_pull_at->isFuture())->toBeTrue()
        ->and($subscription->fresh()->active)->toBeTrue();
});
