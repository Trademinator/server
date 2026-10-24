<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\TrainingRuntime;
use App\Domain\Operations\WorkerRegistry;
use App\Jobs\RebuildBackfilledIntelligence;
use App\Jobs\RecordMarketSignal;
use App\Jobs\TrainMarketIntelligence;
use App\Models\MarketFeed;
use Illuminate\Bus\UniqueLock;
use Illuminate\Cache\RedisStore;
use Illuminate\Console\Command;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class RecoverSignalQueue extends Command
{
    private const DISPATCH_LOCK = 'trademinator:signal-dispatch';

    private const MAX_PAYLOADS = 20000;

    protected $signature = 'trademinator:recover-signal-queue
        {--apply : Apply the backed-up recovery while affected workers on every host are stopped}
        {--workers-stopped : Confirm that workers with lost contact have been stopped on every host}';

    protected $description = 'Inspect or recover the legacy Redis signal backlog without discarding training work';

    public function handle(): int
    {
        $handles = [];
        $dispatchLock = null;
        $maintenanceOwner = null;
        $workers = app(WorkerRegistry::class);
        $backup = null;
        try {
            [$source, $target, $redis, $store, $sourceName, $targetName, $targetConnection] = $this->connections();
            $apply = (bool) $this->option('apply');
            if ($apply) {
                foreach (['intelligence', 'signals'] as $worker) {
                    $handle = fopen(storage_path('framework/trademinator-'.$worker.'-queue.lock'), 'c');
                    if ($handle === false) {
                        throw new RuntimeException('Unable to open the local worker guard.');
                    }
                    $handles[] = $handle;
                    if (! flock($handle, LOCK_EX | LOCK_NB)) {
                        throw new RuntimeException('Stop both intelligence and signals workers before applying recovery.');
                    }
                }
                $dispatchLock = Cache::lock(self::DISPATCH_LOCK, 600);
                if (! $dispatchLock->get()) {
                    throw new RuntimeException('Signal dispatch is running; retry recovery after it finishes.');
                }
                if (! $workers->usesConnection($redis)) {
                    throw new RuntimeException('Queue recovery requires the worker registry and queues on the same Redis connection.');
                }
                $maintenanceOwner = $workers->beginMaintenance();
                $workers->assertQueuesStopped([$sourceName, $targetName], (bool) $this->option('workers-stopped'));
            }

            $snapshots = $this->snapshots($redis, [$source->getQueue($sourceName), $target->getQueue($targetName)]);
            $historyIds = [];
            foreach ($snapshots as &$snapshot) {
                foreach ($snapshot['entries'] as &$entry) {
                    $entry['job'] = $this->job($entry['payload']);
                    if ($entry['job'] instanceof RebuildBackfilledIntelligence) {
                        $historyIds[] = $entry['job']->historyId;
                    }
                }
                unset($entry);
            }
            unset($snapshot);

            return DB::transaction(function () use ($apply, $snapshots, $historyIds, $redis, $store, $target,
                $targetName, $targetConnection, $dispatchLock, $maintenanceOwner, $workers, &$backup): int {
                $history = DB::table('market_history_backfills')->whereIn('history_id', array_unique($historyIds))
                    ->orderBy('history_id')->when($apply, fn ($query) => $query->lockForUpdate())->get()->keyBy('history_id');
                [$keys, $plan, $report, $leases] = $this->plan($snapshots, $history->all(), $redis, $store,
                    $target, $targetName, $targetConnection, $dispatchLock?->owner(), $maintenanceOwner);
                $this->table(['Recovery item', 'Count'], collect($report)->map(fn (int $count, string $name): array => [$name, $count])->all());
                if (! $apply) {
                    $this->info('Dry run complete. Use --apply only after stopping intelligence and signals workers on every host.');

                    return self::SUCCESS;
                }

                $backup = $this->backup($keys, $plan, $history->all(), $report);
                $this->line('Backup: '.$backup);
                if (! $dispatchLock->isOwnedByCurrentProcess()) {
                    throw new RuntimeException('Signal dispatcher guard expired; no recovery was applied.');
                }
                $workers->assertMaintenanceOwner($maintenanceOwner);
                foreach ($leases as $id => $token) {
                    DB::table('market_history_backfills')->where('history_id', $id)->where('build_lease_token', $token)
                        ->update(['build_lease_until' => null, 'updated_at' => now()]);
                }

                // Redis commits atomically; the database lease update is a separate store.
                // A rerun reconciles matching queued tokens if the database commit fails.
                $result = $redis->eval($this->commitScript(), count($keys), ...[...$keys, json_encode($plan, JSON_THROW_ON_ERROR)]);
                if (! is_array($result) || ($result[0] ?? null) !== 1) {
                    throw new RuntimeException('Queue or lock ownership changed during inspection; no Redis recovery was applied. Rerun the dry run.');
                }
                $this->info('Recovery applied. Signal jobs were queued; predictions were not executed.');

                return self::SUCCESS;
            });
        } catch (Throwable $error) {
            $this->error($error::class === RuntimeException::class ? $error->getMessage()
                : 'Recovery could not verify completion. Inspect the private backup and rerun the dry run before retrying.');
            if ($backup !== null) {
                $this->line('Backup: '.$backup);
            }

            return self::FAILURE;
        } finally {
            try {
                if ($maintenanceOwner !== null) {
                    $workers->endMaintenance($maintenanceOwner);
                }
            } catch (Throwable $error) {
                report($error);
            } finally {
                try {
                    $dispatchLock?->release();
                } finally {
                    foreach (array_reverse($handles) as $handle) {
                        flock($handle, LOCK_UN);
                        fclose($handle);
                    }
                }
            }
        }
    }

    /** @return array{RedisQueue, RedisQueue, Connection, RedisStore, string, string, string} */
    private function connections(): array
    {
        $sourceConnection = (string) config('queue.default');
        $targetConnection = (string) (config('intelligence.signals.connection') ?: $sourceConnection);
        $sourceName = (string) config('intelligence.queue', 'intelligence');
        $targetName = (string) config('intelligence.signals.queue', 'signals');
        if ($sourceName === '' || $targetName === '' || $sourceName === $targetName
            || config('queue.connections.'.$sourceConnection.'.driver') !== 'redis'
            || config('queue.connections.'.$targetConnection.'.driver') !== 'redis') {
            throw new RuntimeException('Recovery requires distinct intelligence and signals queues on Redis.');
        }
        $source = Queue::connection($sourceConnection);
        $target = Queue::connection($targetConnection);
        $store = Cache::store()->getStore();
        if (! $source instanceof RedisQueue || ! $target instanceof RedisQueue || ! $store instanceof RedisStore) {
            throw new RuntimeException('Recovery requires Redis queues and a Redis cache lock store.');
        }
        $redis = $source->getConnection();
        if ($redis !== $target->getConnection() || $redis !== $store->lockConnection() || $redis->isCluster()) {
            throw new RuntimeException('Both queues and cache locks must use the same non-cluster Redis connection.');
        }
        if ($redis instanceof PhpRedisConnection && ($redis->serialized() || $redis->compressed())) {
            throw new RuntimeException('Queue recovery requires Redis serialization and compression to be disabled.');
        }

        return [$source, $target, $redis, $store, $sourceName, $targetName, $targetConnection];
    }

    /** @return list<array{key: string, ready: string, state: string, entries: list<array>}> */
    private function snapshots(Connection $redis, array $queues): array
    {
        $snapshots = [];
        $count = 0;
        foreach ($queues as $ready) {
            foreach (['ready', 'delayed', 'reserved'] as $state) {
                $key = $ready.($state === 'ready' ? '' : ':'.$state);
                $entries = [];
                if ($state === 'ready') {
                    foreach ($redis->lrange($key, 0, self::MAX_PAYLOADS) as $index => $payload) {
                        $entries[] = ['payload' => $payload, 'index' => $index];
                    }
                } else {
                    // EVAL gives one portable WITHSCORES shape for PhpRedis and Predis.
                    $rows = $redis->eval("return redis.call('zrange', KEYS[1], 0, ARGV[1], 'WITHSCORES')", 1, $key, self::MAX_PAYLOADS);
                    for ($i = 0; $i < count($rows); $i += 2) {
                        $entries[] = ['payload' => $rows[$i], 'score' => (string) $rows[$i + 1]];
                    }
                }
                $count += count($entries);
                if ($count > self::MAX_PAYLOADS) {
                    throw new RuntimeException('The backlog exceeds the bounded recovery limit of 20000 payloads.');
                }
                $snapshots[] = ['key' => $key, 'ready' => $ready, 'state' => $state, 'entries' => $entries];
            }
        }

        return $snapshots;
    }

    private function job(string $raw): RecordMarketSignal|TrainMarketIntelligence|RebuildBackfilledIntelligence|null
    {
        $payload = json_decode($raw, true);
        $class = $payload['data']['commandName'] ?? null;
        if (! in_array($class, [RecordMarketSignal::class, TrainMarketIntelligence::class, RebuildBackfilledIntelligence::class], true)) {
            return null;
        }
        $command = $payload['data']['command'] ?? null;
        if (! is_string($command)) {
            throw new RuntimeException('A targeted queue payload cannot be safely decoded; recovery was not applied.');
        }

        return $this->deserialize($command, $class);
    }

    private function deserialize(string $command, string $class): RecordMarketSignal|TrainMarketIntelligence|RebuildBackfilledIntelligence
    {
        $job = @unserialize($command, ['allowed_classes' => [$class], 'max_depth' => 64]);
        if (! $job instanceof $class || $this->hasUnsupportedObject(get_object_vars($job))) {
            throw new RuntimeException('A targeted queue payload has unsupported serialized content; recovery was not applied.');
        }

        return $job;
    }

    private function hasUnsupportedObject(mixed $value, int $depth = 0): bool
    {
        if (is_object($value) || $depth > 64) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                if ($this->hasUnsupportedObject($child, $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function refreshTrainingTimeouts(TrainMarketIntelligence|RebuildBackfilledIntelligence $job, int &$count, int $depth = 0): bool
    {
        if (++$count > self::MAX_PAYLOADS || $depth > 32) {
            throw new RuntimeException('Serialized training continuations exceed the bounded recovery limit.');
        }
        $changed = ($job->timeout ?? null) !== TrainingRuntime::jobTimeout();
        $job->timeout = TrainingRuntime::jobTimeout();
        foreach ($job->chained as $index => $serialized) {
            if (! is_string($serialized)) {
                throw new RuntimeException('A training continuation cannot be safely decoded; recovery was not applied.');
            }
            if (! preg_match('/^O:[0-9]+:"([^"]+)":/', $serialized, $matches)
                || ! in_array($matches[1], [TrainMarketIntelligence::class, RebuildBackfilledIntelligence::class], true)) {
                continue;
            }
            $child = $this->deserialize($serialized, $matches[1]);
            if ($this->refreshTrainingTimeouts($child, $count, $depth + 1)) {
                $job->chained[$index] = serialize($child);
                $changed = true;
            }
        }

        return $changed;
    }

    /** @return array{list<string>, array, array<string, int>, array<string, string>} */
    private function plan(array $snapshots, array $history, Connection $redis, RedisStore $store,
        RedisQueue $target, string $targetName, string $targetConnection, ?string $guardOwner, ?string $maintenanceOwner): array
    {
        $keys = [];
        $keyIndex = function (string $key) use (&$keys): int {
            $index = array_search($key, $keys, true);
            if ($index === false) {
                $keys[] = $key;

                return count($keys);
            }

            return $index + 1;
        };
        $report = ['inspected_payloads' => 0, 'old_signal_payloads' => 0, 'superseded_backfill_payloads' => 0,
            'training_timeouts_refreshed' => 0, 'stopped_training_reservations_requeued' => 0,
            'matching_backfill_leases' => 0, 'fresh_signal_jobs' => 0, 'unrepresented_unique_locks_preserved' => 0];
        $plan = ['queues' => [], 'locks' => [], 'signals' => [], 'signal_class' => RecordMarketSignal::class,
            'max_payloads' => self::MAX_PAYLOADS,
            'guard_key' => $keyIndex($store->getPrefix().self::DISPATCH_LOCK), 'guard_owner' => $guardOwner,
            'maintenance_key' => $keyIndex(app(WorkerRegistry::class)->maintenanceKey()), 'maintenance_owner' => $maintenanceOwner,
            'marker' => 'signal-recovery:'.Str::uuid7(), 'target' => $keyIndex($target->getQueue($targetName)),
            'target_notify' => $keyIndex($target->getQueue($targetName).':notify')];
        $oldSignals = [];
        $leases = [];
        $trainingJobs = 0;
        foreach ($snapshots as $snapshot) {
            $queue = ['key' => $keyIndex($snapshot['key']), 'ready' => $keyIndex($snapshot['ready']),
                'notify' => $keyIndex($snapshot['ready'].':notify'), 'state' => $snapshot['state'], 'entries' => []];
            foreach ($snapshot['entries'] as $entry) {
                $report['inspected_payloads']++;
                $job = $entry['job'];
                unset($entry['job']);
                $entry['action'] = 'keep';
                if ($job instanceof RecordMarketSignal) {
                    $oldSignals[$job->marketId][] = $job->uniqueLockOwner ?? '';
                    $entry['action'] = 'remove';
                    $report['old_signal_payloads']++;
                } elseif ($job instanceof TrainMarketIntelligence || $job instanceof RebuildBackfilledIntelligence) {
                    $state = $job instanceof RebuildBackfilledIntelligence ? ($history[$job->historyId] ?? null) : null;
                    if ($job instanceof RebuildBackfilledIntelligence && ($state === null || $state->build_lease_token !== $job->leaseToken)) {
                        $entry['action'] = 'remove';
                        $report['superseded_backfill_payloads']++;
                    } else {
                        if ($job instanceof RebuildBackfilledIntelligence) {
                            $leases[$job->historyId] = $job->leaseToken;
                        }
                        $payload = json_decode($entry['payload'], true, flags: JSON_THROW_ON_ERROR);
                        $changed = $this->refreshTrainingTimeouts($job, $trainingJobs);
                        if ($changed || ($payload['timeout'] ?? null) !== TrainingRuntime::jobTimeout()) {
                            $payload['timeout'] = $job->timeout;
                            $payload['data']['command'] = serialize($job);
                            $entry['replacement'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                            $entry['action'] = 'replace';
                            $report['training_timeouts_refreshed']++;
                        }
                        if ($snapshot['state'] === 'reserved') {
                            $entry['action'] = 'requeue';
                            $entry['replacement'] ??= $entry['payload'];
                            $report['stopped_training_reservations_requeued']++;
                        }
                    }
                }
                $queue['entries'][] = $entry;
            }
            $plan['queues'][] = $queue;
        }
        $report['matching_backfill_leases'] = count($leases);

        $active = config('dashboard.signals_enabled') && config('intelligence.enabled')
            ? MarketFeed::query()->whereNotNull('selected_period')
                ->whereHas('market.subscriptions', fn ($query) => $query->where('active', true))->pluck('market_id')->all()
            : [];
        $encoder = new class($target->getRedis()) extends RedisQueue
        {
            public function encode(RecordMarketSignal $job, string $queue): string
            {
                return $this->createPayload($job, $this->getQueue($queue));
            }
        };
        $encoder->setContainer($this->laravel);
        $encoder->setConnectionName($targetConnection);
        foreach (array_unique([...array_keys($oldSignals), ...$active]) as $marketId) {
            $job = new RecordMarketSignal($marketId);
            $name = UniqueLock::getKey($job);
            $key = $store->getPrefix().$name;
            $currentOwner = $redis->get($key);
            $currentOwner = $currentOwner === false ? null : $currentOwner;
            $owners = $oldSignals[$marketId] ?? [];
            if ($currentOwner !== null && $owners === []) {
                $report['unrepresented_unique_locks_preserved']++;

                continue;
            }
            if ($currentOwner !== null && ! in_array('', $owners, true) && ! in_array($currentOwner, $owners, true)) {
                throw new RuntimeException('A signal lock is owned by work outside the backed-up payloads; recovery was not applied.');
            }
            $lock = ['key' => $keyIndex($key), 'previous_owner' => $currentOwner,
                'previous_ttl_ms' => (int) $redis->pttl($key), 'owner' => null];
            if (in_array($marketId, $active, true)) {
                $job->uniqueLockOwner = Cache::lock($name)->owner();
                $lock['owner'] = $job->uniqueLockOwner;
                $plan['signals'][] = $encoder->encode($job, $targetName);
                $report['fresh_signal_jobs']++;
            }
            $plan['locks'][] = $lock;
        }

        return [$keys, $plan, $report, $leases];
    }

    private function backup(array $keys, array $plan, array $history, array $report): string
    {
        $previousMask = umask(0077);
        $file = false;
        try {
            $directory = storage_path('app/private/signal-queue-recovery');
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new RuntimeException('Unable to create a private recovery backup directory.');
            }
            if (! chmod($directory, 0700)) {
                throw new RuntimeException('Unable to protect the recovery backup directory.');
            }
            $path = $directory.'/'.now()->format('Ymd-His').'-'.Str::uuid7().'.jsonl';
            $file = fopen($path, 'xb');
            if ($file === false) {
                throw new RuntimeException('Unable to create a private recovery backup.');
            }
            $records = [['type' => 'recovery', 'created_at' => now()->toIso8601String(), 'keys' => $keys,
                'counts' => $report, 'locks' => $plan['locks'], 'history' => $history, 'fresh_signals' => $plan['signals']]];
            foreach ($plan['queues'] as $queue) {
                foreach ($queue['entries'] as $entry) {
                    $records[] = ['type' => 'payload', 'key' => $keys[$queue['key'] - 1], 'state' => $queue['state'], ...$entry];
                }
            }
            foreach ($records as $record) {
                $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
                if (fwrite($file, $line) !== strlen($line)) {
                    throw new RuntimeException('Unable to finish the private recovery backup.');
                }
            }
            if (! fflush($file) || ! fsync($file)) {
                throw new RuntimeException('Unable to persist the private recovery backup.');
            }

            return $path;
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
            umask($previousMask);
        }
    }

    private function commitScript(): string
    {
        return <<<'LUA'
local plan = cjson.decode(ARGV[1])
local function signal(payload)
    local valid, job = pcall(cjson.decode, payload)
    return valid and type(job) == 'table' and type(job.data) == 'table' and job.data.commandName == plan.signal_class
end
local function keyType(index, expected)
    local actual = redis.call('type', KEYS[index]).ok
    return actual == 'none' or actual == expected
end
if redis.call('get', KEYS[plan.guard_key]) ~= plan.guard_owner then return {0} end
if redis.call('get', KEYS[plan.maintenance_key]) ~= plan.maintenance_owner then return {0} end
local count = 0
for _, queue in ipairs(plan.queues) do
    if not keyType(queue.key, queue.state == 'ready' and 'list' or 'zset') or not keyType(queue.notify, 'list') then return {0} end
    count = count + redis.call(queue.state == 'ready' and 'llen' or 'zcard', KEYS[queue.key])
    if count > plan.max_payloads then return {0} end
end
for _, queue in ipairs(plan.queues) do
    if queue.state == 'ready' then
        local current = redis.call('lrange', KEYS[queue.key], 0, -1)
        if #current < #queue.entries then return {0} end
        for i, entry in ipairs(queue.entries) do
            if current[i] ~= entry.payload then return {0} end
        end
        for i = #queue.entries + 1, #current do
            if signal(current[i]) then return {0} end
        end
    else
        local current = redis.call('zrange', KEYS[queue.key], 0, -1, 'WITHSCORES')
        local scores = {}
        for i = 1, #current, 2 do scores[current[i]] = current[i + 1] end
        for _, entry in ipairs(queue.entries) do
            if not scores[entry.payload] or tonumber(scores[entry.payload]) ~= tonumber(entry.score) then return {0} end
            scores[entry.payload] = nil
        end
        for payload, _ in pairs(scores) do
            if signal(payload) then return {0} end
        end
    end
end
for _, lock in ipairs(plan.locks) do
    if not keyType(lock.key, 'string') then return {0} end
    local owner = redis.call('get', KEYS[lock.key])
    if lock.previous_owner == cjson.null then
        if owner then return {0} end
    elseif owner ~= lock.previous_owner then return {0} end
end
for _, queue in ipairs(plan.queues) do
    for _, entry in ipairs(queue.entries) do
        if queue.state == 'ready' then
            if entry.action == 'remove' then
                redis.call('lset', KEYS[queue.key], entry.index, plan.marker)
            elseif entry.action == 'replace' then
                redis.call('lset', KEYS[queue.key], entry.index, entry.replacement)
            end
        elseif entry.action ~= 'keep' then
            redis.call('zrem', KEYS[queue.key], entry.payload)
            if entry.action == 'replace' then
                redis.call('zadd', KEYS[queue.key], entry.score, entry.replacement)
            elseif entry.action == 'requeue' then
                redis.call('rpush', KEYS[queue.ready], entry.replacement)
                redis.call('rpush', KEYS[queue.notify], 1)
            end
        end
    end
    if queue.state == 'ready' then redis.call('lrem', KEYS[queue.key], 0, plan.marker) end
end
for _, lock in ipairs(plan.locks) do
    if lock.owner == cjson.null then
        redis.call('pexpire', KEYS[lock.key], 1)
    else
        redis.call('set', KEYS[lock.key], lock.owner)
    end
end
for _, payload in ipairs(plan.signals) do
    redis.call('rpush', KEYS[plan.target], payload)
    redis.call('rpush', KEYS[plan.target_notify], 1)
end
return {1}
LUA;
    }
}
