<?php

use App\Domain\Features\FeatureEngine;
use App\Domain\Intelligence\IntelligenceReadiness;
use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\WeightedKnn;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\Research\FeatureSchema;
use App\Models\MarketFeature;
use App\Models\MarketFeed;
use App\Models\MarketSignal;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    $path = sys_get_temp_dir().'/trademinator-readiness-'.Str::uuid7();
    config(['research.path' => $path.'/research', 'intelligence.path' => $path.'/models',
        'queue.default' => 'database', 'intelligence.patterns.enabled' => false]);
});

afterEach(function () {
    File::deleteDirectory(dirname(config('research.path')));
});

function readinessFeatures(int $count, string $period = '1m', string $version = FeatureEngine::VERSION): void
{
    $timeframe = new CandleTimeframe;
    $timestamp = IntelligenceFixtures::START;
    $values = array_fill_keys(FeatureSchema::keys('core'), 0.5);
    $values['trend.direction'] = $values['candle.direction'] = 0;
    for ($i = 0; $i < $count; $i++) {
        $available = $timeframe->next($timestamp, $period);
        DB::table('market_features')->insert(['feature_id' => (string) Str::uuid7(),
            'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => $period, 'version' => $version,
            'microtimestamp' => $timestamp, 'available_at_ms' => $available,
            'payload' => json_encode(['version' => $version, 'microtimestamp' => $timestamp,
                'available_at_ms' => $available, 'features' => $values]), 'created_at' => now(), 'updated_at' => now()]);
        $timestamp = $available;
    }
}

function readiness(?array $report = null, ?string $period = '1m', ?MarketFeed $feed = null): array
{
    return app(IntelligenceReadiness::class)->describe('kraken', 'BTC/USD', $period, $feed, $report, WeightedKnn::abstain('no_model'));
}

it('can attempt four-hour training immediately from backfilled history older than fourteen days', function () {
    $this->travelTo('2024-03-16 00:00:00 UTC');
    config(['intelligence.max_model_age_days' => 14]);
    readinessFeatures(450, '4h');

    $progress = readiness(period: '4h');

    expect($progress['history']['closed'])->toBe(450)
        ->and($progress['history']['potential'])->toBe(437)
        ->and($progress['eta'])->toBeNull()
        ->and($progress['issues'])->toBe([])
        ->and($progress['eta_note'])->toContain('attempt training now');
});

it('shows configured row requirements and a conditional data ETA excluding open and immature features', function () {
    $this->travelTo('2024-01-01 03:20:00 UTC');
    readinessFeatures(201);
    readinessFeatures(201, version: 'old-version');

    $progress = readiness();

    expect($progress['minimum'])->toBe(405);
    expect($progress['full_fold_minimum'])->toBe(468);
    expect($progress['history']['closed'])->toBe(200);
    expect($progress['history']['potential'])->toBe(187);
    expect($progress['history']['immature'])->toBe(13);
    expect($progress['eta']->format('Y-m-d H:i:s'))->toBe('2024-01-01 06:58:00');
    expect($progress['next_training']->format('Y-m-d H:i:s'))->toBe('2024-01-01 04:00:00');
});

it('keeps the minimum as a history requirement without promising model quality', function (int $count) {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    config(['intelligence.knn.min_train_size' => 36, 'intelligence.knn.test_size' => 12,
        'intelligence.knn.min_validation_rows' => 5, 'intelligence.knn.min_directional_predictions' => 1]);
    $manifest = IntelligenceFixtures::snapshot($count);

    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    $progress = readiness($report);

    expect($progress['minimum'])->toBe(57);
    expect($report['status'])->toBe('abstaining');
    expect($progress['source']['usable_rows'])->toBe($count);
})->with([56, 57]);

it('suppresses misleading ETAs for stale or missing feature history', function () {
    $this->travelTo('2024-01-01 03:20:00 UTC');
    expect(readiness()['eta'])->toBeNull();
    readinessFeatures(10);

    $progress = readiness();

    expect($progress['eta'])->toBeNull();
    expect($progress['issues'])->toContain('Feature collection is stale. Check the collector, scheduler and default queue worker.');
});

it('names missing selected features and withholds a timer for schema warmup', function () {
    $this->travelTo('2024-01-01 00:02:00 UTC');
    readinessFeatures(2);
    config(['intelligence.schema' => 'technical']);

    $progress = readiness();

    expect($progress['history']['missing_keys'])->toBe(['return.24h', 'return.7d', 'return.30d']);
    expect($progress['eta'])->toBeNull();
    expect($progress['history']['potential'])->toBe(0);
});

it('reports source gaps without treating model expiration as a training history limit', function () {
    $this->travelTo('2024-01-01 00:20:00 UTC');
    readinessFeatures(20);
    DB::table('market_features')->where('microtimestamp', IntelligenceFixtures::START + 10 * 60000)->delete();
    config(['intelligence.max_model_age_days' => 1, 'intelligence.knn.min_train_size' => 2000]);

    $progress = readiness();

    expect($progress['history']['gaps'])->toBe(1);
    expect($progress['eta'])->toBeNull();
    expect(implode(' ', $progress['issues']))->toContain('contains gaps')
        ->not->toContain('INTELLIGENCE_MAX_MODEL_AGE_DAYS');
});

