<?php

namespace App\Domain\Operations;

use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Str;
use RuntimeException;

/** Shared worker presence is independent of queue reservations and domain leases. */
final class WorkerRegistry
{
    private const PREFIX = 'trademinator:{workers}:';

    /** @var array{worker_id: string, slot_key: string, owner: string, supervisor_pid: int}|null */
    private ?array $identity = null;

    private ?float $lastGuardAt = null;

    /** @return array<string, mixed>|null */
    public function acquire(string $connection, array $queues, string $slot): ?array
    {
        $this->validateTiming();
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}\z/', $slot)) {
            throw new RuntimeException('A worker slot must contain 1–64 letters, numbers, dots, underscores or hyphens.');
        }
        $hostname = (string) (config('queue_management.workers.hostname') ?: gethostname() ?: 'unknown');
        $hostname = substr(preg_replace('/[^a-zA-Z0-9_.-]/', '_', $hostname), 0, 128);
        $ip = (string) (config('queue_management.workers.ip') ?: gethostbyname($hostname));
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $ip = 'unknown';
        }
        $owner = (string) Str::uuid();
        $workerId = $hostname.':'.$ip.':'.getmypid().':'.$owner;
        $slotKey = self::PREFIX.'slot:'.$hostname.':'.$ip.':'.sha1($connection.'|'.implode(',', $queues)).':'.$slot;
        $now = now()->timestamp;
        $record = [
            'worker_id' => $workerId, 'hostname' => $hostname, 'ip' => $ip, 'slot' => $slot,
            'connection' => $connection, 'queues' => array_values($queues), 'supervisor_pid' => getmypid(),
            'pid' => null, 'started_at' => $now, 'heartbeat_at' => $now, 'ended_at' => null,
            'state' => 'starting', 'job' => null, 'last_job' => null, 'last_progress_at' => null,
            'last_stage' => null, 'slot_key' => $slotKey, 'owner' => $owner,
        ];
        $result = $this->redis()->eval(<<<'LUA'
if redis.call('exists', KEYS[4]) == 1 then return 0 end
if not redis.call('set', KEYS[1], ARGV[1], 'NX', 'EX', ARGV[2]) then return 0 end
redis.call('setex', KEYS[2], ARGV[3], ARGV[4])
redis.call('zadd', KEYS[3], ARGV[5], ARGV[6])
redis.call('zremrangebyscore', KEYS[3], '-inf', ARGV[7])
redis.call('zadd', KEYS[5], ARGV[5], ARGV[6])
return 1
LUA, 5, $slotKey, $this->recordKey($workerId), self::PREFIX.'index', self::PREFIX.'maintenance', self::PREFIX.'active',
            $owner, $this->leaseSeconds(), $this->retentionSeconds(), json_encode($record, JSON_THROW_ON_ERROR),
            $now, $workerId, $now - $this->retentionSeconds());

        return (int) $result === 1 ? $record : null;
    }

    /** @param array<string, mixed> $lease */
    public function heartbeat(array $lease, int $childPid, bool $stopping = false): bool
    {
        return (int) $this->redis()->eval(<<<'LUA'
if redis.call('get', KEYS[1]) ~= ARGV[1] then return 0 end
local raw = redis.call('get', KEYS[2])
if not raw then return 0 end
local record = cjson.decode(raw)
record.heartbeat_at = tonumber(ARGV[2])
record.pid = tonumber(ARGV[3])
if ARGV[4] == '1' then record.state = 'stopping'
elseif record.job and record.job ~= cjson.null then record.state = 'running'
else record.state = 'idle' end
redis.call('expire', KEYS[1], ARGV[5])
redis.call('setex', KEYS[2], ARGV[6], cjson.encode(record))
redis.call('zadd', KEYS[3], ARGV[2], ARGV[7])
redis.call('zremrangebyscore', KEYS[3], '-inf', ARGV[8])
redis.call('zadd', KEYS[4], ARGV[2], ARGV[7])
return 1
LUA, 4, $lease['slot_key'], $this->recordKey($lease['worker_id']), self::PREFIX.'index', self::PREFIX.'active',
            $lease['owner'], now()->timestamp, $childPid, $stopping ? 1 : 0, $this->leaseSeconds(),
            $this->retentionSeconds(), $lease['worker_id'], now()->timestamp - $this->retentionSeconds()) === 1;
    }

    /** Only call after observing that the supervised child has exited. */
    public function finish(array $lease, int $exitCode, ?string $reason = null): void
    {
        $this->redis()->eval(<<<'LUA'
local raw = redis.call('get', KEYS[2])
if raw then
    local record = cjson.decode(raw)
    if record.owner == ARGV[1] then
        record.state = 'stopped'
        record.ended_at = tonumber(ARGV[2])
        record.exit_code = tonumber(ARGV[3])
        record.exit_reason = ARGV[4]
        record.last_job = record.job ~= cjson.null and record.job or record.last_job
        record.job = cjson.null
        redis.call('setex', KEYS[2], ARGV[5], cjson.encode(record))
        redis.call('zrem', KEYS[3], ARGV[6])
    end
end
if redis.call('get', KEYS[1]) == ARGV[1] then redis.call('del', KEYS[1]) end
return 1
LUA, 3, $lease['slot_key'], $this->recordKey($lease['worker_id']), self::PREFIX.'active', $lease['owner'],
            now()->timestamp, $exitCode, $reason ?? '', $this->retentionSeconds(), $lease['worker_id']);
    }

    public function attach(string $workerId, string $slotKey, string $owner, int $supervisorPid): void
    {
        if (! str_starts_with($slotKey, self::PREFIX.'slot:') || $workerId === '' || $owner === '' || $supervisorPid < 1) {
            throw new RuntimeException('The managed worker identity is invalid.');
        }
        $this->identity = ['worker_id' => $workerId, 'slot_key' => $slotKey, 'owner' => $owner,
            'supervisor_pid' => $supervisorPid];
        $this->lastGuardAt = null;
        $this->guard(force: true);
    }

    /** A managed child may never continue after losing its supervisor or ownership. */
    public function guard(bool $beforeClaim = false, bool $force = false): void
    {
        if ($this->identity === null) {
            return;
        }
        $parent = $this->identity['supervisor_pid'];
        $parentAlive = function_exists('posix_getppid') ? posix_getppid() === $parent : is_dir('/proc/'.$parent);
        if (! $parentAlive) {
            throw new RuntimeException('The worker supervisor or Redis slot ownership was lost.');
        }
        $now = hrtime(true) / 1_000_000_000;
        if (! $beforeClaim && ! $force && $this->lastGuardAt !== null && $now - $this->lastGuardAt < 1) {
            return;
        }
        if ($this->redis()->get($this->identity['slot_key']) !== $this->identity['owner']) {
            throw new RuntimeException('The worker supervisor or Redis slot ownership was lost.');
        }
        $this->lastGuardAt = $now;
        if ($beforeClaim && $this->redis()->exists(self::PREFIX.'maintenance')) {
            throw new RuntimeException('Queue maintenance is active; this worker will stop before claiming more work.');
        }
    }

    public function processing(JobProcessing $event): void
    {
        if ($this->identity === null) {
            return;
        }
        $this->guard(force: true);
        $job = ['uuid' => $event->job->uuid(), 'name' => $event->job->resolveName(),
            'queue' => $event->job->getQueue(), 'connection' => $event->connectionName,
            'attempt' => $event->job->attempts(), 'started_at' => now()->timestamp];
        $this->update(['job' => $job, 'state' => 'running', 'last_progress_at' => now()->timestamp,
            'last_stage' => 'job_started']);
    }

    public function attempted(JobAttempted $event): void
    {
        if ($this->identity === null) {
            return;
        }
        $this->update(['job' => null, 'state' => 'idle', 'last_progress_at' => now()->timestamp,
            'last_stage' => 'job_finished', 'last_job' => ['uuid' => $event->job->uuid(),
                'name' => $event->job->resolveName(), 'queue' => $event->job->getQueue(),
                'ended_at' => now()->timestamp, 'outcome' => $event->job->hasFailed() ? 'failed'
                    : ($event->exception !== null ? 'error' : ($event->job->isReleased() ? 'released' : 'completed'))]],
            requireOwnership: false);
    }

    public function progress(string $stage): void
    {
        if ($this->identity !== null) {
            $this->guard();
            $this->update(['last_progress_at' => now()->timestamp, 'last_stage' => substr($stage, 0, 200)]);
        }
    }

    /** @return list<array<string, mixed>> */
    public function snapshot(int $limit = 500): array
    {
        $records = $this->records(max(1, min(2000, $limit)));
        foreach ($records as &$record) {
            $age = max(0, now()->timestamp - (int) $record['heartbeat_at']);
            $record['status'] = $record['state'] === 'stopped' ? 'stopped'
                : ($age >= (int) config('queue_management.workers.lost_seconds', 60) ? 'contact_lost'
                    : ($age >= (int) config('queue_management.workers.overdue_seconds', 30) ? 'overdue' : 'healthy'));
            unset($record['owner'], $record['slot_key']);
        }

        return $records;
    }

    /** Acquire before inspecting all affected workers, closing the start/recovery race. */
    public function beginMaintenance(): string
    {
        $owner = (string) Str::uuid();
        if (! $this->redis()->set(self::PREFIX.'maintenance', $owner, 'EX', 600, 'NX')) {
            throw new RuntimeException('Another queue maintenance operation is active.');
        }

        return $owner;
    }

    public function maintenanceKey(): string
    {
        return self::PREFIX.'maintenance';
    }

    public function usesConnection(Connection $connection): bool
    {
        return $this->redis() === $connection;
    }

    public function assertMaintenanceOwner(string $owner): void
    {
        if ($this->redis()->get(self::PREFIX.'maintenance') !== $owner) {
            throw new RuntimeException('Queue maintenance ownership expired; recovery was not applied.');
        }
    }

    public function endMaintenance(string $owner): void
    {
        $this->redis()->eval("if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0",
            1, self::PREFIX.'maintenance', $owner);
    }

    /** A lost heartbeat is uncertain; only an explicit operator assertion can clear that uncertainty. */
    public function assertQueuesStopped(array $queues, bool $workersStopped = false): void
    {
        $count = (int) $this->redis()->zcard(self::PREFIX.'active');
        if ($count > 10000 && ! $workersStopped) {
            throw new RuntimeException('The worker registry exceeds the recovery inspection limit. Verify affected processes on every host and use --workers-stopped for a batched inspection.');
        }
        // SCAN retains coverage when other supervisors finish or update their scores.
        // Offset pagination could skip a live worker when an earlier entry disappears.
        $cursor = '0';
        do {
            [$cursor, $entries] = $this->redis()->eval("return redis.call('zscan', KEYS[1], ARGV[1], 'COUNT', 250)",
                1, self::PREFIX.'active', $cursor);
            for ($index = 0; $index < count($entries); $index += 2) {
                $id = $entries[$index];
                $raw = $this->redis()->get($this->recordKey($id));
                if (! is_string($raw)) {
                    if (! $workersStopped) {
                        throw new RuntimeException('Some worker records expired without a confirmed exit. Verify affected processes on every host and use --workers-stopped.');
                    }
                    if (now()->timestamp - (int) $entries[$index + 1] < $this->leaseSeconds()) {
                        throw new RuntimeException('A recent worker record is unavailable. Wait for its presence lease to expire and verify the process on its host.');
                    }
                    $this->forgetConfirmedWorker($id, $entries[$index + 1], '', null);

                    continue;
                }
                $record = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                if ($record['state'] === 'stopped' || array_intersect($queues, $record['queues']) === []) {
                    continue;
                }
                $hasLease = $this->redis()->get($record['slot_key']) === $record['owner'];
                $lost = now()->timestamp - (int) $record['heartbeat_at'] >= (int) config('queue_management.workers.lost_seconds', 60);
                if ($workersStopped && ! $hasLease && $lost) {
                    $this->forgetConfirmedWorker($id, $entries[$index + 1], $raw, $record);

                    continue;
                }
                throw new RuntimeException('Stop intelligence and signals workers on every host before recovery. Worker '.$record['worker_id']
                    .($hasLease ? ' still owns its slot.' : ' has uncertain status; after verifying every host, use --workers-stopped.'));
            }
        } while ((string) $cursor !== '0');
    }

    public function heartbeatSeconds(): int
    {
        return max(1, (int) config('queue_management.workers.heartbeat_seconds', 10));
    }

    private function update(array $changes, bool $requireOwnership = true): void
    {
        $result = $this->redis()->eval(<<<'LUA'
if redis.call('get', KEYS[1]) ~= ARGV[1] then return 0 end
local raw = redis.call('get', KEYS[2])
if not raw then return 0 end
local record = cjson.decode(raw)
local changes = cjson.decode(ARGV[2])
for key, value in pairs(changes) do record[key] = value end
redis.call('setex', KEYS[2], ARGV[3], cjson.encode(record))
return 1
LUA, 2, $this->identity['slot_key'], $this->recordKey($this->identity['worker_id']),
            $this->identity['owner'], json_encode($changes, JSON_THROW_ON_ERROR), $this->retentionSeconds());
        if ($requireOwnership && (int) $result !== 1) {
            throw new RuntimeException('The worker Redis slot ownership was lost.');
        }
    }

    private function forgetConfirmedWorker(string $id, string $score, string $raw, ?array $record): void
    {
        $removed = $this->redis()->eval(<<<'LUA'
local score = redis.call('zscore', KEYS[1], ARGV[1])
if not score then return 1 end
if score ~= ARGV[2] then return 0 end
if (redis.call('get', KEYS[2]) or '') ~= ARGV[3] then return 0 end
if ARGV[4] ~= '' and redis.call('get', KEYS[3]) == ARGV[4] then return 0 end
return redis.call('zrem', KEYS[1], ARGV[1])
LUA, 3, self::PREFIX.'active', $this->recordKey($id), $record['slot_key'] ?? $this->recordKey($id),
            $id, $score, $raw, $record['owner'] ?? '');
        if ((int) $removed !== 1) {
            throw new RuntimeException('Worker status changed during verification; retry after confirming that every affected worker has stopped.');
        }
    }

    /** @return list<array<string, mixed>> */
    private function records(int $limit): array
    {
        $ids = $this->redis()->zrevrange(self::PREFIX.'index', 0, $limit - 1);
        if ($ids === []) {
            return [];
        }
        $values = $this->redis()->mget(array_map(fn (string $id): string => $this->recordKey($id), $ids));
        $records = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $record = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
                if (is_array($record)) {
                    $records[] = $record;
                }
            }
        }

        return $records;
    }

    private function recordKey(string $id): string
    {
        return self::PREFIX.'record:'.$id;
    }

    private function redis(): Connection
    {
        return app('redis')->connection((string) config('queue_management.workers.connection', 'default'));
    }

    private function leaseSeconds(): int
    {
        return (int) config('queue_management.workers.lease_seconds', 90);
    }

    private function retentionSeconds(): int
    {
        return max($this->leaseSeconds() * 2, (int) config('queue_management.workers.retention_seconds', 604800));
    }

    private function validateTiming(): void
    {
        $heartbeat = $this->heartbeatSeconds();
        $overdue = (int) config('queue_management.workers.overdue_seconds', 30);
        $lost = (int) config('queue_management.workers.lost_seconds', 60);
        if ($overdue <= $heartbeat || $lost <= $overdue || $this->leaseSeconds() <= $lost) {
            throw new RuntimeException('Worker timing must satisfy heartbeat < overdue < contact lost < lease expiry.');
        }
    }
}
