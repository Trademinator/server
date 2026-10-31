<?php

use App\Domain\Operations\WorkerRegistry;
use App\Jobs\AnalyzeMarketAutoLabels;
use App\Jobs\BuildOwnerDatasetSnapshot;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    $port = getenv('QUEUE_TEST_REDIS_PORT');
    if (! extension_loaded('redis') || ! is_string($port) || ! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        $this->markTestSkipped('Set QUEUE_TEST_REDIS_PORT to an isolated localhost Redis server to run label recovery tests.');
    }
    $this->freezeTime();
    $this->labelRecoveryStorage = sys_get_temp_dir().'/trademinator-label-recovery-'.Str::uuid7();
    File::makeDirectory($this->labelRecoveryStorage.'/framework', 0700, true);
    $this->app->useStoragePath($this->labelRecoveryStorage);
    config([
        'database.redis' => ['client' => 'phpredis', 'options' => ['prefix' => 'label-recovery:'.Str::uuid7().':'],
            'isolated' => ['host' => '127.0.0.1', 'port' => (int) $port, 'database' => 15]],
        'queue.default' => 'label-recovery',
        'queue.connections.label-recovery' => ['driver' => 'redis', 'connection' => 'isolated',
            'queue' => 'intelligence', 'retry_after' => 40277700],
        'queue_management.workers.connection' => 'isolated',
        'cache.default' => 'label-recovery', 'cache.prefix' => 'cache:',
        'cache.stores.label-recovery' => ['driver' => 'redis', 'connection' => 'isolated'],
        'intelligence.queue' => 'intelligence', 'intelligence.max_seconds' => 86400,
    ]);
    $this->labelRedis = app('redis')->connection('isolated');
    $this->labelRecoveryArguments = ['exchange' => 'binance', 'symbol' => 'XRP/USDC', 'period' => '1m'];
});

afterEach(function (): void {
    if (isset($this->labelRedis)) {
        $keys = $this->labelRedis->keys('*');
        if ($keys !== []) {
            $this->labelRedis->client()->rawCommand('DEL', ...$keys);
        }
    }
    if (isset($this->labelRecoveryStorage)) {
        File::deleteDirectory($this->labelRecoveryStorage);
    }
});

function labelRecoveryPayload(string $symbol = 'XRP/USDC', int $attempts = 1): string
{
    $job = new AnalyzeMarketAutoLabels('binance', $symbol, '1m', 'label-recovery:'.$symbol, 1700000000000);
    $job->timeout = 900;
    $job->failOnTimeout = false;
    $queue = Queue::connection();
    $queue->push($job, '', 'fixture');
    $payload = json_decode($queue->getConnection()->lpop($queue->key('fixture')), true, flags: JSON_THROW_ON_ERROR);
    $payload['attempts'] = $attempts;
    $payload['operator_metadata'] = ['preserve' => true];

    return json_encode($payload, JSON_THROW_ON_ERROR);
}

it('defaults to a read-only report without changing queued jobs or locks', function (): void {
    $raw = labelRecoveryPayload();
    $this->labelRedis->zadd('queues:intelligence:reserved', 1833494400, $raw);
    $lock = Cache::lock('label-recovery:XRP/USDC:lock', 1200);
    $lock->get();

    $this->artisan('trademinator:recover-label-queue', $this->labelRecoveryArguments)
        ->expectsOutput('Dry run only. Stop intelligence workers on every host before using --apply --workers-stopped.')
        ->assertSuccessful();

    expect($this->labelRedis->zscore('queues:intelligence:reserved', $raw))->toEqual(1833494400);
    expect($this->labelRedis->llen('queues:intelligence'))->toBe(0);
    expect($lock->isOwnedByCurrentProcess())->toBeTrue();
    expect(is_dir($this->labelRecoveryStorage.'/app/private/label-queue-recovery'))->toBeFalse();
    expect($this->labelRedis->exists(app(WorkerRegistry::class)->maintenanceKey()))->toBe(0);
});

it('requires explicit confirmation that workers on every host are stopped', function (): void {
    $raw = labelRecoveryPayload();
    $this->labelRedis->zadd('queues:intelligence:reserved', 1833494400, $raw);

    $this->artisan('trademinator:recover-label-queue', [...$this->labelRecoveryArguments, '--apply' => true])
        ->expectsOutput('Stop intelligence workers on every host, then confirm with --workers-stopped.')->assertFailed();

    expect($this->labelRedis->zscore('queues:intelligence:reserved', $raw))->toEqual(1833494400);
    expect(is_dir($this->labelRecoveryStorage.'/app/private/label-queue-recovery'))->toBeFalse();
});

