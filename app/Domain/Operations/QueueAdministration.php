<?php

namespace App\Domain\Operations;

use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use RuntimeException;

class QueueAdministration
{
    public function __construct(private QueuePayload $payload, private QueuePriority $priority, private QueueCancellation $cancellation) {}

    public function snapshot(string $connection, string $queue, string $state = 'waiting', int $page = 1, int $perPage = 50): array
    {
        $driver = $this->driver($connection);
        $this->validateState($state);
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset = ($page - 1) * $perPage;
        if ($state === 'waiting') {
            $snapshot = $this->stableSnapshot($driver, $queue);
            $total = count($snapshot['rows']);
            $rows = array_slice($snapshot['rows'], $offset, $perPage);
        } else {
            $key = $driver->key($queue).':'.$state;
            $redis = $driver->getConnection();
            $total = (int) $redis->zcard($key);
            $rawRows = $this->sortedRows($driver, $key, $offset, $offset + $perPage - 1);
            $rows = [];
            foreach ($rawRows as $raw => $availableAt) {
                $rows[] = $this->payload->read($raw, $state, (int) $availableAt);
            }
            $rows = $this->priority->enrich($rows, $queue);
        }

        return ['jobs' => array_map($this->payload->publicDetails(...), $rows), 'total' => $total,
            'page' => $page, 'per_page' => $perPage, 'connection' => $connection, 'queue' => $queue, 'state' => $state];
    }

    public function inspect(string $connection, string $queue, string $uuid): ?array
    {
        $driver = $this->driver($connection);
        $snapshot = $this->stableSnapshot($driver, $queue);
        foreach ($snapshot['rows'] as $row) {
            if ($row['uuid'] === $uuid) {
                return $this->payload->publicDetails($row);
            }
        }
        foreach (['delayed', 'reserved'] as $state) {
            $found = $this->findSorted($driver, $queue, $state, $uuid);
            if ($found !== null) {
                $rows = $this->priority->enrich([$found], $queue);

                return $this->payload->publicDetails($rows[0]);
            }
        }

        return null;
    }

    public function move(string $connection, string $queue, string $uuid, string $direction): bool
    {
        if (! in_array($direction, ['top', 'up', 'down', 'bottom', 'automatic'], true)) {
            throw new InvalidArgumentException('Unsupported queue movement.');
        }
        $driver = $this->driver($connection);
        $snapshot = $this->stableSnapshot($driver, $queue);
        $position = array_search($uuid, array_column($snapshot['rows'], 'uuid'), true);
        if ($position === false) {
            return false;
        }
        $row = $snapshot['rows'][$position];
        $raw = $driver->getConnection()->lindex($driver->key($queue), $row['_index']);
        if (! is_string($raw) || $this->payload->read($raw)['uuid'] !== $uuid) {
            return false;
        }
        $target = match ($direction) {
            'top' => 0, 'up' => max(0, $position - 1),
            'down' => min(count($snapshot['rows']) - 1, $position + 1),
            default => count($snapshot['rows']) - 1,
        };
        $override = json_encode(['direction' => $direction, 'position' => $target,
            'claimed' => $snapshot['claimed'], 'order' => $snapshot['version'] + 1], JSON_THROW_ON_ERROR);

        return (bool) $driver->getConnection()->eval(<<<'LUA'
if tonumber(redis.call('get', KEYS[2]) or '0') ~= tonumber(ARGV[1]) then return false end
if redis.call('lindex', KEYS[1], ARGV[2]) ~= ARGV[3] then return false end
if ARGV[5] == 'automatic' then
    redis.call('hdel', KEYS[3], ARGV[4])
else
    redis.call('hset', KEYS[3], ARGV[4], ARGV[6])
end
redis.call('incr', KEYS[2])
return true
LUA, 3, $driver->key($queue), $driver->controlKey($queue, 'version'), $driver->controlKey($queue, 'manual'),
            $snapshot['version'], $row['_index'], $raw, $uuid, $direction, $override);
    }

