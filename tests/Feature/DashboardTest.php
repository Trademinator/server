<?php

use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Research\FeatureSchema;
use App\Domain\Intelligence\ModelStore;
use App\Models\MarketFeed;
use App\Models\MarketSignal;
use App\Models\MarketSubscription;
use App\Models\Ticker;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

test('guests are redirected to the login page', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('authenticated users can visit the dashboard', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get('/dashboard')->assertOk()->assertHeader('X-Trademinator-Trace')->assertDontSee('Server administration');

    config(['operations.owner_uuid' => $user->getKey()]);
    $this->get('/dashboard')->assertOk()->assertHeader('X-Trademinator-Trace')->assertSee('Server owner');
    $this->get(route('owner.overview'))->assertOk();
});

it('requires verification and scopes dashboard charts to active subscriptions of the current user', function () {
    $this->freezeTime();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $signal = MarketSignal::factory()->create();
    $market = $signal->market;
    $market->exchange->update(['name' => '<script>privateExchange()</script>']);
    MarketFeed::query()->create(['market_id' => $market->getKey(), 'selected_period' => '1m', 'status' => 'active']);
    $sub = MarketSubscription::query()->create(['user_id' => $owner->getKey(), 'market_id' => $market->getKey(), 'active' => true]);
    $datasetId = (string) Str::uuid();
    $modelId = (string) Str::uuid();
    $marketKey = ModelStore::marketKey($market->exchange->class, $market->symbol, '1m');
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
    $url = route('dashboard.chart', $sub->getKey());
    $this->getJson($url)->assertUnauthorized();
    $this->actingAs(User::factory()->unverified()->create())->get('/dashboard')->assertRedirect(route('verification.notice'));
    $this->actingAs($other)->getJson($url)->assertNotFound();
    $this->get(route('dashboard', ['subscription' => $sub->getKey()]))->assertOk()->assertDontSee('BTC/USD');
    $this->get('/dashboard')->assertOk()->assertDontSee('BTC/USD')->assertDontSee('privateExchange');
    $this->actingAs($owner)->get('/dashboard')->assertOk()->assertSee('BTC/USD')->assertSee('Waiting for evidence')
        ->assertSee('aria-label="Outcome KNN: Ready"', false)->assertSee('Action KNN: Not ready')
        ->assertSee('does not enable trading')->assertSee('Unknown')->assertSee('&lt;script&gt;', false)
        ->assertDontSee('<script>privateExchange()</script>', false)->assertHeader('Cache-Control', 'no-store, private');
    $this->getJson($url)->assertOk()->assertJsonPath('subscription_id', $sub->getKey());
    $sub->update(['active' => false]);
    $this->getJson($url)->assertNotFound();
    $this->get('/dashboard')->assertDontSee('BTC/USD');
});

