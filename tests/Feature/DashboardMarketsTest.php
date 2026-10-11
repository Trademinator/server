<?php

use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Research\FeatureSchema;
use App\Models\MarketFeed;
use App\Models\MarketSignal;
use App\Models\MarketSubscription;
use App\Models\Ticker;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function followedDashboardMarket(User $user, string $symbol, string $exchange, string $period): MarketSubscription
{
    $market = MarketSignal::factory()->create()->market;
    $market->update(['symbol' => $symbol]);
    $market->exchange->update(['name' => ucfirst($exchange), 'class' => $exchange]);
    MarketFeed::query()->create(['market_id' => $market->getKey(), 'selected_period' => $period, 'status' => 'ready']);

    return MarketSubscription::query()->create(['user_id' => $user->getKey(), 'market_id' => $market->getKey(), 'active' => true]);
}

function dashboardModel(MarketSubscription $subscription, array $overrides = []): void
{
    $market = $subscription->market;
    $dataset = (string) Str::uuid();
    $model = (string) Str::uuid();
    $key = ModelStore::marketKey($market->exchange->class, $market->symbol, $market->feed->selected_period);
    DB::table('research_datasets')->insert(['dataset_id' => $dataset, 'manifest' => '{}', 'created_at' => now()]);
    $keys = FeatureSchema::keys('core');
    DB::table('intelligence_models')->insert(['model_id' => $model, 'dataset_id' => $dataset, 'market_key' => $key,
        'status' => 'ready', 'sha256' => str_repeat('0', 64), 'created_at' => now(),
        'report' => json_encode(array_replace(['model_id' => $model, 'dataset_id' => $dataset, 'status' => 'ready',
            'validation_version' => IntelligenceTrainer::VERSION, 'trained_as_of_ms' => now()->getTimestampMs(),
            'keys' => $keys, 'training_data' => ['schema' => 'core'],
            'outcome' => ['status' => 'ready', 'reason' => 'validated', 'schema' => 'core'],
            'action' => ['status' => 'abstaining', 'reason' => 'action_model_unavailable']], $overrides))]);
    DB::table('intelligence_heads')->insert(['market_key' => $key, 'model_id' => $model, 'updated_at' => now()]);
}

it('counts current validated models across all followed markets regardless of page or search', function () {
    $this->freezeTime();
    config(['dashboard.page_size' => 1]);
    $user = User::factory()->create();
    $first = followedDashboardMarket($user, 'BTC/USD', 'kraken', '15m');
    dashboardModel($first);
    dashboardModel(followedDashboardMarket($user, 'ETH/USD', 'coinbase', '1h'), ['trained_as_of_ms' => now()->subDays(30)->getTimestampMs()]);
    dashboardModel(followedDashboardMarket($user, 'ADA/USD', 'bitso', '5m'), ['validation_version' => 'old']);
    $actionReady = ['status' => 'ready', 'reason' => 'validated'];
    dashboardModel(followedDashboardMarket($user, 'XRP/USD', 'kraken', '1m'), [
        'outcome' => ['status' => 'abstaining', 'reason' => 'holdout_failed'], 'action' => $actionReady,
    ]);
    dashboardModel(followedDashboardMarket($user, 'SOL/USD', 'kraken', '1m'), ['action' => $actionReady]);
    dashboardModel(followedDashboardMarket(User::factory()->create(), 'PRIVATE/USD', 'kraken', '1m'));
    $inactive = followedDashboardMarket($user, 'OLD/USD', 'kraken', '1m');
    $inactive->update(['active' => false]);
    dashboardModel($inactive);

    foreach (['/dashboard', '/dashboard?page=2', '/dashboard?q=ETH', '/dashboard?q=no-match'] as $url) {
        $this->actingAs($user)->get($url)->assertViewHas('totals', ['followed' => 5, 'outcome' => 2, 'action' => 2, 'coingecko' => 0])
            ->assertSee('Outcome KNN: 2 of 5 ready')->assertSee('Action KNN: 2 of 5 ready')
            ->assertSee('Across all markets you follow')->assertDontSee('For the markets on this page');
    }
});

