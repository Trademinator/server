<?php

use App\Domain\Intelligence\TrainingRuntime;
use App\Jobs\BackfillMarketHistory;
use App\Jobs\RebuildBackfilledIntelligence;
use App\Jobs\RecordMarketSignal;
use App\Jobs\TrainMarketIntelligence;
use App\Models\MarketFeed;
use App\Models\MarketSignal;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $socket = getenv('SIGNAL_REPAIR_TEST_REDIS_SOCKET');
    if (! extension_loaded('redis') || ! is_string($socket) || ! str_starts_with($socket, sys_get_temp_dir().'/') || ! file_exists($socket)) {
        $this->markTestSkipped('Set SIGNAL_REPAIR_TEST_REDIS_SOCKET to an isolated temporary Redis Unix socket.');
    }
    $this->recoveryStorage = sys_get_temp_dir().'/trademinator-recovery-test-'.Str::uuid7();
    File::makeDirectory($this->recoveryStorage.'/framework', 0700, true);
    $this->app->useStoragePath($this->recoveryStorage);
    config([
        'database.redis' => ['client' => 'phpredis', 'options' => ['prefix' => 'recovery-test:'.Str::uuid7().':'],
            'recovery' => ['host' => $socket, 'port' => 0, 'database' => 0, 'timeout' => 2, 'read_timeout' => 2]],
        'queue.default' => 'recovery-training',
        'queue.connections.recovery-training' => ['driver' => 'redis', 'connection' => 'recovery', 'queue' => 'intelligence',
            'retry_after' => 15300, 'block_for' => null, 'after_commit' => false],
        'queue.connections.recovery-signals' => ['driver' => 'redis', 'connection' => 'recovery', 'queue' => 'signals',
            'retry_after' => 1200, 'block_for' => null, 'after_commit' => false],
        'cache.default' => 'recovery', 'cache.prefix' => 'cache:',
        'cache.stores.recovery' => ['driver' => 'redis', 'connection' => 'recovery', 'lock_connection' => 'recovery'],
        'dashboard.signals_enabled' => true, 'intelligence.enabled' => true,
        'intelligence.max_seconds' => 14400, 'intelligence.job_timeout' => 15000,
        'intelligence.queue' => 'intelligence', 'intelligence.signals.queue' => 'signals',
        'intelligence.signals.connection' => 'recovery-signals',
    ]);
    config(['queue.connections.recovery-training.retry_after' => TrainingRuntime::retryAfter()]);
    $this->recoveryRedis = app('redis')->connection('recovery');
});

afterEach(function (): void {
    if (isset($this->recoveryStorage)) {
        File::deleteDirectory($this->recoveryStorage);
    }
});

function signalQueueRecoveryMarket(bool $active = true): string
{
    $fixture = MarketSignal::factory()->create();
    $marketId = $fixture->market_id;
    $fixture->delete();
    MarketFeed::query()->create(['market_id' => $marketId, 'selected_period' => '1m']);
    MarketSubscription::query()->create(['market_id' => $marketId, 'user_id' => User::factory()->create()->getKey(), 'active' => $active]);

    return $marketId;
}

function recoveryPayload(object $job, array $metadata = []): string
{
    $queue = Queue::connection('recovery-training');
    $queue->push($job, '', 'recovery-fixture');
    $raw = $queue->getConnection()->lpop($queue->getQueue('recovery-fixture'));

    return json_encode(array_replace(json_decode($raw, true, flags: JSON_THROW_ON_ERROR), $metadata), JSON_THROW_ON_ERROR);
}

function recoveryLegacySignal(string $marketId): RecordMarketSignal
{
    $job = (new RecordMarketSignal($marketId))->onConnection('recovery-training')->onQueue('intelligence');
    $job->uniqueFor = 1200;

    return $job;
}

