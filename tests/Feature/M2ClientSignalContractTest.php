<?php

use App\Domain\Intelligence\ModelStore;
use App\Models\ClientApiKey;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSignal;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15T12:00:00Z'));
    config([
        'client.enabled' => true,
        'intelligence.max_signal_age_periods' => 2,
        'intelligence.max_signal_age_seconds' => 86400,
    ]);
});

/** @return array{0: string, 1: MarketSubscription, 2: ?MarketSignal} */
function m2SignalContractMarket(?string $action = 'buy', string $reason = 'supported'): array
{
    $user = User::factory()->create();
    $secret = 'tmk_'.Str::random(43);
    ClientApiKey::query()->create([
        'user_id' => $user->getKey(), 'label' => 'm2', 'prefix' => substr($secret, 0, 12),
        'secret_hash' => hash('sha256', $secret),
    ]);
    $exchange = Exchange::query()->create(['name' => 'Bitso', 'class' => 'bitso', 'config' => '{}']);
    $market = Market::query()->create([
        'exchange_id' => $exchange->getKey(), 'symbol' => 'ATOM/USD', 'tick_size' => '0.001',
    ]);
    MarketFeed::query()->create([
        'market_id' => $market->getKey(), 'selected_period' => '15m', 'status' => 'active',
    ]);
    $subscription = MarketSubscription::query()->create([
        'user_id' => $user->getKey(), 'market_id' => $market->getKey(), 'active' => true,
    ]);
    if ($action === null) {
        return [$secret, $subscription, null];
    }

    $model = (string) Str::uuid();
    $dataset = (string) Str::uuid();
    $key = ModelStore::marketKey('bitso', 'ATOM/USD', '15m');
    DB::table('research_datasets')->insert(['dataset_id' => $dataset, 'manifest' => '{}', 'created_at' => now()]);
    DB::table('intelligence_models')->insert([
        'model_id' => $model, 'dataset_id' => $dataset, 'market_key' => $key,
        'status' => $reason === 'degraded_action_only' ? 'abstaining' : 'ready',
        'generation_key' => null, 'sha256' => str_repeat('a', 64), 'report' => '{}', 'created_at' => now(),
    ]);
    DB::table('intelligence_heads')->insert(['market_key' => $key, 'model_id' => $model, 'updated_at' => now()]);
    $signal = MarketSignal::query()->create([
        'market_id' => $market->getKey(), 'snapshot_key' => hash('sha256', $model), 'period' => '15m',
        'model_id' => $model, 'decision_at_ms' => now()->subMinutes(15)->getTimestampMs(),
        'recorded_at_ms' => now()->getTimestampMs(), 'is_change' => true, 'action' => $action, 'reason' => $reason,
        'payload' => ['confidence' => 0.82, 'reference_price' => '4.123456789012345678',
            'reference_price_source' => 'closed_candle_close', 'prediction_input_basis' => 'completed_candle',
            'scoring' => ['version' => 'outcome-action-matrix-v3', 'action' => ['action' => $action]]],
    ]);

    return [$secret, $subscription, $signal];
}

it('reads the M2 signal contract without settings or private execution inputs and leaves trading state unchanged', function () {
    [$secret, $subscription, $signal] = m2SignalContractMarket();
    $recordedSignal = $signal->fresh()->getAttributes();

    $this->withToken($secret)->getJson('/api/v1/client/markets/'.$subscription->getKey())
        ->assertOk()->assertHeader('X-Trademinator-API-Version', '1')
        ->assertJsonPath('api_version', 1)
        ->assertJsonPath('market.subscription_id', $subscription->getKey())
        ->assertJsonPath('market.active', true)
        ->assertJsonPath('market.exchange.class', 'bitso')
        ->assertJsonPath('market.symbol', 'ATOM/USD')
        ->assertJsonPath('market.period', '15m')
        ->assertJsonPath('market.signal.id', $signal->getKey())
        ->assertJsonPath('market.signal.action', 'buy')
        ->assertJsonPath('market.signal.reason', 'supported')
        ->assertJsonPath('market.signal.period', '15m')
        ->assertJsonPath('market.signal.model_id', $signal->model_id)
        ->assertJsonPath('market.signal.is_fresh', true)
        ->assertJsonPath('market.signal.is_current_model', true)
        ->assertJsonPath('market.signal.decision_at_ms', 1792064700000)
        ->assertJsonPath('market.signal.recorded_at_ms', 1792065600000)
        ->assertJsonPath('market.signal.expires_at_ms', 1792066500000)
        ->assertJsonPath('market.signal.reference_price', '4.123456789012345678')
        ->assertJsonMissingPath('market.intelligence')
        ->assertJsonMissingPath('market.eligible')
        ->assertJsonMissingPath('market.sizing')
        ->assertJsonMissingPath('market.signal.eligible')
        ->assertJsonMissingPath('market.signal.sizing')
        ->assertJsonMissingPath('market.signal.checked_at_ms');

    expect($signal->fresh()->getAttributes())->toBe($recordedSignal);
    $this->assertDatabaseCount('market_signals', 1);
    $this->assertDatabaseCount('client_market_settings', 0);
    $this->assertDatabaseCount('client_execution_reports', 0);
    $this->assertDatabaseCount('client_paper_accounts', 0);
    $this->assertDatabaseCount('client_paper_events', 0);
});