it('shows an Action-only ready model consistently in market lists and dashboard search results', function () {
    $this->freezeTime();
    config(['human_training.enabled' => true, 'human_training.candle_enabled' => true]);
    $user = User::factory()->create();
    $subscription = followedDashboardMarket($user, 'XRP/USD', 'kraken', '1m');
    dashboardModel($subscription, [
        'outcome' => ['status' => 'abstaining', 'reason' => 'holdout_failed'],
        'action' => ['status' => 'ready', 'reason' => 'validated'],
    ]);

    $this->actingAs($user)->get('/markets')
        ->assertSee('Outcome KNN: Not ready')->assertSee('Action KNN: Ready')
        ->assertDontSee('aria-label="Outcome KNN: Ready"', false);

    $html = $this->getJson('/dashboard?q=XRP')->assertJsonPath('count', 1)->json('html');
    expect($html)->toContain('Outcome KNN: Not ready', 'Action KNN: Ready')
        ->not->toContain('aria-label="Outcome KNN: Ready"');

    config(['operations.owner_uuid' => $user->getKey()]);
    $this->withSession(['auth.password_confirmed_at' => time()]);
    $model = DB::table('intelligence_heads')->value('model_id');
    foreach (['/owner/intelligence', '/owner/intelligence/'.$model] as $url) {
        $this->get($url)->assertSee('Outcome KNN: Not ready')->assertSee('Action KNN: Ready')
            ->assertDontSee('aria-label="Outcome KNN: Ready"', false);
    }
    $this->get('/owner')->assertViewHas('modelTotals', ['total' => 1, 'outcome' => 0, 'action' => 1, 'coingecko' => 0])
        ->assertSee('Outcome KNN: 0 of 1 ready')->assertSee('Action KNN: 1 of 1 ready');
});

it('searches partial pairs exchange names and periods across pages without leaking other subscriptions', function () {
    config(['dashboard.page_size' => 1]);
    $user = User::factory()->create();
    followedDashboardMarket($user, 'BTC/USD', 'kraken', '15m');
    followedDashboardMarket($user, 'ETH/USDT', 'coinbase', '1h');
    followedDashboardMarket(User::factory()->create(), 'PRIVATE/USDT', 'coinbase', '1h');

    foreach (['eth/us', 'OINB', '1H'] as $term) {
        $response = $this->actingAs($user)->getJson('/dashboard?q='.urlencode($term))->assertJsonPath('count', 1)
            ->assertHeader('Cache-Control', 'no-store, private');
        expect($response->json('html'))->toContain('ETH/USDT')->not->toContain('BTC/USD', 'PRIVATE/USDT', 'Needs attention');
        expect($response->json())->not->toHaveKey('attention_count');
    }
    $this->getJson('/dashboard?q=%25')->assertJsonPath('count', 0);
    $this->getJson('/dashboard?q=_')->assertJsonPath('count', 0);
    $this->getJson('/dashboard?q=not-found')->assertJsonPath('count', 0);
    $this->getJson('/dashboard?q=USD&page=2')->assertJsonPath('count', 2);
    $this->get('/dashboard')->assertSee('data-dashboard-markets', false)->assertSee('dashboard-exchange-logo', false)
        ->assertSee('Search your markets');
});

