<?php

use App\Domain\Features\FeatureBuilder;
use App\Domain\Intelligence\BackfillIntelligence;
use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\KnnSchemaFallback;
use App\Domain\Intelligence\MarketIntelligence;
use App\Domain\Intelligence\ModelStore;
use App\Domain\MarketData\ExchangeMetadata;
use App\Domain\MarketData\MarketHistoryBackfill;
use App\Domain\Research\DatasetSnapshotBuilder;
use App\Domain\Research\FeatureSchema;
use App\Domain\Research\SemanticLabels;
use App\Jobs\BackfillMarketHistory;
use App\Jobs\RebuildBackfilledIntelligence;
use App\Jobs\TrainMarketIntelligence;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

function backfillExpectedGeneration(string $id, int $revision): string
{
    return hash('sha256', hash('sha256', 'history:'.$id.':'.$revision)
        .'|'.FeatureSchema::VERSION
        .'|'.KnnSchemaFallback::VERSION
        .'|'.IntelligenceTrainer::VERSION
        .'|rubix:'.\Rubix\ML\VERSION);
}

function backfillTrainingCandle(int $timestamp): array
{
    $phase = $timestamp / 3600000;
    $open = 100 + 10 * sin($phase * M_PI / 10);
    $close = 100 + 10 * sin(($phase + 1) * M_PI / 10);

    return ['microtimestamp' => $timestamp, 'open' => (string) $open, 'close' => (string) $close,
        'high' => (string) (max($open, $close) + 0.2), 'low' => (string) (min($open, $close) - 0.2), 'volume' => '10'];
}

function backfillTrainingFeed(): MarketFeed
{
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketSubscription::query()->create(['user_id' => User::factory()->create()->user_id, 'market_id' => $market->market_id, 'active' => true]);
    $feed = MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1h']);
    $start = strtotime('2024-01-02 UTC') * 1000;
    app(TickerRepository::class)->saveTickers('kraken', 'BTC/USD', '1h',
        array_map(backfillTrainingCandle(...), range($start, $start + 99 * 3600000, 3600000)));

    return $feed;
}

function importTrainingHistory(bool $empty = false): string
{
    expect(app(MarketHistoryBackfill::class)->dispatchDue())->toBe(1);
    $state = DB::table('market_history_backfills')->first();
    $repository = Mockery::mock(ExchangeRepository::class);
    $repository->shouldReceive('setExchange')->once();
    $repository->shouldReceive('prepareCandleMarket')->once()->with('BTC/USD');
    $repository->shouldReceive('periods')->once()->andReturn(['1h' => '1h']);
    $repository->shouldReceive('fetchHistoryPage')->once()->andReturnUsing(
        fn ($symbol, $period, $from, $until) => $empty ? [] : array_map(backfillTrainingCandle(...), range($from, $until - 3600000, 3600000)));
    $metadata = Mockery::mock(ExchangeMetadata::class);
    $metadata->shouldReceive('assertUsable')->once()->andReturn([]);
    (new BackfillMarketHistory($state->history_id, $state->lease_token))->handle(
        app(MarketHistoryBackfill::class), $repository, app(TickerRepository::class), $metadata, app(BackfillIntelligence::class));

    return $state->history_id;
}

function runBackfillTrainingStep(string $id): RebuildBackfilledIntelligence
{
    $state = DB::table('market_history_backfills')->where('history_id', $id)->first();
    expect($state->build_lease_token)->not->toBeNull();
    $job = new RebuildBackfilledIntelligence($id, $state->build_lease_token);
    $job->handle(app(BackfillIntelligence::class), app(FeatureBuilder::class), app(MarketIntelligence::class));

    return $job;
}

beforeEach(function () {
    $path = sys_get_temp_dir().'/trademinator-backfill-'.Str::uuid7();
    config(['research.path' => $path.'/research', 'intelligence.path' => $path.'/models',
        'queue.default' => 'database', 'history_backfill.enabled' => true, 'intelligence.enabled' => true,
        'intelligence.schema' => 'core', 'intelligence.knn.min_train_size' => 36, 'intelligence.knn.test_size' => 12,
        'intelligence.knn.min_validation_rows' => 5, 'intelligence.knn.min_directional_predictions' => 1,
        'intelligence.patterns.enabled' => false, 'intelligence.horizon' => 2, 'intelligence.lookback' => 3]);
});

