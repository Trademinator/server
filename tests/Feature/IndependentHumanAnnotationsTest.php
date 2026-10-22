<?php

use App\Domain\Intelligence\IndependentHumanAnnotations;
use App\Domain\Research\DatasetSnapshotBuilder;
use App\Domain\Research\SemanticLabels;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    config([
        'intelligence.max_model_age_days' => 14,
        'intelligence.lookback' => 3,
        'intelligence.min_horizon_distance_observations' => 1,
        'human_training.min_reviewers' => 1,
        'research.path' => sys_get_temp_dir().'/human-labels-'.\Illuminate\Support\Str::uuid7(),
    ]);
});

afterEach(function () {
    \Illuminate\Support\Facades\File::deleteDirectory(config('research.path'));
});

it('saves OHLCV human labels without any features and respects authorization', function () {
    IntelligenceFixtures::candles();
    DB::table('market_features')->delete();
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id]);
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1m', 'status' => 'ready']);
    MarketSubscription::query()->create(['user_id' => $owner->user_id, 'market_id' => $market->market_id, 'active' => true]);

    $this->actingAs($owner)->post(route('human-training.ohlcv.store', $market), [
        'source' => 'human_action', 'timestamp' => IntelligenceFixtures::START + 40 * 60000, 'label' => 'hold',
    ])->assertRedirect()->assertSessionHas('status');
    $this->assertDatabaseCount('independent_human_annotations', 1);
    $this->assertDatabaseCount('market_features', 0);
});

it('projects independent human labels only when original OHLCV matches current dataset', function () {
    IntelligenceFixtures::candles();
    $service = app(IndependentHumanAnnotations::class);
    $id = (string) User::factory()->create()->user_id;
    config(['operations.owner_uuid' => $id]);
    $timestamp = IntelligenceFixtures::START + 40 * 60000;
    $service->save($id, 'kraken', 'BTC/USD', '1m', 'human_action', $timestamp, 'hold');
    $manifest = app(DatasetSnapshotBuilder::class)->build('kraken', 'BTC/USD', '1m',
        new SemanticLabels(2, 3), asOfMs: now()->getTimestampMs());
    $opinions = $service->opinions($manifest, 'human_action', now()->getTimestampMs(), microtime(true) + 40);
    expect($opinions)->not->toBeEmpty();
    expect($opinions[0]['label'])->toBe('hodl');

    DB::table('independent_human_annotations')->update(['candle_sha256' => str_repeat('0', 64)]);
    $invalid = $service->opinions($manifest, 'human_action', now()->getTimestampMs(), microtime(true) + 40);
    expect($invalid)->toBeEmpty();
});

it('feeds two human learning paths from OHLCV-only opinions', function () {
    IntelligenceFixtures::candles();
    config(['human_training.candle_enabled' => true, 'human_training.trend_enabled' => true,
        'human_training.candle_min_samples' => 50, 'human_training.min_samples' => 50]);
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id]);
    $timestamp = IntelligenceFixtures::START + 40 * 60000;
    $annotations = app(IndependentHumanAnnotations::class);
    $annotations->save((string) $trainer->user_id, 'kraken', 'BTC/USD', '1m', 'human_action', $timestamp, 'hold');
    $annotations->save((string) $trainer->user_id, 'kraken', 'BTC/USD', '1m', 'human_outcome', $timestamp, 'bull');
    $manifest = app(DatasetSnapshotBuilder::class)->build('kraken', 'BTC/USD', '1m',
        new SemanticLabels(2, 3), asOfMs: now()->getTimestampMs());
    $settings = config('intelligence.knn');
    $deadline = microtime(true) + 90;
    $action = app(\App\Domain\Intelligence\HumanCandleKnn::class)->train($manifest, $settings, $deadline);
    $outcome = app(\App\Domain\Intelligence\HumanGuidance::class)->train($manifest, $settings, $deadline);
    expect($action['bundle']['samples'])->toBeGreaterThan(0)
        ->and($outcome['bundle']['samples'])->toBeGreaterThan(0);
});
