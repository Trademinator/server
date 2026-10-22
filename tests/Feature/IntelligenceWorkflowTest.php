<?php

use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\MarketIntelligence;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Research\DatasetSnapshotBuilder;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\FeatureSchema;
use App\Domain\Research\SemanticLabels;
use App\Jobs\TrainMarketIntelligence;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    $path = sys_get_temp_dir().'/trademinator-m4-'.Str::uuid7();
    config(['research.path' => $path.'/research', 'intelligence.path' => $path.'/models',
        'intelligence.knn.min_train_size' => 36, 'intelligence.knn.test_size' => 12,
        'intelligence.knn.min_validation_rows' => 5, 'intelligence.knn.min_directional_predictions' => 1,
        'intelligence.patterns.enabled' => false, 'intelligence.horizon' => 2, 'intelligence.lookback' => 3,
        'intelligence.min_horizon_distance_observations' => 1]);
});

afterEach(function () {
    File::deleteDirectory(dirname(config('research.path')));
});

it('freezes semantic labels and pattern outcomes only after their genuine candle closes', function () {
    $this->travelTo('2024-01-01 02:00:00 UTC');
    IntelligenceFixtures::candles();
    $cutoff = IntelligenceFixtures::START + 90 * 60000;

    $manifest = app(DatasetSnapshotBuilder::class)->build('kraken', 'BTC/USD', '1m',
        new SemanticLabels(2, 3), asOfMs: $cutoff);
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);

    expect($manifest['label_definition']['fee_bps'])->toBe(0);
    expect($manifest['label_definition']['cost_model'])->toBe('none');
    expect($manifest['label_definition']['version'])->toBe(SemanticLabels::VERSION);
    expect(($manifest['label_counts']['bull'] ?? 0) + ($manifest['label_counts']['super_bull'] ?? 0))->toBeGreaterThan(0);
    expect(($manifest['label_counts']['bear'] ?? 0) + ($manifest['label_counts']['super_bear'] ?? 0))->toBeGreaterThan(0);
    foreach ($rows as $row) {
        expect($row['label_available_at_ms'])->toBeLessThanOrEqual($cutoff)
            ->and($row)->toHaveKeys(['gross_return', 'buy_price_return', 'sell_base_price_return'])
            ->not->toHaveKeys(['buy_net_return', 'sell_base_net_return']);
        foreach ($row['patterns'] as $pattern) {
            expect($pattern['label_available_at_ms'])->toBeLessThanOrEqual($cutoff);
        }
    }
});

it('publishes a validated model with separate chronological tuning and untouched holdout metrics', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $manifest = IntelligenceFixtures::snapshot();

    $this->artisan('trademinator:knn-build', ['exchange' => 'kraken', 'symbol' => 'BTC/USD',
        'period' => '1m', '--dataset' => $manifest['dataset_id']])->assertSuccessful();
    $artifact = app(ModelStore::class)->current('kraken', 'BTC/USD', '1m');

    expect($artifact['status'])->toBe('ready');
    expect($artifact['holdout']['macro_f1'])->toBeGreaterThanOrEqual(0.55);
    expect($artifact['holdout_training_labels_available_by_ms'])->toBeLessThan($artifact['holdout_from_ms']);
    expect($artifact['knowledge_rows'])->toBe(240);
    $this->assertDatabaseCount('intelligence_models', 1);
    $this->assertDatabaseCount('intelligence_heads', 1);
    $this->artisan('trademinator:model-info', ['model' => $artifact['model_id']])->assertSuccessful();
    expect(app(ModelStore::class)->report($artifact['model_id']))->not->toHaveKey('knowledge');
});