afterEach(function () {
    File::deleteDirectory(dirname(config('research.path')));
});

it('coalesces successful imports into a feature rebuild followed by one fresh KNN model', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    backfillTrainingFeed();
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);
    $id = importTrainingHistory();
    $this->travel(1)->minutes();
    importTrainingHistory();
    $this->assertDatabaseCount('market_features', 0);
    $this->assertDatabaseHas('market_history_backfills', ['history_revision' => 2, 'trained_revision' => 0, 'build_stage' => 'features']);

    runBackfillTrainingStep($id);

    $this->assertDatabaseCount('market_features', 148);
    $performance = json_decode(DB::table('market_history_backfills')->where('history_id', $id)
        ->value('build_performance'), true, flags: JSON_THROW_ON_ERROR);
    expect($performance['feature_replay']['rows_processed'])->toBeGreaterThan(0)
        ->and($performance['feature_replay']['chunks'])->toBeGreaterThan(0);
    $this->assertDatabaseCount('intelligence_models', 0);
    $this->assertDatabaseHas('market_history_backfills', ['build_stage' => 'knn', 'build_revision' => 2, 'feature_revision' => 2]);
    $job = runBackfillTrainingStep($id);
    $job->handle(app(BackfillIntelligence::class), app(FeatureBuilder::class), app(MarketIntelligence::class));

    $this->assertDatabaseCount('intelligence_models', 1);
    $this->assertDatabaseCount('research_datasets', 1);
    $report = json_decode(DB::table('intelligence_models')->value('report'), true, flags: JSON_THROW_ON_ERROR);
    expect($report['build_performance']['total_ms'])->toBeGreaterThanOrEqual(0)
        ->and($report['build_performance']['stages'])->toHaveKeys([
            'dataset_ms', 'patterns_ms', 'lead_lag_ms', 'knn_tuning_ms',
            'holdout_ms', 'human_guidance_ms', 'candle_guidance_ms', 'persistence_ms',
        ])
        ->and($report['build_performance']['feature_replay']['rows_processed'])->toBeGreaterThan(0);
    $this->assertDatabaseHas('intelligence_models', ['generation_key' => backfillExpectedGeneration($id, 2)]);
    $this->assertDatabaseHas('market_history_backfills', ['trained_revision' => 2, 'build_stage' => null, 'build_error' => null]);
    Bus::assertDispatchedTimes(RebuildBackfilledIntelligence::class, 2);
});

it('rebuilds after a successful backfill even when the weekly generation is already complete', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    backfillTrainingFeed();
    app(FeatureBuilder::class)->build('kraken', 'BTC/USD', '1h');
    (new TrainMarketIntelligence('kraken', 'BTC/USD', '1h', '2024-01-08'))->handle(app(MarketIntelligence::class));
    $weekly = app(ModelStore::class)->current('kraken', 'BTC/USD', '1h');
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);

    $id = importTrainingHistory();
    runBackfillTrainingStep($id);
    runBackfillTrainingStep($id);

    $current = app(ModelStore::class)->current('kraken', 'BTC/USD', '1h');
    expect($current['model_id'])->not->toBe($weekly['model_id']);
    expect($current['generation_key'])->toBe(backfillExpectedGeneration($id, 1));
    $this->assertDatabaseCount('intelligence_models', 2);
    Bus::assertDispatchedTimes(RebuildBackfilledIntelligence::class, 2);
});