it('refuses a live managed worker even when workers-stopped is supplied', function (): void {
    $raw = labelRecoveryPayload();
    $this->labelRedis->zadd('queues:intelligence:reserved', 1833494400, $raw);
    $workers = app(WorkerRegistry::class);
    $lease = $workers->acquire('label-recovery', ['intelligence'], 'remote');

    $this->artisan('trademinator:recover-label-queue', [...$this->labelRecoveryArguments, '--apply' => true, '--workers-stopped' => true])
        ->expectsOutput('Stop intelligence and signals workers on every host before recovery. Worker '.$lease['worker_id'].' still owns its slot.')
        ->assertFailed();

    expect($this->labelRedis->zscore('queues:intelligence:reserved', $raw))->toEqual(1833494400);
    expect($this->labelRedis->get($lease['slot_key']))->toBe($lease['owner']);
    expect($this->labelRedis->exists($workers->maintenanceKey()))->toBe(0);
    expect(is_dir($this->labelRecoveryStorage.'/app/private/label-queue-recovery'))->toBeFalse();
});

it('refuses the local legacy worker guard without modifying reservations', function (): void {
    $raw = labelRecoveryPayload();
    $this->labelRedis->zadd('queues:intelligence:reserved', 1833494400, $raw);
    $guard = fopen($this->labelRecoveryStorage.'/framework/trademinator-intelligence-queue.lock', 'c');
    flock($guard, LOCK_EX | LOCK_NB);
    try {
        $this->artisan('trademinator:recover-label-queue', [...$this->labelRecoveryArguments, '--apply' => true, '--workers-stopped' => true])
            ->expectsOutput('Stop the local intelligence worker before applying recovery.')->assertFailed();

        expect($this->labelRedis->zscore('queues:intelligence:reserved', $raw))->toEqual(1833494400);
    } finally {
        flock($guard, LOCK_UN);
        fclose($guard);
    }
});

it('backs up and refreshes only the selected market while preserving identities cutoffs retries and unrelated work', function (): void {
    $readyRaw = labelRecoveryPayload(attempts: 0);
    $reservedRaw = labelRecoveryPayload(attempts: 2);
    $delayedRaw = labelRecoveryPayload();
    $unrelatedRaw = labelRecoveryPayload('BTC/USDC');
    $queue = Queue::connection();
    $queue->push(new BuildOwnerDatasetSnapshot('binance', 'XRP/USDC', '1m', true), '', 'fixture');
    $snapshotRaw = $this->labelRedis->lpop($queue->key('fixture'));
    $this->labelRedis->rpush('queues:intelligence', $unrelatedRaw, $readyRaw, $snapshotRaw);
    $this->labelRedis->zadd('queues:intelligence:delayed', 1833494401, $delayedRaw);
    $this->labelRedis->zadd('queues:intelligence:reserved', 1833494400, $reservedRaw);
    $this->labelRedis->zadd('queues:intelligence:reserved', 1833494402, $unrelatedRaw);
    $lock = Cache::lock('label-recovery:XRP/USDC:lock', 1200);
    $lock->get();
    $arguments = [...$this->labelRecoveryArguments, '--apply' => true, '--workers-stopped' => true];

    $this->artisan('trademinator:recover-label-queue', $arguments)->assertSuccessful();

    $ready = $this->labelRedis->lrange('queues:intelligence', 0, -1);
    expect($ready)->toHaveCount(4);
    expect($ready[0])->toBe($unrelatedRaw);
    expect($ready[2])->toBe($snapshotRaw);
    $delayed = $this->labelRedis->zrange('queues:intelligence:delayed', 0, -1);
    expect($delayed)->toHaveCount(1);
    expect($this->labelRedis->zscore('queues:intelligence:delayed', $delayed[0]))->toEqual(1833494401);
    expect($this->labelRedis->zrange('queues:intelligence:reserved', 0, -1))->toBe([$unrelatedRaw]);
    expect($this->labelRedis->zscore('queues:intelligence:reserved', $unrelatedRaw))->toEqual(1833494402);
    foreach ([[$ready[1], $readyRaw, 2], [$ready[3], $reservedRaw, 3], [$delayed[0], $delayedRaw, 2]] as [$updated, $original, $tries]) {
        $new = json_decode($updated, true, flags: JSON_THROW_ON_ERROR);
        $old = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
        foreach (['uuid', 'id', 'attempts', 'operator_metadata', 'queue_context'] as $field) {
            expect($new[$field])->toBe($old[$field]);
        }
        expect($new['timeout'])->toBe(173400);
        expect($new['failOnTimeout'])->toBeFalse();
        expect($new['maxExceptions'])->toBe(3);
        expect($new['maxTries'])->toBe(0);
        $job = unserialize($new['data']['command']);
        expect($job->asOfMs)->toBe(1700000000000);
        expect($job->timeout)->toBe(173400);
        expect($job->failOnTimeout)->toBeFalse();
        expect($job->tries)->toBe(0);
    }
    expect($lock->isOwnedByCurrentProcess())->toBeTrue();
    $backups = File::files($this->labelRecoveryStorage.'/app/private/label-queue-recovery');
    expect($backups)->toHaveCount(1);
    expect(fileperms($backups[0]->getPathname()) & 0777)->toBe(0600);
    expect(fileperms($backups[0]->getPath()) & 0777)->toBe(0700);
    $records = array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($backups[0]->getPathname(), FILE_IGNORE_NEW_LINES));
    expect(array_column(array_slice($records, 1), 'payload'))->toContain($readyRaw, $reservedRaw, $delayedRaw, $unrelatedRaw, $snapshotRaw);

    $this->artisan('trademinator:recover-label-queue', $arguments)
        ->expectsOutput('No label recovery changes are needed.')->assertSuccessful();

    expect($this->labelRedis->lrange('queues:intelligence', 0, -1))->toBe($ready);
    expect($this->labelRedis->zrange('queues:intelligence:delayed', 0, -1))->toBe($delayed);
    expect(File::files($this->labelRecoveryStorage.'/app/private/label-queue-recovery'))->toHaveCount(1);
});