it('stores shared Outcome and Action KNN knowledge separately and streams it for prediction', function () {
    $this->travelTo('2024-01-01 04:05:00 UTC');
    $manifest = IntelligenceFixtures::snapshot();
    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    $store = app(ModelStore::class);
    $metadata = $store->currentForPrediction('kraken', 'BTC/USD', '1m');

    expect($metadata)->not->toHaveKey('knowledge')
        ->and($metadata['format_version'])->toBe('m4-intelligence-v3')
        ->and(is_file($store->knowledgePath($report['model_id'])))->toBeTrue()
        ->and($metadata['outcome']['human'])->not->toHaveKey('knowledge')
        ->and($metadata['action']['human'])->not->toHaveKey('knowledge')
        ->and(is_file($store->humanKnowledgePath($report['model_id'], 'outcome')))->toBeTrue()
        ->and(is_file($store->humanKnowledgePath($report['model_id'], 'action')))->toBeTrue()
        ->and(iterator_count($store->knowledge($metadata)))->toBe($report['knowledge_rows']);

    IntelligenceFixtures::feature(243, 0.0);
    IntelligenceFixtures::feature(244, 0.0);
    IntelligenceFixtures::feature(245, 1.0);

    $prediction = app(MarketIntelligence::class)->predict('kraken', 'BTC/USD', '1m');
    expect($prediction['action'])->toBe('buy')
        ->and($prediction['prediction_input_basis'])->toBe('completed_candle')
        ->and($prediction['reference_price_source'])->toBe('closed_candle_close');
});

it('rejects corrupted streamed KNN knowledge', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $manifest = IntelligenceFixtures::snapshot();
    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    $store = app(ModelStore::class);
    $metadata = $store->currentForPrediction('kraken', 'BTC/USD', '1m');
    file_put_contents($store->knowledgePath($report['model_id']), "{\"corrupt\":true}\n");

    expect(fn () => iterator_to_array($store->knowledge($metadata), false))
        ->toThrow(RuntimeException::class, 'checksum');
});

it('verifies all streamed knowledge files and refuses a modified Human KNN sidecar', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $manifest = IntelligenceFixtures::snapshot();
    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    $store = app(ModelStore::class);
    $model = $store->currentForPrediction('kraken', 'BTC/USD', '1m');

    $store->verify($report['model_id']);
    $this->artisan('trademinator:model-info', ['model' => $report['model_id']])->assertSuccessful();
    file_put_contents($store->humanKnowledgePath($report['model_id'], 'action'), "{\"corrupt\":true}\n");

    expect(fn () => iterator_to_array($store->humanKnowledge($model, 'action'), false))
        ->toThrow(RuntimeException::class, 'checksum');
    $this->artisan('trademinator:model-info', ['model' => $report['model_id']])->assertFailed();
});

it('compacts an existing v2 model into a new, equivalent v3 head without retraining', function () {
    $this->travelTo('2024-01-01 04:05:00 UTC');
    $manifest = IntelligenceFixtures::snapshot();
    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    $store = app(ModelStore::class);
    $originalId = $report['model_id'];
    IntelligenceFixtures::feature(243, 0.0);
    IntelligenceFixtures::feature(244, 0.0);
    IntelligenceFixtures::feature(245, 1.0);

    // Reconstruct the former v2 layout with embedded human bundles and a
    // separately stored automatic knowledge file, plus a valid DB digest.
    $legacy = $store->load($originalId);
    $legacy['format_version'] = 'm4-intelligence-v2';
    unset($legacy['knowledge'], $legacy['outcome']['human']['knowledge_sha256'],
        $legacy['action']['human']['knowledge_sha256']);
    $legacy['human_guidance'] = $legacy['outcome']['human'];
    $legacy['candle_guidance'] = $legacy['action']['human'];
    $bytes = serialize($legacy);
    file_put_contents($store->path($originalId), $bytes);
    DB::table('intelligence_models')->where('model_id', $originalId)->update(['sha256' => hash('sha256', $bytes)]);
    $before = app(MarketIntelligence::class)->predict('kraken', 'BTC/USD', '1m');

    $arguments = ['exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m'];
    $this->artisan('trademinator:compact-model', $arguments)->assertSuccessful();
    $compacted = $store->currentForPrediction('kraken', 'BTC/USD', '1m');
    $newId = $compacted['model_id'];
    expect($newId)->not->toBe($originalId)
        ->and($compacted['format_version'])->toBe('m4-intelligence-v3')
        ->and($compacted['action']['human'])->not->toHaveKey('knowledge')
        ->and($compacted['human_guidance'])->not->toHaveKey('knowledge')
        ->and($compacted['candle_guidance'])->not->toHaveKey('knowledge')
        ->and(iterator_count($store->knowledge($compacted)))->toBe($report['knowledge_rows']);
    $store->verify($newId);
    $after = app(MarketIntelligence::class)->predict('kraken', 'BTC/USD', '1m');
    expect($after['scoring'])->toBe($before['scoring']);
    $this->artisan('trademinator:compact-model', $arguments)->assertSuccessful();
    expect($store->currentForPrediction('kraken', 'BTC/USD', '1m')['model_id'])->toBe($newId);
    $this->assertDatabaseCount('intelligence_models', 2);
});

