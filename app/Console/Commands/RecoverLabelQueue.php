<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\TrainingRuntime;
use App\Domain\Operations\ManagedRedisQueue;
use App\Domain\Operations\WorkerRegistry;
use App\Jobs\AnalyzeMarketAutoLabels;
use Illuminate\Console\Command;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class RecoverLabelQueue extends Command
{
    private const MAX_PAYLOADS = 20000;

    protected $signature = 'trademinator:recover-label-queue
        {exchange : Exchange class, for example binance}
        {symbol : Exact market symbol, for example XRP/USDC}
        {period : Candle period, for example 1m}
        {--apply : Back up, refresh label timeouts, and requeue stopped reservations}
        {--workers-stopped : Confirm ALL intelligence workers, including unmanaged workers, are stopped on EVERY host}';

    protected $description = 'Inspect or recover auto-label jobs for one market without discarding queued work';

    public function handle(WorkerRegistry $workers): int
    {
        $guard = null;
        $maintenance = null;
        $backup = null;
        try {
            $name = (string) config('intelligence.queue', 'intelligence');
            $queue = Queue::connection();
            if (! $queue instanceof ManagedRedisQueue || $name === '') {
                throw new RuntimeException('Label recovery requires the managed Redis intelligence queue.');
            }
            $redis = $queue->getConnection();
            if ($redis->isCluster() || ! $workers->usesConnection($redis)) {
                throw new RuntimeException('Label recovery requires the queue and worker registry on the same non-cluster Redis connection.');
            }
            if ($redis instanceof PhpRedisConnection && ($redis->serialized() || $redis->compressed())) {
                throw new RuntimeException('Label recovery requires Redis serialization and compression to be disabled.');
            }
            $apply = (bool) $this->option('apply');
            if ($apply) {
                if (! $this->option('workers-stopped')) {
                    throw new RuntimeException('Stop intelligence workers on every host, then confirm with --workers-stopped.');
                }
                $guard = fopen(storage_path('framework/trademinator-intelligence-queue.lock'), 'c');
                if ($guard === false || ! flock($guard, LOCK_EX | LOCK_NB)) {
                    throw new RuntimeException('Stop the local intelligence worker before applying recovery.');
                }
                $maintenance = $workers->beginMaintenance();
                $workers->assertQueuesStopped([$name], true);
            }

            $key = $queue->key($name);
            $snapshots = $this->snapshots($redis, $key);
            $report = ['matched_labels' => 0, 'timeouts_or_retries_refreshed' => 0, 'stopped_reservations_to_requeue' => 0];
            foreach ($snapshots as &$snapshot) {
                foreach ($snapshot['entries'] as &$entry) {
                    $replacement = $this->refresh($entry['payload']);
                    if ($replacement === null) {
                        continue;
                    }
                    $report['matched_labels']++;
                    if ($replacement !== $entry['payload']) {
                        $report['timeouts_or_retries_refreshed']++;
                        $entry['replacement'] = $replacement;
                    }
                    if ($snapshot['state'] === 'reserved') {
                        $report['stopped_reservations_to_requeue']++;
                        $entry['replacement'] = $replacement;
                    }
                }
                unset($entry);
            }
            unset($snapshot);
            $this->table(['Recovery item', 'Count'], collect($report)->map(fn (int $count, string $item): array => [$item, $count])->all());
            $this->line('Label timeout: '.TrainingRuntime::labelJobTimeout().' seconds; reservation: '.(TrainingRuntime::labelJobTimeout() + 300).' seconds.');
            if (! $apply) {
                $this->info('Dry run only. Stop intelligence workers on every host before using --apply --workers-stopped.');

                return self::SUCCESS;
            }
            if ($report['timeouts_or_retries_refreshed'] + $report['stopped_reservations_to_requeue'] === 0) {
                $this->info('No label recovery changes are needed.');

                return self::SUCCESS;
            }

            $backup = $this->backup($key, $snapshots, $report);
            $this->line('Backup: '.$backup);
            $workers->assertMaintenanceOwner($maintenance);
            $workers->assertQueuesStopped([$name], true);
            $result = $redis->eval($this->commitScript(), 6,
                $workers->maintenanceKey(), $key, $key.':delayed', $key.':reserved', $key.':notify',
                $queue->controlKey($name, 'version'), $maintenance, json_encode($snapshots, JSON_THROW_ON_ERROR));
            if ((int) $result !== 1) {
                throw new RuntimeException('Queue changed during inspection; no recovery was applied. Rerun the dry run.');
            }
            $this->info('Label recovery applied. Restart intelligence workers with the patched code; model training was not executed by this command.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error instanceof RuntimeException ? $error->getMessage()
                : 'Label recovery could not verify completion. Inspect the private backup and rerun the dry run.');
            if ($backup !== null) {
                $this->line('Backup: '.$backup);
            }

            return self::FAILURE;
        } finally {
            try {
                if ($maintenance !== null) {
                    $workers->endMaintenance($maintenance);
                }
            } finally {
                if (is_resource($guard)) {
                    flock($guard, LOCK_UN);
                    fclose($guard);
                }
            }
        }
    }

    /** @return list<array{state: string, entries: list<array>}> */
    private function snapshots(Connection $redis, string $key): array
    {
        $snapshots = [];
        $count = 0;
        foreach (['ready', 'delayed', 'reserved'] as $state) {
            $entries = [];
            if ($state === 'ready') {
                foreach ($redis->lrange($key, 0, self::MAX_PAYLOADS) as $payload) {
                    $entries[] = ['payload' => $payload];
                }
            } else {
                $rows = $redis->eval("return redis.call('zrange', KEYS[1], 0, ARGV[1], 'WITHSCORES')",
                    1, $key.':'.$state, self::MAX_PAYLOADS);
                for ($index = 0; $index < count($rows); $index += 2) {
                    $entries[] = ['payload' => $rows[$index], 'score' => (string) $rows[$index + 1]];
                }
            }
            $count += count($entries);
            if ($count > self::MAX_PAYLOADS) {
                throw new RuntimeException('The backlog exceeds the bounded recovery limit of 20000 payloads.');
            }
            $snapshots[] = ['state' => $state, 'entries' => $entries];
        }

        return $snapshots;
    }

    private function refresh(string $raw): ?string
    {
        $payload = json_decode($raw, true);
        if (($payload['data']['commandName'] ?? null) !== AnalyzeMarketAutoLabels::class) {
            return null;
        }
        $serialized = $payload['data']['command'] ?? null;
        $job = is_string($serialized)
            ? @unserialize($serialized, ['allowed_classes' => [AnalyzeMarketAutoLabels::class], 'max_depth' => 64]) : null;
        if (! $job instanceof AnalyzeMarketAutoLabels || $this->containsObject(get_object_vars($job))) {
            throw new RuntimeException('A label payload cannot be safely decoded; recovery was not applied.');
        }
        if ([$job->exchange, $job->symbol, $job->period] !== [
            $this->argument('exchange'), $this->argument('symbol'), $this->argument('period'),
        ]) {
            return null;
        }
        $timeout = TrainingRuntime::labelJobTimeout();
        // Preserve attempt evidence. Cooperative continuations need unlimited releases;
        // genuine exceptions remain bounded independently of successful work slices.
        $tries = 0;
        $asOfMs = $job->asOfMs ?? (int) (($payload['createdAt'] ?? now()->getTimestamp()) * 1000);
        if (($job->timeout ?? null) === $timeout && ! $job->failOnTimeout && $job->tries === $tries
            && $job->maxExceptions === 3 && $job->asOfMs === $asOfMs
            && ($payload['timeout'] ?? null) === $timeout && ($payload['failOnTimeout'] ?? null) === false
            && ($payload['maxTries'] ?? null) === $tries && ($payload['maxExceptions'] ?? null) === 3) {
            return $raw;
        }
        $job->timeout = $payload['timeout'] = $timeout;
        $job->failOnTimeout = $payload['failOnTimeout'] = false;
        $job->tries = $payload['maxTries'] = $tries;
        $job->maxExceptions = $payload['maxExceptions'] = 3;
        $job->asOfMs = $asOfMs;
        $payload['data']['command'] = serialize($job);

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function containsObject(mixed $value, int $depth = 0): bool
    {
        if (is_object($value) || $depth > 64) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                if ($this->containsObject($child, $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function backup(string $queue, array $snapshots, array $report): string
    {
        $mask = umask(0077);
        $file = false;
        try {
            $directory = storage_path('app/private/label-queue-recovery');
            if ((! is_dir($directory) && ! mkdir($directory, 0700, true)) || ! chmod($directory, 0700)) {
                throw new RuntimeException('Unable to create a private label recovery backup directory.');
            }
            $path = $directory.'/'.now()->format('Ymd-His').'-'.Str::uuid7().'.jsonl';
            $file = fopen($path, 'xb');
            if ($file === false) {
                throw new RuntimeException('Unable to create a private label recovery backup.');
            }
            $header = ['type' => 'recovery', 'created_at' => now()->toIso8601String(), 'queue' => $queue,
                'target' => [$this->argument('exchange'), $this->argument('symbol'), $this->argument('period')], 'counts' => $report];
            $this->writeRecord($file, $header);
            foreach ($snapshots as $snapshot) {
                foreach ($snapshot['entries'] as $entry) {
                    $this->writeRecord($file, ['type' => 'payload', 'state' => $snapshot['state'], ...$entry]);
                }
            }
            if (! fflush($file) || ! fsync($file)) {
                throw new RuntimeException('Unable to persist the private label recovery backup.');
            }

            return $path;
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
            umask($mask);
        }
    }

    /** @param resource $file */
    private function writeRecord($file, array $record): void
    {
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        if (fwrite($file, $line) !== strlen($line)) {
            throw new RuntimeException('Unable to finish the private label recovery backup.');
        }
    }

    private function commitScript(): string
    {
        return <<<'LUA'
if redis.call('get', KEYS[1]) ~= ARGV[1] then return 0 end
local snapshots = cjson.decode(ARGV[2])
local types = {'list', 'zset', 'zset', 'list', 'string'}
for index, expected in ipairs(types) do
    local actual = redis.call('type', KEYS[index + 1]).ok
    if actual ~= 'none' and actual ~= expected then return 0 end
end
local version = redis.call('get', KEYS[6])
if version and not tonumber(version) then return 0 end
for index, snapshot in ipairs(snapshots) do
    local key = KEYS[index + 1]
    if snapshot.state == 'ready' then
        local rows = redis.call('lrange', key, 0, -1)
        if #rows ~= #snapshot.entries then return 0 end
        for i, entry in ipairs(snapshot.entries) do
            if rows[i] ~= entry.payload then return 0 end
        end
    else
        if redis.call('zcard', key) ~= #snapshot.entries then return 0 end
        for _, entry in ipairs(snapshot.entries) do
            local score = redis.call('zscore', key, entry.payload)
            if not score or tonumber(score) ~= tonumber(entry.score) then return 0 end
        end
    end
end
for index, snapshot in ipairs(snapshots) do
    local key = KEYS[index + 1]
    for i, entry in ipairs(snapshot.entries) do
        if entry.replacement then
            if snapshot.state == 'ready' then
                redis.call('lset', key, i - 1, entry.replacement)
            else
                redis.call('zrem', key, entry.payload)
                if snapshot.state == 'delayed' then
                    redis.call('zadd', key, entry.score, entry.replacement)
                else
                    redis.call('rpush', KEYS[2], entry.replacement)
                    redis.call('rpush', KEYS[5], 1)
                end
            end
        end
    end
end
redis.call('incr', KEYS[6])
return 1
LUA;
    }
}
