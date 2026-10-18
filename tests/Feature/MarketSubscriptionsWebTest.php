<?php

use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Research\FeatureSchema;
use App\Domain\Intelligence\ModelStore;
use App\Domain\MarketData\ExchangeMetadata;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use App\Repositories\ExchangeRepository;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('requires sign in and restricts unsubscribe to the subscription owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $exchange = Exchange::query()->create(['name' => 'Demo', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    $item = MarketSubscription::query()->create(['user_id' => $owner->user_id, 'market_id' => $market->market_id, 'active' => true]);

    $this->get(route('markets.index'))->assertRedirect(route('login'));
    $this->actingAs($other)->delete(route('markets.destroy', $item->market_subscription_id))->assertNotFound();
    expect($item->fresh()->active)->toBeTrue();
    $this->actingAs($owner)->delete(route('markets.destroy', $item->market_subscription_id))->assertRedirect(route('markets.index'));
    expect($item->fresh()->active)->toBeFalse();
});

it('requires a verified email before accessing markets', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('markets.index'))
        ->assertRedirect(route('verification.notice'));
    $this->post(route('markets.store'), [])
        ->assertRedirect(route('verification.notice'));
});

it('throttles market subscription writes', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '198.51.100.20']);

    for ($attempt = 0; $attempt < 6; $attempt++) {
        $this->post(route('markets.store'), [])->assertSessionHasErrors(['exchange', 'symbol']);
    }

    $this->post(route('markets.store'), [])->assertStatus(429);
});

it('lets a signed-in user subscribe once to a configured market', function () {
    $user = User::factory()->create();
    $exchange = Exchange::query()->create(['name' => 'Demo', 'class' => 'kraken', 'config' => '{}']);
    $repository = Mockery::mock(ExchangeRepository::class);
    $repository->shouldReceive('setExchange')->once()->with(Mockery::type(Exchange::class), [], Mockery::on(fn (User $actor): bool => $actor->is($user)));
    $repository->shouldReceive('describe')->once()->andReturn([
        'name' => 'Kraken', 'timeframes' => ['1m' => '1m'], 'precisionMode' => \ccxt\TICK_SIZE,
    ]);
    $repository->shouldReceive('spotMarkets')->once()->andReturn([
        'BTC/USD' => ['spot' => true, 'precision' => ['price' => 0.01]],
    ]);
    app()->instance(ExchangeRepository::class, $repository);

    $this->actingAs($user)->post(route('markets.store'), [
        'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'tick_size' => '999',
    ])->assertRedirect(route('markets.index'));
    $this->actingAs($user)->post(route('markets.store'), [
        'exchange' => 'kraken', 'symbol' => 'BTC/USD',
    ])->assertRedirect(route('markets.index'));
    expect(Market::query()->count())->toBe(1)->and(MarketSubscription::query()->count())->toBe(1)
        ->and(bccomp((string) Market::query()->first()->tick_size, '0.01', 18))->toBe(0);
});

