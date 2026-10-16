<?php

use App\Domain\Intelligence\ModelStore;
use App\Models\ClientApiKey;
use App\Models\ClientMarketSetting;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSignal;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function f6Bearer(User $user): string
{
    $secret = 'tmk_'.str_repeat('f', 43);
    ClientApiKey::query()->create([
        'user_id' => $user->user_id, 'label' => 'f6', 'prefix' => substr($secret, 0, 12),
        'secret_hash' => hash('sha256', $secret),
    ]);

    return $secret;
}

function f6Market(User $user, string $period, int $decisionAtMs, string $referencePrice = '100', array $extraPayload = [],
    string $action = 'buy', string $reason = 'supported'): array
{
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create([
        'exchange_id' => $exchange->getKey(), 'symbol' => 'BTC/USD', 'tick_size' => '0.01',
    ]);
    MarketFeed::query()->create([
        'market_id' => $market->getKey(), 'selected_period' => $period, 'status' => 'active',
    ]);
    $subscription = MarketSubscription::query()->create([
        'user_id' => $user->getKey(), 'market_id' => $market->getKey(), 'active' => true,
    ]);
    ClientMarketSetting::query()->create([
        'user_id' => $user->getKey(), 'market_subscription_id' => $subscription->getKey(),
        'max_order_quote' => 100,
        'max_position_quote' => null, 'reserve_quote' => 0, 'max_spread_bps' => 50,
        'max_taker_fee_bps' => 50, 'max_signal_drift_bps' => 100, 'min_signal_confidence' => 0.6,
        'block_conflicting_exposure' => true, 'paper_initial_quote' => 1000,
        'alert_on_signal_change' => false, 'alert_on_execution_failure' => false, 'digest_frequency' => 'off',
    ]);

    $dataset = (string) Str::uuid();
    $model = (string) Str::uuid();
    $marketKey = ModelStore::marketKey('kraken', 'BTC/USD', $period);
    DB::table('research_datasets')->insert([
        'dataset_id' => $dataset, 'manifest' => '{}', 'created_at' => now(),
    ]);
    DB::table('intelligence_models')->insert([
        'model_id' => $model, 'dataset_id' => $dataset, 'market_key' => $marketKey,
        'status' => 'ready', 'generation_key' => null, 'sha256' => str_repeat('a', 64),
        'report' => '{}', 'created_at' => now(),
    ]);
    DB::table('intelligence_heads')->insert([
        'market_key' => $marketKey, 'model_id' => $model, 'updated_at' => now(),
    ]);
    $signal = MarketSignal::query()->create([
        'market_id' => $market->getKey(), 'snapshot_key' => hash('sha256', $model.$decisionAtMs),
        'period' => $period, 'model_id' => $model, 'decision_at_ms' => $decisionAtMs,
        'recorded_at_ms' => now()->getTimestampMs(), 'is_change' => true,
        'action' => $action, 'reason' => $reason,
        'payload' => ['confidence' => $reason === 'supported' ? 0.8 : 0.0, 'evidence_score' => 0.8,
            'reference_price' => $referencePrice, 'reference_price_source' => 'closed_candle_close', ...$extraPayload],
    ]);

    return [f6Bearer($user), $subscription, $signal];
}

it('publishes expiry and freshness even for stale coarse-period intelligence', function () {
    $this->freezeTime();
    config(['intelligence.max_signal_age_periods' => 2, 'intelligence.max_signal_age_seconds' => 86400]);
    [$secret, $subscription] = f6Market(User::factory()->create(), '1w', now()->subHours(25)->getTimestampMs());

    $response = $this->withToken($secret)->getJson('/api/v1/client/markets/'.$subscription->getKey())
        ->assertOk()->assertJsonPath('market.signal.is_fresh', false)
        ->assertJsonPath('market.signal.is_current_model', true)
        ->assertJsonPath('market.signal.action', 'buy');
    expect($response->json('market.signal.expires_at_ms'))->toBeLessThan(now()->getTimestampMs());
    expect($response->json('market.signal'))->not->toHaveKeys(['eligible', 'sizing', 'quote_balance', 'base_balance']);
});

