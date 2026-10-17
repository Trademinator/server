<?php

use App\Domain\Intelligence\DashboardData;
use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Intelligence\SignalJournal;
use App\Domain\Research\FeatureSchema;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSignal;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function degradedSignalDisplayFixture(User $user, int $ageMinutes = 15, string $action = 'hodl'): MarketSubscription
{
    $exchange = Exchange::query()->create(['name' => 'Bitso', 'class' => 'bitso', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->getKey(),
        'symbol' => 'ATOM/USD', 'tick_size' => '0.01']);
    MarketFeed::query()->create(['market_id' => $market->getKey(), 'selected_period' => '15m', 'status' => 'active']);
    $subscription = MarketSubscription::query()->create(['user_id' => $user->getKey(),
        'market_id' => $market->getKey(), 'active' => true]);

    $dataset = (string) Str::uuid7();
    $model = (string) Str::uuid7();
    $key = ModelStore::marketKey('bitso', 'ATOM/USD', '15m');
    DB::table('research_datasets')->insert(['dataset_id' => $dataset, 'manifest' => '{}', 'created_at' => now()]);
    $report = [
        'model_id' => $model, 'dataset_id' => $dataset, 'status' => 'abstaining',
        'validation_version' => IntelligenceTrainer::VERSION,
        'rubix_ml_major' => \Rubix\ML\VERSION,
        'trained_as_of_ms' => now()->subHour()->getTimestampMs(),
        'outcome' => ['status' => 'abstaining', 'reason' => 'holdout_failed', 'schema' => 'core'],
        'action' => ['status' => 'ready', 'reason' => 'validated'],
        'keys' => FeatureSchema::keys('core'), 'label_definition' => ['horizon' => 13],
        'settings' => config('intelligence.knn'), 'k' => 7, 'action_k' => 64,
        'selection' => ['candidates' => []], 'holdout' => null,
        'knowledge_rows' => 250, 'patterns' => [],
    ];
    DB::table('intelligence_models')->insert(['model_id' => $model, 'dataset_id' => $dataset,
        'market_key' => $key, 'status' => 'abstaining', 'generation_key' => null,
        'sha256' => str_repeat('a', 64), 'report' => json_encode($report, JSON_THROW_ON_ERROR),
        'created_at' => now()]);
    DB::table('intelligence_heads')->insert(['market_key' => $key, 'model_id' => $model, 'updated_at' => now()]);

    $decision = now()->subMinutes($ageMinutes)->getTimestampMs();
    $signal = [
        'action' => $action, 'reason' => 'degraded_action_only', 'confidence' => 0.6393635744,
        'neighbors' => 64, 'effective_neighbors' => 60.39435353, 'similarity' => 0.8188,
        'decision_at_ms' => $decision, 'regime' => 'neutral', 'patterns' => [],
        'scoring' => ['decision_mode' => 'degraded_action_only'],
        'outcome_knn' => ['outcome' => 'neutral', 'reason' => 'holdout_failed', 'confidence' => 0,
            'neighbors' => 0, 'effective_neighbors' => 0,
            'sources' => ['effective_weights' => ['algorithmic' => 0, 'human' => 0]]],
        'action_knn' => ['action' => $action, 'reason' => 'supported', 'confidence' => 0.6393635744,
            'neighbors' => 64, 'effective_neighbors' => 60.39435353,
            'sources' => ['effective_weights' => ['algorithmic' => 1, 'human' => 0]]],
        // A recorded payload may have an old explanation: the Web UI must use current semantics.
        'explanation' => 'The model is currently unavailable. No directional signal is being issued.',
    ];
    MarketSignal::query()->create(['market_id' => $market->getKey(),
        'snapshot_key' => hash('sha256', $model.$decision), 'period' => '15m',
        'model_id' => $model, 'decision_at_ms' => $decision,
        'recorded_at_ms' => now()->getTimestampMs(), 'is_change' => true,
        'action' => $action, 'reason' => 'degraded_action_only', 'payload' => $signal]);

    return $subscription;
}