it('preserves supported SELL and HOLD evidence without synthesizing execution permission', function (
    string $action, string $reason, string $expectedAction, string $evidence,
) {
    [$secret, $subscription] = m2SignalContractMarket($action, $reason);

    $this->withToken($secret)->getJson('/api/v1/client/markets/'.$subscription->getKey())
        ->assertOk()->assertJsonPath('market.signal.action', $expectedAction)
        ->assertJsonPath('market.signal.reason', $reason)
        ->assertJsonPath('market.signal.evidence_status', $evidence)
        ->assertJsonPath('market.signal.confidence', 0.82)
        ->assertJsonPath('market.signal.is_fresh', true)
        ->assertJsonPath('market.signal.is_current_model', true)
        ->assertJsonMissingPath('market.signal.eligible')
        ->assertJsonMissingPath('market.signal.sizing');
})->with([
    'full SELL' => ['sell', 'supported', 'sell', 'supported'],
    'full HOLD' => ['hodl', 'supported', 'hold', 'supported'],
    'Action-only SELL' => ['sell', 'degraded_action_only', 'sell', 'degraded'],
    'Action-only HOLD' => ['hodl', 'degraded_action_only', 'hold', 'degraded'],
]);

it('returns a null signal for a subscription with no recorded evidence', function () {
    [$secret, $subscription] = m2SignalContractMarket(null);

    $this->withToken($secret)->getJson('/api/v1/client/markets/'.$subscription->getKey())
        ->assertOk()->assertJsonPath('market.active', true)
        ->assertJsonPath('market.signal', null)
        ->assertJsonMissingPath('market.intelligence');
    $this->assertDatabaseCount('market_signals', 0);
});

it('keeps inactive owned subscriptions readable without rewriting their recorded BUY evidence', function () {
    [$secret, $subscription, $signal] = m2SignalContractMarket();
    $subscription->update(['active' => false]);

    $this->withToken($secret)->getJson('/api/v1/client/markets/'.$subscription->getKey())
        ->assertOk()->assertJsonPath('market.active', false)
        ->assertJsonPath('market.signal.id', $signal->getKey())
        ->assertJsonPath('market.signal.action', 'buy')
        ->assertJsonPath('market.signal.reason', 'supported')
        ->assertJsonPath('market.signal.is_fresh', true)
        ->assertJsonPath('market.signal.is_current_model', true)
        ->assertJsonMissingPath('market.signal.eligible');
    expect($subscription->fresh()->active)->toBeFalse();
});

it('returns 404 for a subscription belonging to another account', function () {
    [$secret, $subscription] = m2SignalContractMarket();
    $subscription->update(['user_id' => User::factory()->create()->getKey()]);

    $this->withToken($secret)->getJson('/api/v1/client/markets/'.$subscription->getKey())
        ->assertNotFound()->assertJsonMissingPath('market');
});

it('returns 401 for an unauthenticated request for subscription intelligence', function () {
    [, $subscription] = m2SignalContractMarket();

    $this->getJson('/api/v1/client/markets/'.$subscription->getKey())
        ->assertUnauthorized()->assertJsonMissingPath('market');
});