it('keeps unknown period, disabled scheduling and a nonpersistent queue explicit', function () {
    config(['intelligence.enabled' => false, 'queue.default' => 'sync']);

    $progress = readiness(period: null);

    expect($progress['history'])->toBeNull();
    expect($progress['next_training'])->toBeNull();
    expect(implode(' ', $progress['issues']))->toContain('disabled')->toContain('persistent queue');
});

it('uses calendar-month boundaries and the configured scheduler timezone', function () {
    $this->travelTo('2024-09-01 00:00:00 UTC');
    readinessFeatures(8, '1M');
    config(['intelligence.horizon' => 2, 'intelligence.knn.min_train_size' => 3,
        'intelligence.max_model_age_days' => 730,
        'intelligence.knn.min_validation_rows' => 1, 'app.schedule_timezone' => 'America/Toronto']);

    $progress = readiness(period: '1M');

    expect($progress['minimum'])->toBe(10);
    expect($progress['history']['potential'])->toBe(5);
    expect($progress['eta']->format('Y-m-d H:i:s'))->toBe('2025-02-01 00:00:00');
    expect($progress['next_training']->format('Y-m-d H:i:s'))->toBe('2024-09-02 08:00:00');
});

it('preserves legacy model counts without confusing the retained pool with total history', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $manifest = IntelligenceFixtures::snapshot(227);
    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    unset($report['training_data']);

    $progress = readiness($report);

    expect($progress['source']['source_rows'])->toBe(227);
    expect($progress['source']['usable_rows'])->toBe(227);
    expect($progress['settings']['min_train_size'])->toBe(250);
    expect($progress['tuning'])->toBeNull();
    $report['pattern_keys'] = ['pattern.bullish_engulfing.probability'];
    expect(readiness($report)['source']['usable_rows'])->toBeNull();
});

it('counts all historical features independently of the configured model lifetime', function () {
    $this->travelTo('2024-01-03 05:25:00 UTC');
    readinessFeatures(3205);

    expect(readiness()['history']['closed'])->toBe(3205);
    config(['intelligence.max_model_age_days' => 1]);
    $progress = readiness();

    expect($progress['history']['closed'])->toBe(3205)
        ->and($progress['history']['potential'])->toBe(1987);
});

it('bounds feature loading while reporting all available history and the latest sample', function () {
    $this->travelTo('2024-01-03 05:25:00 UTC');
    readinessFeatures(3206);
    $loaded = 0;
    $queries = 0;
    Event::listen('eloquent.retrieved: '.MarketFeature::class, function () use (&$loaded): void {
        $loaded++;
    });
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'market_features')) {
            $queries++;
        }
    });

    $progress = readiness();

    expect($loaded)->toBeLessThanOrEqual(2001);
    expect($queries)->toBeLessThanOrEqual(3);
    expect($progress['history'])->toMatchArray([
        'closed' => 3205, 'checked' => 2000, 'sampled' => true,
        'complete' => 2000, 'potential' => 1987, 'immature' => 13,
        'missing_keys' => [], 'invalid' => false, 'gaps' => 0, 'stale' => false,
    ]);
});

it('does not invent a wait when unchecked rows may already meet the training requirement', function () {
    $this->travelTo('2024-01-03 05:25:00 UTC');
    readinessFeatures(3205);
    config(['intelligence.knn.min_train_size' => 2000]);

    $progress = readiness();

    expect($progress['history']['sampled'])->toBeTrue();
    expect($progress['eta'])->toBeNull();
    expect($progress['eta_note'])->toContain('Older stored rows may already satisfy');
});

it('keeps recent gaps and invalid features visible when older history is not inspected', function () {
    $this->travelTo('2024-01-03 05:25:00 UTC');
    readinessFeatures(3205);
    DB::table('market_features')->where('microtimestamp', IntelligenceFixtures::START + 3200 * 60000)->delete();
    $latest = MarketFeature::query()->orderByDesc('microtimestamp')->firstOrFail();
    $payload = $latest->payload;
    $payload['features']['candle.body'] = 'not-a-number';
    DB::table('market_features')->where('feature_id', $latest->getKey())->update(['payload' => json_encode($payload)]);

    $progress = readiness();

    expect($progress['history'])->toMatchArray(['closed' => 3204, 'checked' => 2000, 'sampled' => true, 'invalid' => true, 'gaps' => 1]);
    expect($progress['eta'])->toBeNull();
    expect($progress['full_schema']['invalid'])->toBeTrue();
});

it('labels sampled readiness counts on the dashboard and the intelligence page', function () {
    $this->travelTo('2024-01-03 05:25:00 UTC');
    readinessFeatures(3205);
    $user = User::factory()->create();
    $signal = MarketSignal::factory()->create();
    MarketFeed::query()->create(['market_id' => $signal->market_id, 'selected_period' => '1m', 'status' => 'active']);
    $subscription = MarketSubscription::query()->create([
        'user_id' => $user->getKey(), 'market_id' => $signal->market_id, 'active' => true,
    ]);

    $this->actingAs($user)->get('/dashboard')
        ->assertSee('Potential training rows (checked sample)')
        ->assertSee('Checked the latest 2,000 of 3,205 rows in the available history.');
    $this->get(route('markets.intelligence', $subscription->getKey()))
        ->assertSee('Potential training rows (checked sample)')
        ->assertSee('training, which uses all available eligible history.');
});
