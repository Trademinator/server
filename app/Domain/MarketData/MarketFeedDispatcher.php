<?php

namespace App\Domain\MarketData;

use App\Jobs\CollectMarketFeed;
use App\Models\MarketFeed;
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
                ->whereColumn('market_subscriptions.market_id', 'market_feeds.market_id')->where('active', true));
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

        // Rank due feeds within each exchange, then dispatch one feed from each
        // exchange per round. This reduces same-exchange request bursts while
        // preserving oldest-first ordering inside every exchange.
        $ranked = MarketFeed::query()
            ->select(['market_feeds.market_id', 'markets.exchange_id', 'market_feeds.next_pull_at'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY markets.exchange_id ORDER BY market_feeds.next_pull_at, market_feeds.market_id) AS exchange_rank')
            ->selectRaw('MIN(market_feeds.next_pull_at) OVER (PARTITION BY markets.exchange_id) AS exchange_first_due')
            ->join('markets', 'markets.market_id', '=', 'market_feeds.market_id')
            ->where('market_feeds.next_pull_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('market_feeds.lease_until')
                ->orWhere('market_feeds.lease_until', '<=', now()))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('market_subscriptions')
                ->whereColumn('market_subscriptions.market_id', 'market_feeds.market_id')->where('active', true));

        $ids = DB::query()->fromSub($ranked, 'due_feeds')
            ->orderBy('exchange_rank')
            ->orderBy('exchange_first_due')
            ->orderBy('exchange_id')
            ->limit($limit)
            ->pluck('market_id');

        foreach ($ids as $marketId) {
            $token = (string) Str::uuid7();
            // The conditional update is a database claim. The cache scheduler lock
            // reduces contention; this claim remains safe with multiple HTTP nodes.
            $claimed = DB::table('market_feeds')->where('market_id', $marketId)
                ->where('next_pull_at', '<=', now())
                ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
                ->whereExists(fn ($query) => $query->selectRaw('1')->from('market_subscriptions')
                    ->whereColumn('market_subscriptions.market_id', 'market_feeds.market_id')->where('active', true))
                ->update(['lease_token' => $token, 'lease_until' => now()->addMinutes(15), 'status' => 'queued', 'updated_at' => now()]);
            if (! $claimed) {
                continue;
            }
            try {
                CollectMarketFeed::dispatch((string) $marketId, $token);
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
}