it('keeps final holdout targets out of K selection', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $manifest = IntelligenceFixtures::snapshot();
    $first = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    $path = app(DatasetStore::class)->directory($manifest['dataset_id']);
    $lines = file($path.'/rows.jsonl');
    foreach ($lines as $i => &$line) {
        if ($i >= 192) {
            $row = json_decode($line, true);
            $row['label'] = 'neutral';
            $line = json_encode($row)."\n";
        }
    }
    unset($line);
    $bytes = implode('', $lines);
    $manifest['rows_sha256'] = hash('sha256', $bytes);
    file_put_contents($path.'/rows.jsonl', $bytes);
    file_put_contents($path.'/manifest.json', json_encode($manifest));
    DB::table('research_datasets')->where('dataset_id', $manifest['dataset_id'])->update(['manifest' => json_encode($manifest)]);

    $second = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);

    expect($second['selection'])->toBe($first['selection']);
    expect($second['status'])->toBe('abstaining');
    expect($second['reason'])->toBe('outcome_knn_unavailable');
});

it('predicts from closed features while ignoring an open candle and abstains when data becomes stale', function () {
    $this->travelTo('2024-01-01 04:05:00 UTC');
    $manifest = IntelligenceFixtures::snapshot();
    app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    IntelligenceFixtures::feature(243, 0.0);
    IntelligenceFixtures::feature(244, 0.0);
    IntelligenceFixtures::feature(245, 1.0);

    $signal = app(MarketIntelligence::class)->predict('kraken', 'BTC/USD', '1m');

    expect($signal['action'])->toBe('buy');
    expect($signal['decision_at_ms'])->toBe(IntelligenceFixtures::START + 245 * 60000);
    expect($signal['reference_price_source'])->toBe('closed_candle_close');
    expect((float) $signal['reference_price'])->toBeGreaterThan(0);
    $this->travelTo('2024-01-01 04:08:00 UTC');
    expect(app(MarketIntelligence::class)->predict('kraken', 'BTC/USD', '1m')['reason'])->toBe('stale_features');
});

it('publishes an abstaining model when no predictively valid Outcome K exists', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $manifest = IntelligenceFixtures::snapshot(240, true);

    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);

    expect($report['status'])->toBe('abstaining');
    expect($report['k'])->toBeNull();
    expect($report['reason'])->toBe('outcome_knn_unavailable');
});

it('fails safely on corrupted artifacts and rejects model path traversal', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $manifest = IntelligenceFixtures::snapshot();
    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    file_put_contents(app(ModelStore::class)->path($report['model_id']), 'corrupt');

    expect(fn () => app(ModelStore::class)->current('kraken', 'BTC/USD', '1m'))->toThrow(RuntimeException::class, 'checksum');
    $this->artisan('trademinator:model-info', ['model' => '../../.env'])->assertFailed();
});

