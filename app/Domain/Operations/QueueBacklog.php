<?php

namespace App\Domain\Operations;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\BeanstalkdQueue;
use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\SqsQueue;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisClusterConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Throwable;

final class QueueBacklog
{
    /** @return array{rows: list<array>, connections: list<string>, errors: list<string>, notices: list<string>} */
    public function snapshot(): array
    {
        $configured = (array) config('queue.connections', []);
        $names = (array) config('operations.queue_backlog_connections', []);
        if ($names === []) {
            $names = [(string) config('queue.default'), ...array_keys($configured)];
        }
        $snapshot = ['rows' => [], 'connections' => [], 'errors' => [], 'notices' => []];
        $visited = [];
        foreach (array_unique($names) as $name) {
            $this->inspectConnection($name, $configured, $visited, $snapshot);
        }
        $snapshot['rows'] = $this->deduplicateSignalRedisRows($snapshot['rows'], $configured);
        usort($snapshot['rows'], fn (array $a, array $b): int => [$a['connection'], $a['queue']] <=> [$b['connection'], $b['queue']]);

        return $snapshot;
    }

    /** @return list<array> */
    private function deduplicateSignalRedisRows(array $rows, array $configured): array
    {
        $signalsConnection = (string) config('intelligence.signals.connection');
        $signalsConfig = $configured[$signalsConnection] ?? [];
        if (($signalsConfig['driver'] ?? null) !== 'redis' || $signalsConnection === config('queue.default')) {
            return $rows;
        }
        $signalsQueue = (string) config('intelligence.signals.queue', 'signals');
        $redisConnection = $signalsConfig['connection'] ?? 'default';
        $signalQueues = [];
        $otherQueues = [];
        foreach ($rows as $row) {
            if ($row['driver'] !== 'redis' || ($configured[$row['connection']]['connection'] ?? 'default') !== $redisConnection) {
                continue;
            }
            if ($row['connection'] === $signalsConnection) {
                $signalQueues[$row['queue']] = true;
            } else {
                $otherQueues[$row['queue']] = true;
            }
        }

        // Keep discoveries visible when only one alias was requested or could be read.
        return array_values(array_filter($rows, function (array $row) use ($configured, $signalsConnection, $signalsQueue, $redisConnection, $signalQueues, $otherQueues): bool {
            if ($row['driver'] !== 'redis' || ($configured[$row['connection']]['connection'] ?? 'default') !== $redisConnection) {
                return true;
            }
            if ($row['connection'] === $signalsConnection) {
                return $row['queue'] === $signalsQueue || ! isset($otherQueues[$row['queue']]);
            }

            return $row['queue'] !== $signalsQueue || ! isset($signalQueues[$signalsQueue]);
        }));
    }

    private function inspectConnection(string $name, array $configured, array &$visited, array &$snapshot): void
    {
        if (isset($visited[$name])) {
            return;
        }
        $visited[$name] = true;
        $config = $configured[$name] ?? [];
        $driver = $config['driver'] ?? '';
        if ($driver === 'failover') {
            foreach ($config['connections'] ?? [] as $child) {
                $this->inspectConnection($child, $configured, $visited, $snapshot);
            }

            return;
        }
        if (in_array($driver, ['sync', 'null', 'deferred', 'background'], true)) {
            $snapshot['notices'][] = "{$name} ({$driver}) has no persistent queue backlog.";

            return;
        }
        $snapshot['connections'][] = $name;
        try {
            if ($driver === 'database') {
                array_push($snapshot['rows'], ...$this->databaseRows($name, $config));

                return;
            }
            $queue = Queue::connection($name);
            foreach ($this->queueNames($queue, $config) as $queueName) {
                try {
                    $waiting = (int) $queue->pendingSize($queueName);
                    $delayed = (int) $queue->delayedSize($queueName);
                    $reserved = (int) $queue->reservedSize($queueName);
                    $total = $waiting + $delayed + $reserved;
                    if ($total === 0) {
                        continue;
                    }
                    $snapshot['rows'][] = [
                        'connection' => $name, 'driver' => $driver, 'queue' => $queueName,
                        'waiting' => $waiting, 'delayed' => $delayed, 'reserved' => $reserved, 'total' => $total,
                        'oldest' => $waiting > 0 ? $queue->creationTimeOfOldestPendingJob($queueName) : null,
                    ];
                } catch (Throwable) {
                    $snapshot['errors'][] = "Unable to read queue {$queueName} on {$name} ({$driver}).";
                }
            }
        } catch (Throwable) {
            $snapshot['errors'][] = "Unable to read queue connection {$name} ({$driver}).";
        }
    }

