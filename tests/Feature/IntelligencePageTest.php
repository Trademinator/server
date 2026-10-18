<?php

use App\Domain\Intelligence\IntelligenceTrainer;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

it('restricts intelligence pages to the active subscription owner and escapes market text', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $exchange = Exchange::query()->create(['name' => '<script>alert(1)</script>', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1m', 'status' => 'active']);
    $subscription = MarketSubscription::query()->create(['user_id' => $owner->user_id, 'market_id' => $market->market_id, 'active' => true]);
    $url = route('markets.intelligence', $subscription->getKey());

    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs($other)->get($url)->assertNotFound();
    $this->actingAs($owner)->get($url)->assertSee('Action<strong>WAITING</strong>', false)->assertSee('0.0%')
        ->assertSee('Training has not completed')->assertSee('What is missing?')
        ->assertSee('Outcome KNN: Not ready — No model built yet')
        ->assertSee('Action KNN: Not ready — No model built yet')
        ->assertSee('M2/M3 jobs alone do not train an M4 model.')
        ->assertSee('fallback zeros')->assertDontSee('ETA')
        ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    $subscription->update(['active' => false]);
    $this->get($url)->assertNotFound();
});

it('shows same-pair peer exchanges and a subscribe action for an unfollowed peer', function () {
    $user = User::factory()->create();
    $kraken = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $kraken->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1m', 'status' => 'active']);
    $subscription = MarketSubscription::query()->create(['user_id' => $user->user_id, 'market_id' => $market->market_id, 'active' => true]);

    $bitso = Exchange::query()->create(['name' => 'Bitso', 'class' => 'bitso', 'config' => '{}']);
    $peer = Market::query()->create(['exchange_id' => $bitso->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketFeed::query()->create(['market_id' => $peer->market_id, 'selected_period' => '1m', 'status' => 'idle']);

    $reviewUrl = route('markets.suggestions.review', ['exchange' => 'bitso', 'symbol' => 'BTC/USD']);
    $this->actingAs($user)->get(route('markets.intelligence', $subscription->getKey()))
        ->assertOk()->assertSee('Same pair on other exchanges')->assertSee('Bitso')->assertSee('Available')
        ->assertSee($reviewUrl);

    $this->get($reviewUrl)->assertOk()->assertSee('Available market')->assertSee('Not subscribed')
        ->assertSee('Subscribe to BTC/USD');
});

it('renders actual history counts and failed validation requirements for an abstaining model', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $path = sys_get_temp_dir().'/trademinator-page-'.Str::uuid7();
    config(['research.path' => $path.'/research', 'intelligence.path' => $path.'/models',
        'intelligence.patterns.enabled' => true]);
    $owner = User::factory()->create();
    config(['human_training.trainer_uuids' => [$owner->user_id]]);
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1m', 'status' => 'active']);
    $subscription = MarketSubscription::query()->create(['user_id' => $owner->user_id, 'market_id' => $market->market_id, 'active' => true]);
    try {
        $manifest = IntelligenceFixtures::snapshot(227);
        app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
        IntelligenceFixtures::feature(249, 0.0);

        $response = $this->actingAs($owner)->get(route('markets.intelligence', $subscription->getKey()));

        $response->assertOk()->assertSee('227 retained examples')
            ->assertSee('Outcome + Action scoring')->assertSee('Human influence follows')
            ->assertSee('Outcome KNN: Not ready')->assertSee('Action KNN: Not ready')
            ->assertSee('Outcome KNN')->assertSee('Action KNN')->assertSee('Human weight')
            ->assertSee('Not recorded')->assertSee('No current recorded Outcome/Action scoring is available.')
            ->assertSee('227 out of 380')->assertSee('Outcome K selection requirements')
            ->assertSee(route('human-training.index', [
                'exchange' => $exchange->class, 'symbol' => $market->symbol, 'period' => '1m',
            ]))
            ->assertSee('minimum validation sample')->assertSee('No tuning results are available yet.')
            ->assertSee('Pattern training history')->assertSee('0 out of 100')
            ->assertSee('<progress', false)->assertSee('role="progressbar"', false)
            ->assertDontSee('Build performance');

        config(['operations.owner_uuid' => $owner->user_id]);
        $this->actingAs($owner)->get(route('markets.intelligence', $subscription->getKey()))
            ->assertOk()->assertSee('Build performance')->assertSee('Total intelligence build')
            ->assertSee('KNN tuning')->assertSee('Model persistence');
    } finally {
        File::deleteDirectory($path);
    }
});
