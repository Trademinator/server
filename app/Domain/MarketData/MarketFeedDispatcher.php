<?php

namespace App\Domain\MarketData;

use App\Jobs\CollectMarketFeed;
use App\Models\MarketFeed;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class MarketFeedDispatcher
{
    /** Queue precisely this feed, even if its next scheduled pull is in the future. */
    public function dispatchOne(string $marketId, bool $force = false): bool
    {
        $token = (string) Str::uuid7();
        $candidate = DB::table('market_feeds')->where('market_id', $marketId)
            ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('market_subscriptions')
                ->join('users', 'users.user_id', '=', 'market_subscriptions.user_id')
                ->whereColumn('market_subscriptions.market_id', 'market_feeds.market_id')
                ->where('market_subscriptions.active', true)->whereNull('users.suspended_at'));
        if (! $force) {
            $candidate->where('next_pull_at', '<=', now());
        }
        $claimed = $candidate->update([
            'lease_token' => $token, 'lease_until' => now()->addMinutes(15),
            'status' => 'queued', 'updated_at' => now(),
        ]);
        if (! $claimed) {
            return false;
        }
        try {
            CollectMarketFeed::dispatch($marketId, $token);
        } catch (Throwable $error) {
            DB::table('market_feeds')->where('market_id', $marketId)->where('lease_token', $token)
                ->update(['lease_token' => null, 'lease_until' => null, 'status' => 'error',
                    'last_error' => mb_substr($error->getMessage(), 0, 1000), 'updated_at' => now()]);
            throw $error;
        }

        return true;
    }

    public function dispatchDue(int $limit = 100): int
    {
        $dispatched = 0;

        $agedBefore = now()->subSeconds(max(1, (int) config('queue_management.feed_max_wait_seconds', 300)));
        $priority = 'CASE WHEN market_feeds.next_pull_at <= ? THEN 0 ELSE 1 END, '
            .'CASE WHEN market_feeds.next_pull_at <= ? THEN market_feeds.next_pull_at END, '
            .'demand.active_subscriber_count DESC, market_feeds.next_pull_at, market_feeds.market_id';

        // Preserve exchange turns while preferring demand within each turn.
        // Protected old work is ordered by age before ordinary subscriber ranking.
        $ranked = MarketFeed::query()
            ->select(['market_feeds.market_id', 'markets.exchange_id', 'market_feeds.next_pull_at', 'demand.active_subscriber_count'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY markets.exchange_id ORDER BY '.$priority.') AS exchange_rank', [$agedBefore, $agedBefore])
            ->selectRaw('CASE WHEN market_feeds.next_pull_at <= ? THEN 1 ELSE 0 END AS priority_aged', [$agedBefore])
            ->join('markets', 'markets.market_id', '=', 'market_feeds.market_id')
            ->joinSub($this->subscriberCounts(), 'demand', 'demand.market_id', '=', 'market_feeds.market_id')
            ->where('market_feeds.next_pull_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('market_feeds.lease_until')
                ->orWhere('market_feeds.lease_until', '<=', now()));

        $ids = DB::query()->fromSub($ranked, 'due_feeds')
            ->orderBy('exchange_rank')->orderByDesc('priority_aged')
            ->orderByRaw('CASE WHEN priority_aged = 1 THEN next_pull_at END')
            ->orderByDesc('active_subscriber_count')->orderBy('next_pull_at')->orderBy('exchange_id')
            ->limit($limit)->get(['market_id', 'next_pull_at']);

        foreach ($ids as $candidate) {
            $marketId = (string) $candidate->market_id;
            $token = (string) Str::uuid7();
            // The conditional update is a database claim. The cache scheduler lock
            // reduces contention; this claim remains safe with multiple HTTP nodes.
            $claimed = DB::table('market_feeds')->where('market_id', $marketId)
                ->where('next_pull_at', '<=', now())
                ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
                ->whereExists(fn ($query) => $query->selectRaw('1')->from('market_subscriptions')
                    ->join('users', 'users.user_id', '=', 'market_subscriptions.user_id')
                    ->whereColumn('market_subscriptions.market_id', 'market_feeds.market_id')
                    ->where('market_subscriptions.active', true)->whereNull('users.suspended_at'))
                ->update(['lease_token' => $token, 'lease_until' => now()->addMinutes(15), 'status' => 'queued', 'updated_at' => now()]);
            if (! $claimed) {
                continue;
            }
            try {
                $job = new CollectMarketFeed($marketId, $token);
                $job->queueRequestedAt = Carbon::parse($candidate->next_pull_at)->getTimestamp();
                dispatch($job);
                $dispatched++;
            } catch (Throwable $exception) {
                DB::table('market_feeds')->where('market_id', $marketId)->where('lease_token', $token)
                    ->update(['lease_token' => null, 'lease_until' => null, 'status' => 'error',
                        'last_error' => mb_substr($exception->getMessage(), 0, 1000), 'updated_at' => now()]);
                throw $exception;
            }
        }

        return $dispatched;
    }

    private function subscriberCounts(): Builder
    {
        return DB::table('market_subscriptions')->join('users', 'users.user_id', '=', 'market_subscriptions.user_id')
            ->where('market_subscriptions.active', true)->whereNull('users.suspended_at')
            ->groupBy('market_subscriptions.market_id')
            ->select('market_subscriptions.market_id')->selectRaw('COUNT(DISTINCT market_subscriptions.user_id) AS active_subscriber_count');
    }
}
