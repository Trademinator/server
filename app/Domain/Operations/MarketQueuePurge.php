<?php

namespace App\Domain\Operations;

use App\Domain\Intelligence\AlgorithmicLabelSets;
use App\Domain\Intelligence\BuildCheckpoint;
use App\Domain\Intelligence\KnowledgeWindow;
use App\Domain\Research\FeatureSchema;
use App\Domain\Research\SemanticLabels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/** Remove every queued continuation that can recreate a purged market. */
final class MarketQueuePurge
{
    private const MAX_PAYLOADS = 100000;

    public function __construct(private QueuePayload $payload, private QueueCancellation $cancellation) {}

    /** @return list<array{connection:string,queue:string}> */
    public function definitions(): array
    {
        $default = (string) config('queue.default');
        $definitions = [
            ['connection' => $default, 'queue' => (string) config('queue.connections.'.$default.'.queue', 'default')],
            ['connection' => $default, 'queue' => (string) config('features.queue', 'features')],
            ['connection' => $default, 'queue' => (string) config('history_backfill.queue', 'history')],
            ['connection' => $default, 'queue' => (string) config('intelligence.queue', 'intelligence')],
            ['connection' => (string) (config('intelligence.signals.connection') ?: $default),
                'queue' => (string) config('intelligence.signals.queue', 'signals')],
            ['connection' => $default, 'queue' => (string) config('intelligence.tournament.queue', 'tournament')],
        ];
        $unique = [];
        foreach ($definitions as $definition) {
            if ($definition['connection'] === '' || $definition['queue'] === '') {
                continue;
            }
            $unique[$definition['connection'].'|'.$definition['queue']] = $definition;
        }

        return array_values($unique);
    }

    /** @return list<string> */
    public function queueNames(): array
    {
        return array_values(array_unique(array_column($this->definitions(), 'queue')));
    }

    public function assertManaged(WorkerRegistry $workers): void
    {
        foreach ($this->definitions() as $definition) {
            $driver = Queue::connection($definition['connection']);
            if (! $driver instanceof ManagedRedisQueue) {
                throw new RuntimeException('Market purge requires managed Redis queues for '.$definition['connection'].'.');
            }
            if (! $workers->usesConnection($driver->getConnection())) {
                throw new RuntimeException('Market purge requires queues and the worker registry on the same Redis connection.');
            }
        }
    }

    /** @param array<string, mixed> $scope @return array{jobs:list<array>,checkpoint_keys:list<string>,total:int} */
    public function plan(array $scope): array
    {
        $jobs = [];
        $checkpointKeys = [];
        foreach ($this->definitions() as $definition) {
            $driver = Queue::connection($definition['connection']);
            if (! $driver instanceof ManagedRedisQueue) {
                continue;
            }
            foreach ($this->snapshot($driver, $definition['queue'])['entries'] as $entry) {
                if (! $this->matches($entry['row'], $entry['raw'], $scope)) {
                    continue;
                }
                $jobs[] = [
                    'connection' => $definition['connection'], 'queue' => $definition['queue'],
                    'state' => $entry['state'], 'uuid' => $entry['row']['uuid'], 'name' => $entry['row']['name'],
                    'batched' => (bool) ($entry['row']['batched'] ?? false),
                ];
                $checkpoint = $this->checkpointKey($entry['row']);
                if ($checkpoint !== null) {
                    $checkpointKeys[$checkpoint] = true;
                }
            }
        }
        if (Schema::hasTable('failed_jobs') && ($scope['failed_job_ids'] ?? []) !== []) {
            foreach (DB::table('failed_jobs')->whereIn('id', $scope['failed_job_ids'])->pluck('payload') as $raw) {
                if (! is_string($raw)) {
                    continue;
                }
                $row = $this->payload->read($raw, 'failed');
                $checkpoint = $this->checkpointKey($row);
                if ($checkpoint !== null) {
                    $checkpointKeys[$checkpoint] = true;
                }
            }
        }

        return ['jobs' => $jobs, 'checkpoint_keys' => array_keys($checkpointKeys), 'total' => count($jobs)];
    }

