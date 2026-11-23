<?php

namespace App\Domain\MarketData;

use App\Domain\Operations\ActionLog;
use App\Jobs\BackfillMarketHistory;
use App\Jobs\SyncMarketCandles;
use App\Models\MarketFeed;
use ccxt\AuthenticationError;
use ccxt\BadSymbol;
use ccxt\NetworkError;
use ccxt\NotSupported;
use ccxt\PermissionDenied;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class MarketHistoryBackfill
{
    public function feeds(?string $exchange = null, ?string $symbol = null, ?string $period = null): Builder
    {
        return MarketFeed::query()->with('market.exchange')->whereNotNull('selected_period')
            ->whereHas('market.subscriptions', fn ($query) => $query->where('active', true))
            ->when($exchange !== null, fn ($query) => $query->whereHas('market.exchange', fn ($query) => $query->where('class', $exchange)))
            ->when($symbol !== null, fn ($query) => $query->whereHas('market', fn ($query) => $query->where('symbol', $symbol)))
            ->when($period !== null, fn ($query) => $query->where('selected_period', $period));
    }

    public function dispatchDue(?string $exchange = null, ?string $symbol = null, ?string $period = null, bool $resume = false): int
    {
        $count = 0;
        foreach ($this->feeds($exchange, $symbol, $period)->lazyById(100, 'market_id') as $feed) {
            if ($period !== null && $feed->selected_period !== $period) {
                continue;
            }
            DB::table('market_history_backfills')->insertOrIgnore([
                'history_id' => (string) Str::uuid7(), 'market_id' => $feed->market_id,
                'period' => $feed->selected_period, 'next_attempt_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $query = DB::table('market_history_backfills')->where('market_id', $feed->market_id)
                ->where('period', $feed->selected_period);
            if ($resume) {
                $this->unleased(clone $query)->where('status', 'paused')->update([
                    'status' => 'active', 'reason' => null, 'empty_windows' => 0, 'failures' => 0,
                    'last_error' => null, 'next_attempt_at' => now(), 'updated_at' => now(),
                ]);
            }
            if ($this->claimAndDispatch($feed->market_id, (string) $feed->selected_period)) {
                $count++;
            }
            if ($count >= 100) {
                return $count;
            }
        }

        // Candidate-period evaluations use the same resumable history worker,
        // but they are intentionally not the feed's selected period yet.
        if (! $resume && $count < 100) {
            $candidates = DB::table('market_history_backfills as history')
                ->join('markets', 'markets.market_id', '=', 'history.market_id')
                ->join('exchanges', 'exchanges.exchange_id', '=', 'markets.exchange_id')
                ->join('market_feeds as feeds', 'feeds.market_id', '=', 'history.market_id')
                ->whereNotNull('history.target_start_ms')
                ->where(fn ($query) => $query->whereNull('feeds.selected_period')
                    ->orWhereColumn('feeds.selected_period', '<>', 'history.period'))
                ->whereExists(fn ($query) => $query->selectRaw('1')->from('market_subscriptions')
                    ->whereColumn('market_subscriptions.market_id', 'history.market_id')->where('active', true))
                ->when($exchange !== null, fn ($query) => $query->where('exchanges.class', $exchange))
                ->when($symbol !== null, fn ($query) => $query->where('markets.symbol', $symbol))
                ->when($period !== null, fn ($query) => $query->where('history.period', $period))
                ->where(fn ($query) => $query->whereNull('history.next_attempt_at')->orWhere('history.next_attempt_at', '<=', now()))
                ->where(fn ($query) => $query->whereNull('history.lease_until')->orWhere('history.lease_until', '<=', now()))
                ->whereNotIn('history.status', ['paused', 'complete'])
                ->orderBy('history.next_attempt_at')
                ->limit(100 - $count)
                ->get(['history.market_id', 'history.period']);

            foreach ($candidates as $candidate) {
                if ($this->claimAndDispatch((string) $candidate->market_id, (string) $candidate->period)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    public function dispatchCandidate(MarketFeed $feed, string $period, int $targetStartMs): bool
    {
        if (! in_array($period, CandleTimeframe::SUPPORTED, true) || $targetStartMs < 0
            || in_array(config('queue.default'), ['sync', 'null'], true)
            || ! $feed->market()->whereHas('subscriptions', fn ($query) => $query->where('active', true))->exists()) {
            return false;
        }

        DB::transaction(function () use ($feed, $period, $targetStartMs): void {
            DB::table('market_history_backfills')->insertOrIgnore([
                'history_id' => (string) Str::uuid7(),
                'market_id' => $feed->market_id,
                'period' => $period,
                'target_start_ms' => $targetStartMs,
                'next_attempt_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $query = DB::table('market_history_backfills')->where('market_id', $feed->market_id)
                ->where('period', $period);
            $row = (clone $query)->lockForUpdate()->first();
            if ($row === null) {
                return;
            }

            $target = $row->target_start_ms === null ? $targetStartMs : min((int) $row->target_start_ms, $targetStartMs);
            $changes = ['target_start_ms' => $target, 'updated_at' => now()];
            $covered = $row->before_ms !== null && (int) $row->before_ms <= $target;
            if (! $covered && ($row->status === 'complete' || $row->status === 'idle'
                || ($row->status === 'paused' && $row->reason === 'target_reached'))) {
                $changes += [
                    'status' => 'active', 'reason' => null, 'empty_windows' => 0, 'failures' => 0,
                    'last_error' => null, 'next_attempt_at' => now(),
                ];
            }
            $query->update($changes);
        });

        return $this->claimAndDispatch($feed->market_id, $period);
    }

    /** Refresh a bounded candidate tail even when its older backfill already completed. */
    public function refreshCandidate(MarketFeed $feed, string $period, int $fromMs, int $toMs): bool
    {
        if (! in_array($period, CandleTimeframe::SUPPORTED, true) || $fromMs < 0 || $fromMs > $toMs
            || in_array(config('queue.default'), ['sync', 'null'], true)
            || ! $feed->market()->whereHas('subscriptions', fn ($query) => $query->where('active', true))->exists()) {
            return false;
        }
        $feed->loadMissing('market.exchange');
        $job = new SyncMarketCandles($feed->market->exchange->class, $feed->market->symbol,
            $period, intdiv($fromMs, 1000), intdiv($toMs, 1000));
        $lock = Cache::lock($job->candidateLockKey(), 86_400);
        if (! $lock->get()) {
            return false;
        }
        $job->candidateLockOwner = $lock->owner();
        try {
            dispatch($job)->onQueue(config('history_backfill.queue'));
        } catch (Throwable $error) {
            $lock->release();
            throw $error;
        }

        return true;
    }

    private function claimAndDispatch(string $marketId, string $period): bool
    {
        $query = DB::table('market_history_backfills')->where('market_id', $marketId)->where('period', $period);
        $token = (string) Str::uuid7();
        $claimed = $this->unleased(clone $query)->whereNotIn('status', ['paused', 'complete'])
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->update([
                'status' => 'queued', 'lease_token' => $token, 'lease_until' => now()->addMinutes(15), 'updated_at' => now(),
            ]);
        if (! $claimed) {
            return false;
        }

        $id = $query->value('history_id');
        try {
            BackfillMarketHistory::dispatch($id, $token)->onQueue(config('history_backfill.queue'));
        } catch (Throwable $error) {
            $this->release($id, $token, ['status' => 'retrying', 'reason' => 'dispatch_failed',
                'last_error' => mb_substr($error->getMessage(), 0, 1000)]);
            throw $error;
        }

        return true;
    }

    public function owned(string $id, string $token): QueryBuilder
    {
        return DB::table('market_history_backfills')->where('history_id', $id)->where('lease_token', $token);
    }

    public function release(string $id, string $token, array $changes = [], int $delay = 60): void
    {
        $updated = $this->owned($id, $token)->update(array_merge([
            'lease_token' => null, 'lease_until' => null, 'next_attempt_at' => now()->addSeconds($delay),
            'status' => 'active', 'updated_at' => now(),
        ], $changes));
        if ($updated) {
            app(ActionLog::class)->write('history.updated', ['subject_id' => $id,
                'status' => $changes['status'] ?? 'active', 'reason' => $changes['reason'] ?? null,
                'rows' => $changes['window_rows'] ?? 0, 'outcome' => 'completed']);
        }
    }

    public function failure(string $id, string $token, Throwable $error): void
    {
        $row = $this->owned($id, $token)->first();
        if ($row === null) {
            return;
        }
        $log = app(ActionLog::class);
        $log->write('history.failed', ['subject_id' => $id, 'market_id' => $row->market_id,
            'outcome' => 'failed', ...$log->exception($error)]);
        $failures = min(100, $row->failures + 1);
        $permanent = $error instanceof NotSupported || $error instanceof BadSymbol
            || $error instanceof AuthenticationError || $error instanceof PermissionDenied
            || $error instanceof MarketCatalogException;
        $transient = $error instanceof NetworkError;
        $pause = $permanent || (! $transient && $failures >= max(1, config('history_backfill.errors_before_pause')));
        $this->release($id, $token, [
            'status' => $pause ? 'paused' : 'retrying',
            'reason' => $permanent ? 'access_or_support' : ($pause ? 'repeated_errors' : 'request_failed'),
            'failures' => $failures, 'last_error' => mb_substr($error->getMessage(), 0, 1000),
        ], min(3600, 60 * (2 ** min(6, $failures - 1))));
    }

    private function unleased(QueryBuilder $query): QueryBuilder
    {
        return $query->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()));
    }
}
