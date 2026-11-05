<?php

use App\Domain\Archive\TickerArchive;
use App\Domain\Intelligence\ActionLabelAnalysis;
use App\Domain\Intelligence\AlgorithmicLabelBuilder;
use App\Domain\Intelligence\BuildCheckpoint;
use App\Domain\Intelligence\BuildPaused;
use App\Domain\Intelligence\KnowledgeRequestJournal;
use App\Domain\Intelligence\LabelChunkWriter;
use App\Domain\Intelligence\TrainingLease;
use App\Domain\MarketData\HistoryChanges;
use App\Domain\Research\DatasetSnapshotBuilder;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\SemanticLabels;
use App\Jobs\AnalyzeMarketAutoLabels;
use App\Jobs\BuildOwnerDatasetSnapshot;
use App\Jobs\TrainMarketIntelligence;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeature;
use App\Models\Ticker;
use App\Repositories\TickerRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    config(['exchange_fees.taker_overrides.kraken.rate' => 0.001,
        'intelligence.lookback' => 3, 'intelligence.min_horizon_distance_observations' => 1,
        'research.checkpoint_rows' => 7, 'archive.enabled' => false,
        'research.path' => sys_get_temp_dir().'/build-checkpoint-'.Str::uuid7()]);
});

afterEach(function () {
    Event::forget('knowledge.checkpoint.saved');
    File::deleteDirectory(config('research.path'));
});

function checkpointInterrupt(Closure $when): void
{
    Event::listen('knowledge.checkpoint.saved', function (string $key, array $state) use ($when): void {
        if ($when($state)) {
            throw new RuntimeException('fixture worker interrupted after commit');
        }
    });
}

function checkpointLabelValues(array $set): array
{
    return array_combine(LabelChunkWriter::KINDS, array_map(
        fn (string $kind): array => iterator_to_array($set[$kind]), LabelChunkWriter::KINDS));
}

it('resumes interrupted label passes with exactly the uninterrupted labels and maturity', function (string $stage, ?int $pass) {
    IntelligenceFixtures::candles(130);
    Ticker::query()->where('microtimestamp', IntelligenceFixtures::START + 75 * 60000)->delete();
    $asOf = now()->getTimestampMs();
    $builder = app(AlgorithmicLabelBuilder::class);
    $reference = $builder->build('kraken', 'BTC/USD', '1m', $asOf - 1);
    checkpointInterrupt(fn (array $state): bool => $state['stage'] === $stage
        && ($pass === null ? (($state['analysis']['candles'] ?? 0) >= 14)
            : (($state['action_pass'] ?? -1) === $pass && ($state['action_pass_state']['position'] ?? 0) >= 14)));

    expect(fn () => $builder->build('kraken', 'BTC/USD', '1m', $asOf))
        ->toThrow(RuntimeException::class, 'fixture worker interrupted');
    $this->assertDatabaseCount('algorithmic_label_sets', 1);
    Event::forget('knowledge.checkpoint.saved');
    $resumed = $builder->build('kraken', 'BTC/USD', '1m', $asOf);

    expect(checkpointLabelValues($resumed))->toBe(checkpointLabelValues($reference));
    foreach (['action_counts', 'distance_frequencies', 'pivot_count', 'horizon', 'gaps', 'candles_examined'] as $key) {
        expect($resumed['action'][$key])->toBe($reference['action'][$key]);
    }
    $this->assertDatabaseEmpty('knowledge_build_checkpoints');
    $this->assertDatabaseEmpty('knowledge_build_checkpoint_chunks');
})->with([
    'source ingestion' => ['action_read', null],
    'hold reservations' => ['action_passes', 0],
    'candidate assignment' => ['action_passes', 1],
    'consecutive pivots' => ['action_passes', 2],
    'profit floor' => ['action_passes', 3],
    'zigzags' => ['action_passes', 4],
    'bottom relocation' => ['action_passes', 5],
    'final consecutive pivots' => ['action_passes', 6],
    'dojis' => ['action_passes', 7],
    'middle chains' => ['action_passes', 8],
    'emission' => ['action_emit', null],
    'Outcome calculation' => ['outcomes', null],
]);

