<?php

use App\Domain\Intelligence\ModelStore;
use App\Models\ClientApiKey;
use App\Models\ClientMarketSetting;
use App\Models\ClientPaperEvent;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSignal;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function f8Bearer(User $user): string
{
    $secret = 'tmk_'.str_repeat('8', 43);
    ClientApiKey::query()->create([
        'user_id' => $user->user_id, 'label' => 'f8', 'prefix' => substr($secret, 0, 12),
        'secret_hash' => hash('sha256', $secret),
    ]);

    return $secret;
}

function f8PaperMarket(User $user, float $slippageBps, string $referencePrice = '100'): array
{
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create([
        'exchange_id' => $exchange->getKey(), 'symbol' => 'BTC/USD', 'tick_size' => '0.01',
    ]);
    MarketFeed::query()->create([
        'market_id' => $market->getKey(), 'selected_period' => '1h', 'status' => 'active',
    ]);
    $subscription = MarketSubscription::query()->create([
        'user_id' => $user->getKey(), 'market_id' => $market->getKey(), 'active' => true,
    ]);
    ClientMarketSetting::query()->create([
        'user_id' => $user->getKey(), 'market_subscription_id' => $subscription->getKey(),
        'max_order_quote' => 100,
        'max_position_quote' => null, 'reserve_quote' => 0, 'max_spread_bps' => 50,
        'max_taker_fee_bps' => 200, 'max_signal_drift_bps' => 500,
        'min_signal_confidence' => 0.6, 'block_conflicting_exposure' => true,
        'paper_initial_quote' => 1000, 'paper_slippage_bps' => $slippageBps,
        'alert_on_signal_change' => false, 'alert_on_execution_failure' => false, 'digest_frequency' => 'off',
    ]);

    $dataset = (string) Str::uuid();
    $model = (string) Str::uuid();
    $marketKey = ModelStore::marketKey('kraken', 'BTC/USD', '1h');
    DB::table('research_datasets')->insert([
        'dataset_id' => $dataset, 'manifest' => '{}', 'created_at' => now(),
    ]);
    DB::table('intelligence_models')->insert([
        'model_id' => $model, 'dataset_id' => $dataset, 'market_key' => $marketKey,
        'status' => 'ready', 'generation_key' => null, 'sha256' => str_repeat('8', 64),
        'report' => '{}', 'created_at' => now(),
    ]);
    DB::table('intelligence_heads')->insert([
        'market_key' => $marketKey, 'model_id' => $model, 'updated_at' => now(),
    ]);
    $signal = MarketSignal::query()->create([
        'market_id' => $market->getKey(), 'snapshot_key' => hash('sha256', $model),
        'period' => '1h', 'model_id' => $model,
        'decision_at_ms' => now()->subMinutes(30)->getTimestampMs(),
        'recorded_at_ms' => now()->getTimestampMs(), 'is_change' => true,
        'action' => 'buy', 'reason' => 'supported',
        'payload' => [
            'confidence' => 0.8, 'evidence_score' => 0.8,
            'reference_price' => $referencePrice, 'reference_price_source' => 'closed_candle_close',
            'action_meaning' => 'supported_bottom_with_upward_future_move',
        ],
    ]);

    return [f8Bearer($user), $subscription, $signal];
}

it('applies configured paper slippage and base-asset fees through the API', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    [$secret, $subscription] = f8PaperMarket($user, 100);

    $response = $this->withToken($secret)->postJson(
        '/api/v1/client/markets/'.$subscription->getKey().'/paper',
        [
            'idempotency_key' => 'paper-f8-0001',
            'reported_at_ms' => now()->getTimestampMs(),
            'best_bid' => 100,
            'best_ask' => 100,
            'taker_fee_bps' => 100,
            'fee_asset' => 'base',
        ]
    )->assertOk()
        ->assertJsonPath('event', 'executed')
        ->assertJsonPath('reason', 'paper_fill')
        ->assertJsonPath('fee_asset', 'base')
        ->assertJsonPath('execution_assumptions.depth_aware', false)
        ->assertJsonPath('execution_assumptions.slippage_bps', 100);

    expect((float) $response->json('price'))->toBe(101.0);
    expect((float) $response->json('fee_base'))->toBeGreaterThan(0.0);
    expect((float) $response->json('fee_quote'))->toBe(0.0);
    expect((float) $response->json('paper.base_balance'))->toBeLessThan((float) $response->json('quantity'));
    expect((float) $response->json('paper.fees_quote_equivalent'))->toBeGreaterThan(0.0);

    $settings = $this->withToken($secret)
        ->getJson('/api/v1/client/markets/'.$subscription->getKey())
        ->assertOk();
    expect((float) $settings->json('market.settings.paper_slippage_bps'))->toBe(100.0);
});

it('preserves JSON decimal prices throughout paper sizing execution and balances', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    [$secret, $subscription] = f8PaperMarket($user, 0, '0.1');

    $this->withToken($secret)->postJson('/api/v1/client/markets/'.$subscription->getKey().'/paper', [
        'idempotency_key' => 'paper-f11-decimals',
        'reported_at_ms' => now()->getTimestampMs(),
        'best_bid' => 0.1,
        'best_ask' => 0.1,
        'taker_fee_bps' => 0,
        'amount_step' => 0.1,
    ])->assertOk()
        ->assertJsonPath('event', 'executed')
        ->assertJsonPath('price', '0.100000000000000000')
        ->assertJsonPath('quantity', '1000.000000000000000000')
        ->assertJsonPath('decision.sizing.quote_amount', '100.000000000000000000')
        ->assertJsonPath('paper.quote_balance', '900.000000000000000000')
        ->assertJsonPath('paper.base_balance', '1000.000000000000000000');

    $this->assertDatabaseHas('client_paper_accounts', [
        'market_subscription_id' => $subscription->getKey(),
        'quote_balance' => '900.000000000000000000',
        'base_balance' => '1000.000000000000000000',
    ]);
    $event = ClientPaperEvent::query()->sole();
    expect($event->result['price'])->toBe('0.100000000000000000');
    expect($event->result['quantity'])->toBe('1000.000000000000000000');
});

it('preserves the last decimal place in remaining position capacity', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    [$secret, $subscription] = f8PaperMarket($user, 0, '1');
    $subscription->clientSetting->update([
        'max_order_quote' => '1', 'max_position_quote' => '1',
    ]);

    $this->withToken($secret)->postJson('/api/v1/client/markets/'.$subscription->getKey().'/decision', [
        'reported_at_ms' => now()->getTimestampMs(),
        'best_bid' => '1',
        'best_ask' => '1',
        'taker_fee_bps' => 0,
        'quote_balance' => '1',
        'base_balance' => '0',
        'position_quote' => '0.900000000000000001',
    ])->assertOk()
        ->assertJsonPath('eligible', true)
        ->assertJsonPath('sizing.quote_amount', '0.099999999999999999')
        ->assertJsonPath('sizing.base_amount', '0.099999999999999999');
});