it('lets the owner browse every active subscription while keeping non owners limited to their own markets', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    config(['operations.owner_uuid' => $owner->getKey()]);
    followedDashboardMarket($owner, 'BTC/USD', 'kraken', '15m');
    followedDashboardMarket($other, 'ETH/USD', 'coinbase', '1h');

    $this->actingAs($owner)->get('/dashboard')->assertOk()
        ->assertViewHas('scope', 'all')->assertSee('All followed markets')
        ->assertSee('BTC/USD')->assertSee('ETH/USD')->assertSee('Mine')->assertSee('User market');

    $this->get('/dashboard?scope=mine')->assertOk()->assertViewHas('scope', 'mine')
        ->assertSee('BTC/USD')->assertDontSee('ETH/USD')->assertSee('Markets you follow');

    $this->actingAs($other)->get('/dashboard?scope=all')->assertOk()->assertViewHas('scope', 'mine')
        ->assertSee('ETH/USD')->assertDontSee('BTC/USD')->assertDontSee('All markets');
});

it('stores favourites per viewer and applies the favourite filter inside the selected scope', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    config(['operations.owner_uuid' => $owner->getKey()]);
    $own = followedDashboardMarket($owner, 'BTC/USD', 'kraken', '15m');
    $otherSubscription = followedDashboardMarket($other, 'ETH/USD', 'coinbase', '1h');

    $this->actingAs($owner)->put(route('dashboard.favorites.store', [
        'subscription' => $otherSubscription->getKey(), 'scope' => 'all',
    ]))->assertRedirect(route('dashboard', ['scope' => 'all']));

    $this->assertDatabaseHas('dashboard_market_favorites', [
        'user_id' => $owner->getKey(), 'market_subscription_id' => $otherSubscription->getKey(),
    ]);
    $this->get('/dashboard?scope=all&favorites=1')->assertOk()->assertSee('ETH/USD')->assertDontSee('BTC/USD')
        ->assertSee('★', false);
    $this->get('/dashboard?scope=mine&favorites=1')->assertOk()->assertDontSee('ETH/USD')->assertDontSee('BTC/USD');

    $this->put(route('dashboard.favorites.store', ['subscription' => $own->getKey()]))->assertRedirect();
    $this->get('/dashboard?scope=mine&favorites=1')->assertSee('BTC/USD')->assertDontSee('ETH/USD');

    $this->delete(route('dashboard.favorites.destroy', ['subscription' => $otherSubscription->getKey(), 'scope' => 'all']))
        ->assertRedirect(route('dashboard', ['scope' => 'all']));
    $this->assertDatabaseMissing('dashboard_market_favorites', [
        'user_id' => $owner->getKey(), 'market_subscription_id' => $otherSubscription->getKey(),
    ]);

    $this->actingAs($other)->put(route('dashboard.favorites.store', ['subscription' => $own->getKey()]))
        ->assertNotFound();
});

it('exposes detailed collection errors and recovery commands only to the server owner', function () {
    $user = User::factory()->create();
    $sub = followedDashboardMarket($user, 'BTC/USD', 'kraken', '1m');
    $sub->market->feed->update(['status' => 'blocked', 'last_error' => '<script>private-diagnostic</script>']);
    $this->actingAs($user)->get('/dashboard')->assertDontSee('Needs attention')->assertDontSee('private-diagnostic')
        ->assertDontSee('trademinator:refresh-exchanges');
    $this->getJson('/dashboard')->assertJsonMissingPath('attention_count');
    config(['operations.owner_uuid' => strtoupper($user->getKey())]);
    $this->get('/dashboard')->assertSee('Needs attention')->assertSee('Collector blocked:')
        ->assertSee('trademinator:refresh-exchanges --check')->assertSee('&lt;script&gt;private-diagnostic', false)
        ->assertDontSee('<script>private-diagnostic</script>', false);
    $response = $this->getJson('/dashboard')->assertJsonPath('attention_count', 1);
    expect($response->json('html'))->toContain('trademinator:refresh-exchanges --check');
    config(['operations.owner_uuid' => null]);
    expect($this->getJson('/dashboard')->json('html'))->not->toContain('private-diagnostic', 'Needs attention', 'queue:work');
});