it('resumes snapshot rows without duplicate output and reuses the completed dataset', function () {
    IntelligenceFixtures::candles(130);
    $asOf = now()->getTimestampMs();
    $builder = app(DatasetSnapshotBuilder::class);
    $reference = $builder->build('kraken', 'BTC/USD', '1m', new SemanticLabels(2, 3), asOfMs: $asOf - 1);
    checkpointInterrupt(fn (array $state): bool => $state['stage'] === 'snapshot_rows' && ($state['snapshot']['count'] ?? 0) >= 14);

    expect(fn () => $builder->build('kraken', 'BTC/USD', '1m', new SemanticLabels(2, 3), asOfMs: $asOf))
        ->toThrow(RuntimeException::class, 'fixture worker interrupted');
    $this->assertDatabaseCount('research_datasets', 1);
    Event::forget('knowledge.checkpoint.saved');
    $resumed = $builder->build('kraken', 'BTC/USD', '1m', new SemanticLabels(2, 3), asOfMs: $asOf);
    $again = $builder->build('kraken', 'BTC/USD', '1m', new SemanticLabels(2, 3), asOfMs: $asOf);

    expect($resumed['rows_sha256'])->toBe($reference['rows_sha256']);
    expect($resumed['rows'])->toBe($reference['rows']);
    expect($resumed['skipped'])->toBe($reference['skipped']);
    expect($again['dataset_id'])->toBe($resumed['dataset_id']);
    [, $expected] = app(DatasetStore::class)->load($reference['dataset_id']);
    [, $actual] = app(DatasetStore::class)->load($resumed['dataset_id']);
    expect($actual)->toBe($expected);
    $this->assertDatabaseEmpty('knowledge_build_checkpoint_chunks');
});

it('preserves the snapshot error and saved rows when temporary cleanup fails', function () {
    IntelligenceFixtures::candles(130);
    $asOf = now()->getTimestampMs();
    $builder = app(DatasetSnapshotBuilder::class);
    $reference = $builder->build('kraken', 'BTC/USD', '1m', new SemanticLabels(2, 3), asOfMs: $asOf - 1);
    $failure = new RuntimeException('fixture snapshot failure');
    $temporary = null;
    Event::listen('knowledge.checkpoint.saved', function (string $key, array $state) use ($failure, &$temporary): void {
        if ($state['stage'] !== 'snapshot_rows' || ($state['snapshot']['count'] ?? 0) < 14) {
            return;
        }
        $paths = glob(config('research.path').'/'.$state['id'].'.tmp.*', GLOB_ONLYDIR);
        expect($paths)->toHaveCount(1);
        $temporary = $paths[0];
        file_put_contents($temporary.'/.cleanup-fixture', 'preserve unexpected files');
        throw $failure;
    });

    $caught = null;
    try {
        $builder->build('kraken', 'BTC/USD', '1m', new SemanticLabels(2, 3), asOfMs: $asOf);
    } catch (Throwable $error) {
        $caught = $error;
    }

    expect($caught)->toBe($failure);
    expect($temporary)->not->toBeNull();
    expect(is_file($temporary.'/rows.jsonl'))->toBeFalse();
    expect(file_get_contents($temporary.'/.cleanup-fixture'))->toBe('preserve unexpected files');
    $this->assertDatabaseCount('research_datasets', 1);
    $this->assertDatabaseHas('knowledge_build_checkpoint_chunks', ['stream' => 'snapshot-rows']);
    Event::forget('knowledge.checkpoint.saved');

    $resumed = $builder->build('kraken', 'BTC/USD', '1m', new SemanticLabels(2, 3), asOfMs: $asOf);

    expect($resumed['rows_sha256'])->toBe($reference['rows_sha256']);
    [, $expected] = app(DatasetStore::class)->load($reference['dataset_id']);
    [, $actual] = app(DatasetStore::class)->load($resumed['dataset_id']);
    expect($actual)->toBe($expected);
    expect(is_file($temporary.'/.cleanup-fixture'))->toBeTrue();
    $this->assertDatabaseCount('research_datasets', 2);
    $this->assertDatabaseEmpty('knowledge_build_checkpoint_chunks');
});