it('does not train a second model while the market lock is held', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $manifest = IntelligenceFixtures::snapshot();
    $lock = Cache::lock('trademinator:intelligence:'.ModelStore::marketKey('kraken', 'BTC/USD', '1m'), 720);
    $lock->get();
    try {
        expect(fn () => app(IntelligenceTrainer::class)->train($manifest['dataset_id']))
            ->toThrow(RuntimeException::class, 'already running');
        $this->assertDatabaseCount('intelligence_models', 0);
    } finally {
        $lock->release();
    }
});

it('checks the caller ownership guard before publishing and releases build locks after rejection', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $manifest = IntelligenceFixtures::snapshot();

    expect(fn () => app(MarketIntelligence::class)->build('kraken', 'BTC/USD', '1m', $manifest['dataset_id'],
        beforePublish: fn () => throw new RuntimeException('The owning rebuild was superseded.')))
        ->toThrow(RuntimeException::class, 'The owning rebuild was superseded.');

    $this->assertDatabaseCount('intelligence_models', 0);
    $this->assertDatabaseCount('intelligence_heads', 0);
    $report = app(MarketIntelligence::class)->build('kraken', 'BTC/USD', '1m', $manifest['dataset_id']);
    expect($report['model_id'])->not->toBeEmpty();
    $this->assertDatabaseCount('intelligence_models', 1);
});

it('dispatches one intelligence job for a shared market and rejects synchronous dispatch', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    config(['queue.default' => 'database']);
    Queue::fake([TrainMarketIntelligence::class]);
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1m', 'status' => 'active']);
    foreach (User::factory()->count(2)->create() as $user) {
        MarketSubscription::query()->create(['user_id' => $user->user_id, 'market_id' => $market->market_id, 'active' => true]);
    }

    $this->artisan('trademinator:dispatch-market-intelligence')->assertSuccessful();

    Queue::assertPushed(TrainMarketIntelligence::class, fn ($job) => $job->queue === 'intelligence'
        && $job->exchange === 'kraken' && $job->symbol === 'BTC/USD' && $job->week === '2024-01-01');
    config(['queue.default' => 'sync']);
    $this->artisan('trademinator:dispatch-market-intelligence')->assertFailed();
});

it('keeps Full KNN independent of missing CoinGecko context', function () {
    expect(FeatureSchema::keys('full'))->toBe(FeatureSchema::keys('technical'));
});

it('builds once per weekly job even if the queue delivers it again', function () {
    $this->travelTo('2024-01-01 02:00:00 UTC');
    IntelligenceFixtures::candles();
    $job = new TrainMarketIntelligence('kraken', 'BTC/USD', '1m', '2024-01-01');

    $job->handle(app(MarketIntelligence::class));
    Cache::forget('trademinator:intelligence-week:'.$job->uniqueId());
    $job->handle(app(MarketIntelligence::class));

    $this->assertDatabaseCount('intelligence_models', 1);
    $this->assertDatabaseCount('research_datasets', 1);
});