it('restarts stale prepared features without publishing an obsolete revision', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    backfillTrainingFeed();
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);
    $id = importTrainingHistory();
    runBackfillTrainingStep($id);
    $this->travel(1)->minutes();
    importTrainingHistory();

    runBackfillTrainingStep($id);

    $this->assertDatabaseHas('market_history_backfills', ['trained_revision' => 0, 'history_revision' => 2, 'build_stage' => 'features']);
    $this->assertDatabaseCount('intelligence_models', 0);
    $this->assertDatabaseCount('research_datasets', 0);
    expect(app(BackfillIntelligence::class)->dispatchDue())->toBe(0);
    runBackfillTrainingStep($id);
    runBackfillTrainingStep($id);
    $this->assertDatabaseHas('market_history_backfills', ['trained_revision' => 2, 'history_revision' => 2, 'build_stage' => null]);
    $this->assertDatabaseCount('intelligence_models', 1);
    $this->assertDatabaseCount('research_datasets', 1);
    $this->assertDatabaseHas('intelligence_models', ['generation_key' => backfillExpectedGeneration($id, 2)]);
    $this->assertDatabaseMissing('intelligence_models', ['generation_key' => backfillExpectedGeneration($id, 1)]);
    Bus::assertDispatchedTimes(RebuildBackfilledIntelligence::class, 4);
});

it('preserves imported candles and retries a failed feature rebuild before attempting KNN', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    backfillTrainingFeed();
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);
    $id = importTrainingHistory();
    $lock = Cache::lock('trademinator:features:'.ModelStore::marketKey('kraken', 'BTC/USD', '1h'), 720);
    $lock->get();

    try {
        runBackfillTrainingStep($id);
    } finally {
        $lock->release();
    }

    $this->assertDatabaseCount('tickers', 124);
    $this->assertDatabaseCount('intelligence_models', 0);
    $this->assertDatabaseHas('market_history_backfills', ['history_revision' => 1, 'trained_revision' => 0,
        'build_stage' => 'features', 'build_failures' => 1,
        'build_error' => 'Features are already being built for this market and period.']);
    expect(app(BackfillIntelligence::class)->dispatchDue())->toBe(0);
    $this->travel(1)->minutes();
    expect(app(BackfillIntelligence::class)->dispatchDue())->toBe(1);
    runBackfillTrainingStep($id);
    runBackfillTrainingStep($id);
    $this->assertDatabaseHas('market_history_backfills', ['trained_revision' => 1, 'build_failures' => 0, 'build_error' => null]);
    $this->assertDatabaseCount('intelligence_models', 1);
    Bus::assertDispatchedTimes(RebuildBackfilledIntelligence::class, 3);
});

it('does not queue intelligence for an empty history window', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    backfillTrainingFeed();
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);

    importTrainingHistory(empty: true);

    $this->assertDatabaseHas('market_history_backfills', ['history_revision' => 0, 'build_stage' => null]);
    Bus::assertNotDispatched(RebuildBackfilledIntelligence::class);
});

it('retains pending training while intelligence is disabled and recovers stale build leases', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    config(['intelligence.max_seconds' => 14400, 'intelligence.job_timeout' => 15000]);
    backfillTrainingFeed();
    config(['intelligence.enabled' => false]);
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);
    $id = importTrainingHistory();
    $this->assertDatabaseHas('market_history_backfills', ['history_revision' => 1, 'build_lease_token' => null]);
    config(['intelligence.enabled' => true]);
    expect(app(BackfillIntelligence::class)->dispatchDue())->toBe(1);
    $first = DB::table('market_history_backfills')->first();
    $stale = new RebuildBackfilledIntelligence($id, $first->build_lease_token);
    expect(app(BackfillIntelligence::class)->renew($id, $first->build_lease_token))->toBeTrue();
    $this->assertDatabaseHas('market_history_backfills', [
        'history_id' => $id, 'build_lease_until' => '2024-01-10 04:10:00',
    ]);
    $this->travel(21)->minutes();
    expect(app(BackfillIntelligence::class)->dispatchDue())->toBe(0);
    $this->travel(4)->hours();
    expect(app(BackfillIntelligence::class)->dispatchDue())->toBe(1);

    $stale->handle(app(BackfillIntelligence::class), app(FeatureBuilder::class), app(MarketIntelligence::class));

    $this->assertDatabaseCount('market_features', 0);
    runBackfillTrainingStep($id);
    runBackfillTrainingStep($id);
    $this->assertDatabaseHas('market_history_backfills', ['trained_revision' => 1, 'build_stage' => null]);
    $this->assertDatabaseCount('intelligence_models', 1);
    Bus::assertDispatchedTimes(RebuildBackfilledIntelligence::class, 3);
});