it('exposes independent scoring without execution state', function () {
    $this->freezeTime();
    $scoring = ['version' => 'outcome-action-matrix-v2', 'decision_mode' => 'full',
        'action' => ['action' => 'buy', 'reason' => 'supported'],
        'outcome' => ['outcome' => 'bull', 'reason' => 'supported']];
    [$secret, $subscription] = f6Market(User::factory()->create(), '1h',
        now()->subMinutes(30)->getTimestampMs(), extraPayload: ['scoring' => $scoring]);

    $this->withToken($secret)->getJson('/api/v1/client/markets/'.$subscription->getKey())
        ->assertOk()->assertJsonPath('market.signal.scoring', $scoring)
        ->assertJsonPath('market.signal.is_fresh', true)
        ->assertJsonPath('market.signal.is_current_model', true)
        ->assertJsonPath('market.signal.action_meaning', 'supported_buy_by_outcome_action_matrix');
});

it('preserves actionable degraded Action-only evidence, without server trade authorization', function () {
    $this->freezeTime();
    [$secret, $subscription, $signal] = f6Market(User::factory()->create(), '1h',
        now()->subMinutes(30)->getTimestampMs(),
        extraPayload: ['confidence' => 0.82], action: 'buy', reason: 'degraded_action_only');
    DB::table('intelligence_models')->where('model_id', $signal->model_id)->update(['status' => 'abstaining']);
    $response = $this->withToken($secret)->getJson('/api/v1/client/markets/'.$subscription->getKey())
        ->assertOk()->assertJsonPath('market.signal.action', 'buy')
        ->assertJsonPath('market.signal.evidence_status', 'degraded')
        ->assertJsonPath('market.signal.confidence', 0.82)
        ->assertJsonPath('market.signal.is_current_model', true);
    expect($response->json('market.signal'))->not->toHaveKeys(['eligible', 'sizing']);
});

it('distinguishes abstaining evidence from supported HOLD', function () {
    $this->freezeTime();
    [$secret, $subscription] = f6Market(User::factory()->create(), '1h',
        now()->subMinutes(30)->getTimestampMs(),
        action: 'hodl', reason: 'insufficient_effective_neighbors');
    $this->withToken($secret)->getJson('/api/v1/client/markets/'.$subscription->getKey())
        ->assertOk()->assertJsonPath('market.signal.action', 'hold')
        ->assertJsonPath('market.signal.evidence_status', 'abstaining')
        ->assertJsonPath('market.signal.reason', 'insufficient_effective_neighbors');
});

it('reports a superseded model and changed candle period without rewriting the signal', function () {
    $this->freezeTime();
    [$secret, $subscription, $signal] = f6Market(User::factory()->create(), '1h',
        now()->subMinutes(30)->getTimestampMs());
    DB::table('intelligence_heads')->where('model_id', $signal->model_id)->delete();
    $this->withToken($secret)->getJson('/api/v1/client/markets/'.$subscription->getKey())
        ->assertOk()->assertJsonPath('market.signal.is_current_model', false);
    $subscription->market->feed->update(['selected_period' => '4h']);
    $this->withToken($secret)->getJson('/api/v1/client/markets/'.$subscription->getKey())
        ->assertOk()->assertJsonPath('market.signal.is_fresh', false)
        ->assertJsonPath('market.signal.is_current_model', false);
});

it('does not accept private balances on the retired decision route', function () {
    [$secret, $subscription] = f6Market(User::factory()->create(), '1h',
        now()->subMinutes(30)->getTimestampMs());
    $this->withToken($secret)->postJson('/api/v1/client/markets/'.$subscription->getKey().'/decision', [
        'quote_balance' => 500, 'base_balance' => 5,
    ])->assertNotFound();
});