it('queues every selected and published market period once per forced invocation', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    config(['queue.default' => 'database', 'intelligence.enabled' => false, 'intelligence.schema' => 'core']);
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1m', 'status' => 'active']);
    foreach (User::factory()->count(2)->create() as $user) {
        MarketSubscription::query()->create(['user_id' => $user->user_id, 'market_id' => $market->market_id, 'active' => true]);
    }
    $manifest = IntelligenceFixtures::snapshot();
    foreach (['1m', '15m'] as $period) {
        $id = (string) Str::uuid7();
        $key = ModelStore::marketKey('kraken', 'BTC/USD', $period);
        DB::table('intelligence_models')->insert([
            'model_id' => $id, 'dataset_id' => $manifest['dataset_id'], 'market_key' => $key,
            'status' => 'ready', 'sha256' => str_repeat('0', 64), 'created_at' => now(),
            'report' => json_encode(['exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => $period,
                'schema_selection' => ['requested_schema' => 'enhanced']]),
        ]);
        DB::table('intelligence_heads')->insert(['market_key' => $key, 'model_id' => $id, 'updated_at' => now()]);
    }
    Queue::fake([TrainMarketIntelligence::class]);

    $this->artisan('trademinator:dispatch-market-intelligence', ['--force' => true, '--all' => true])->assertSuccessful();
    $this->artisan('trademinator:dispatch-market-intelligence', ['--force' => true, '--all' => true])->assertSuccessful();

    $jobs = Queue::pushed(TrainMarketIntelligence::class);
    expect($jobs)->toHaveCount(4)
        ->and($jobs->pluck('week')->unique())->toHaveCount(2)
        ->and($jobs->pluck('period')->all())->toBe(['1m', '15m', '1m', '15m'])
        ->and($jobs->pluck('schema')->all())->toBe(['core', 'enhanced', 'core', 'enhanced']);
    foreach ($jobs as $job) {
        expect($job->week)->toStartWith('manual:');
    }
});

it('requires an explicit force request to rebuild previously published market periods', function () {
    Queue::fake();

    $this->artisan('trademinator:dispatch-market-intelligence', ['--all' => true])
        ->expectsOutput('--all requires --force.')->assertFailed();

    Queue::assertNothingPushed();
});

it('rebuilds after a completed weekly generation and keeps forced job retries idempotent', function () {
    $this->travelTo('2024-01-01 02:00:00 UTC');
    IntelligenceFixtures::candles();
    $service = app(MarketIntelligence::class);
    (new TrainMarketIntelligence('kraken', 'BTC/USD', '1m', '2024-01-01'))->handle($service);
    $old = app(ModelStore::class)->currentReport('kraken', 'BTC/USD', '1m');
    $forced = new TrainMarketIntelligence('kraken', 'BTC/USD', '1m', 'manual:rubix-upgrade');

    $forced->handle($service);
    $forced->handle($service);

    $this->assertDatabaseCount('intelligence_models', 2);
    $this->assertDatabaseCount('research_datasets', 2);
    // Both training generations are preserved. If neither passes the current
    // readiness gates there must be no active model; an abstaining candidate
    // cannot displace a usable incumbent either.
    $active = app(ModelStore::class)->currentReport('kraken', 'BTC/USD', '1m');
    expect($active['model_id'] ?? null)->toBe($old['model_id'] ?? null);
});

it('returns zero confidence without any model and rejects mismatched frozen markets', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $signal = app(MarketIntelligence::class)->predict('kraken', 'BTC/USD', '1m');
    expect($signal['action'])->toBe('hodl');
    expect($signal['confidence'])->toBe(0.0);
    $manifest = IntelligenceFixtures::snapshot();
    $this->artisan('trademinator:knn-build', ['exchange' => 'bitso', 'symbol' => 'BTC/USD',
        'period' => '1m', '--dataset' => $manifest['dataset_id']])->assertFailed();
    $this->assertDatabaseCount('intelligence_models', 0);
});

it('keeps validated patterns informational and never appends them to KNN vectors', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    config(['intelligence.patterns.enabled' => true, 'intelligence.patterns.as_knn_features' => true,
        'intelligence.patterns.min_samples' => 30, 'intelligence.patterns.min_block_rows' => 5,
        'intelligence.patterns.trees' => 5]);
    mt_srand(42);
    $manifest = IntelligenceFixtures::snapshot(patterns: true);

    app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    $artifact = app(ModelStore::class)->current('kraken', 'BTC/USD', '1m');

    expect($artifact['patterns']['models'])->toHaveKey('bullish_engulfing');
    expect($artifact['pattern_keys'])->toBe([]);
    expect($artifact['outcome']['pattern_ablation']['candidate_pattern_keys'])->toBe([]);
    expect($artifact['outcome']['pattern_ablation']['selected'])->toBe('technical_only');
    foreach ($artifact['knowledge'] as $row) {
        expect($row['vector'])->toHaveCount(1);
    }
});