it('keeps one pending rebuild and its token while it waits longer than a day', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    backfillTrainingFeed();
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);
    $id = importTrainingHistory();
    $token = DB::table('market_history_backfills')->where('history_id', $id)->value('build_lease_token');

    $this->travel(26)->hours();

    expect(app(BackfillIntelligence::class)->dispatchDue())->toBe(0);
    $this->assertDatabaseHas('market_history_backfills', [
        'history_id' => $id, 'build_lease_token' => $token, 'build_lease_until' => null,
        'history_revision' => 1, 'trained_revision' => 0, 'build_stage' => 'features',
    ]);
    $this->assertDatabaseCount('market_features', 0);
    Bus::assertDispatchedTimes(RebuildBackfilledIntelligence::class, 1);
});

it('releases a pending rebuild identity after terminal worker failure so dispatch can recover', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    backfillTrainingFeed();
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);
    $id = importTrainingHistory();
    $token = DB::table('market_history_backfills')->where('history_id', $id)->value('build_lease_token');
    $job = new RebuildBackfilledIntelligence($id, $token);

    $job->failed(new RuntimeException('Worker exhausted its retry allowance.'));

    $this->assertDatabaseHas('market_history_backfills', [
        'history_id' => $id, 'build_lease_token' => null, 'build_lease_until' => null,
        'build_stage' => 'features', 'build_failures' => 1,
        'build_error' => 'Worker exhausted its retry allowance.',
    ]);
    expect(app(BackfillIntelligence::class)->dispatchDue())->toBe(0);
    $this->travel(61)->seconds();
    expect(app(BackfillIntelligence::class)->dispatchDue())->toBe(1);
    expect(DB::table('market_history_backfills')->where('history_id', $id)->value('build_lease_token'))
        ->not->toBe($token);
    Bus::assertDispatchedTimes(RebuildBackfilledIntelligence::class, 2);
});

it('does not let an obsolete failed job release another pending rebuild', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    backfillTrainingFeed();
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);
    $id = importTrainingHistory();
    $token = DB::table('market_history_backfills')->where('history_id', $id)->value('build_lease_token');
    $obsolete = new RebuildBackfilledIntelligence($id, (string) Str::uuid7());

    $obsolete->failed(new RuntimeException('An older attempt failed.'));

    $this->assertDatabaseHas('market_history_backfills', [
        'history_id' => $id, 'build_lease_token' => $token, 'build_lease_until' => null,
        'build_stage' => 'features', 'build_failures' => 0, 'build_error' => null,
    ]);
    expect(app(BackfillIntelligence::class)->dispatchDue())->toBe(0);
    Bus::assertDispatchedTimes(RebuildBackfilledIntelligence::class, 1);
});

it('keeps a split feature continuation pending without expiring its identity', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    backfillTrainingFeed();
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);
    $id = importTrainingHistory();
    $state = DB::table('market_history_backfills')->where('history_id', $id)->first();
    $child = new RebuildBackfilledIntelligence($id, $state->build_lease_token, 'core',
        strtotime('2024-01-02 UTC') * 1000, strtotime('2024-01-03 UTC') * 1000, false, 1);

    $child->handle(app(BackfillIntelligence::class), app(FeatureBuilder::class), app(MarketIntelligence::class));
    $this->travel(30)->minutes();

    expect(app(BackfillIntelligence::class)->dispatchDue())->toBe(0);
    $this->assertDatabaseHas('market_history_backfills', [
        'history_id' => $id, 'build_stage' => 'features', 'build_lease_token' => $state->build_lease_token,
        'build_lease_until' => null, 'build_failures' => 0,
    ]);
    $this->assertDatabaseCount('intelligence_models', 0);
    Bus::assertDispatchedTimes(RebuildBackfilledIntelligence::class, 1);
});

