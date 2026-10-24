<?php

namespace App\Domain\Operations;

use Illuminate\Queue\Attributes\Delay;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Queue\LuaScripts;
use Illuminate\Queue\RedisQueue;
use RuntimeException;

use function Illuminate\Support\enum_value;

/** Native Laravel payloads and reservations, with shared ordering before atomic claim. */
class ManagedRedisQueue extends RedisQueue
{
    public function key(string $queue): string
    {
        return $this->getQueueRedisKey($queue);
    }

    public function controlKey(string $queue, string $suffix): string
    {
        return 'trademinator:queue-control:'.$this->key($queue).':'.$suffix;
    }

    protected function createPayloadArray($job, $queue, $data = '')
    {
        $payload = parent::createPayloadArray($job, $queue, $data);
        if (is_object($job)) {
            $payload['queue_context'] = app(QueuePayload::class)->metadata($job, (int) $payload['createdAt']);
        }

        return $payload;
    }

    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $name = enum_value($queue) ?: $this->default;
        $key = $this->key($name);
        $this->getConnection()->eval(LuaScripts::push()."\nredis.call('incr', KEYS[3])", 3,
            $key, $key.':notify', $this->controlKey($name, 'version'), $payload);

        return json_decode($payload, true)['id'] ?? null;
    }

    protected function bulkOnClusterConnection($jobs, $data = '', $queue = null)
    {
        foreach ($jobs as $job) {
            $delay = is_object($job) ? $this->getAttributeValue($job, Delay::class, 'delay') : null;
            if ($delay !== null) {
                $this->later($delay, $job, $data, $queue);
            } else {
                $this->push($job, $data, $queue);
            }
        }
    }

    public function migrateExpiredJobs($from, $to)
    {
        $version = 'trademinator:queue-control:'.$to.':version';
        $script = str_replace('return val', "if #val > 0 then redis.call('incr', KEYS[4]) end\nreturn val", LuaScripts::migrateExpiredJobs());

        return $this->getConnection()->eval($script, 4, $from, $to, $to.':notify', $version,
            $this->currentTime(), $this->migrationLimit());
    }

    /** Drain the due backlog using short scripts before comparing eligible jobs. */
    protected function migrate($queue)
    {
        $sources = [$queue.':delayed'];
        if ($this->retryAfter !== null) {
            $sources[] = $queue.':reserved';
        }
        foreach ($sources as $source) {
            $due = (int) $this->getConnection()->zcount($source, '-inf', $this->currentTime());
            for ($remaining = $due; $remaining > 0; $remaining -= $this->migrationLimit()) {
                if (count($this->migrateExpiredJobs($source, $queue)) < $this->migrationLimit()) {
                    break;
                }
            }
        }
    }

    private function migrationLimit(): int
    {
        return (int) $this->migrationBatchSize > 0 ? min(1000, (int) $this->migrationBatchSize) : 100;
    }

    public function pop($queue = null, $index = 0)
    {
        $name = enum_value($queue) ?: $this->default;
        $this->migrate($this->key($name));
        app(QueueCancellation::class)->reconcile($this, $name);
        $block = ! $this->secondaryQueueHadJob && $index === 0;
        $reserved = $this->selectAndReserve($name);
        if ($reserved === null && $block && $this->blockFor !== null
            && $this->getConnection()->blpop([$this->key($name).':notify'], $this->blockFor)) {
            $reserved = $this->selectAndReserve($name);
        }
        if ($index === 0) {
            $this->secondaryQueueHadJob = false;
        }
        if ($reserved !== null) {
            if ($index > 0) {
                $this->secondaryQueueHadJob = true;
            }

            return new RedisJob($this->container, $this, $reserved[0], $reserved[1], $this->connectionName, $name);
        }

        return null;
    }

    /** Lightweight rows are read in pages; serialized commands are never retained for the whole queue. */
    public function readySnapshot(string $queue): array
    {
        $redis = $this->getConnection();
        $payload = app(QueuePayload::class);
        $priority = app(QueuePriority::class);
        $batchSize = min(1000, max(1, (int) config('queue_management.read_batch_size', 100)));
        $versionKey = $this->controlKey($queue, 'version');
        $version = (int) $redis->get($versionKey);
        $claimed = (int) $redis->get($this->controlKey($queue, 'claimed'));
        $rows = [];
        for ($offset = 0; ; $offset += $batchSize) {
            $rawRows = $redis->lrange($this->key($queue), $offset, $offset + $batchSize - 1);
            $batch = [];
            foreach ($rawRows as $index => $raw) {
                $row = $payload->read($raw);
                $row['_index'] = $offset + $index;
                $batch[] = $row;
            }
            array_push($rows, ...$priority->enrich($batch, $queue));
            if (count($rawRows) < $batchSize) {
                break;
            }
        }
        $manual = [];
        foreach ($redis->hgetall($this->controlKey($queue, 'manual')) as $uuid => $encoded) {
            $value = json_decode($encoded, true);
            if (is_array($value)) {
                $manual[$uuid] = $value;
            }
        }

        return ['version' => $version, 'consistent' => $version === (int) $redis->get($versionKey),
            'claimed' => $claimed, 'rows' => $priority->order($rows, $manual, $claimed)];
    }

    private function selectAndReserve(string $queue): ?array
    {
        $redis = $this->getConnection();
        $key = $this->key($queue);
        $attempts = max(1, (int) config('queue_management.claim_retries', 5));
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $snapshot = $this->readySnapshot($queue);
            if (! $snapshot['consistent']) {
                continue;
            }
            if ($snapshot['rows'] === []) {
                return null;
            }
            $row = $snapshot['rows'][0];
            $raw = $redis->lindex($key, $row['_index']);
            if (! is_string($raw) || app(QueuePayload::class)->read($raw)['uuid'] !== $row['uuid']) {
                continue;
            }
            app(WorkerRegistry::class)->guard(beforeClaim: true);
            $result = $redis->eval(<<<'LUA'
if tonumber(redis.call('get', KEYS[4]) or '0') ~= tonumber(ARGV[1]) then return false end
local data = cjson.decode(ARGV[2])
if redis.call('lrem', KEYS[1], 1, ARGV[2]) ~= 1 then return false end
data['attempts'] = (data['attempts'] or 0) + 1
local reserved = cjson.encode(data)
redis.call('zadd', KEYS[2], ARGV[4], reserved)
redis.call('lpop', KEYS[3])
redis.call('incr', KEYS[4])
redis.call('hdel', KEYS[5], ARGV[3])
redis.call('incr', KEYS[6])
return {ARGV[2], reserved}
LUA, 6, $key, $key.':reserved', $key.':notify', $this->controlKey($queue, 'version'),
                $this->controlKey($queue, 'manual'), $this->controlKey($queue, 'claimed'),
                $snapshot['version'], $raw, $row['uuid'], $this->availableAt($this->retryAfter));
            if (is_array($result) && count($result) === 2) {
                return $result;
            }
        }

        throw new RuntimeException('Queue order changed during selection; the worker will retry without claiming stale work.');
    }

    public function deleteAndRelease($queue, $job, $delay)
    {
        $key = $this->key($queue);
        $this->getConnection()->eval(<<<'LUA'
if redis.call('zrem', KEYS[2], ARGV[1]) ~= 1 then return false end
redis.call('zadd', KEYS[1], ARGV[2], ARGV[1])
return true
LUA, 2, $key.':delayed', $key.':reserved', $job->getReservedJob(), $this->availableAt($delay));
    }

    public function clear($queue = null)
    {
        $name = enum_value($queue) ?: $this->default;
        $key = $this->key($name);

        return $this->getConnection()->eval(<<<'LUA'
local size = redis.call('llen', KEYS[1]) + redis.call('zcard', KEYS[2]) + redis.call('zcard', KEYS[3])
redis.call('del', KEYS[1], KEYS[2], KEYS[3], KEYS[4], KEYS[6], KEYS[7])
redis.call('incr', KEYS[5])
return size
LUA, 7, $key, $key.':delayed', $key.':reserved', $key.':notify',
            $this->controlKey($name, 'version'), $this->controlKey($name, 'manual'), $this->controlKey($name, 'claimed'));
    }
}
