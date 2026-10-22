<?php

namespace App\Domain\Intelligence;

use App\Domain\MarketData\MarketHistoryBackfill;
use App\Jobs\RebuildBackfilledIntelligence;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class BackfillIntelligence
{
    public function __construct(private MarketHistoryBackfill $history) {}

    public function dispatchDue(?string $exchange = null, ?string $symbol = null, ?string $period = null): int
    {
        if (! config('history_backfill.enabled') || ! config('intelligence.enabled')
            || in_array(config('queue.default'), ['sync', 'null'], true)) {
            return 0;
        }
        $count = 0;
        foreach ($this->history->feeds($exchange, $symbol, $period)->lazyById(100, 'market_id') as $feed) {
            if ($period !== null && $feed->selected_period !== $period) {
                continue;
            }
            $query = DB::table('market_history_backfills')->where('market_id', $feed->market_id)
                ->where('period', $feed->selected_period)->whereColumn('history_revision', '>', 'trained_revision')
                ->where(fn ($query) => $query->whereNull('build_next_attempt_at')->orWhere('build_next_attempt_at', '<=', now()))
                ->where(fn ($query) => $query->whereNull('build_lease_token')
                    ->orWhere(fn ($query) => $query->whereNotNull('build_lease_until')
                        ->where('build_lease_until', '<=', now())));
            $row = $query->first();
            if ($row === null) {
                continue;
            }
            $token = (string) Str::uuid7();
            $stage = $row->build_stage ?? 'features';
            $claimed = $query->where('history_revision', $row->history_revision)
                ->where('trained_revision', $row->trained_revision)->where('build_stage', $row->build_stage)->update([
                    'build_stage' => $stage,
                    'build_revision' => $stage === 'features' ? $row->history_revision : $row->build_revision,
                    // Waiting work retains its token until a worker starts its bounded lease.
                    'build_lease_token' => $token, 'build_lease_until' => null, 'updated_at' => now(),
                ]);
            if (! $claimed) {
                continue;
            }
            try {
                RebuildBackfilledIntelligence::dispatch($row->history_id, $token)->onQueue(config('intelligence.queue'));
            } catch (Throwable $error) {
                $this->release($row->history_id, $token, [
                    'build_error' => mb_substr($error->getMessage(), 0, 1000),
                ], 60);
                throw $error;
            }
            if (++$count >= 100) {
                break;
            }
        }

        return $count;
    }

    public function owned(string $id, string $token): Builder
    {
        return DB::table('market_history_backfills')->where('history_id', $id)->where('build_lease_token', $token);
    }

    public function renew(string $id, string $token, bool $allowWaiting = true): bool
    {
        $now = now();
        $until = $now->copy()->addSeconds(TrainingRuntime::lockSeconds());
        $owned = $this->owned($id, $token)->where(function ($query) use ($now, $allowWaiting): void {
            $query->where('build_lease_until', '>', $now);
            if ($allowWaiting) {
                $query->orWhereNull('build_lease_until');
            }
        });
        if ((clone $owned)->update([
            'build_lease_until' => $until,
            'build_next_attempt_at' => null,
            'build_error' => null,
            'updated_at' => $now,
        ]) > 0) {
            return true;
        }

        // MySQL may report zero changed rows when two checkpoints renew in the
        // same second. A current locking read still has to prove ownership.
        return $owned->where('build_lease_until', '>=', $until)->lockForUpdate()->first(['history_id']) !== null;
    }

    /** Keep the existing token while a released attempt or chained child waits. */
    public function waiting(string $id, string $token): bool
    {
        return $this->owned($id, $token)->update([
            'build_lease_until' => null, 'updated_at' => now(),
        ]) > 0;
    }

    public function assertRunning(string $id, string $token): void
    {
        if (! $this->owned($id, $token)->where('build_lease_until', '>', now())->exists()) {
            throw new RuntimeException('Backfill intelligence lease ownership was lost.');
        }
    }

    public function release(string $id, string $token, array $changes = [], int $delay = 0): void
    {
        $this->owned($id, $token)->update(array_merge([
            'build_lease_token' => null, 'build_lease_until' => null,
            'build_next_attempt_at' => now()->addSeconds($delay), 'updated_at' => now(),
        ], $changes));
    }

    public function failure(string $id, string $token, Throwable $error): void
    {
        $row = $this->owned($id, $token)->first();
        if ($row === null) {
            return;
        }
        $failures = min(100, $row->build_failures + 1);
        $this->release($id, $token, [
            'build_stage' => 'features', 'build_revision' => null, 'build_failures' => $failures,
            'build_performance' => null,
            'build_error' => mb_substr($error->getMessage(), 0, 1000),
        ], min(3600, 60 * (2 ** min(6, $failures - 1))));
    }
}