it('aborts atomically when another producer appends a job during recovery', function (): void {
    $raw = labelRecoveryPayload();
    $appended = labelRecoveryPayload('BTC/USDC');
    $this->labelRedis->zadd('queues:intelligence:reserved', 1833494400, $raw);
    $redis = $this->labelRedis;
    $output = new class(function () use ($redis, $appended): void {
        $redis->rpush('queues:intelligence', $appended);
    }) extends BufferedOutput
    {
        private bool $injected = false;

        public function __construct(private Closure $append)
        {
            parent::__construct();
        }

        protected function doWrite(string $message, bool $newline): void
        {
            if (! $this->injected && str_contains($message, 'Backup:')) {
                $this->injected = true;
                ($this->append)();
            }
            parent::doWrite($message, $newline);
        }
    };

    $exit = Artisan::call('trademinator:recover-label-queue', [...$this->labelRecoveryArguments, '--apply' => true, '--workers-stopped' => true], $output);

    expect($exit)->toBe(1);
    expect($output->fetch())->toContain('Queue changed during inspection; no recovery was applied.');
    expect($this->labelRedis->lrange('queues:intelligence', 0, -1))->toBe([$appended]);
    expect($this->labelRedis->zscore('queues:intelligence:reserved', $raw))->toEqual(1833494400);
    expect($this->labelRedis->exists(app(WorkerRegistry::class)->maintenanceKey()))->toBe(0);
});

it('refuses unsupported serialized label content before changing any queue', function (): void {
    $raw = json_decode(labelRecoveryPayload(), true, flags: JSON_THROW_ON_ERROR);
    $raw['data']['command'] = serialize(new stdClass);
    $raw = json_encode($raw, JSON_THROW_ON_ERROR);
    $this->labelRedis->zadd('queues:intelligence:reserved', 1833494400, $raw);

    $this->artisan('trademinator:recover-label-queue', [...$this->labelRecoveryArguments, '--apply' => true, '--workers-stopped' => true])
        ->expectsOutput('A label payload cannot be safely decoded; recovery was not applied.')->assertFailed();

    expect($this->labelRedis->zscore('queues:intelligence:reserved', $raw))->toEqual(1833494400);
    expect(is_dir($this->labelRecoveryStorage.'/app/private/label-queue-recovery'))->toBeFalse();
});

it('leaves reservations untouched when a private backup cannot be created', function (): void {
    $raw = labelRecoveryPayload();
    $this->labelRedis->zadd('queues:intelligence:reserved', 1833494400, $raw);
    File::makeDirectory($this->labelRecoveryStorage.'/app/private/label-queue-recovery', 0700, true);
    $directory = $this->labelRecoveryStorage.'/app/private/label-queue-recovery';
    File::deleteDirectory($directory);
    file_put_contents($directory, 'This is a file, not a backup directory.');

    $this->artisan('trademinator:recover-label-queue', [...$this->labelRecoveryArguments, '--apply' => true, '--workers-stopped' => true])
        ->assertFailed();

    expect($this->labelRedis->zscore('queues:intelligence:reserved', $raw))->toEqual(1833494400);
    expect($this->labelRedis->llen('queues:intelligence'))->toBe(0);
    expect($this->labelRedis->exists(app(WorkerRegistry::class)->maintenanceKey()))->toBe(0);
});