    public function cancel(string $connection, string $queue, string $uuid): bool
    {
        $driver = $this->driver($connection);
        $snapshot = $this->stableSnapshot($driver, $queue);
        $position = array_search($uuid, array_column($snapshot['rows'], 'uuid'), true);
        $row = null;
        if ($position !== false) {
            $row = $snapshot['rows'][$position];
            $row['_raw'] = $driver->getConnection()->lindex($driver->key($queue), $row['_index']);
        } else {
            $row = $this->findSorted($driver, $queue, 'delayed', $uuid);
        }
        if ($row === null || ! $row['can_cancel'] || ! is_string($row['_raw'])) {
            return false;
        }
        $raw = $row['_raw'];
        if ($this->payload->read($raw)['uuid'] !== $uuid) {
            return false;
        }
        $key = $driver->key($queue);
        $removed = $driver->getConnection()->eval(<<<'LUA'
local removed = 0
if ARGV[3] == 'waiting' then
    if tonumber(redis.call('get', KEYS[4]) or '0') ~= tonumber(ARGV[4]) then return false end
    removed = redis.call('lrem', KEYS[1], 1, ARGV[1])
    if removed == 1 then redis.call('lpop', KEYS[3]) end
else
    removed = redis.call('zrem', KEYS[2], ARGV[1])
end
if removed ~= 1 then return false end
redis.call('hdel', KEYS[5], ARGV[2])
redis.call('hset', KEYS[6], ARGV[2], ARGV[1])
redis.call('incr', KEYS[4])
return true
LUA, 6, $key, $key.':delayed', $key.':notify', $driver->controlKey($queue, 'version'),
            $driver->controlKey($queue, 'manual'), $driver->controlKey($queue, 'cancelled'),
            $raw, $uuid, $row['state'], $snapshot['version']);
        if (! $removed) {
            return false;
        }
        $this->cancellation->reconcile($driver, $queue);

        return true;
    }

    private function driver(string $connection): ManagedRedisQueue
    {
        if (! array_key_exists($connection, (array) config('queue.connections', []))) {
            throw new InvalidArgumentException('Unknown queue connection.');
        }
        $driver = Queue::connection($connection);
        if (! $driver instanceof ManagedRedisQueue) {
            throw new InvalidArgumentException('Individual queue controls require the managed Redis connection.');
        }

        return $driver;
    }

    private function stableSnapshot(ManagedRedisQueue $driver, string $queue): array
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $snapshot = $driver->readySnapshot($queue);
            if ($snapshot['consistent']) {
                return $snapshot;
            }
        }

        throw new RuntimeException('The queue changed while it was being read. Refresh to see its current order.');
    }

    private function findSorted(ManagedRedisQueue $driver, string $queue, string $state, string $uuid): ?array
    {
        $key = $driver->key($queue).':'.$state;
        for ($offset = 0; ; $offset += 100) {
            $rows = $this->sortedRows($driver, $key, $offset, $offset + 99);
            foreach ($rows as $raw => $availableAt) {
                $row = $this->payload->read($raw, $state, (int) $availableAt);
                if ($row['uuid'] === $uuid) {
                    return [...$row, '_raw' => $raw];
                }
            }
            if (count($rows) < 100) {
                return null;
            }
        }
    }

    /** Redis clients disagree on the WITHSCORES argument; Lua returns one portable shape. */
    private function sortedRows(ManagedRedisQueue $driver, string $key, int $start, int $end): array
    {
        $entries = $driver->getConnection()->eval("return redis.call('zrange', KEYS[1], ARGV[1], ARGV[2], 'WITHSCORES')", 1, $key, $start, $end);
        $rows = [];
        for ($index = 0; $index < count($entries); $index += 2) {
            $rows[$entries[$index]] = (int) $entries[$index + 1];
        }

        return $rows;
    }

    private function validateState(string $state): void
    {
        if (! in_array($state, ['waiting', 'delayed', 'reserved'], true)) {
            throw new InvalidArgumentException('Unknown queue state.');
        }
    }
}
