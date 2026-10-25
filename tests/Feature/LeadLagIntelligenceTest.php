<?php

use App\Domain\Intelligence\ExchangeTimezone;
use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\LeadLagIntelligence;
use App\Domain\Intelligence\LeadLagSeries;
use App\Domain\Intelligence\MarketIntelligence;
use App\Domain\Intelligence\ModelStore;
use App\Jobs\TrainMarketIntelligence;
use App\Models\Exchange;
use App\Models\Ticker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;
use Tests\Support\LeadLagFixtures;

beforeEach(function () {
    $path = sys_get_temp_dir().'/trademinator-lead-lag-'.Str::uuid7();
    config(['research.path' => $path.'/research', 'intelligence.path' => $path.'/models',
        'intelligence.patterns.enabled' => false, 'intelligence.knn.min_train_size' => 36,
        'intelligence.knn.test_size' => 12, 'intelligence.knn.min_validation_rows' => 5,
        'intelligence.knn.min_directional_predictions' => 1]);
});

afterEach(function () {
    File::deleteDirectory(dirname(config('research.path')));
});

it('persists auditable lead lag models and stacks evidence strictly after validation without future source leakage', function () {
    $this->travelTo('2024-01-02 00:00:00 UTC');
    $subscription = LeadLagFixtures::market('kraken');
    LeadLagFixtures::market('bitso');
    LeadLagFixtures::candles();
    $manifest = IntelligenceFixtures::snapshot(900);

    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    $model = app(ModelStore::class)->load($report['model_id']);

    expect($report['lead_lag']['report']['bitso']['status'])->toBe('validated');
    expect($model['lead_lag_keys'])->toBe(['lead_lag.bitso.direction']);
    expect($report['lead_lag'])->not->toHaveKey('models');
    expect($report['training_data']['lead_lag_excluded_rows'])->toBeGreaterThan(0);
    foreach ($model['knowledge'] as $row) {
        expect($row['decision_at_ms'])->toBeGreaterThan($model['lead_lag']['available_at_ms']);
        expect($row['vector'])->toHaveCount(2);
    }
    $at = IntelligenceFixtures::START + 902 * 60000;
    $before = app(LeadLagIntelligence::class)->current($model['lead_lag'], $at);
    Ticker::query()->where('exchange', 'bitso')->where('microtimestamp', '>=', $at)->update([
        'payload' => json_encode(['open' => 1, 'high' => 2000, 'low' => 1, 'close' => 2000, 'volume' => 100]),
    ]);
    expect(app(LeadLagIntelligence::class)->current($model['lead_lag'], $at))->toBe($before);
    expect($before['signals']['bitso']['reason'])->toBe('validated');
    expect(abs($before['vector'][0] - 0.5))->toBeLessThanOrEqual(0.125);
    $this->actingAs($subscription->user)->get(route('markets.intelligence', $subscription->getKey()))
        ->assertSee('Cross-exchange lead / lag')->assertSee('3 candles')->assertSee('Evidence strength');
});

it('neutralizes missing stale disabled and changed-timezone evidence', function () {
    $this->travelTo('2024-01-02 00:00:00 UTC');
    LeadLagFixtures::market('kraken');
    LeadLagFixtures::market('bitso');
    LeadLagFixtures::candles();
    $manifest = IntelligenceFixtures::snapshot(900);
    app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    $bundle = app(ModelStore::class)->current('kraken', 'BTC/USD', '1m')['lead_lag'];
    $engine = app(LeadLagIntelligence::class);
    $at = IntelligenceFixtures::START + 902 * 60000;

    expect($engine->features($bundle, [], $at)['vector'])->toBe([0.5]);
    expect($engine->current($bundle, $bundle['available_at_ms'])['signals']['bitso']['reason'])->toBe('not_yet_validated');
    expect($engine->current($bundle, $at + 15 * 86400000)['signals']['bitso']['reason'])->toBe('stale_evidence');
    config(['lead_lag.enabled' => false]);
    expect($engine->current($bundle, $at)['vector'])->toBe([0.5]);
    config(['lead_lag.enabled' => true]);
    Exchange::query()->where('class', 'bitso')->update(['timezone' => 'America/Mexico_City', 'timezone_source' => 'operator']);
    expect($engine->current($bundle, $at)['vector'])->toBe([0.5]);
});

it('does not truncate eligible auxiliary lead lag training to a candle cap or model lifetime', function () {
    $this->travelTo('2024-01-02 00:00:00 UTC');
    config(['intelligence.max_model_age_days' => 1, 'lead_lag.max_bars' => 10]);
    LeadLagFixtures::market('kraken');
    LeadLagFixtures::market('bitso');
    LeadLagFixtures::candles();
    $manifest = IntelligenceFixtures::snapshot(900);

    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);

    expect($report['lead_lag']['report']['bitso']['samples'])->toBeGreaterThan(2000);
});

