<?php

use App\Domain\Intelligence\KnowledgeRequestJournal;
use App\Domain\Intelligence\ModelStore;
use App\Jobs\BuildMarketFeatures;
use App\Jobs\BuildOwnerDatasetSnapshot;
use App\Jobs\CollectMarketFeed;
use App\Jobs\TrainMarketIntelligence;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function waterfallOwnerAndMarket(): array
{
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id, 'queue.default' => 'database']);
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id,
        'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1m',
        'status' => 'ready', 'next_pull_at' => now()->addHour()]);
    MarketSubscription::query()->create(['user_id' => $owner->user_id, 'market_id' => $market->market_id,
        'active' => true]);

    return [$owner, $market];
}

it('includes never-trained pairs and explanatory color bars on the EXISTING intelligence page', function () {
    [$owner] = waterfallOwnerAndMarket();
    $this->actingAs($owner)->get(route('owner.intelligence'))
        ->assertOk()->assertSee('Global intelligence report')
        ->assertSee('Subscribed markets awaiting their first model')
        ->assertSee('knowledge-waterfall-bar-red')
        ->assertSee('knowledge-waterfall-panel')
        ->assertSee('knowledge-waterfall-toggle')
        ->assertSee('owner-untrained-market')
        ->assertSee('Never trained')
        ->assertSee('Sync OHLCV')
        ->assertSee('Build features')
        ->assertSee('Analyze labels')
        ->assertSee('Build snapshot')
        ->assertSee('Train AI')
        ->assertSee('Repair gaps / full recovery');
});

it('queues a requested feature build only for the authorized selected market and period', function () {
    Queue::fake();
    [$owner, $market] = waterfallOwnerAndMarket();
    $this->actingAs($owner)->post(route('owner.intelligence.action', $market), ['action' => 'features'])
        ->assertRedirect()->assertSessionHas('status');
    Queue::assertPushed(BuildMarketFeatures::class, fn ($job) => $job->exchange === 'kraken' && $job->symbol === 'BTC/USD' && $job->period === '1m');
});

it('forces exactly the requested feed while respecting its existing lease', function () {
    Queue::fake();
    [$owner, $market] = waterfallOwnerAndMarket();
    $this->actingAs($owner)->post(route('owner.intelligence.action', $market), ['action' => 'collect'])
        ->assertRedirect();
    Queue::assertPushed(CollectMarketFeed::class, fn ($job) => $job->marketId === $market->market_id);
    expect($market->feed->fresh()->status)->toBe('queued');
});

it('can queue an immutable dataset snapshot and model retraining separately', function () {
    Queue::fake();
    [$owner, $market] = waterfallOwnerAndMarket();
    $this->actingAs($owner)->post(route('owner.intelligence.action', $market), ['action' => 'snapshot'])
        ->assertRedirect();
    $this->post(route('owner.intelligence.action', $market), ['action' => 'train'])
        ->assertRedirect();
    Queue::assertPushed(BuildOwnerDatasetSnapshot::class);
    Queue::assertNotPushed(TrainMarketIntelligence::class);
    expect(app(KnowledgeRequestJournal::class)
        ->outstanding('kraken', 'BTC/USD', '1m', 'snapshot'))->toBe(1);
});

it('rejects arbitrary commands, inactive subscriptions, and non-owner requests', function () {
    Queue::fake();
    [$owner, $market] = waterfallOwnerAndMarket();
    $this->actingAs($owner)->post(route('owner.intelligence.action', $market), ['action' => 'php artisan'])
        ->assertSessionHasErrors('action');
    $market->subscriptions()->update(['active' => false]);
    $this->post(route('owner.intelligence.action', $market), ['action' => 'train'])
        ->assertSessionHasErrors('action');
    $this->actingAs(User::factory()->create())
        ->post(route('owner.intelligence.action', $market), ['action' => 'features'])
        ->assertForbidden();
    Queue::assertNotPushed(BuildMarketFeatures::class);
    Queue::assertNotPushed(TrainMarketIntelligence::class);
});

it('keeps the waterfall collapsed below each pair and provides responsive card styling', function () {
    [$owner] = waterfallOwnerAndMarket();
    $this->actingAs($owner)->get(route('owner.intelligence'))
        ->assertOk()
        ->assertSeeInOrder(['Never trained', 'knowledge-waterfall-panel', 'Sync OHLCV'])
        ->assertDontSee('scope="col">Knowledge waterfall', false);
    $css = file_get_contents(resource_path('css/owner.css'));
    expect($css)->toContain('@media (max-width: 760px)')
        ->toContain('.owner-intelligence-table > tbody > .owner-intelligence-model')
        ->toContain('@media (max-width: 430px)')
        ->toContain('.knowledge-waterfall-toggle');
});

it('places the current model waterfall beneath the pair in a full-width table row', function () {
    [$owner, $market] = waterfallOwnerAndMarket();
    $datasetId = (string) Str::uuid();
    $modelId = (string) Str::uuid();
    $marketKey = ModelStore::marketKey('kraken', 'BTC/USD', '1m');
    DB::table('research_datasets')->insert([
        'dataset_id' => $datasetId, 'manifest' => '{}', 'created_at' => now(),
    ]);
    DB::table('intelligence_models')->insert([
        'model_id' => $modelId, 'dataset_id' => $datasetId, 'market_key' => $marketKey,
        'status' => 'abstaining', 'sha256' => str_repeat('0', 64), 'created_at' => now(),
        'report' => json_encode([
            'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
            'status' => 'abstaining', 'reason' => 'no_eligible_k', 'knowledge_rows' => 0,
        ], JSON_THROW_ON_ERROR),
    ]);
    DB::table('intelligence_heads')->insert([
        'market_key' => $marketKey, 'model_id' => $modelId, 'updated_at' => now(),
    ]);

    $response = $this->actingAs($owner)->get(route('owner.intelligence'));
    $response->assertOk()->assertSeeInOrder([
        'owner-intelligence-market-cell', 'owner-intelligence-waterfall',
        'knowledge-waterfall-panel', 'owner-intelligence-diagnostics',
    ]);
    expect(preg_match(
        '/<tr class="owner-intelligence-waterfall">\s*<td colspan="6">\s*<details class="knowledge-waterfall-panel">/',
        $response->getContent()
    ))->toBe(1);
    $css = file_get_contents(resource_path('css/owner.css'));
    expect($css)->toContain('.owner-intelligence-waterfall .knowledge-waterfall-stages')
        ->toContain('.owner-intelligence-waterfall .knowledge-waterfall-actions')
        ->toContain('repeat(auto-fit, minmax(min(100%, 185px), 1fr))');
});
