<?php

namespace App\Jobs;

use App\Domain\Intelligence\AlgorithmicLabelBuilder;
use App\Domain\Intelligence\KnowledgeRequestJournal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

final class AnalyzeMarketAutoLabels implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public ?string $dispatchLockOwner = null;

    public function __construct(
        public string $exchange,
        public string $symbol,
        public string $period,
        public string $cacheKey,
        public ?int $asOfMs = null,
    ) {}

    public function handle(AlgorithmicLabelBuilder $builder): void
    {
        $lock = $this->dispatchLockOwner === null ? null
            : Cache::lock($this->cacheKey.':lock', $this->timeout + 300, $this->dispatchLockOwner);
        if ($lock !== null && ! $lock->refresh() && ! $lock->get()) {
            return;
        }

        Cache::put($this->cacheKey.':status', 'running', now()->addDay());
        try {
            $result = $builder->build($this->exchange, $this->symbol, $this->period, $this->asOfMs ?? now()->getTimestampMs());
            app(KnowledgeRequestJournal::class)
                ->markSatisfied($this->exchange, $this->symbol, $this->period, 'labels', (int) $result['as_of_ms']);
            Cache::put($this->cacheKey.':summary', [
                'analyzed_at' => now()->toIso8601String(),
                'action_counts' => $result['action']['action_counts'],
            ], now()->addDay());
            Cache::forget($this->cacheKey.':error');
            Cache::put($this->cacheKey.':status', 'completed', now()->addDay());
        } catch (\Throwable $error) {
            app(KnowledgeRequestJournal::class)
                ->fail($this->exchange, $this->symbol, $this->period, 'labels', $error->getMessage());
            Cache::put($this->cacheKey.':status', 'failed', now()->addDay());
            Cache::put($this->cacheKey.':error', $error->getMessage(), now()->addDay());
            throw $error;
        } finally {
            $lock?->release();
        }
    }

    public function failed(?\Throwable $error): void
    {
        $lock = $this->dispatchLockOwner === null ? null
            : Cache::restoreLock($this->cacheKey.':lock', $this->dispatchLockOwner);
        if ($lock !== null && ! $lock->isOwnedByCurrentProcess()) {
            return;
        }

        Cache::put($this->cacheKey.':status', 'failed', now()->addDay());
        Cache::put($this->cacheKey.':error',
            $error?->getMessage() ?? 'Auto-labelling exhausted its retry attempts.', now()->addDay());
        $lock?->release();
    }
}
