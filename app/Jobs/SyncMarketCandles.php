<?php

namespace App\Jobs;

use App\Domain\MarketData\CandleSyncPages;
use App\Domain\MarketData\MarketDataSynchronizer;
use App\Repositories\TickerRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class SyncMarketCandles implements ShouldQueue
{
    use Queueable;

    /** Ownership is carried across bounded pages; ordinary sync jobs do not acquire this lock. */
    public ?string $candidateLockOwner = null;

    public int $tries = 5;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [30, 60, 120, 240];

    public function __construct(
        public readonly string $exchange,
        public readonly string $symbol,
        public readonly string $period,
        public readonly int $from,
        public readonly int $to,
        public readonly bool $incremental = false,
        public readonly bool $repairGaps = false,
        public readonly int $pageSize = CandleSyncPages::DEFAULT_SIZE,
    ) {}

    public function handle(MarketDataSynchronizer $synchronizer, TickerRepository $tickers, CandleSyncPages $pages): void
    {
        if ($this->candidateLockOwner !== null
            && ! Cache::restoreLock($this->candidateLockKey(), $this->candidateLockOwner)->isOwnedByCurrentProcess()) {
            return;
        }
        $from = $this->from;
        if ($this->incremental) {
            $latest = $tickers->latestTimestamp($this->exchange, $this->symbol, $this->period);
            if ($latest !== null) {
                $from = max($from, intdiv($latest, 1000));
            }
        }
        if ($from > $this->to) {
            $this->releaseCandidateLock();

            return;
        }

        $page = $pages->window($from, $this->to, $this->period, $this->pageSize);
        $synchronizer->sync($this->exchange, $this->symbol, $this->period, $page['from'], $page['to'], false, $this->repairGaps, $this->pageSize);

        if ($page['next'] !== null) {
            $next = new self($this->exchange, $this->symbol, $this->period, $page['next'], $this->to, false, $this->repairGaps, $this->pageSize);
            $next->candidateLockOwner = $this->candidateLockOwner;
            dispatch($next)->onConnection($this->connection)->onQueue($this->queue)
                ->delay(now()->addSecond());
        } else {
            $this->releaseCandidateLock();
        }
    }

    public function candidateLockKey(): string
    {
        return 'trademinator:candidate-refresh:'.hash('sha256', "$this->exchange|$this->symbol|$this->period");
    }

    public function failed(?Throwable $error): void
    {
        $this->releaseCandidateLock();
    }

    private function releaseCandidateLock(): void
    {
        if ($this->candidateLockOwner !== null) {
            Cache::restoreLock($this->candidateLockKey(), $this->candidateLockOwner)->release();
        }
    }
}