it('groups subscriptions in independent collapsible exchanges and links each pair to its review', function () {
    $user = User::factory()->create();
    $zeta = Exchange::query()->create(['name' => 'Zeta ID', 'class' => 'coinbase', 'config' => '{}']);
    $alpha = Exchange::query()->create(['name' => 'Alpha ID', 'class' => 'kraken', 'config' => '{}']);
    foreach ([[$zeta, 'ETH/USD', '1m'], [$alpha, 'BTC/USD', '15m'], [$alpha, 'ADA/USD', '1m']] as [$exchange, $symbol, $period]) {
        $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => $symbol, 'tick_size' => '0.01']);
        MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => $period]);
        MarketSubscription::query()->create(['user_id' => $user->user_id, 'market_id' => $market->market_id, 'active' => $symbol !== 'ADA/USD']);
    }
    $datasetId = (string) Str::uuid();
    $modelId = (string) Str::uuid();
    $marketKey = ModelStore::marketKey('kraken', 'BTC/USD', '15m');
    DB::table('research_datasets')->insert(['dataset_id' => $datasetId, 'manifest' => '{}', 'created_at' => now()]);
    DB::table('intelligence_models')->insert([
        'model_id' => $modelId, 'dataset_id' => $datasetId, 'market_key' => $marketKey, 'status' => 'ready',
        'generation_key' => null, 'sha256' => str_repeat('0', 64), 'report' => json_encode([
            'model_id' => $modelId, 'dataset_id' => $datasetId, 'status' => 'ready',
            'keys' => FeatureSchema::keys('core'), 'training_data' => ['schema' => 'core'],
            'validation_version' => IntelligenceTrainer::VERSION, 'trained_as_of_ms' => now()->getTimestampMs(),
            'rubix_ml_major' => \Rubix\ML\VERSION,
            'outcome' => ['status' => 'ready', 'reason' => 'validated'],
            'action' => ['status' => 'abstaining', 'reason' => 'action_model_unavailable'],
        ], JSON_THROW_ON_ERROR), 'created_at' => now(),
    ]);
    DB::table('intelligence_heads')->insert(['market_key' => $marketKey, 'model_id' => $modelId, 'updated_at' => now()]);
    $metadata = Mockery::mock(ExchangeMetadata::class);
    $metadata->shouldReceive('all')->once()->andReturn([
        'coinbase' => ['name' => 'Zeta Exchange', 'access' => ['state' => 'public'], 'spot' => true, 'fetchOHLCV' => true, 'timeframes' => ['1m'], 'logo' => 'https://example.com/zeta.png'],
        'kraken' => ['name' => 'Alpha Exchange', 'access' => ['state' => 'public'], 'spot' => true, 'fetchOHLCV' => true, 'timeframes' => ['1m'], 'logo' => 'https://example.com/alpha.png'],
    ]);
    app()->instance(ExchangeMetadata::class, $metadata);

    $response = $this->actingAs($user)->get(route('markets.index'))->assertOk();
    $list = explode('<h2>Your markets</h2>', $response->getContent(), 2)[1];
    expect($list)->toContain('https://example.com/alpha.png')->toContain('https://example.com/zeta.png');
    expect(strpos($list, 'Alpha Exchange'))->toBeLessThan(strpos($list, 'ADA/USD'));
    expect(strpos($list, 'ADA/USD'))->toBeLessThan(strpos($list, 'BTC/USD'));
    expect(strpos($list, 'BTC/USD'))->toBeLessThan(strpos($list, 'Zeta Exchange'));
    expect(strpos($list, 'Zeta Exchange'))->toBeLessThan(strpos($list, 'ETH/USD'));
    expect($list)->toContain('BTC/USD · 15m');
    expect(substr_count($list, 'aria-label="Outcome KNN: Ready"'))->toBe(1);
    expect($list)->toMatch('/BTC\\/USD · 15m.*aria-label="Outcome KNN: Ready"/s');
    expect(substr_count($list, 'aria-label="Action KNN: Not ready'))->toBe(3);
    expect(substr_count($list, 'aria-label="CoinGecko context: Not ready'))->toBe(3);
    $response->assertSee('/markets/suggestions/review?exchange=kraken&amp;symbol=ADA%2FUSD', false)
        ->assertSee('/markets/suggestions/review?exchange=kraken&amp;symbol=BTC%2FUSD', false)
        ->assertSee('/markets/suggestions/review?exchange=coinbase&amp;symbol=ETH%2FUSD', false);

    $document = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
    foreach ([[$alpha, 2], [$zeta, 1]] as [$exchange, $count]) {
        $id = 'exchange-markets-'.$exchange->exchange_id;
        $panel = $document->getElementById($id);
        $toggle = $document->querySelector('button[data-bs-target="#'.$id.'"]');
        expect($panel->querySelectorAll('.market-row'))->toHaveCount($count);
        expect($panel->classList->contains('show'))->toBeFalse();
        expect($panel->hasAttribute('data-bs-parent'))->toBeFalse();
        expect($toggle->getAttribute('aria-controls'))->toBe($id);
        expect($toggle->getAttribute('aria-expanded'))->toBe('false');
    }
});