it('shows a fresh Action-only HOLD with its actual neighbours, even when the overall model abstains', function () {
    $this->travelTo('2026-10-09 13:00:00 UTC');
    config(['intelligence.max_signal_age_periods' => 2, 'intelligence.max_signal_age_seconds' => 86400]);
    $user = User::factory()->create();
    $subscription = degradedSignalDisplayFixture($user);

    $this->actingAs($user)->get(route('markets.intelligence', $subscription->getKey()))
        ->assertOk()->assertSee('Action<strong>HOLD</strong>', false)
        ->assertSee('degraded mode · Action KNN only')
        ->assertSee('Action-only fallback result')
        ->assertSee('Only Action KNN has sufficient evidence')
        ->assertSee('Holdout Failed')->assertSee('60.4')
        ->assertSee('Action KNN: Ready')->assertSee('Outcome KNN: Not ready')
        ->assertDontSee('The model is currently unavailable. No directional signal is being issued.');

    $card = app(DashboardData::class)->markets($user)['cards']->first();
    expect($card['signal_fresh'])->toBeTrue()
        ->and($card['label'])->toBe('HOLD (Action-only)');

    $this->get('/dashboard')->assertOk()->assertSee('HOLD (Action-only)')
        ->assertSee('Only Action KNN has sufficient evidence')
        ->assertDontSee('The model is currently unavailable. No directional signal is being issued.');
});

it('shows a supported Action-only BUY in the intelligence page and dashboard', function () {
    $this->travelTo('2026-10-09 13:00:00 UTC');
    config(['intelligence.max_signal_age_periods' => 2, 'intelligence.max_signal_age_seconds' => 86400]);
    $user = User::factory()->create();
    $subscription = degradedSignalDisplayFixture($user, action: 'buy');

    $this->actingAs($user)->get(route('markets.intelligence', $subscription->getKey()))
        ->assertOk()->assertSee('Action<strong>BUY</strong>', false)
        ->assertSee('degraded mode · Action KNN only')
        ->assertSee('Action-only fallback result');
    $card = app(DashboardData::class)->markets($user)['cards']->first();
    expect($card['signal_fresh'])->toBeTrue()
        ->and($card['label'])->toBe('BUY (Action-only)');
    $this->get('/dashboard')->assertOk()->assertSee('BUY (Action-only)');
});

it('does not reuse an expired Action-only recorded observation as a current signal', function () {
    $this->travelTo('2026-10-09 13:00:00 UTC');
    config(['intelligence.max_signal_age_periods' => 2, 'intelligence.max_signal_age_seconds' => 86400]);
    $user = User::factory()->create();
    $subscription = degradedSignalDisplayFixture($user, ageMinutes: 45);

    $this->actingAs($user)->get(route('markets.intelligence', $subscription->getKey()))
        ->assertOk()->assertSee('Action<strong>WAITING</strong>', false)
        ->assertDontSee('HOLD (Action-only)');

    $card = app(DashboardData::class)->markets($user)['cards']->first();
    expect($card['signal_fresh'])->toBeFalse()
        ->and($card['label'])->toBe('Waiting for evidence');
});

it('shows the underlying degraded Action KNN proposal while signal memory waits for displacement', function () {
    $this->travelTo('2026-10-09 13:00:00 UTC');
    config(['intelligence.max_signal_age_periods' => 2, 'intelligence.max_signal_age_seconds' => 86400]);
    $user = User::factory()->create();
    $subscription = degradedSignalDisplayFixture($user, action: 'buy');
    $recorded = $subscription->market->latestSignal()->firstOrFail();
    $payload = $recorded->payload;
    $payload['action'] = 'hodl';
    $payload['reason'] = 'awaiting_price_displacement';
    $payload['confidence'] = 0.0;
    $payload['scoring']['signal_memory'] = [
        'status' => 'pending',
        'raw_action' => 'buy',
        'raw_reason' => 'degraded_action_only',
    ];
    $recorded->update(['action' => 'hodl', 'reason' => 'awaiting_price_displacement', 'payload' => $payload]);

    $this->actingAs($user)->get(route('markets.intelligence', $subscription->getKey()))
        ->assertOk()
        ->assertSee('Action<strong>BUY</strong>', false)
        ->assertSee('degraded mode · Action KNN only · awaiting price displacement')
        ->assertDontSee('Action<strong>WAITING</strong>', false);
});

it('keeps abstentions distinct from supported degraded decisions', function () {
    expect(SignalJournal::label('hodl', 'supported'))->toBe('HOLD')
        ->and(SignalJournal::label('hodl', 'degraded_action_only'))->toBe('HOLD (Action-only)')
        ->and(SignalJournal::label('sell', 'degraded_action_only'))->toBe('SELL (Action-only)')
        ->and(SignalJournal::hasDecision('buy', 'degraded_action_only'))->toBeTrue()
        ->and(SignalJournal::label('buy', 'degraded_action_only'))->toBe('BUY (Action-only)')
        ->and(SignalJournal::label('hodl', 'knn_abstention'))->toBe('Waiting for evidence')
        ->and(SignalJournal::explain('degraded_action_only'))->toContain('Outcome KNN cannot confirm');
});