it('reports incompatible periods and excludes different quotes without blocking core intelligence', function () {
    $this->travelTo('2024-01-02 00:00:00 UTC');
    LeadLagFixtures::market('kraken');
    LeadLagFixtures::market('bitso', '1h');
    LeadLagFixtures::market('ndax', '1m', 'BTC/CAD');
    $manifest = IntelligenceFixtures::snapshot();

    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);

    expect($report['status'])->toBe('ready');
    expect($report['lead_lag']['report']['bitso']['status'])->toBe('different_selected_period');
    expect($report['lead_lag']['report'])->not->toHaveKey('ndax');
    expect($report['lead_lag_keys'])->toBe([]);
});

it('rejects open flat zero-volume and gapped candles in aligned return history', function () {
    $this->travelTo('2024-01-01 00:10:00 UTC');
    for ($i = 0; $i < 6; $i++) {
        Ticker::query()->create(['exchange' => 'bitso', 'symbol' => 'BTC/USD', 'period' => '1m',
            'microtimestamp' => IntelligenceFixtures::START + $i * 60000,
            'payload' => json_encode(['open' => 100, 'high' => 101, 'low' => 99, 'close' => 100,
                'volume' => $i === 2 ? 0 : 1])]);
    }
    $rows = app(LeadLagSeries::class)->load('bitso', 'BTC/USD', '1m', IntelligenceFixtures::START, IntelligenceFixtures::START + 5 * 60000);

    expect(array_keys($rows))->toBe([IntelligenceFixtures::START + 2 * 60000, IntelligenceFixtures::START + 5 * 60000]);
    Ticker::query()->where('microtimestamp', IntelligenceFixtures::START + 4 * 60000)
        ->update(['payload' => json_encode(['open' => 100, 'high' => 100, 'low' => 100, 'close' => 100, 'volume' => 1])]);
    expect(app(LeadLagSeries::class)->load('bitso', 'BTC/USD', '1m', IntelligenceFixtures::START, IntelligenceFixtures::START + 5 * 60000))
        ->toHaveCount(1);
});

it('allows an explicit IANA timezone and preserves it when metadata has another country prior', function () {
    $subscription = LeadLagFixtures::market('bitso');
    $this->artisan('trademinator:exchange', ['action' => 'edit', 'class' => 'bitso', '--timezone' => 'Mars/Local'])->assertFailed();
    $this->artisan('trademinator:exchange', ['action' => 'edit', 'class' => 'bitso', '--timezone' => 'America/Mexico_City'])->assertSuccessful();
    $exchange = $subscription->market->exchange->fresh();
    app(ExchangeTimezone::class)->seed($exchange, ['countries' => ['GB']]);

    expect($exchange->fresh()->timezone)->toBe('America/Mexico_City');
    expect($exchange->fresh()->timezone_source)->toBe('operator');
    $this->artisan('trademinator:exchange', ['action' => 'list'])->expectsOutputToContain('America/Mexico_City')->assertSuccessful();
});

it('uses UTC for ambiguous country metadata and marks a unique zone as a prior', function () {
    $subscription = LeadLagFixtures::market('bitso');
    $exchange = $subscription->market->exchange;
    $zones = app(ExchangeTimezone::class);
    $zones->seed($exchange, ['countries' => ['CA']]);
    expect($exchange->fresh()->timezone)->toBe('UTC');
    expect($exchange->fresh()->timezone_source)->toBe('unknown');
    $zones->seed($exchange, ['countries' => ['GB']]);
    expect($exchange->fresh()->timezone)->toBe('Europe/London');
    expect($exchange->fresh()->timezone_source)->toBe('country_prior');
});

it('dispatches daily reevaluation only for shared overlapping markets and remains idempotent on redelivery', function () {
    $this->travelTo('2024-01-01 02:00:00 UTC');
    config(['queue.default' => 'database']);
    LeadLagFixtures::market('kraken');
    LeadLagFixtures::market('bitso');
    LeadLagFixtures::market('ndax', symbol: 'BTC/CAD');
    Queue::fake([TrainMarketIntelligence::class]);

    $this->artisan('trademinator:dispatch-lead-lag')->assertSuccessful();

    Queue::assertPushed(TrainMarketIntelligence::class, 2);
    Queue::assertPushed(TrainMarketIntelligence::class, fn ($job) => $job->week === 'lead-lag:2024-01-01' && $job->queue === 'intelligence');
    config(['queue.default' => 'sync']);
    $this->artisan('trademinator:dispatch-lead-lag')->assertFailed();
    IntelligenceFixtures::candles();
    $job = new TrainMarketIntelligence('kraken', 'BTC/USD', '1m', 'lead-lag:2024-01-01');
    $job->handle(app(MarketIntelligence::class));
    Cache::forget('trademinator:intelligence-week:'.$job->uniqueId());
    $job->handle(app(MarketIntelligence::class));
    $this->assertDatabaseCount('intelligence_models', 1);
});
