<?php

namespace App\Jobs;

use App\Domain\Archive\FeatureCheckpointStore;
use App\Domain\Features\FeatureBuilder;
use App\Domain\Features\FeatureBuildLocked;
use App\Domain\Features\FeatureEngine;
use App\Domain\Features\FeatureHistoryRevisions;
use App\Domain\Features\FeatureReplayTimeout;
use App\Domain\Intelligence\KnowledgeRequestJournal;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\Operations\ActionLog;
use App\Repositories\TickerRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class BuildMarketFeatures implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    public int $maxExceptions = 3;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public array $backoff = [60, 300];

    // Defaults also apply to jobs serialized before continuation support existed.
    public ?int $replayFromMs = null;

    public ?int $replayCutoffMs = null;

    public ?int $checkpointBeforeMs = null;

    public ?int $chunkCandles = null;

    public ?int $queueRequestedAt = null;

    public ?string $featureHistoryId = null;

    public ?int $featureHistoryRevision = null;

    public function __construct(public string $exchange, public string $symbol, public string $period)
    {
        $this->onQueue((string) config('features.queue', 'features'));
    }

    public function uniqueId(): string
    {
        return hash('sha256', "$this->exchange|$this->symbol|$this->period");
    }

    public function handle(FeatureBuilder $builder, TickerRepository $tickers): void
    {
        $latestCandle = $tickers->latestTimestamp($this->exchange, $this->symbol, $this->period);
        if ($latestCandle === null) {
            return;
        }
        $timeframe = new CandleTimeframe;
        if ($timeframe->next($latestCandle, $this->period) > now()->getTimestampMs()) {
            $latestCandle = $timeframe->previous($latestCandle, $this->period);
        }
        if ($latestCandle < 0) {
            return;
        }

        $latestFeature = DB::table('market_features')
            ->where('exchange', $this->exchange)
            ->where('symbol', $this->symbol)
            ->where('period', $this->period)
            ->where('version', FeatureEngine::VERSION)
            ->max('microtimestamp');

        $revision = $this->replayCutoffMs === null
            ? app(FeatureHistoryRevisions::class)->pending($this->exchange, $this->symbol, $this->period)
            : null;
        if ($revision !== null) {
            $this->featureHistoryId = $revision['history_id'];
            $this->featureHistoryRevision = $revision['revision'];
        }

        // A previous queued build may have caught up before this job started.
        if ($this->replayCutoffMs === null && $revision === null && $latestFeature !== null && (int) $latestFeature >= $latestCandle) {
            app(KnowledgeRequestJournal::class)
                ->markSatisfied($this->exchange, $this->symbol, $this->period, 'features', (int) $latestFeature);

            return;
        }

        $fromMs = $this->replayFromMs;
        if ($revision !== null) {
            $fromMs = $latestFeature === null ? null : $revision['from_ms'];
        } elseif ($this->replayCutoffMs === null && $latestFeature !== null) {
            // Rebuild a small overlap so any recent exchange correction is folded
            // into the new feature rows while the checkpoint preserves older state.
            $fromMs = (int) $latestFeature;
            for ($i = 0; $i < 3; $i++) {
                $fromMs = max(0, $timeframe->previous($fromMs, $this->period));
            }
        }

        $cutoffMs = $this->replayCutoffMs ?? (int) floor(microtime(true) * 1000);
        $checkpointBeforeMs = $this->checkpointBeforeMs ?? $fromMs;
        $checkpoint = $checkpointBeforeMs === null ? null : app(FeatureCheckpointStore::class)
            ->before($this->exchange, $this->symbol, $this->period, $checkpointBeforeMs);
        $resumeThroughMs = isset($checkpoint['through_ms']) ? (int) $checkpoint['through_ms'] : null;
        $chunkCandles = max(1, $this->chunkCandles ?? (int) config('features.queue_chunk_candles', 1000));
        $chunkCutoffMs = $cutoffMs;
        $candles = 0;

        // Bound the actual source replay, including any warmup before fromMs.
        // streamHistory includes archived candles as well as live database rows.
        foreach ($tickers->streamHistory($this->exchange, $this->symbol, $this->period,
            $resumeThroughMs === null ? 0 : $resumeThroughMs + 1, $cutoffMs) as $timestamp => $_) {
            $closeMs = $timeframe->next((int) $timestamp, $this->period);
            if ($closeMs > $cutoffMs) {
                break;
            }
            if (++$candles >= $chunkCandles) {
                $chunkCutoffMs = $closeMs;
                break;
            }
        }
        if ($candles === 0) {
            return;
        }

        $started = hrtime(true);
        try {
            $rows = $builder->build($this->exchange, $this->symbol, $this->period,
                cutoffMs: $chunkCutoffMs, fromMs: $fromMs, checkpointBeforeMs: $checkpointBeforeMs);
        } catch (FeatureBuildLocked) {
            $this->release(60);

            return;
        } catch (FeatureReplayTimeout $timeout) {
            $advanced = $timeout->throughMs !== null
                && ($resumeThroughMs === null || $timeout->throughMs > $resumeThroughMs);
            if (! $advanced && $chunkCandles === 1) {
                throw $timeout;
            }

            $this->continueReplay($fromMs, $cutoffMs,
                $advanced ? $timeout->throughMs + 1 : $checkpointBeforeMs,
                max(1, intdiv($chunkCandles, 2)));
            $this->logContinuation($timeout->rowsProcessed, $timeout->throughMs, $started, 'time_budget');

            return;
        }

        if ($chunkCutoffMs < $cutoffMs) {
            $this->continueReplay($fromMs, $cutoffMs, $chunkCutoffMs, $chunkCandles);
            $this->logContinuation($rows, $timeframe->previous($chunkCutoffMs, $this->period), $started, 'chunk_limit');
        } else {
            if ($this->featureHistoryId !== null && $this->featureHistoryRevision !== null) {
                app(FeatureHistoryRevisions::class)->complete($this->featureHistoryId, $this->featureHistoryRevision);
            }
            $completed = DB::table('market_features')->where('exchange', $this->exchange)
                ->where('symbol', $this->symbol)->where('period', $this->period)
                ->where('version', FeatureEngine::VERSION)->max('microtimestamp');
            if ($completed !== null) {
                app(KnowledgeRequestJournal::class)
                    ->markSatisfied($this->exchange, $this->symbol, $this->period, 'features', (int) $completed);
            }
            if (app(FeatureHistoryRevisions::class)->pending($this->exchange, $this->symbol, $this->period) !== null) {
                $next = (new self($this->exchange, $this->symbol, $this->period))
                    ->onConnection($this->connection)->onQueue($this->queue);
                $next->queueRequestedAt = $this->requestedAt();
                $this->prependToChain($next);
            }
        }
    }

    public function failed(?\Throwable $error): void
    {
        app(KnowledgeRequestJournal::class)
            ->fail($this->exchange, $this->symbol, $this->period, 'features',
                $error?->getMessage() ?? 'Feature build exhausted retries.');
    }

    private function continueReplay(?int $fromMs, int $cutoffMs, ?int $checkpointBeforeMs, int $chunkCandles): void
    {
        $next = (new self($this->exchange, $this->symbol, $this->period))
            ->onConnection($this->connection)->onQueue($this->queue);
        $next->replayFromMs = $fromMs;
        $next->replayCutoffMs = $cutoffMs;
        $next->checkpointBeforeMs = $checkpointBeforeMs;
        $next->chunkCandles = $chunkCandles;
        $next->queueRequestedAt = $this->requestedAt();
        $next->featureHistoryId = $this->featureHistoryId;
        $next->featureHistoryRevision = $this->featureHistoryRevision;

        // Dispatch after successful completion releases this job's unique lock.
        // Fresh jobs preserve retries for real failures and keep recursive state sequential.
        $this->prependToChain($next);
    }

    private function requestedAt(): int
    {
        return $this->queueRequestedAt ?? (int) ($this->job?->payload()['queue_context']['requested_at'] ?? now()->getTimestamp());
    }

    private function logContinuation(int $rows, ?int $throughMs, int $started, string $reason): void
    {
        app(ActionLog::class)->write('features.continued', [
            'exchange' => $this->exchange, 'symbol' => $this->symbol, 'period' => $this->period,
            'queue' => $this->queue, 'rows' => $rows, 'candle_ms' => $throughMs,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'reason' => $reason, 'outcome' => 'continued',
        ]);
    }
}
