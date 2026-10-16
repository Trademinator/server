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

function f9Bearer(User $user): string
{
    $secret = 'tmk_'.str_repeat('9', 43);
    ClientApiKey::query()->create([
        'user_id' => $user->getKey(),
        'label' => 'f9',
        'prefix' => substr($secret, 0, 12),
        'secret_hash' => hash('sha256', $secret),
    ]);

    return $secret;
}

function f9SellMarket(User $user): array
{
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create([
        'exchange_id' => $exchange->getKey(),
        'symbol' => 'BTC/USD',
        'tick_size' => '0.01',
    ]);
    MarketFeed::query()->create([
        'market_id' => $market->getKey(),
        'selected_period' => '1h',
        'status' => 'active',
    ]);
    $subscription = MarketSubscription::query()->create([
        'user_id' => $user->getKey(),
        'market_id' => $market->getKey(),
        'active' => true,
    ]);
    ClientMarketSetting::query()->create([
        'user_id' => $user->getKey(),
        'market_subscription_id' => $subscription->getKey(),
        'trading_enabled' => true,
        'paper_enabled' => false,
        'max_order_quote' => 100,
        'max_position_quote' => null,
        'reserve_quote' => 0,
        'max_spread_bps' => 50,
        'max_taker_fee_bps' => 50,
        'max_signal_drift_bps' => 100,
        'min_signal_confidence' => 0.6,
        'block_conflicting_exposure' => true,
        'paper_initial_quote' => 1000,
        'paper_slippage_bps' => 10,
        'alert_on_signal_change' => false,
        'alert_on_execution_failure' => false,
        'digest_frequency' => 'off',
    ]);

    $dataset = (string) Str::uuid();
    $model = (string) Str::uuid();
    $marketKey = ModelStore::marketKey('kraken', 'BTC/USD', '1h');
    DB::table('research_datasets')->insert([
        'dataset_id' => $dataset,
        'manifest' => '{}',
        'created_at' => now(),
    ]);
    DB::table('intelligence_models')->insert([
        'model_id' => $model,
        'dataset_id' => $dataset,
        'market_key' => $marketKey,
        'status' => 'ready',
        'generation_key' => null,
        'sha256' => str_repeat('9', 64),
        'report' => '{}',
        'created_at' => now(),
    ]);
    DB::table('intelligence_heads')->insert([
        'market_key' => $marketKey,
        'model_id' => $model,
        'updated_at' => now(),
    ]);
    MarketSignal::query()->create([
        'market_id' => $market->getKey(),
        'snapshot_key' => hash('sha256', $model.'sell'),
        'period' => '1h',
        'model_id' => $model,
        'decision_at_ms' => now()->subMinutes(30)->getTimestampMs(),
        'recorded_at_ms' => now()->getTimestampMs(),
        'is_change' => true,
        'action' => 'sell',
        'reason' => 'supported',
        'payload' => [
            'confidence' => 0.8,
            'evidence_score' => 0.8,
            'reference_price' => '100',
            'reference_price_source' => 'closed_candle_close',
            'action_meaning' => 'supported_top_with_downward_future_move',
        ],
    ]);

    return [f9Bearer($user), $subscription];
}

it('keeps managed SELL quantities on the Client rather than exposing server order sizing', function () {
    $this->freezeTime();
    [$secret, $subscription] = f9SellMarket(User::factory()->create());
    $response = $this->withToken($secret)->getJson('/api/v1/client/markets/'.$subscription->getKey())
        ->assertOk()->assertJsonPath('market.signal.action', 'sell')
        ->assertJsonPath('market.signal.action_meaning', 'supported_top_with_downward_future_move');
    expect($response->json('market.signal'))->not->toHaveKeys([
        'wallet_base_balance', 'managed_base_balance', 'base_amount', 'sizing',
    ]);
    $this->withToken($secret)->postJson('/api/v1/client/markets/'.$subscription->getKey().'/decision', [
        'managed_base_balance' => 0.25, 'base_balance' => 10,
    ])->assertNotFound();
});