it('invalidates saved snapshot progress when a previously processed feature changes', function () {
    IntelligenceFixtures::candles();
    $asOf = now()->getTimestampMs();
    $builder = app(DatasetSnapshotBuilder::class);
    checkpointInterrupt(fn (array $state): bool => $state['stage'] === 'snapshot_rows' && ($state['snapshot']['count'] ?? 0) >= 14);
    expect(fn () => $builder->build('kraken', 'BTC/USD', '1m', new SemanticLabels(2, 3), asOfMs: $asOf))
        ->toThrow(RuntimeException::class, 'fixture worker interrupted');
    Event::forget('knowledge.checkpoint.saved');
    $row = MarketFeature::query()->where('microtimestamp', IntelligenceFixtures::START + 5 * 60000)->firstOrFail();
    $payload = $row->payload;
    $payload['features']['candle.body'] = 0.123;
    $row->payload = $payload;
    $row->save();

    $resumed = $builder->build('kraken', 'BTC/USD', '1m', new SemanticLabels(2, 3), asOfMs: $asOf);
    $fresh = $builder->build('kraken', 'BTC/USD', '1m', new SemanticLabels(2, 3), asOfMs: $asOf - 1);

    expect($resumed['rows_sha256'])->toBe($fresh['rows_sha256']);
});

it('resumes snapshot publication after completion metadata rolls back with the dataset', function () {
    IntelligenceFixtures::candles();
    $asOf = now()->getTimestampMs();
    $builder = app(DatasetSnapshotBuilder::class);
    checkpointInterrupt(fn (array $state): bool => $state['stage'] === 'snapshot_complete');

    expect(fn () => $builder->build('kraken', 'BTC/USD', '1m', new SemanticLabels(2, 3), asOfMs: $asOf))
        ->toThrow(RuntimeException::class, 'fixture worker interrupted');
    $this->assertDatabaseEmpty('research_datasets');
    $state = json_decode(DB::table('knowledge_build_checkpoints')->value('state'), true, flags: JSON_THROW_ON_ERROR);
    expect($state['stage'])->toBe('snapshot_rows');
    expect(DB::table('knowledge_build_checkpoint_chunks')->where('stream', 'snapshot-rows')->count())->toBeGreaterThan(0);
    Event::forget('knowledge.checkpoint.saved');

    $resumed = $builder->build('kraken', 'BTC/USD', '1m', new SemanticLabels(2, 3), asOfMs: $asOf);
    $reference = $builder->build('kraken', 'BTC/USD', '1m', new SemanticLabels(2, 3), asOfMs: $asOf - 1);

    expect($resumed['dataset_id'])->toBe($state['id']);
    expect($resumed['rows_sha256'])->toBe($reference['rows_sha256']);
    $this->assertDatabaseEmpty('knowledge_build_checkpoint_chunks');
});

