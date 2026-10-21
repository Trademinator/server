<?php

use App\Domain\Features\FeatureEngine;
use App\Domain\Intelligence\KnnSchemaFallback;
use App\Domain\Research\FeatureSchema;
use App\Models\MarketFeature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

it('chooses Core if Technical returns are missing, without looking at CoinGecko', function (): void {
    config(['intelligence.knn.min_train_size' => 32, 'intelligence.knn.test_size' => 12,
        'intelligence.knn.min_validation_rows' => 5, 'intelligence.knn.gap' => 0]);
    $start = IntelligenceFixtures::START;
    for ($i = 0; $i < 180; $i++) {
        $timestamp = $start + $i * 60000;
        $features = array_fill_keys(FeatureSchema::keys('technical'), 0.5);
        $features['trend.direction'] = $features['candle.direction'] = 0;
        foreach (['return.24h', 'return.7d', 'return.30d'] as $key) {
            $features[$key] = null;
        }
        MarketFeature::query()->forceCreate([
            'feature_id' => (string) Str::uuid7(), 'exchange' => 'kraken', 'symbol' => 'BTC/USD',
            'period' => '1m', 'microtimestamp' => $timestamp,
            'available_at_ms' => $timestamp + 60000, 'version' => FeatureEngine::VERSION,
            'payload' => ['version' => FeatureEngine::VERSION, 'microtimestamp' => $timestamp,
                'available_at_ms' => $timestamp + 60000, 'features' => $features],
        ]);
    }
    $result = app(KnnSchemaFallback::class)->inspect('kraken', 'BTC/USD', '1m',
        $start, $start + 180 * 60000, $start + 180 * 60000, 2, microtime(true) + 15);

    expect($result['effective_schema'])->toBe('core')
        ->and($result['reason'])->toBe('insufficient_technical_history')
        ->and($result['potential_history']['core']['sufficient'])->toBeTrue()
        ->and($result['potential_history']['technical']['sufficient'])->toBeFalse();

});

it('does not invent a fallback when neither input has usable history', function (): void {
    config(['intelligence.knn.min_train_size' => 32, 'intelligence.knn.test_size' => 12,
        'intelligence.knn.min_validation_rows' => 5, 'intelligence.knn.gap' => 0]);
    $result = app(KnnSchemaFallback::class)->inspect('kraken', 'BTC/USD', '1m',
        IntelligenceFixtures::START, IntelligenceFixtures::START + 1000,
        IntelligenceFixtures::START + 1000, 2, microtime(true) + 15);
    expect($result['effective_schema'])->toBeNull()
        ->and($result['reason'])->toBe('insufficient_both_feature_histories');
});

it('inspects every chronological page without rescanning offsets or ordering by feature UUIDs', function (): void {
    config(['intelligence.knn.min_train_size' => 32, 'intelligence.knn.test_size' => 12,
        'intelligence.knn.min_validation_rows' => 5, 'intelligence.knn.gap' => 0]);
    $start = IntelligenceFixtures::START;
    for ($i = 619; $i >= 0; $i--) {
        $timestamp = $start + $i * 60000;
        $features = array_fill_keys(FeatureSchema::keys('technical'), 0.5);
        $features['trend.direction'] = $features['candle.direction'] = 0;
        if ($i < 500) {
            foreach (['return.24h', 'return.7d', 'return.30d'] as $key) {
                $features[$key] = null;
            }
        }
        MarketFeature::query()->forceCreate([
            'feature_id' => (string) Str::uuid7(), 'exchange' => 'kraken', 'symbol' => 'BTC/USD',
            'period' => '1m', 'microtimestamp' => $timestamp,
            'available_at_ms' => $timestamp + 60000, 'version' => FeatureEngine::VERSION,
            'payload' => ['version' => FeatureEngine::VERSION, 'microtimestamp' => $timestamp,
                'available_at_ms' => $timestamp + 60000, 'features' => $features],
        ]);
    }
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'market_features')) {
            $queries[] = strtolower($query->sql);
        }
    });

    $result = app(KnnSchemaFallback::class)->inspect('kraken', 'BTC/USD', '1m',
        $start, $start + 620 * 60000, $start + 620 * 60000, 2, microtime(true) + 15);

    expect($result['feature_rows'])->toBe(620);
    expect($result['complete_feature_rows'])->toBe(['technical' => 120, 'core' => 620]);
    expect($result['potential_history']['technical']['potential_mature_rows'])->toBe(118);
    expect($result['effective_schema'])->toBe('technical');
    expect($queries)->toHaveCount(2);
    foreach ($queries as $query) {
        expect($query)->not->toContain(' offset ');
    }
});
