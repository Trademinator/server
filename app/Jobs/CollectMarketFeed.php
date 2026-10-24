<?php

namespace App\Jobs;

use App\Domain\MarketData\CandleFeedSchedule;
use App\Domain\MarketData\CandlePeriodReevaluation;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\MarketData\ExchangeMetadata;
use App\Domain\MarketData\MarketCatalogException;
use App\Domain\MarketData\MarketDataSynchronizer;
use App\Domain\Operations\ActionLog;
use App\Models\MarketFeed;
use App\Repositories\TickerRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class CollectMarketFeed implements ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    public int $maxExceptions = 5;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [30, 60, 120, 240];

    public ?int $queueRequestedAt = null;

    public function __construct(public readonly string $marketId, public readonly string $leaseToken) {}

    public function handle(CandlePeriodReevaluation $reevaluation, MarketDataSynchronizer $synchronizer,
        TickerRepository $tickers, ExchangeMetadata $metadata): void
    {
        $lock = Cache::lock('trademinator:market-feed:'.$this->marketId, 720);
        if (! $lock->get()) {
            $this->release(30);

            return;
        }
        try {
            $feed = MarketFeed::query()->with('market.exchange')->find($this->marketId);
            if ($feed === null || $feed->lease_token !== $this->leaseToken) {
                return; // Stale job from an expired or replaced claim.
            }
            if (! $feed->market->subscriptions()->where('active', true)
                ->whereHas('user', fn ($query) => $query->whereNull('suspended_at'))->exists()) {
                $this->finish('idle', null);

                return;
            }
            $market = $feed->market;
            $exchange = $market->exchange;
            try {
                $metadata->assertUsable($exchange, spotOnly: false, symbol: $market->symbol);
            } catch (MarketCatalogException $exception) {
                $this->finish('blocked', now()->addHours(6), $exception->getMessage());

                return;
            }
            if ($feed->selected_period === null) {
                $result = $reevaluation->evaluate($feed);
                $feed->refresh();
                if ($feed->selected_period === null) {
                    $next = $feed->selection_next_attempt_at ?? now()->addMinutes(15);
                    $this->finish('pending', $next, $result['reason'] ?? 'Automatic candle-period evaluation is pending.');

                    return;
                }
            }
            $period = $feed->selected_period;
            if (! in_array($period, CandleTimeframe::SUPPORTED, true)) {
                throw new RuntimeException('Invalid stored candle period.');
            }
            $schedule = new CandleFeedSchedule;
            $timeframe = new CandleTimeframe;
            $latest = $tickers->latestTimestamp($exchange->class, $market->symbol, $period);
            $toMs = $schedule->lastClosedOpening($period, now()->getTimestampMs(), $latest);
            $windowStart = $toMs;
            for ($i = 0; $i < 89; $i++) {
                $windowStart = $timeframe->previous($windowStart, $period);
            }
            $overlap = $latest ?? 0;
            for ($i = 0; $latest !== null && $i < 3; $i++) {
                $overlap = $timeframe->previous($overlap, $period);
            }
            $from = intdiv(max(0, $windowStart, $overlap), 1000);
            $to = intdiv($toMs, 1000);
            if ($from <= $to) {
                $synchronizer->sync($exchange->class, $market->symbol, $period, $from, $to, false, false, 90);
            }
            $completedAt = now()->getTimestampMs();
            $grace = max(0, min(300, (int) config('queue_management.feed_publication_grace_seconds', 5)));
            $expected = $schedule->lastClosedOpening($period, $completedAt - $grace * 1000, $latest);
            $received = $tickers->timestamps($exchange->class, $market->symbol, $period, $expected, $expected) !== [];
            $next = $schedule->nextPull($period, $completedAt, $latest, $received, $grace,
                (int) config('queue_management.feed_publication_retry_seconds', 60));
            $this->finish($received ? 'ready' : 'pending', Carbon::createFromTimestampMs($next, 'UTC'),
                $received ? null : 'The latest completed candle is not available yet; collection will be retried shortly.', true);
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception instanceof MaxAttemptsExceededException) {
            $this->finish('pending', now()->addMinute(), 'Market feed remained busy; collection will be retried.');

            return;
        }

        $this->finish('error', now()->addMinutes(5), $exception?->getMessage() ?? 'Collection failed.');
    }

    private function finish(string $status, mixed $next, ?string $error = null, bool $pulled = false): void
    {
        $updated = DB::table('market_feeds')->where('market_id', $this->marketId)->where('lease_token', $this->leaseToken)
            ->update(['status' => $status, 'next_pull_at' => $next,
                'last_pulled_at' => $pulled ? now() : DB::raw('last_pulled_at'),
                'last_error' => $error === null ? null : mb_substr($error, 0, 1000),
                'lease_until' => null, 'lease_token' => null, 'updated_at' => now()]);
        if ($updated) {
            app(ActionLog::class)->write('feed.updated', ['market_id' => $this->marketId,
                'status' => $status, 'outcome' => $status === 'error' ? 'failed' : 'completed']);
        }
    }
}