function recoveryHistory(string $marketId, string $token): string
{
    $id = (string) Str::uuid7();
    DB::table('market_history_backfills')->insert(['history_id' => $id, 'market_id' => $marketId, 'period' => '1m',
        'history_revision' => 3, 'trained_revision' => 1, 'build_revision' => 3, 'build_stage' => 'knn',
        'build_lease_token' => $token, 'build_lease_until' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

it('defaults to a read-only report and leaves payloads, owners, and running lease timestamps unchanged', function (): void {
    $marketId = signalQueueRecoveryMarket();
    $legacy = recoveryLegacySignal($marketId);
    $raw = recoveryPayload($legacy);
    $this->recoveryRedis->rpush('queues:intelligence', $raw);
    $lock = Cache::lock(UniqueLock::getKey($legacy), 1200);
    expect($lock->get())->toBeTrue();
    $token = (string) Str::uuid7();
    $id = recoveryHistory($marketId, $token);
    $rebuild = new RebuildBackfilledIntelligence($id, $token);
    $rebuild->timeout = 900;
    $rebuildRaw = recoveryPayload($rebuild);
    $this->recoveryRedis->zadd('queues:intelligence:reserved', 1700000000, $rebuildRaw);
    $lease = DB::table('market_history_backfills')->where('history_id', $id)->value('build_lease_until');

    $this->artisan('trademinator:recover-signal-queue')
        ->expectsOutput('Dry run complete. Use --apply only after stopping both local queue workers.')->assertSuccessful();

    expect($this->recoveryRedis->lrange('queues:intelligence', 0, -1))->toBe([$raw]);
    expect($this->recoveryRedis->zscore('queues:intelligence:reserved', $rebuildRaw))->toEqual(1700000000);
    expect($this->recoveryRedis->llen('queues:signals'))->toBe(0);
    expect($lock->isOwnedByCurrentProcess())->toBeTrue();
    expect(DB::table('market_history_backfills')->where('history_id', $id)->value('build_lease_until'))->toBe($lease);
    expect(is_dir($this->recoveryStorage.'/app/private/signal-queue-recovery'))->toBeFalse();
});

it('refuses recovery while either local worker guard is held', function (string $worker): void {
    $raw = recoveryPayload(recoveryLegacySignal(signalQueueRecoveryMarket()));
    $this->recoveryRedis->rpush('queues:intelligence', $raw);
    $handle = fopen($this->recoveryStorage.'/framework/trademinator-'.$worker.'-queue.lock', 'c');
    expect(flock($handle, LOCK_EX | LOCK_NB))->toBeTrue();
    try {
        $this->artisan('trademinator:recover-signal-queue', ['--apply' => true])
            ->expectsOutput('Stop both intelligence and signals workers before applying recovery.')->assertFailed();
        expect($this->recoveryRedis->lrange('queues:intelligence', 0, -1))->toBe([$raw]);
        expect($this->recoveryRedis->llen('queues:signals'))->toBe(0);
        expect(is_dir($this->recoveryStorage.'/app/private/signal-queue-recovery'))->toBeFalse();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
})->with(['intelligence', 'signals']);

it('refuses recovery during signal dispatch and does not release the competing owner', function (): void {
    $lock = Cache::lock('trademinator:signal-dispatch', 600);
    expect($lock->get())->toBeTrue();
    try {
        $this->artisan('trademinator:recover-signal-queue', ['--apply' => true])
            ->expectsOutput('Signal dispatch is running; retry recovery after it finishes.')->assertFailed();
        expect($lock->isOwnedByCurrentProcess())->toBeTrue();
    } finally {
        $lock->release();
    }
});

it('atomically coalesces legacy signals, preserves training work, and can be rerun after recovery', function (): void {
    $firstMarket = signalQueueRecoveryMarket();
    $secondMarket = signalQueueRecoveryMarket();
    $inactiveMarket = signalQueueRecoveryMarket(false);
    $legacy = recoveryLegacySignal($firstMarket);
    $oldOwner = Cache::lock(UniqueLock::getKey($legacy), 1200);
    expect($oldOwner->get())->toBeTrue();
    $ownedSignal = new RecordMarketSignal($secondMarket);
    $unique = new UniqueLock(Cache::store());
    expect($unique->acquire($ownedSignal))->toBeTrue();
    $token = (string) Str::uuid7();
    $historyId = recoveryHistory($firstMarket, $token);
    $training = new TrainMarketIntelligence('kraken', 'BTC/USD', '1m', '2026-W43');
    $training->timeout = 900;
    $trainingRaw = recoveryPayload($training, ['timeout' => 900, 'attempts' => 2, 'operator_metadata' => ['retain' => true]]);
    $reservedTraining = new TrainMarketIntelligence('kraken', 'ETH/USD', '1m', '2026-W43');
    $reservedTraining->timeout = 900;
    $reservedRaw = recoveryPayload($reservedTraining, ['timeout' => 900, 'attempts' => 1]);
    $rebuild = new RebuildBackfilledIntelligence($historyId, $token);
    $rebuild->timeout = 900;
    $rebuildRaw = recoveryPayload($rebuild, ['timeout' => 900]);
    $staleRaw = recoveryPayload(new RebuildBackfilledIntelligence($historyId, (string) Str::uuid7()));
    $historical = recoveryPayload(new BackfillMarketHistory((string) Str::uuid7(), (string) Str::uuid7()));
    $historicalSecond = recoveryPayload(new BackfillMarketHistory((string) Str::uuid7(), (string) Str::uuid7()));
    $legacyRaw = recoveryPayload($legacy);
    $inactiveRaw = recoveryPayload(recoveryLegacySignal($inactiveMarket));
    $ownedRaw = recoveryPayload($ownedSignal);
    $originals = [$historical, $legacyRaw, $trainingRaw, $staleRaw, $historicalSecond, $rebuildRaw, $inactiveRaw];
    $this->recoveryRedis->rpush('queues:intelligence', ...$originals);
    $this->recoveryRedis->zadd('queues:intelligence:delayed', 1900000000, $legacyRaw);
    $this->recoveryRedis->zadd('queues:intelligence:delayed', 1900000001, $staleRaw);
    $this->recoveryRedis->zadd('queues:intelligence:delayed', 1900000002, $historical);
    $this->recoveryRedis->zadd('queues:intelligence:reserved', 1700000000, $reservedRaw);
    $this->recoveryRedis->zadd('queues:intelligence:reserved', 1700000001, $historicalSecond);
    $this->recoveryRedis->rpush('queues:signals', $ownedRaw);
    $this->recoveryRedis->zadd('queues:signals:reserved', 1700000000, $legacyRaw);
    $executionLock = Cache::lock('trademinator:intelligence-week:'.$training->uniqueId().':lock', 1020);
    expect($executionLock->get())->toBeTrue();

    $this->artisan('trademinator:recover-signal-queue', ['--apply' => true])->assertSuccessful();

    $ready = $this->recoveryRedis->lrange('queues:intelligence', 0, -1);
    expect($ready)->toHaveCount(5);
    expect($ready[0])->toBe($historical)->and($ready[2])->toBe($historicalSecond);
    $refreshed = json_decode($ready[1], true, flags: JSON_THROW_ON_ERROR);
    $originalTraining = json_decode($trainingRaw, true, flags: JSON_THROW_ON_ERROR);
    expect($refreshed['uuid'])->toBe($originalTraining['uuid'])->and($refreshed['id'])->toBe($originalTraining['id']);
    expect($refreshed['attempts'])->toBe(2)->and($refreshed['operator_metadata'])->toBe(['retain' => true]);
    expect($refreshed['timeout'])->toBe(TrainingRuntime::jobTimeout())
        ->and(unserialize($refreshed['data']['command'])->timeout)->toBe(TrainingRuntime::jobTimeout());
    expect(json_decode($ready[3], true)['data']['commandName'])->toBe(RebuildBackfilledIntelligence::class);
    expect(json_decode($ready[4], true)['attempts'])->toBe(1);
    expect($this->recoveryRedis->zrange('queues:intelligence:delayed', 0, -1))->toBe([$historical]);
    expect($this->recoveryRedis->zscore('queues:intelligence:delayed', $historical))->toEqual(1900000002);
    expect($this->recoveryRedis->zrange('queues:intelligence:reserved', 0, -1))->toBe([$historicalSecond]);
    expect($this->recoveryRedis->zcard('queues:signals:reserved'))->toBe(0);
    expect($executionLock->isOwnedByCurrentProcess())->toBeTrue();
    $this->assertDatabaseHas('market_history_backfills', ['history_id' => $historyId, 'build_lease_token' => $token,
        'build_lease_until' => null, 'build_stage' => 'knn', 'history_revision' => 3, 'trained_revision' => 1]);
    $fresh = array_map(fn (string $raw): array => json_decode($raw, true, flags: JSON_THROW_ON_ERROR), $this->recoveryRedis->lrange('queues:signals', 0, -1));
    expect($fresh)->toHaveCount(2);
    $jobs = array_map(fn (array $payload): RecordMarketSignal => unserialize($payload['data']['command']), $fresh);
    expect(array_column($jobs, 'marketId'))->toEqualCanonicalizing([$firstMarket, $secondMarket]);
    $unique->release($ownedSignal);
    foreach ($jobs as $job) {
        expect($job->queue)->toBe('signals')->and($job->connection)->toBe('recovery-signals')->and($job->uniqueFor)->toBe(0);
        expect($job->uniqueLockOwner)->not->toBe('');
        expect(Cache::restoreLock(UniqueLock::getKey($job), $job->uniqueLockOwner)->isOwnedByCurrentProcess())->toBeTrue();
        expect($this->recoveryRedis->pttl('cache:'.UniqueLock::getKey($job)))->toBe(-1);
    }
    $this->assertDatabaseCount('market_signals', 0);
    $backups = File::files($this->recoveryStorage.'/app/private/signal-queue-recovery');
    expect($backups)->toHaveCount(1);
    expect(fileperms($backups[0]->getPathname()) & 0777)->toBe(0600);
    expect(fileperms($backups[0]->getPath()) & 0777)->toBe(0700);
    $records = array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($backups[0]->getPathname(), FILE_IGNORE_NEW_LINES));
    expect(array_column(array_slice($records, 1), 'payload'))->toContain(...[...$originals, $reservedRaw, $ownedRaw]);

    $this->artisan('trademinator:recover-signal-queue', ['--apply' => true])->assertSuccessful();

    expect($this->recoveryRedis->lrange('queues:intelligence', 0, -1))->toBe($ready);
    expect($this->recoveryRedis->llen('queues:signals'))->toBe(2);
    expect($executionLock->isOwnedByCurrentProcess())->toBeTrue();
    $this->assertDatabaseCount('market_signals', 0);
});

it('preserves unrelated jobs appended after its snapshot', function (): void {
    $legacyRaw = recoveryPayload(recoveryLegacySignal(signalQueueRecoveryMarket()));
    $this->recoveryRedis->rpush('queues:intelligence', $legacyRaw);
    $appended = recoveryPayload(new BackfillMarketHistory((string) Str::uuid7(), (string) Str::uuid7()));
    $injected = false;
    DB::listen(function ($query) use ($appended, &$injected): void {
        if (! $injected && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'market_feeds')) {
            $injected = true;
            $this->recoveryRedis->rpush('queues:intelligence', $appended);
        }
    });

    $this->artisan('trademinator:recover-signal-queue', ['--apply' => true])->assertSuccessful();

    expect($injected)->toBeTrue();
    expect($this->recoveryRedis->lrange('queues:intelligence', 0, -1))->toBe([$appended]);
    expect($this->recoveryRedis->llen('queues:signals'))->toBe(1);
});

it('refreshes nested split continuation timeouts without changing their history identity or order', function (): void {
    $marketId = signalQueueRecoveryMarket();
    $token = (string) Str::uuid7();
    $historyId = recoveryHistory($marketId, $token);
    $first = new RebuildBackfilledIntelligence($historyId, $token, 'core', 1000, 2000, false, 3);
    $last = new RebuildBackfilledIntelligence($historyId, $token, 'technical', 2000, 4000, true, 3);
    $last->timeout = 2200;
    $weekly = new TrainMarketIntelligence('kraken', 'BTC/USD', '1m', '2026-W43', 'core');
    $weekly->timeout = 2200;
    $last->chain([$weekly]);
    $first->chain([$last]);
    $raw = recoveryPayload($first);
    $this->recoveryRedis->rpush('queues:intelligence', $raw);

    $this->artisan('trademinator:recover-signal-queue', ['--apply' => true])->assertSuccessful();

    $payload = json_decode($this->recoveryRedis->lindex('queues:intelligence', 0), true, flags: JSON_THROW_ON_ERROR);
    $saved = unserialize($payload['data']['command']);
    expect($saved->historyId)->toBe($historyId)->and($saved->leaseToken)->toBe($token);
    expect($saved->featureStartMs)->toBe(1000)->and($saved->featureCutoffMs)->toBe(2000)->and($saved->featureFinal)->toBeFalse();
    expect($saved->timeout)->toBe(TrainingRuntime::jobTimeout())->and($saved->chained)->toHaveCount(1);
    $continuation = unserialize($saved->chained[0]);
    expect($continuation->timeout)->toBe(TrainingRuntime::jobTimeout());
    expect($continuation->historyId)->toBe($historyId)->and($continuation->leaseToken)->toBe($token);
    expect($continuation->schema)->toBe('technical')->and($continuation->featureStartMs)->toBe(2000)
        ->and($continuation->featureCutoffMs)->toBe(4000)->and($continuation->featureFinal)->toBeTrue()->and($continuation->featureRevision)->toBe(3);
    $weeklyContinuation = unserialize($continuation->chained[0]);
    expect($weeklyContinuation->timeout)->toBe(TrainingRuntime::jobTimeout());
    expect($weeklyContinuation->exchange)->toBe('kraken')->and($weeklyContinuation->symbol)->toBe('BTC/USD')
        ->and($weeklyContinuation->period)->toBe('1m')->and($weeklyContinuation->week)->toBe('2026-W43')->and($weeklyContinuation->schema)->toBe('core');
});

it('aborts atomically when an unbacked signal appears and rolls back the queued lease update', function (): void {
    $marketId = signalQueueRecoveryMarket();
    $legacy = recoveryLegacySignal($marketId);
    $raw = recoveryPayload($legacy);
    $this->recoveryRedis->rpush('queues:intelligence', $raw);
    $owner = Cache::lock(UniqueLock::getKey($legacy), 1200);
    expect($owner->get())->toBeTrue();
    $token = (string) Str::uuid7();
    $historyId = recoveryHistory($marketId, $token);
    $rebuildRaw = recoveryPayload(new RebuildBackfilledIntelligence($historyId, $token));
    $this->recoveryRedis->rpush('queues:intelligence', $rebuildRaw);
    $lease = DB::table('market_history_backfills')->where('history_id', $historyId)->value('build_lease_until');
    $appended = recoveryPayload($legacy);
    $injected = false;
    DB::listen(function ($query) use ($appended, &$injected): void {
        if (! $injected && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'market_feeds')) {
            $injected = true;
            $this->recoveryRedis->rpush('queues:intelligence', $appended);
        }
    });

    $this->artisan('trademinator:recover-signal-queue', ['--apply' => true])
        ->expectsOutput('Queue or lock ownership changed during inspection; no Redis recovery was applied. Rerun the dry run.')->assertFailed();

    expect($this->recoveryRedis->lrange('queues:intelligence', 0, -1))->toBe([$raw, $rebuildRaw, $appended]);
    expect($this->recoveryRedis->llen('queues:signals'))->toBe(0);
    expect($owner->isOwnedByCurrentProcess())->toBeTrue();
    expect(DB::table('market_history_backfills')->where('history_id', $historyId)->value('build_lease_until'))->toBe($lease);
});

it('preserves an unrepresented durable owner and does not manufacture a replacement for it', function (): void {
    $marketId = signalQueueRecoveryMarket();
    $job = new RecordMarketSignal($marketId);
    $unique = new UniqueLock(Cache::store());
    expect($unique->acquire($job))->toBeTrue();

    $this->artisan('trademinator:recover-signal-queue', ['--apply' => true])->assertSuccessful();

    expect(Cache::restoreLock(UniqueLock::getKey($job), $job->uniqueLockOwner)->isOwnedByCurrentProcess())->toBeTrue();
    expect($this->recoveryRedis->llen('queues:signals'))->toBe(0);
});