it('does not flag healthy ready feeds or current in-progress collection as needing attention', function () {
    $this->travelTo('2026-09-30 04:15:00 UTC');
    $user = User::factory()->create();
    config(['operations.owner_uuid' => $user->getKey()]);
    $sub = followedDashboardMarket($user, 'BTC/USD', 'kraken', '1m');
    Ticker::query()->create(['exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
        'microtimestamp' => now()->subMinute()->getTimestampMs(),
        'payload' => json_encode(['open' => '100', 'high' => '102', 'low' => '99', 'close' => '101', 'volume' => '5'])]);
    $healthyPage = $this->actingAs($user)->get('/dashboard')->assertOk()
        ->assertDontSee('data-dashboard-attention', false);
    expect($healthyPage->getContent())->toMatch('/<button[^>]*data-attention-toggle[^>]*\bhidden\b/')
        ->toContain('xl:grid-cols-3');
    $healthyJson = $this->getJson('/dashboard')->assertJsonPath('attention_count', 0);
    expect($healthyJson->json('html'))->not->toContain('data-dashboard-attention', 'No collection problems detected for the displayed markets.');
    $sub->market->feed->update(['status' => 'queued', 'lease_until' => now()->addMinutes(10)]);
    $this->getJson('/dashboard')->assertJsonPath('attention_count', 0);
    $sub->market->feed->update(['lease_until' => now()->subMinute()]);
    $alertPage = $this->get('/dashboard')->assertOk()->assertSee('data-dashboard-attention', false);
    expect($alertPage->getContent())->not->toMatch('/<button[^>]*data-attention-toggle[^>]*\bhidden\b/');
    expect($alertPage->getContent())->toContain('xl:grid-cols-4');
    $response = $this->getJson('/dashboard')->assertJsonPath('attention_count', 1);
    expect($response->json('html'))->toContain('data-dashboard-attention', 'lease expired', 'trademinator:dispatch-market-feeds');
});

it('collapses an expired queued retry and its dependent symptoms into one recovery item', function () {
    $this->travelTo('2026-10-01 11:30:00 UTC');
    $user = User::factory()->create();
    config(['operations.owner_uuid' => $user->getKey()]);
    $sub = followedDashboardMarket($user, 'BTC/USDC', 'ndax', '1m');
    $sub->market->feed->update([
        'selected_period' => null,
        'status' => 'queued',
        'lease_until' => now()->subMinute(),
        'last_error' => 'No candle period meets the quality, coverage and historical-depth thresholds yet.',
    ]);

    $response = $this->actingAs($user)->getJson('/dashboard')->assertJsonPath('attention_count', 1);
    $html = $response->json('html');

    expect($html)
        ->toContain('lease expired', 'Previous attempt:', 'No candle period meets the quality, coverage and historical-depth thresholds yet.')
        ->not->toContain('Collector queued:', 'No reliable candle period has been selected.');
    expect(substr_count($html, 'trademinator:dispatch-market-feeds'))->toBe(1)
        ->and(substr_count($html, 'queue:work --queue=default'))->toBe(1);
});

it('does not repeat a pending period-selection error as a second missing-period warning', function () {
    $user = User::factory()->create();
    config(['operations.owner_uuid' => $user->getKey()]);
    $sub = followedDashboardMarket($user, 'BTC/USDC', 'ndax', '1m');
    $sub->market->feed->update([
        'selected_period' => null,
        'status' => 'pending',
        'lease_until' => null,
        'last_error' => 'No candle period meets the quality, coverage and historical-depth thresholds yet.',
    ]);

    $html = $this->actingAs($user)->getJson('/dashboard')->assertJsonPath('attention_count', 1)->json('html');

    expect(substr_count($html, 'No candle period meets the quality, coverage and historical-depth thresholds yet.'))->toBe(1)
        ->and($html)->not->toContain('No reliable candle period has been selected.')
        ->and($html)->toContain('trademinator:dispatch-market-feeds');
});
