<?php

use App\Domain\Operations\WorkerRegistry;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $port = getenv('QUEUE_WORKER_TEST_REDIS_PORT');
    if (! extension_loaded('redis') || ! is_string($port) || ! ctype_digit($port)) {
        $this->markTestSkipped('Set QUEUE_WORKER_TEST_REDIS_PORT to an isolated localhost Redis server.');
    }
    $this->workerPrefix = 'worker-test:'.Str::uuid().':';
    config([
        'database.redis' => ['client' => 'phpredis', 'options' => ['prefix' => $this->workerPrefix],
            'worker-registry' => ['host' => '127.0.0.1', 'port' => (int) $port, 'database' => 14,
                'timeout' => 2, 'read_timeout' => 2]],
        'queue_management.workers.connection' => 'worker-registry',
        'queue_management.workers.hostname' => 'worker-a', 'queue_management.workers.ip' => '192.0.2.10',
        'queue_management.workers.heartbeat_seconds' => 10, 'queue_management.workers.overdue_seconds' => 30,
        'queue_management.workers.lost_seconds' => 60, 'queue_management.workers.lease_seconds' => 90,
        'queue_management.workers.retention_seconds' => 180,
    ]);
    $this->workerRedis = app('redis')->connection('worker-registry');
});

afterEach(function (): void {
    if (isset($this->workerRedis)) {
        $keys = $this->workerRedis->keys('*');
        if ($keys !== []) {
            $this->workerRedis->client()->rawCommand('DEL', ...$keys);
        }
    }
});

it('excludes overlapping launches in one slot and allows other slots and hosts', function (): void {
    $workers = new WorkerRegistry;
    $first = $workers->acquire('redis', ['intelligence'], '1');
    expect($workers->acquire('redis', ['intelligence'], '1'))->toBeNull();
    expect($workers->acquire('redis', ['intelligence'], '2'))->not->toBeNull();
    config(['queue_management.workers.hostname' => 'worker-b', 'queue_management.workers.ip' => '192.0.2.20']);
    expect($workers->acquire('redis', ['intelligence'], '1'))->not->toBeNull();
    expect($workers->snapshot())->toHaveCount(3);
    expect($first['slot_key'])->toContain('worker-a:192.0.2.10:');
    expect($first['worker_id'])->toContain('worker-a:192.0.2.10:');
    expect($workers->snapshot()[0])->not->toHaveKeys(['owner', 'slot_key']);
});

it('never renews or releases a successor slot and retains the stopped predecessor', function (): void {
    $workers = new WorkerRegistry;
    $first = $workers->acquire('redis', ['features'], '1');
    $this->workerRedis->del($first['slot_key']);
    $second = $workers->acquire('redis', ['features'], '1');
    expect($workers->heartbeat($first, 111))->toBeFalse();
    $workers->finish($first, 1, 'ownership_lost');
    expect($this->workerRedis->get($second['slot_key']))->toBe($second['owner']);
    expect(collect($workers->snapshot())->keyBy('worker_id')->get($first['worker_id']))
        ->toMatchArray(['status' => 'stopped', 'exit_code' => 1]);
    expect($workers->heartbeat($second, 222))->toBeTrue();
});

it('shows overdue and lost contact while preserving last-known details and queue reservations', function (): void {
    $this->freezeTime();
    $workers = new WorkerRegistry;
    $lease = $workers->acquire('redis', ['intelligence'], '1');
    $this->workerRedis->zadd('queues:intelligence:reserved', 9999999999, 'reserved-job');
    $this->travel(31)->seconds();
    expect($workers->snapshot()[0]['status'])->toBe('overdue');
    $this->travel(30)->seconds();
    $this->workerRedis->del($lease['slot_key']);
    expect($workers->snapshot()[0])->toMatchArray(['status' => 'contact_lost', 'hostname' => 'worker-a']);
    expect($this->workerRedis->zscore('queues:intelligence:reserved', 'reserved-job'))->toEqual(9999999999);
});