it('does not publish a model after its backfill token is replaced during training', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    backfillTrainingFeed();
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);
    $id = importTrainingHistory();
    runBackfillTrainingStep($id);
    $replacement = (string) Str::uuid7();
    $replaced = false;
    DB::listen(function ($query) use ($id, $replacement, &$replaced): void {
        if (! $replaced && str_starts_with(strtolower(ltrim($query->sql)), 'insert')
            && str_contains($query->sql, 'research_datasets')) {
            $replaced = true;
            DB::table('market_history_backfills')->where('history_id', $id)->update([
                'build_lease_token' => $replacement, 'build_lease_until' => null,
            ]);
        }
    });

    runBackfillTrainingStep($id);

    expect($replaced)->toBeTrue();
    $this->assertDatabaseCount('intelligence_models', 0);
    $this->assertDatabaseHas('market_history_backfills', [
        'history_id' => $id, 'build_lease_token' => $replacement, 'build_lease_until' => null,
        'build_stage' => 'knn', 'trained_revision' => 0, 'build_failures' => 0,
    ]);
});

it('lends the feature lock through dataset creation and keeps it held until model publication completes', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    backfillTrainingFeed();
    app(FeatureBuilder::class)->build('kraken', 'BTC/USD', '1h');
    $name = 'trademinator:features:'.ModelStore::marketKey('kraken', 'BTC/USD', '1h');
    $lock = Cache::lock($name, 720);
    expect($lock->get())->toBeTrue();
    $protectedDuringPublication = null;
    DB::listen(function ($query) use ($name, &$protectedDuringPublication): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'insert') && str_contains($query->sql, 'intelligence_models')) {
            $contender = Cache::lock($name, 720);
            $protectedDuringPublication = ! $contender->get();
            if (! $protectedDuringPublication) {
                $contender->release();
            }
        }
    });

    try {
        $report = app(MarketIntelligence::class)->build('kraken', 'BTC/USD', '1h', featureLockOwner: $lock->owner());
        expect($lock->isOwnedByCurrentProcess())->toBeTrue();
        expect($protectedDuringPublication)->toBeTrue();
        $this->assertDatabaseHas('intelligence_models', ['model_id' => $report['model_id']]);
        $this->assertDatabaseCount('research_datasets', 1);
    } finally {
        $lock->release();
    }

    $next = Cache::lock($name, 720);
    try {
        expect($next->get())->toBeTrue();
    } finally {
        $next->release();
    }
});

it('rejects a wrong or expired borrowed owner without unlocking another builder', function (bool $expired) {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    $name = 'trademinator:features:'.ModelStore::marketKey('kraken', 'BTC/USD', '1h');
    $old = Cache::lock($name, 1);
    expect($old->get())->toBeTrue();
    if ($expired) {
        $this->travel(2)->seconds();
        $holder = Cache::lock($name, 720);
        expect($holder->get())->toBeTrue();
        $token = $old->owner();
    } else {
        $holder = $old;
        $token = (string) Str::uuid7();
    }

    try {
        expect(fn () => app(DatasetSnapshotBuilder::class)->build(
            'kraken', 'BTC/USD', '1h', new SemanticLabels(2, 3), featureLockOwner: $token,
        ))->toThrow(RuntimeException::class, 'Feature lock ownership was lost');
        expect($holder->isOwnedByCurrentProcess())->toBeTrue();
        $this->assertDatabaseCount('research_datasets', 0);
    } finally {
        $holder->release();
    }
})->with([false, true]);

it('does not accept a borrowed feature lock belonging to another market', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    $lock = Cache::lock('trademinator:features:'.ModelStore::marketKey('kraken', 'BTC/USD', '1h'), 720);
    expect($lock->get())->toBeTrue();
    try {
        expect(fn () => app(DatasetSnapshotBuilder::class)->build(
            'kraken', 'ETH/USD', '1h', new SemanticLabels(2, 3), featureLockOwner: $lock->owner(),
        ))->toThrow(RuntimeException::class, 'Feature lock ownership was lost');
        expect($lock->isOwnedByCurrentProcess())->toBeTrue();
        $this->assertDatabaseCount('research_datasets', 0);
    } finally {
        $lock->release();
    }
});