    /** @return list<array> */
    private function databaseRows(string $name, array $config): array
    {
        $time = now()->getTimestamp();

        return DB::connection($config['connection'] ?? null)->table($config['table'] ?? 'jobs')
            ->select('queue')->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN 1 ELSE 0 END) AS waiting', [$time])
            ->selectRaw('SUM(CASE WHEN reserved_at IS NULL AND available_at > ? THEN 1 ELSE 0 END) AS delayed', [$time])
            ->selectRaw('SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) AS reserved')
            ->selectRaw('MIN(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN created_at ELSE NULL END) AS oldest', [$time])
            ->groupBy('queue')->get()->map(fn (object $row): array => [
                'connection' => $name, 'driver' => 'database', 'queue' => $row->queue,
                'waiting' => (int) $row->waiting, 'delayed' => (int) $row->delayed,
                'reserved' => (int) $row->reserved, 'total' => (int) $row->total,
                'oldest' => $row->oldest === null ? null : (int) $row->oldest,
            ])->all();
    }

    /** @return list<string> */
    private function queueNames(QueueContract $queue, array $config): array
    {
        if ($queue instanceof RedisQueue) {
            return $this->redisQueueNames($queue);
        }
        if ($queue instanceof BeanstalkdQueue) {
            return array_map(fn ($tube): string => (string) $tube, $queue->getPheanstalk()->listTubes());
        }
        if ($queue instanceof SqsQueue) {
            $names = [];
            $token = null;
            do {
                $options = ['MaxResults' => 1000];
                if ($token !== null) {
                    $options['NextToken'] = $token;
                }
                $page = $queue->getSqs()->listQueues($options);
                array_push($names, ...($page['QueueUrls'] ?? []));
                $token = $page['NextToken'] ?? null;
            } while ($token !== null);

            return array_values(array_unique($names));
        }

        return array_values(array_unique(array_filter([
            $config['queue'] ?? 'default', config('features.queue'), config('intelligence.queue'),
            config('intelligence.signals.queue'), config('history_backfill.queue'), config('archive.portable_queue'),
        ], fn ($name): bool => is_string($name) && $name !== '')));
    }

    /** @return list<string> */
    private function redisQueueNames(RedisQueue $queue): array
    {
        $connection = $queue->getConnection();
        if ($connection instanceof PredisClusterConnection) {
            $prefix = (string) ($connection->client()->getOptions()->prefix ?? '');
            $names = [];
            foreach ($connection->client() as $node) {
                array_push($names, ...$this->scanRedisQueues(new PredisConnection($node), $prefix));
            }

            return array_values(array_unique($names));
        }

        return $this->scanRedisQueues($connection);
    }

    /** @return list<string> */
    private function scanRedisQueues(Connection $connection, ?string $clusterPrefix = null): array
    {
        $prefix = '';
        $match = 'queues:*';
        if ($connection instanceof PhpRedisConnection) {
            $prefix = (string) $connection->client()->getOption(\Redis::OPT_PREFIX);
            $prefixesScan = defined('Redis::SCAN_PREFIX')
                && ($connection->client()->getOption(\Redis::OPT_SCAN) & \Redis::SCAN_PREFIX) !== 0;
            if (! $prefixesScan) {
                $match = addcslashes($prefix, '\\*?[]').$match;
            }
        } elseif ($connection instanceof PredisConnection) {
            $prefix = $clusterPrefix ?? (string) ($connection->client()->getOptions()->prefix ?? '');
            $match = addcslashes($prefix, '\\*?[]').$match;
        }
        $names = [];
        $cursor = null;
        do {
            // A raw Predis SCAN avoids version-dependent MATCH prefix processing.
            $page = $connection instanceof PredisConnection
                ? $connection->client()->executeRaw(['SCAN', $cursor ?? 0, 'MATCH', $match, 'COUNT', 1000])
                : $connection->scan($cursor, ['match' => $match, 'count' => 1000]);
            if ($page === false) {
                break;
            }
            if (! is_array($page) || count($page) !== 2 || ! is_array($page[1])) {
                throw new RuntimeException('Invalid queue discovery response.');
            }
            [$cursor, $keys] = $page;
            foreach ($keys as $key) {
                if (! str_starts_with($key, $prefix.'queues:') || str_ends_with($key, ':notify')) {
                    continue;
                }
                $name = substr($key, strlen($prefix.'queues:'));
                $name = preg_replace('/:(delayed|reserved)$/D', '', $name);
                if (str_starts_with($name, '{') && str_ends_with($name, '}')) {
                    $name = substr($name, 1, -1);
                }
                if ($name !== '') {
                    $names[$name] = true;
                }
            }
        } while ($cursor !== null && (string) $cursor !== '0');

        return array_keys($names);
    }
}