    /** @param array<string, mixed> $scope @return array<string, int> */
    public function purge(array $scope): array
    {
        $removed = [];
        foreach ($this->definitions() as $definition) {
            $driver = Queue::connection($definition['connection']);
            if (! $driver instanceof ManagedRedisQueue) {
                throw new RuntimeException('Market purge requires managed Redis queues.');
            }
            $key = $definition['connection'].'|'.$definition['queue'];
            $removed[$key] = 0;
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $snapshot = $this->snapshot($driver, $definition['queue']);
                $matches = array_values(array_filter($snapshot['entries'],
                    fn (array $entry): bool => $this->matches($entry['row'], $entry['raw'], $scope)));
                if ($matches === []) {
                    break;
                }
                $batched = array_values(array_filter($matches,
                    fn (array $entry): bool => (bool) ($entry['row']['batched'] ?? false)));
                if ($batched !== []) {
                    throw new RuntimeException('A matching job belongs to a coordinated Laravel batch; cancel that batch before purging the market.');
                }
                foreach (array_chunk($matches, 200) as $batch) {
                    $result = $this->remove($driver, $definition['queue'], $snapshot['version'], $batch);
                    if (! $result['consistent']) {
                        continue 2;
                    }
                    $snapshot['version'] = $result['version'];
                    $removed[$key] += $result['removed'];
                }
                $this->reconcile($driver, $definition['queue'],
                    array_values(array_unique(array_map(fn (array $entry): string => $entry['row']['uuid'], $matches))));
            }
            $remaining = array_filter($this->snapshot($driver, $definition['queue'])['entries'],
                fn (array $entry): bool => $this->matches($entry['row'], $entry['raw'], $scope));
            if ($remaining !== []) {
                throw new RuntimeException('The '.$definition['queue'].' queue changed repeatedly during market purge; rerun after stopping dispatchers.');
            }
        }