it('leaves a borrowed lock with its caller when dataset creation fails', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    $lock = Cache::lock('trademinator:features:'.ModelStore::marketKey('kraken', 'BTC/USD', '1h'), 720);
    expect($lock->get())->toBeTrue();
    try {
        expect(fn () => app(DatasetSnapshotBuilder::class)->build(
            'kraken', 'BTC/USD', '1h', new SemanticLabels(2, 3), featureLockOwner: $lock->owner(),
        ))->toThrow(RuntimeException::class, 'No M2 features in this range');
        expect($lock->isOwnedByCurrentProcess())->toBeTrue();
        expect(glob(config('research.path').'/*.tmp') ?: [])->toBe([]);
        $this->assertDatabaseCount('research_datasets', 0);
    } finally {
        $lock->release();
    }
});

it('still releases a dataset-owned lock when a normal dataset build fails', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    expect(fn () => app(DatasetSnapshotBuilder::class)->build(
        'kraken', 'BTC/USD', '1h', new SemanticLabels(2, 3),
    ))->toThrow(RuntimeException::class, 'No M2 features in this range');
    $lock = Cache::lock('trademinator:features:'.ModelStore::marketKey('kraken', 'BTC/USD', '1h'), 720);
    try {
        expect($lock->get())->toBeTrue();
    } finally {
        $lock->release();
    }
});

it('retries KNN lock contention without releasing the competing owner or recording a build failure', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    backfillTrainingFeed();
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);
    $id = importTrainingHistory();
    runBackfillTrainingStep($id);
    $lock = Cache::lock('trademinator:features:'.ModelStore::marketKey('kraken', 'BTC/USD', '1h'), 720);
    expect($lock->get())->toBeTrue();
    try {
        runBackfillTrainingStep($id);
        expect($lock->isOwnedByCurrentProcess())->toBeTrue();
        $this->assertDatabaseHas('market_history_backfills', ['history_id' => $id, 'build_stage' => 'knn',
            'build_failures' => 0, 'trained_revision' => 0]);
        $this->assertDatabaseCount('intelligence_models', 0);
    } finally {
        $lock->release();
    }

    runBackfillTrainingStep($id);
    $this->assertDatabaseCount('intelligence_models', 1);
    $this->assertDatabaseHas('market_history_backfills', ['history_id' => $id, 'trained_revision' => 1,
        'build_stage' => null, 'build_failures' => 0, 'build_error' => null]);
});

it('rechecks the revision after acquiring the feature lock before making a dataset', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    backfillTrainingFeed();
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);
    $id = importTrainingHistory();
    runBackfillTrainingStep($id);
    $state = DB::table('market_history_backfills')->where('history_id', $id)->first();
    $job = new RebuildBackfilledIntelligence($id, $state->build_lease_token);
    $reads = 0;
    $changed = false;
    DB::listen(function ($query) use ($id, &$reads, &$changed): void {
        if (! $changed && str_starts_with(strtolower(ltrim($query->sql)), 'select')
            && str_contains($query->sql, 'market_history_backfills')) {
            // The second owned-state read follows the history lock. Its result
            // is already read: emulate a repair before acquiring the feature lock.
            if (++$reads === 2) {
                $changed = true;
                DB::table('market_history_backfills')->where('history_id', $id)->increment('history_revision');
            }
        }
    });

    $job->handle(app(BackfillIntelligence::class), app(FeatureBuilder::class), app(MarketIntelligence::class));

    expect($changed)->toBeTrue();
    $this->assertDatabaseCount('research_datasets', 0);
    $this->assertDatabaseCount('intelligence_models', 0);
    $this->assertDatabaseHas('market_history_backfills', ['history_id' => $id,
        'trained_revision' => 0, 'history_revision' => 2, 'build_stage' => 'features', 'build_error' => null]);
    $lock = Cache::lock('trademinator:features:'.ModelStore::marketKey('kraken', 'BTC/USD', '1h'), 720);
    try {
        expect($lock->get())->toBeTrue();
    } finally {
        $lock->release();
    }
});