it('requires rebuilding legacy model validation before issuing a new signal', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $manifest = IntelligenceFixtures::snapshot();
    app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    $legacy = app(ModelStore::class)->current('kraken', 'BTC/USD', '1m');
    unset($legacy['validation_version']);
    $candidate = app(ModelStore::class)->save($legacy);
    expect($candidate['model_id'])->not->toBe($legacy['model_id']);
    expect(app(ModelStore::class)->currentReport('kraken', 'BTC/USD', '1m')['model_id'])
        ->toBe($legacy['model_id']);
});

it('requires rebuilding models from the retired maximum-supply feature version', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $manifest = IntelligenceFixtures::snapshot();
    app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    $legacy = app(ModelStore::class)->current('kraken', 'BTC/USD', '1m');
    $legacy['feature_version'] = 'm2-v5';
    $candidate = app(ModelStore::class)->save($legacy);

    expect($candidate['model_id'])->not->toBe($legacy['model_id']);
    expect(app(ModelStore::class)->currentReport('kraken', 'BTC/USD', '1m')['model_id'])
        ->toBe($legacy['model_id']);
    $this->assertDatabaseCount('intelligence_models', 2);
});

it('reports the five-class Outcome KNN state directly', function (float $body, string $regime) {
    $this->travelTo('2024-01-01 04:05:00 UTC');
    config(['intelligence.knn.min_effective_neighbors' => 6.0]);
    $manifest = IntelligenceFixtures::snapshot();
    app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    IntelligenceFixtures::feature(243, $body);
    IntelligenceFixtures::feature(244, $body);

    $signal = app(MarketIntelligence::class)->predict('kraken', 'BTC/USD', '1m');

    expect($signal['reason'])->toBe('supported');
    expect($signal['regime'])->toBe($regime);
})->with([[0.0, 'bull'], [1.0, 'bear'], [0.21, 'bull'], [0.79, 'bear']]);

it('rejects a missing training dataset file before publishing a model', function () {
    $manifest = IntelligenceFixtures::snapshot(40);
    unlink(app(DatasetStore::class)->directory($manifest['dataset_id']).'/rows.jsonl');

    expect(fn () => app(IntelligenceTrainer::class)->train($manifest['dataset_id']))
        ->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('intelligence_models', 0);
});

it('keeps Action KNN training data when the Outcome horizon has fewer than the required d observations', function () {
    $this->travelTo('2024-01-01 02:00:00 UTC');
    config(['intelligence.min_horizon_distance_observations' => 999]);
    IntelligenceFixtures::candles();
    $cutoff = IntelligenceFixtures::START + 90 * 60000;

    $manifest = app(DatasetSnapshotBuilder::class)->build('kraken', 'BTC/USD', '1m',
        new SemanticLabels(2, 3), asOfMs: $cutoff);
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);

    expect($manifest['outcome_available'])->toBeFalse()
        ->and($manifest['action_label_analysis']['horizon'])->toBeNull()
        ->and($manifest['action_label_analysis']['distance_observations'])->toBeLessThan(999)
        ->and(count($rows))->toBeGreaterThan(0)
        ->and(collect($rows)->filter(fn (array $row): bool => in_array($row['action_label'] ?? null, ['buy', 'hodl', 'sell'], true))->count())->toBeGreaterThan(0)
        ->and(collect($rows)->filter(fn (array $row): bool => ($row['label'] ?? null) !== null)->count())->toBe(0);

    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);

    expect($report['outcome']['algorithmic']['status'])->toBe('abstaining')
        ->and($report['outcome']['algorithmic']['reason'])->toBe('insufficient_outcome_history')
        ->and($report['action']['algorithmic']['samples'])->toBeGreaterThan(0)
        ->and($report['action_label_analysis']['minimum_distance_observations'])->toBe(999);
});
