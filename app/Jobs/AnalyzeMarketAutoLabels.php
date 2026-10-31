<?php

namespace App\Jobs;

use App\Domain\Intelligence\AlgorithmicLabelBuilder;
use App\Domain\Intelligence\BuildPaused;
use App\Domain\Intelligence\KnowledgeRequestJournal;
use App\Domain\Intelligence\TrainingRuntime;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

final class AnalyzeMarketAutoLabels implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    // Older serialized jobs omit this property because 900 was its default.
    public int $timeout = 900;

    public bool $failOnTimeout = false;

    public ?string $dispatchLockOwner = null;

    public function __construct(
        public string $exchange,
        public string $symbol,
        public string $period,
        public string $cacheKey,
        public ?int $asOfMs = null,
    ) {
        $this->timeout = TrainingRuntime::labelJobTimeout();
        $this->asOfMs ??= now()->getTimestampMs();
    }

    public static function lockSeconds(): int
    {
        return TrainingRuntime::labelJobTimeout() + 300;
    }

    public function handle(AlgorithmicLabelBuilder $builder): void
    {
        $lock = $this->dispatchLockOwner === null ? null
            : Cache::lock($this->cacheKey.':lock', $this->timeout + 300, $this->dispatchLockOwner);
        if ($lock !== null && ! $lock->refresh() && ! $lock->get()) {
            $this->release(30);

            return;
        }
        $paused = false;

        Cache::put($this->cacheKey.':status', 'running', now()->addSeconds($this->timeout + 300));
        try {
            $result = $builder->build($this->exchange, $this->symbol, $this->period, $this->asOfMs ?? (int) (($this->job?->payload()['createdAt'] ?? now()->getTimestamp()) * 1000),
                deadline: $this->job === null ? null : microtime(true) + min(
                    (float) config('research.checkpoint_work_seconds', 240), max(1, $this->timeout - 60)));
            app(KnowledgeRequestJournal::class)
                ->markSatisfied($this->exchange, $this->symbol, $this->period, 'labels', (int) $result['as_of_ms']);
            Cache::put($this->cacheKey.':summary', [
                'analyzed_at' => now()->toIso8601String(),
                'action_counts' => $result['action']['action_counts'],
            ], now()->addDay());
            Cache::forget($this->cacheKey.':error');
            Cache::put($this->cacheKey.':status', 'completed', now()->addDay());
        } catch (BuildPaused $pause) {
            if ($this->job === null) {
                throw $pause;
            }
            $paused = true;
            Cache::put($this->cacheKey.':status', 'queued', now()->addSeconds($this->timeout + 300));
            $this->release(5);
        } catch (\Throwable $error) {
            app(KnowledgeRequestJournal::class)
                ->fail($this->exchange, $this->symbol, $this->period, 'labels', $error->getMessage());
            Cache::put($this->cacheKey.':status', 'failed', now()->addDay());
            Cache::put($this->cacheKey.':error', $error->getMessage(), now()->addDay());
            throw $error;
        } finally {
            if (! $paused) {
                $lock?->release();
            }
        }
    }

    public function failed(?\Throwable $error): void
    {
        $lock = $this->dispatchLockOwner === null ? null
            : Cache::restoreLock($this->cacheKey.':lock', $this->dispatchLockOwner);
        if ($lock !== null && ! $lock->isOwnedByCurrentProcess()) {
            return;
        }

        app(KnowledgeRequestJournal::class)->fail($this->exchange, $this->symbol, $this->period,
            'labels', $error?->getMessage() ?? 'Auto-labelling exhausted its retry attempts.');
        Cache::put($this->cacheKey.':status', 'failed', now()->addDay());
        Cache::put($this->cacheKey.':error',
            $error?->getMessage() ?? 'Auto-labelling exhausted its retry attempts.', now()->addDay());
        $lock?->release();
    }
}
