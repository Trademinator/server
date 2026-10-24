<?php

namespace App\Domain\Operations;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Cleanup is durable and idempotent after Redis atomically removes unreserved work. */
class QueueCancellation
{
    public function __construct(private QueuePayload $payload) {}

    public function reconcile(ManagedRedisQueue $driver, string $queue): void
    {
        $redis = $driver->getConnection();
        $key = $driver->controlKey($queue, 'cancelled');
        [$cursor, $entries] = $redis->eval(<<<'LUA'
local cursor = redis.call('get', KEYS[2]) or '0'
local result = redis.call('hscan', KEYS[1], cursor, 'COUNT', 20)
if result[1] == '0' then redis.call('del', KEYS[2]) else redis.call('set', KEYS[2], result[1]) end
return result
LUA, 2, $key, $driver->controlKey($queue, 'cancel_cursor'));
        for ($index = 0; $index < count($entries); $index += 2) {
            try {
                $this->cleanup($this->payload->read($entries[$index + 1]));
                $redis->hdel($key, $entries[$index]);
            } catch (Throwable $error) {
                report($error);
            }
        }
    }

    protected function cleanup(array $row): void
    {
        $properties = $row['_properties'];
        $unique = $row['_unique'];
        $lock = is_array($unique) && ! empty($unique['owner']) && ! empty($unique['key'])
            ? Cache::store($unique['store'] ?? null)->restoreLock($unique['key'], $unique['owner']) : null;
        $labelLock = $row['name'] === 'AnalyzeMarketAutoLabels'
            && isset($properties['cacheKey'], $properties['dispatchLockOwner'])
                ? Cache::restoreLock($properties['cacheKey'].':lock', $properties['dispatchLockOwner']) : null;
        $ownsUnique = $lock === null || $lock->isOwnedByCurrentProcess();
        $ownsLabels = $row['name'] !== 'AnalyzeMarketAutoLabels'
            || ($labelLock !== null && $labelLock->isOwnedByCurrentProcess());

        DB::transaction(function () use ($row, $properties, $ownsUnique, $ownsLabels): void {
            $this->releaseLease($row['name'], $properties);
            if (! $ownsUnique || ! $ownsLabels) {
                return;
            }
            if ($row['name'] === 'RecoverMarketHistory' && isset($properties['requestId'])) {
                DB::table('history_recovery_requests')->where('request_id', $properties['requestId'])
                    ->where('status', 'queued')->update(['status' => 'cancelled',
                        'error' => 'Cancelled before execution by an owner.', 'updated_at' => now()]);
            }
            foreach ($row['_requests'] as $request) {
                if (! is_array($request) || ! isset($request['request_id'])) {
                    continue;
                }
                DB::table('knowledge_stage_requests')->where('request_id', $request['request_id'])
                    ->whereIn('status', ['queued', 'blocked'])
                    ->where('required_through_ms', $request['required_through_ms'] ?? null)
                    ->update(['status' => 'cancelled', 'error' => 'Cancelled before execution by an owner.', 'updated_at' => now()]);
            }
        });

        if ($ownsUnique && $row['name'] === 'TrainIntelligenceTournament' && isset($properties['datasetId'])) {
            Cache::put('trademinator:tournament:status:'.$properties['datasetId'],
                ['status' => 'cancelled', 'finished_at' => now()->toIso8601String()], now()->addDays(2));
        }
        if ($ownsLabels && $labelLock !== null) {
            Cache::put($properties['cacheKey'].':status', 'cancelled', now()->addDay());
            $labelLock->release();
        }
        $lock?->release();
    }

    private function releaseLease(string $name, array $properties): void
    {
        if (empty($properties['leaseToken'])) {
            return;
        }
        $definition = match ($name) {
            'CollectMarketFeed' => ['market_feeds', 'market_id', 'marketId', '',
                ['status' => 'pending', 'next_pull_at' => now()->addMinute()]],
            'BackfillMarketHistory' => ['market_history_backfills', 'history_id', 'historyId', '',
                ['status' => 'pending', 'reason' => 'owner_cancelled', 'next_attempt_at' => now()->addMinute()]],
            'RebuildBackfilledIntelligence' => ['market_history_backfills', 'history_id', 'historyId', 'build_',
                ['build_error' => 'Cancelled before execution by an owner.', 'build_next_attempt_at' => now()->addMinute()]],
            'RepairMissingCandles' => ['candle_gap_repairs', 'gap_id', 'gapId', '',
                ['status' => 'pending', 'reason' => 'owner_cancelled', 'next_attempt_at' => now()->addMinute()]],
            default => null,
        };
        if ($definition === null) {
            return;
        }
        [$table, $column, $property, $prefix, $changes] = $definition;
        if (! isset($properties[$property])) {
            return;
        }
        DB::table($table)->where($column, $properties[$property])
            ->where($prefix.'lease_token', $properties['leaseToken'])
            ->update([...$changes, $prefix.'lease_token' => null, $prefix.'lease_until' => null, 'updated_at' => now()]);
    }
}