        return array_filter($removed);
    }

    /** @return array{version:int,entries:list<array{state:string,raw:string,row:array}>} */
    private function snapshot(ManagedRedisQueue $driver, string $queue): array
    {
        $redis = $driver->getConnection();
        $ready = $driver->key($queue);
        $versionKey = $driver->controlKey($queue, 'version');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $version = (int) ($redis->get($versionKey) ?? 0);
            $entries = [];
            $count = 0;
            for ($offset = 0; ; $offset += 500) {
                $rows = $redis->lrange($ready, $offset, $offset + 499);
                foreach ($rows as $raw) {
                    $entries[] = ['state' => 'ready', 'raw' => $raw, 'row' => $this->payload->read($raw, 'waiting')];
                }
                $count += count($rows);
                if ($count > self::MAX_PAYLOADS) {
                    throw new RuntimeException('A queue exceeds the bounded market-purge inspection limit.');
                }
                if (count($rows) < 500) {
                    break;
                }
            }
            foreach (['delayed', 'reserved'] as $state) {
                for ($offset = 0; ; $offset += 500) {
                    $rows = $redis->eval("return redis.call('zrange', KEYS[1], ARGV[1], ARGV[2])",
                        1, $ready.':'.$state, $offset, $offset + 499);
                    foreach ($rows as $raw) {
                        $entries[] = ['state' => $state, 'raw' => $raw, 'row' => $this->payload->read($raw, $state)];
                    }
                    $count += count($rows);
                    if ($count > self::MAX_PAYLOADS) {
                        throw new RuntimeException('A queue exceeds the bounded market-purge inspection limit.');
                    }
                    if (count($rows) < 500) {
                        break;
                    }
                }
            }
            if ($version === (int) ($redis->get($versionKey) ?? 0)) {
                return compact('version', 'entries');
            }
        }

        throw new RuntimeException('Queue contents changed while the market purge inspected them.');
    }

    /** @return array{consistent:bool,version:int,removed:int} */
    private function remove(ManagedRedisQueue $driver, string $queue, int $version, array $entries): array
    {
        $redis = $driver->getConnection();
        $key = $driver->key($queue);
        $args = [$version];
        foreach ($entries as $entry) {
            array_push($args, $entry['state'], $entry['raw'], $entry['row']['uuid']);
        }
        $result = $redis->eval(<<<'LUA'
if tonumber(redis.call('get', KEYS[5]) or '0') ~= tonumber(ARGV[1]) then
    return {0, tonumber(redis.call('get', KEYS[5]) or '0'), 0}
end
local removed = 0
for i = 2, #ARGV, 3 do
    local state = ARGV[i]
    local raw = ARGV[i + 1]
    local uuid = ARGV[i + 2]
    local changed = 0
    if state == 'ready' then
        changed = redis.call('lrem', KEYS[1], 1, raw)
        if changed == 1 then redis.call('lpop', KEYS[4]) end
    elseif state == 'delayed' then
        changed = redis.call('zrem', KEYS[2], raw)
    elseif state == 'reserved' then
        changed = redis.call('zrem', KEYS[3], raw)
    end
    if changed == 1 then
        redis.call('hdel', KEYS[6], uuid)
        redis.call('hset', KEYS[7], uuid, raw)
        removed = removed + 1
    end
end
if removed > 0 then redis.call('incr', KEYS[5]) end
return {1, tonumber(redis.call('get', KEYS[5]) or '0'), removed}
LUA, 7, $key, $key.':delayed', $key.':reserved', $key.':notify',
            $driver->controlKey($queue, 'version'), $driver->controlKey($queue, 'manual'),
            $driver->controlKey($queue, 'cancelled'), ...$args);

        return ['consistent' => (int) ($result[0] ?? 0) === 1,
            'version' => (int) ($result[1] ?? $version), 'removed' => (int) ($result[2] ?? 0)];
    }

    private function reconcile(ManagedRedisQueue $driver, string $queue, array $uuids): void
    {
        $key = $driver->controlKey($queue, 'cancelled');
        $pending = static function () use ($driver, $key, $uuids): bool {
            foreach ($uuids as $uuid) {
                if ($driver->getConnection()->hexists($key, $uuid)) {
                    return true;
                }
            }

            return false;
        };
        for ($pass = 0; $pass < 1000 && $pending(); $pass++) {
            $this->cancellation->reconcile($driver, $queue);
        }
        if ($pending()) {
            throw new RuntimeException('Cancelled market work could not release every owned lock.');
        }
    }

    private function matches(array $row, string $raw, array $scope): bool
    {
        $properties = $row['_properties'] ?? [];
        if (($row['exchange'] ?? null) === $scope['exchange'] && ($row['symbol'] ?? null) === $scope['symbol']) {
            return true;
        }
        foreach ([
            'marketId' => 'market_ids', 'datasetId' => 'dataset_ids', 'snapshotDataset' => 'dataset_ids',
            'modelId' => 'model_ids', 'historyId' => 'history_ids', 'gapId' => 'gap_ids', 'requestId' => 'request_ids',
        ] as $property => $set) {
            if (isset($properties[$property]) && in_array((string) $properties[$property], $scope[$set] ?? [], true)) {
                return true;
            }
        }
        if (isset($properties['modelId']) && in_array((string) $properties['modelId'], $scope['shadow_model_ids'] ?? [], true)) {
            return true;
        }

        // QueuePayload extracts bounded scalar metadata from supported legacy serialized jobs.
        return false;
    }

    private function checkpointKey(array $row): ?string
    {
        $properties = $row['_properties'] ?? [];
        try {
            if ($row['name'] === 'AnalyzeMarketAutoLabels' && isset($properties['exchange'], $properties['symbol'],
                $properties['period'], $properties['asOfMs'])) {
                return BuildCheckpoint::keyFor([
                    'kind' => 'labels', 'exchange' => $properties['exchange'], 'symbol' => $properties['symbol'],
                    'period' => $properties['period'], 'as_of_ms' => (int) $properties['asOfMs'],
                    'profile' => app(AlgorithmicLabelSets::class)->profile($properties['exchange'], $properties['symbol']),
                    'implementation' => BuildCheckpoint::implementation('labels'),
                ]);
            }
            if ($row['name'] === 'BuildOwnerDatasetSnapshot' && isset($properties['exchange'], $properties['symbol'],
                $properties['period'], $properties['requestedAsOfMs'])) {
                $schema = (string) ($properties['schema'] ?? config('intelligence.schema'));
                $asOfMs = (int) $properties['requestedAsOfMs'];

                return BuildCheckpoint::keyFor([
                    'kind' => 'snapshot', 'exchange' => $properties['exchange'], 'symbol' => $properties['symbol'],
                    'period' => $properties['period'], 'as_of_ms' => $asOfMs,
                    'from_ms' => KnowledgeWindow::trainingFromMs(), 'to_ms' => $asOfMs,
                    'schema' => $schema, 'keys' => FeatureSchema::keys($schema),
                    'definition' => (new SemanticLabels((int) config('intelligence.horizon'),
                        (int) config('intelligence.lookback')))->metadata(),
                    'generation' => $properties['generation'] ?? null,
                    'profile' => app(AlgorithmicLabelSets::class)->profile($properties['exchange'], $properties['symbol']),
                    'implementation' => BuildCheckpoint::implementation('snapshot'),
                ]);
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