it('separates a heartbeat from job progress and records the current execution', function (): void {
    $this->freezeTime();
    $workers = new WorkerRegistry;
    $lease = $workers->acquire('redis', ['intelligence'], '1');
    $workers->attach($lease['worker_id'], $lease['slot_key'], $lease['owner'], posix_getppid());
    $uuid = (string) Str::uuid();
    $job = new SyncJob(app(), json_encode(['uuid' => $uuid, 'displayName' => 'Training probe',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call']), 'redis', 'intelligence');
    $workers->processing(new JobProcessing('redis', $job));
    $progressAt = now()->timestamp;
    $this->travel(20)->seconds();
    expect($workers->heartbeat($lease, 1234))->toBeTrue();
    $record = $workers->snapshot()[0];
    expect($record)->toMatchArray(['state' => 'running', 'status' => 'healthy', 'pid' => 1234,
        'last_progress_at' => $progressAt, 'last_stage' => 'job_started']);
    expect($record['job'])->toMatchArray(['uuid' => $uuid, 'name' => 'Training probe', 'started_at' => $progressAt]);
    expect($record['heartbeat_at'])->toBe($progressAt + 20);
    $workers->progress('holdout');
    expect($workers->snapshot()[0])->toMatchArray(['last_progress_at' => $progressAt + 20, 'last_stage' => 'holdout']);
    $workers->attempted(new JobAttempted('redis', $job));
    expect($workers->snapshot()[0])->toMatchArray(['state' => 'idle', 'job' => null]);
    expect($workers->snapshot()[0]['last_job'])->toMatchArray(['uuid' => $uuid, 'outcome' => 'completed']);
});

it('fences new claims and forced checkpoints after slot loss without altering reservations', function (): void {
    $workers = new WorkerRegistry;
    $lease = $workers->acquire('redis', ['intelligence'], '1');
    $workers->attach($lease['worker_id'], $lease['slot_key'], $lease['owner'], posix_getppid());
    $this->workerRedis->zadd('queues:intelligence:reserved', 9999999999, 'reserved-job');
    $this->workerRedis->del($lease['slot_key']);
    expect(fn () => $workers->guard(beforeClaim: true))->toThrow(RuntimeException::class, 'ownership was lost');
    expect(fn () => $workers->guard(force: true))->toThrow(RuntimeException::class, 'ownership was lost');
    expect($this->workerRedis->zcard('queues:intelligence:reserved'))->toBe(1);
});

it('rejects an orphaned child even while its old slot lease remains valid', function (): void {
    $workers = new WorkerRegistry;
    $lease = $workers->acquire('redis', ['intelligence'], '1');
    expect(fn () => $workers->attach($lease['worker_id'], $lease['slot_key'], $lease['owner'], posix_getppid() + 100000))
        ->toThrow(RuntimeException::class, 'supervisor');
    expect($this->workerRedis->get($lease['slot_key']))->toBe($lease['owner']);
});

it('blocks new workers and claims during maintenance while allowing an owned running job to finish', function (): void {
    $workers = new WorkerRegistry;
    $lease = $workers->acquire('redis', ['intelligence'], '1');
    $workers->attach($lease['worker_id'], $lease['slot_key'], $lease['owner'], posix_getppid());
    $maintenance = $workers->beginMaintenance();
    expect($workers->acquire('redis', ['features'], '2'))->toBeNull();
    expect(fn () => $workers->guard(beforeClaim: true))->toThrow(RuntimeException::class, 'maintenance');
    $workers->guard(force: true);
    expect(fn () => $workers->assertQueuesStopped(['intelligence'], true))->toThrow(RuntimeException::class, 'still owns its slot');
    $workers->endMaintenance('another-owner');
    $workers->assertMaintenanceOwner($maintenance);
    $workers->finish($lease, 0);
    $workers->assertQueuesStopped(['intelligence']);
    $workers->endMaintenance($maintenance);
    expect($workers->acquire('redis', ['features'], '2'))->not->toBeNull();
});

it('requires an explicit stopped-worker assertion for lost contact during recovery', function (): void {
    $this->freezeTime();
    $workers = new WorkerRegistry;
    $lease = $workers->acquire('redis', ['signals'], '1');
    $this->workerRedis->del($lease['slot_key']);
    $this->travel(61)->seconds();
    expect(fn () => $workers->assertQueuesStopped(['signals']))->toThrow(RuntimeException::class, 'uncertain status');
    $workers->assertQueuesStopped(['signals'], workersStopped: true);
    expect($workers->snapshot()[0]['status'])->toBe('contact_lost');
    expect($this->workerRedis->zcard('trademinator:{workers}:active'))->toBe(0);
});

it('refuses to acknowledge a missing recent record while its presence lease may still be live', function (): void {
    $this->freezeTime();
    $workers = new WorkerRegistry;
    $lease = $workers->acquire('redis', ['signals'], '1');
    $this->workerRedis->del('trademinator:{workers}:record:'.$lease['worker_id']);
    expect(fn () => $workers->assertQueuesStopped(['signals'], workersStopped: true))
        ->toThrow(RuntimeException::class, 'presence lease');
    expect($this->workerRedis->get($lease['slot_key']))->toBe($lease['owner']);
    expect($this->workerRedis->zcard('trademinator:{workers}:active'))->toBe(1);
    $this->travel(91)->seconds();
    $this->workerRedis->del($lease['slot_key']);
    $workers->assertQueuesStopped(['signals'], workersStopped: true);
    expect($this->workerRedis->zcard('trademinator:{workers}:active'))->toBe(0);
});

it('cleans acknowledged expired records in batches beyond the ordinary recovery inspection limit', function (): void {
    $this->freezeTime();
    $workers = new WorkerRegistry;
    $this->workerRedis->eval(<<<'LUA'
for i = 1, 10001 do redis.call('zadd', KEYS[1], ARGV[1], 'expired-worker:' .. i) end
return 1
LUA, 1, 'trademinator:{workers}:active', now()->subDays(8)->timestamp);
    $this->workerRedis->zadd('queues:signals:reserved', 9999999999, 'reserved-job');
    expect(fn () => $workers->assertQueuesStopped(['signals']))->toThrow(RuntimeException::class, 'inspection limit');
    $workers->assertQueuesStopped(['signals'], workersStopped: true);
    expect($this->workerRedis->zcard('trademinator:{workers}:active'))->toBe(0);
    expect($this->workerRedis->zscore('queues:signals:reserved', 'reserved-job'))->toEqual(9999999999);
});

it('rejects unsafe heartbeat timing before acquiring a slot', function (): void {
    config(['queue_management.workers.lease_seconds' => 60]);
    expect(fn () => (new WorkerRegistry)->acquire('redis', ['intelligence'], '1'))
        ->toThrow(RuntimeException::class, 'heartbeat < overdue < contact lost < lease expiry');
    expect($this->workerRedis->keys('*'))->toBe([]);
});