it('renders only closed valid candles in the selected feed period and never trades or subscribes on a read', function () {
    $this->travelTo('2026-09-29 12:10:00 UTC');
    Queue::fake();
    $owner = User::factory()->create();
    $signal = MarketSignal::factory()->create();
    $market = $signal->market;
    MarketFeed::query()->create(['market_id' => $market->getKey(), 'selected_period' => '1m', 'status' => 'active']);
    $sub = MarketSubscription::query()->create(['user_id' => $owner->getKey(), 'market_id' => $market->getKey(), 'active' => true]);
    foreach ([[-240000, '1m', 95], [-180000, '1m', 0], [-120000, '1m', 100], [-60000, '1m', 101], [0, '1m', 999], [-86400000, '1d', 888]] as [$offset, $period, $close]) {
        Ticker::query()->create(['exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => $period,
            'microtimestamp' => now()->getTimestampMs() + $offset,
            'payload' => json_encode(['open' => $close, 'close' => $close, 'high' => $close, 'low' => $close, 'volume' => 0])]);
    }
    $this->actingAs($owner)->getJson(route('dashboard.chart', $sub->getKey()))->assertOk()
        ->assertJsonCount(3, 'chart.series')->assertJsonPath('chart.series.2.close', 101)
        ->assertJsonPath('chart.stale', false)->assertJsonPath('chart.series.2.volume', 0)
        ->assertJsonPath('chart.invalid_candles', 1)->assertJsonPath('chart.gaps', 1);
    $this->get('/dashboard')->assertOk()->assertSee('Potential training rows');
    $this->assertDatabaseCount('market_subscriptions', 1);
    $this->assertDatabaseCount('market_signals', 1);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('keeps the previous visit boundary stable during refresh and counts only this users new changes', function () {
    $this->freezeTime();
    $owner = User::factory()->create();
    $owner->forceFill(['dashboard_seen_at_ms' => now()->subHour()->getTimestampMs()])->save();
    $signal = MarketSignal::factory()->create();
    MarketSubscription::query()->create(['user_id' => $owner->getKey(), 'market_id' => $signal->market_id, 'active' => true]);
    MarketSignal::factory()->create(['market_id' => $signal->market_id, 'recorded_at_ms' => now()->subHours(2)->getTimestampMs()]);
    MarketSignal::factory()->create(['market_id' => $signal->market_id, 'is_change' => false]);
    MarketSignal::factory()->create();
    $this->actingAs($owner)->get('/dashboard')->assertOk()->assertViewHas('changeCount', 1);
    $this->get('/dashboard')->assertOk()->assertViewHas('changeCount', 1);
    expect($owner->fresh()->dashboard_seen_at_ms)->toBe(now()->getTimestampMs());
});
it('paginates dashboard candles in both directions within the captured browsing ceiling', function () {
    $this->travelTo('2026-09-30 12:30:00 UTC');
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->getKey(), 'dashboard.chart_page_size' => 10, 'human_training.enabled' => true]);
    $signal = MarketSignal::factory()->create();
    $market = $signal->market;
    MarketFeed::query()->create(['market_id' => $market->getKey(), 'selected_period' => '1m', 'status' => 'active']);
    $sub = MarketSubscription::query()->create(['user_id' => $owner->getKey(), 'market_id' => $market->getKey(), 'active' => true]);

    $start = now()->subMinutes(30)->getTimestampMs();
    for ($i = 0; $i < 20; $i++) {
        $price = 100 + $i;
        Ticker::query()->create(['exchange' => $market->exchange->class, 'symbol' => $market->symbol, 'period' => '1m',
            'microtimestamp' => $start + $i * 60000,
            'payload' => json_encode(['open' => $price, 'close' => $price, 'high' => $price, 'low' => $price, 'volume' => 1])]);
    }

    $initial = $this->actingAs($owner)->getJson(route('dashboard.chart', $sub->getKey()))->assertOk()->json('chart');
    $until = $initial['last_closed_at_ms'];
    expect($until)->not->toBeNull();

    $earliest = $this->getJson(route('dashboard.chart.history', $sub->getKey()).'?'.http_build_query([
        'direction' => 'earliest', 'until_ms' => $until,
    ]))->assertOk()->assertJsonCount(10, 'chart.series')->assertJsonPath('chart.has_older', false)
        ->assertJsonPath('chart.has_newer', true)->json('chart');

    Ticker::query()->create(['exchange' => $market->exchange->class, 'symbol' => $market->symbol, 'period' => '1m',
        'microtimestamp' => $until,
        'payload' => json_encode(['open' => 999, 'close' => 999, 'high' => 999, 'low' => 999, 'volume' => 1])]);

    $newer = $this->getJson(route('dashboard.chart.history', $sub->getKey()).'?'.http_build_query([
        'direction' => 'newer',
        'anchor_ms' => $earliest['series'][array_key_last($earliest['series'])]['time'] * 1000,
        'until_ms' => $until,
    ]))->assertOk()->assertJsonCount(10, 'chart.series')->assertJsonPath('chart.has_newer', false)->json('chart');

    expect(max(array_column($newer['series'], 'time')) * 1000)->toBeLessThan($until);

    $this->get(route('dashboard', ['subscription' => $sub->getKey()]))->assertOk()
        ->assertSee('Earliest data')->assertSee('Show Server decisions')
        ->assertSee('Show Action KNN decisions')->assertSee('Show Outcome KNN decisions')->assertSee('>Train<', false)
        ->assertSee('Full intelligence report');
});