it('invalidates interrupted labels for an in-range correction but preserves them for newer corrections', function (bool $inside) {
    $exchange = Exchange::query()->create(['class' => 'kraken', 'name' => 'Kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    IntelligenceFixtures::candles();
    $asOf = now()->getTimestampMs();
    checkpointInterrupt(fn (array $state): bool => $state['stage'] === 'action_passes' && ($state['action_pass'] ?? 0) === 2);
    $builder = app(AlgorithmicLabelBuilder::class);
    expect(fn () => $builder->build('kraken', 'BTC/USD', '1m', $asOf))
        ->toThrow(RuntimeException::class, 'fixture worker interrupted');
    Event::forget('knowledge.checkpoint.saved');
    if ($inside) {
        Ticker::query()->where('microtimestamp', IntelligenceFixtures::START + 5 * 60000)->delete();
    }
    $timestamp = $inside ? IntelligenceFixtures::START + 5 * 60000 : $asOf + 60000;
    app(HistoryChanges::class)->record($market->market_id, '1m', $timestamp, $timestamp, 'live_candle_correction');
    $firstStage = null;
    Event::listen('knowledge.checkpoint.saved', function (string $key, array $state) use (&$firstStage): void {
        $firstStage ??= $state['stage'];
    });

    $resumed = $builder->build('kraken', 'BTC/USD', '1m', $asOf);
    $fresh = $builder->build('kraken', 'BTC/USD', '1m', $asOf - 1);

    expect($firstStage)->toBe($inside ? 'action_read' : 'action_passes');
    expect(checkpointLabelValues($resumed))->toBe(checkpointLabelValues($fresh));
})->with(['corrected old candle' => true, 'newer live correction' => false]);

it('rejects missing and corrupt checkpoint data instead of publishing partial labels', function (string $damage) {
    IntelligenceFixtures::candles();
    $asOf = now()->getTimestampMs();
    $builder = app(AlgorithmicLabelBuilder::class);
    checkpointInterrupt(fn (array $state): bool => $state['stage'] === 'action_read' && ($state['processed'] ?? 0) >= 14);
    expect(fn () => $builder->build('kraken', 'BTC/USD', '1m', $asOf))
        ->toThrow(RuntimeException::class, 'fixture worker interrupted');
    Event::forget('knowledge.checkpoint.saved');
    $query = DB::table('knowledge_build_checkpoint_chunks')->where('stream', 'action-candles');
    if ($damage === 'missing') {
        $query->delete();
    } else {
        $query->update(['payload' => '[]']);
    }

    expect(fn () => $builder->build('kraken', 'BTC/USD', '1m', $asOf))->toThrow(RuntimeException::class);

    $this->assertDatabaseEmpty('algorithmic_label_sets');
})->with(['missing', 'corrupt']);

it('fences an expired writer after another worker takes over the checkpoint', function () {
    $identity = ['kind' => 'labels', 'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m'];
    $first = new BuildCheckpoint($identity);
    $first->state = ['stage' => 'action_read', 'processed' => 14];
    $first->save();
    expect(fn () => new BuildCheckpoint($identity))->toThrow(BuildPaused::class);
    Cache::lock('trademinator:checkpoint:'.$first->key)->forceRelease();
    // A replacement host has its own in-process lease registry.
    app()->forgetInstance(TrainingLease::class);
    $second = new BuildCheckpoint($identity);
    try {
        expect($second->state['processed'])->toBe(14);
        $second->state['processed'] = 28;
        $second->save();
        $first->state['processed'] = 1;
        expect(fn () => $first->save())->toThrow(RuntimeException::class);
    } finally {
        $first->close();
        $second->close();
    }
    $third = new BuildCheckpoint($identity);
    expect($third->state['processed'])->toBe(28);
    $third->close();
});

it('releases paused labels without failing the request or losing the frozen cutoff', function () {
    IntelligenceFixtures::candles();
    config(['research.checkpoint_work_seconds' => 0]);
    $job = (new AnalyzeMarketAutoLabels('kraken', 'BTC/USD', '1m', 'checkpoint-job'))->withFakeQueueInteractions();
    $cutoff = $job->asOfMs;
    $lock = Cache::lock($job->cacheKey.':lock', 900);
    $lock->get();
    $job->dispatchLockOwner = $lock->owner();
    app(KnowledgeRequestJournal::class)->request('kraken', 'BTC/USD', '1m', 'labels', $cutoff);

    $job->handle(app(AlgorithmicLabelBuilder::class));

    $job->assertReleased(5)->assertNotFailed();
    expect(Cache::get($job->cacheKey.':status'))->toBe('queued');
    expect($lock->isOwnedByCurrentProcess())->toBeTrue();
    $this->assertDatabaseEmpty('algorithmic_label_sets');
    $this->travel(1)->minute();
    config(['research.checkpoint_work_seconds' => 240]);
    $continued = unserialize(serialize($job));
    $continued->withFakeQueueInteractions()->handle(app(AlgorithmicLabelBuilder::class));

    $continued->assertNotReleased()->assertNotFailed();
    $this->assertDatabaseHas('algorithmic_label_sets', ['as_of_ms' => $cutoff]);
    $this->assertDatabaseHas('knowledge_stage_requests', ['stage' => 'labels', 'status' => 'completed']);
    expect($lock->isOwnedByCurrentProcess())->toBeFalse();
});

it('releases paused snapshots and dispatches training only after a complete immutable dataset exists', function () {
    IntelligenceFixtures::candles();
    $job = (new BuildOwnerDatasetSnapshot('kraken', 'BTC/USD', '1m', true))->withFakeQueueInteractions();
    app(AlgorithmicLabelBuilder::class)->build('kraken', 'BTC/USD', '1m', $job->requestedAsOfMs);
    Bus::fake([TrainMarketIntelligence::class]);
    config(['research.checkpoint_work_seconds' => 0]);

    $job->handle(app(DatasetSnapshotBuilder::class), app(TickerRepository::class), app(KnowledgeRequestJournal::class));

    $job->assertReleased(5)->assertNotFailed();
    Bus::assertNotDispatched(TrainMarketIntelligence::class);
    $this->assertDatabaseEmpty('research_datasets');
    $saved = json_decode(DB::table('knowledge_build_checkpoints')->value('state'), true);
    $job->withFakeQueueInteractions()->handle(app(DatasetSnapshotBuilder::class), app(TickerRepository::class), app(KnowledgeRequestJournal::class));
    $job->assertReleased(5)->assertNotFailed();
    $continued = json_decode(DB::table('knowledge_build_checkpoints')->value('state'), true);
    expect($continued['snapshot']['feature_through'])->toBeGreaterThan($saved['snapshot']['feature_through']);
    // Live collection continues, but this build must not wait for newer features.
    $this->travel(10)->minutes();
    Ticker::query()->create(['exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
        'microtimestamp' => now()->getTimestampMs() - 60000,
        'payload' => json_encode(['open' => '100', 'high' => '101', 'low' => '99', 'close' => '100', 'volume' => '10'])]);
    app(TickerRepository::class)->invalidateHistory('kraken', 'BTC/USD', '1m');
    config(['research.checkpoint_work_seconds' => 240]);
    $job->withFakeQueueInteractions()->handle(app(DatasetSnapshotBuilder::class), app(TickerRepository::class), app(KnowledgeRequestJournal::class));
    $job->assertNotReleased()->assertNotFailed();
    Bus::assertDispatched(TrainMarketIntelligence::class, fn ($training) => $training->snapshotDataset !== null
        && DB::table('research_datasets')->where('dataset_id', $training->snapshotDataset)->exists());
    $this->assertDatabaseCount('research_datasets', 1);
});

it('releases a paused snapshot even when its temporary directory cannot be removed', function () {
    IntelligenceFixtures::candles();
    $job = (new BuildOwnerDatasetSnapshot('kraken', 'BTC/USD', '1m', true))->withFakeQueueInteractions();
    app(AlgorithmicLabelBuilder::class)->build('kraken', 'BTC/USD', '1m', $job->requestedAsOfMs);
    Bus::fake([TrainMarketIntelligence::class]);
    config(['research.checkpoint_work_seconds' => 0]);
    $temporary = null;
    Event::listen('knowledge.checkpoint.saved', function (string $key, array $state) use (&$temporary): void {
        if ($state['stage'] !== 'snapshot_rows' || ($state['snapshot']['feature_through'] ?? null) === null) {
            return;
        }
        $paths = glob(config('research.path').'/'.$state['id'].'.tmp.*', GLOB_ONLYDIR);
        expect($paths)->toHaveCount(1);
        $temporary = $paths[0];
        file_put_contents($temporary.'/.cleanup-fixture', 'preserve unexpected files');
    });

    $job->handle(app(DatasetSnapshotBuilder::class), app(TickerRepository::class), app(KnowledgeRequestJournal::class));

    $job->assertReleased(5)->assertNotFailed();
    expect($temporary)->not->toBeNull();
    expect(is_file($temporary.'/rows.jsonl'))->toBeFalse();
    expect(is_file($temporary.'/.cleanup-fixture'))->toBeTrue();
    Bus::assertNotDispatched(TrainMarketIntelligence::class);
    $this->assertDatabaseEmpty('research_datasets');
    Event::forget('knowledge.checkpoint.saved');
    config(['research.checkpoint_work_seconds' => 240]);

    $job->withFakeQueueInteractions()->handle(app(DatasetSnapshotBuilder::class), app(TickerRepository::class), app(KnowledgeRequestJournal::class));

    $job->assertNotReleased()->assertNotFailed();
    Bus::assertDispatched(TrainMarketIntelligence::class, fn ($training) => $training->snapshotDataset !== null
        && DB::table('research_datasets')->where('dataset_id', $training->snapshotDataset)->exists());
    $this->assertDatabaseCount('research_datasets', 1);
});

it('finds the last actual closed candle before a frozen cutoff in cold history', function () {
    IntelligenceFixtures::candles(110);
    config(['archive.enabled' => true, 'archive.root' => config('research.path').'/archive']);
    app(TickerArchive::class)->archiveMonth('kraken', 'BTC/USD', '1m', 2024, 1);
    Ticker::query()->delete();
    $repository = app(TickerRepository::class);

    expect($repository->latestClosedTimestamp('kraken', 'BTC/USD', '1m', IntelligenceFixtures::START + 50 * 60000 + 30000))
        ->toBe(IntelligenceFixtures::START + 49 * 60000);
    expect($repository->latestClosedTimestamp('kraken', 'BTC/USD', '1m', now()->getTimestampMs()))
        ->toBe(IntelligenceFixtures::START + 109 * 60000);
    expect($repository->latestClosedTimestamp('kraken', 'BTC/USD', '1m', IntelligenceFixtures::START))->toBeNull();
});

it('rejects a source correction between the final saved rows and snapshot publication', function () {
    $exchange = Exchange::query()->create(['class' => 'kraken', 'name' => 'Kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    IntelligenceFixtures::candles();
    $last = (int) MarketFeature::query()->max('microtimestamp');
    $corrected = false;
    Event::listen('knowledge.checkpoint.saved', function (string $key, array $state) use ($market, $last, &$corrected): void {
        if (! $corrected && ($state['snapshot']['feature_through'] ?? null) === $last) {
            $corrected = true;
            app(HistoryChanges::class)->record($market->market_id, '1m', IntelligenceFixtures::START,
                IntelligenceFixtures::START, 'live_candle_correction');
        }
    });

    expect(fn () => app(DatasetSnapshotBuilder::class)->build('kraken', 'BTC/USD', '1m',
        new SemanticLabels(2, 3), asOfMs: now()->getTimestampMs()))
        ->toThrow(RuntimeException::class, 'Candle history changed during snapshot generation');

    expect($corrected)->toBeTrue();
    $this->assertDatabaseEmpty('research_datasets');
});

it('rolls back both cursor and output when a checkpoint transaction cannot commit', function () {
    $identity = ['kind' => 'snapshot', 'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m'];
    $checkpoint = new BuildCheckpoint($identity);
    $checkpoint->state = ['stage' => 'snapshot_rows', 'processed' => 1];
    $checkpoint->onSave('fixture', fn () => $checkpoint->put('rows', 0, (string) $checkpoint->state['processed']));
    $checkpoint->save();
    DB::statement("CREATE TRIGGER fail_checkpoint BEFORE UPDATE ON knowledge_build_checkpoints BEGIN SELECT RAISE(ABORT, 'checkpoint fixture failure'); END");
    $checkpoint->state['processed'] = 2;
    try {
        expect(fn () => $checkpoint->save())->toThrow(QueryException::class);
    } finally {
        DB::statement('DROP TRIGGER fail_checkpoint');
        $checkpoint->close();
    }

    $resumed = new BuildCheckpoint($identity);
    expect($resumed->state['processed'])->toBe(1);
    expect(iterator_to_array($resumed->chunks('rows')))->toBe(['1']);
    $resumed->close();
});

it('resumes durable work in a different PHP process after SIGKILL', function (string $kind) {
    if (! function_exists('posix_kill')) {
        $this->markTestSkipped('This process interruption test needs POSIX signals.');
    }
    $directory = config('research.path').'/crash';
    File::makeDirectory($directory, 0700, true);
    $run = function (string $mode) use ($directory, $kind): Process {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M',
            base_path('tests/Support/knowledge-checkpoint-crash.php'), $directory, $kind, $mode], base_path());
        $process->setTimeout(90);
        try {
            $process->run();
        } catch (ProcessSignaledException $error) {
            if ($mode !== 'kill' || $process->getTermSignal() !== SIGKILL) {
                throw $error;
            }
        }

        return $process;
    };

    $killed = $run('kill');
    expect($killed->hasBeenSignaled())->toBeTrue($killed->getOutput().$killed->getErrorOutput());
    expect($killed->getTermSignal())->toBe(SIGKILL);
    $resumed = $run('resume');
    expect($resumed->isSuccessful())->toBeTrue($resumed->getOutput().$resumed->getErrorOutput());
    $reference = $run('reference');
    expect($reference->isSuccessful())->toBeTrue($reference->getOutput().$reference->getErrorOutput());
    expect(json_decode($resumed->getOutput(), true, flags: JSON_THROW_ON_ERROR))
        ->toBe(json_decode($reference->getOutput(), true, flags: JSON_THROW_ON_ERROR));
})->with(['labels', 'snapshot']);

it('preserves array-reference Action labels when checkpoints evict mutable candle pages', function (string $pattern) {
    $history = [];
    for ($index = 0; $index < 2600; $index++) {
        if ($index === 1400) {
            continue;
        }
        $flat = $pattern === 'long holds' ? ! in_array($index % 1300, [10, 1200], true) : $index % 11 === 0;
        $direction = $pattern === 'chains' ? (intdiv($index, 25) % 2 ? -1 : 1) : ($index % 2 ? -1 : 1);
        $history[IntelligenceFixtures::START + $index * 60000] = ['open' => '100',
            'close' => $flat ? '100' : ($direction > 0 ? '103.000000000000000001' : '97.000000000000000001'),
            'high' => $flat ? '100' : '104', 'low' => $flat ? '100' : '96', 'volume' => '1'];
    }
    app()->instance(TickerRepository::class, new class($history) extends TickerRepository
    {
        public function __construct(private array $history) {}

        public function streamHistory(string $exchange, string $symbol, string $period, ?int $fromMs = null, ?int $toMs = null): Generator
        {
            foreach ($this->history as $timestamp => $candle) {
                if ($timestamp >= ($fromMs ?? 0) && $timestamp <= ($toMs ?? PHP_INT_MAX)) {
                    yield $timestamp => $candle;
                }
            }
        }
    });
    config(['research.checkpoint_rows' => 512]);
    $asOf = IntelligenceFixtures::START + 2700 * 60000;
    $reference = app(ActionLabelAnalysis::class)->analyze('kraken', 'BTC/USD', '1m', $asOf);

    $set = app(AlgorithmicLabelBuilder::class)->build('kraken', 'BTC/USD', '1m', $asOf);

    expect(iterator_to_array($set['action_labels']))->toBe($reference['_action_labels']);
    expect(iterator_to_array($set['action_available_at_ms']))->toBe($reference['_action_available_at_ms']);
    expect($set['action']['distance_frequencies'])->toBe($reference['distance_frequencies']);
})->with(['alternating', 'chains', 'long holds']);
