<?php

namespace App\Jobs;

use App\Domain\Archive\FeatureCheckpointStore;
use App\Domain\Features\FeatureBuilder;
use App\Domain\Features\FeatureReplayTimeout;
use App\Domain\Intelligence\BackfillIntelligence;
use App\Domain\Intelligence\MarketIntelligence;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Intelligence\TrainingRuntime;
use App\Domain\MarketData\HistoryChanges;
use App\Models\MarketFeed;
use App\Repositories\TickerRepository;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

final class RebuildBackfilledIntelligence implements ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    public int $maxExceptions = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public ?string $schema = null;

    public function __construct(
        public readonly string $historyId,
        public readonly string $leaseToken,
        ?string $schema = null,
        public readonly ?int $featureStartMs = null,
        public readonly ?int $featureCutoffMs = null,
        public readonly bool $featureFinal = true,
        public readonly ?int $featureRevision = null,
    ) {
        $this->schema = $schema ?? (string) config('intelligence.schema');
        $this->timeout = TrainingRuntime::jobTimeout();
    }

    public function handle(BackfillIntelligence $builds, FeatureBuilder $features, MarketIntelligence $intelligence): void
    {
        $state = $builds->owned($this->historyId, $this->leaseToken)->first();
        if ($state === null) {
            return;
        }
        $feed = MarketFeed::query()->with('market.exchange')->find($state->market_id);
        if (! config('history_backfill.enabled') || ! config('intelligence.enabled') || $feed === null
            || $feed->selected_period !== $state->period || ! $feed->market->subscriptions()->where('active', true)->exists()) {
            $builds->release($this->historyId, $this->leaseToken, ['build_error' => 'Waiting for an active feed and enabled intelligence.'], 60);

            return;
        }
        $exchange = $feed->market->exchange->class;
        $symbol = $feed->market->symbol;
        $key = ModelStore::marketKey($exchange, $symbol, $state->period);
        $lock = Cache::lock('trademinator:history-intelligence:'.$key, TrainingRuntime::lockSeconds());
        if (! $lock->get()) {
            $this->release(60);

            return;
        }
        $featureLockAcquired = false;
        $leaseKey = 'backfill:'.$this->historyId.':'.$this->leaseToken;
        try {
            TrainingRuntime::registerLock($lock);
            // Refresh after taking the lock: a repair may have completed while queued.
            $state = $builds->owned($this->historyId, $this->leaseToken)->first();
            if ($state === null) {
                return;
            }
            if (! $builds->renew($this->historyId, $this->leaseToken)) {
                return;
            }
            TrainingRuntime::registerLease($leaseKey,
                fn (): bool => $builds->renew($this->historyId, $this->leaseToken, allowWaiting: false));
            $this->assertOwnership($builds, $lock);
            $expectedRevision = $this->featureRevision
                ?? ($state->build_stage === 'knn' ? (int) $state->build_revision : null);
            if ($expectedRevision !== null && $expectedRevision !== (int) $state->history_revision) {
                // A changed prefix invalidates checkpoints from this split chain.
                // Releasing the token makes all remaining old children harmless.
                $this->restartChangedHistory($builds, $exchange, $symbol, $state->period);

                return;
            }
            if ($state->build_stage === 'features') {
                // Coalesce imports that completed before the root rebuild actually
                // started. Split children then carry this exact revision so imports
                // arriving while the chain runs remain dirty for the next rebuild.
                $rootReplay = $this->featureRevision === null;
                $revision = $this->featureRevision ?? (int) $state->history_revision;
                if ($revision <= 0) {
                    throw new RuntimeException('Invalid backfill feature revision.');
                }
                if ($rootReplay) {
                    $builds->owned($this->historyId, $this->leaseToken)->update([
                        'build_revision' => $revision, 'updated_at' => now(),
                    ]);
                }

                $cutoffMs = $this->featureCutoffMs ?? (int) floor(microtime(true) * 1000);
                $changedFromMs = app(HistoryChanges::class)->replayStart($state, $revision);
                $startMs = $this->featureStartMs ?? $changedFromMs
                    ?? $this->firstCandleMs($exchange, $symbol, $state->period, $cutoffMs);

                $checkpoints = app(FeatureCheckpointStore::class);
                if ($rootReplay) {
                    // Recursive state strictly before the changed range is safe.
                    // A normal older-history prepend has no earlier checkpoint and
                    // therefore still falls back to the required full replay.
                    $this->assertOwnership($builds, $lock);
                    $checkpoints->deleteFrom($exchange, $symbol, $state->period, $startMs);
                    $builds->owned($this->historyId, $this->leaseToken)->update([
                        'build_performance' => json_encode([
                            'feature_replay' => [
                                'duration_ms' => 0, 'rows_processed' => 0, 'chunks' => 0,
                                'from_ms' => $startMs, 'through_ms' => $startMs,
                                'checkpoint_used' => false,
                                'history_revision' => $revision,
                                'changed_from_ms' => $changedFromMs,
                                'range_tracking' => $changedFromMs === null ? 'legacy_full_replay' : 'exact_changed_range',
                            ],
                        ], JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
                }
                $this->assertOwnership($builds, $lock);

                $checkpointBeforeMs = $this->featureCutoffMs === null
                    ? $startMs
                    : ($cutoffMs === PHP_INT_MAX ? PHP_INT_MAX : $cutoffMs + 1);
                $checkpointUsed = $checkpoints->before($exchange, $symbol, $state->period, $checkpointBeforeMs) !== null;
                $featureStarted = hrtime(true);
                try {
                    $rows = $features->build($exchange, $symbol, $state->period, cutoffMs: $cutoffMs,
                        fromMs: $startMs, checkpointBeforeMs: $checkpointBeforeMs);
                    $this->assertOwnership($builds, $lock);
                    $this->recordFeatureReplay($builds, $this->elapsedMs($featureStarted), $rows,
                        $checkpointUsed, $startMs, $cutoffMs);
                } catch (FeatureReplayTimeout $timeout) {
                    $this->assertOwnership($builds, $lock);
                    $this->recordFeatureReplay($builds, $this->elapsedMs($featureStarted), $timeout->rowsProcessed,
                        $checkpointUsed, $startMs, $timeout->throughMs ?? $cutoffMs);
                    $this->splitFeatureReplay($builds, $startMs, $cutoffMs, $timeout, $revision);

                    return;
                }

                if (! $this->featureFinal) {
                    $builds->waiting($this->historyId, $this->leaseToken);

                    return;
                }

                $builds->release($this->historyId, $this->leaseToken, [
                    'build_stage' => 'knn', 'build_revision' => $revision, 'build_error' => null,
                ]);
            } elseif ($state->build_stage === 'knn' && (int) $state->build_revision > 0) {
                $featureLock = Cache::lock('trademinator:features:'.$key, TrainingRuntime::lockSeconds());
                $featureLockAcquired = $featureLock->get();
                if (! $featureLockAcquired) {
                    $builds->waiting($this->historyId, $this->leaseToken);
                    $this->release(30);

                    return;
                }
                TrainingRuntime::registerLock($featureLock);
                // A repair/import may finish between the first revision check
                // and acquiring this lock. Do not snapshot stale feature state.
                $state = $builds->owned($this->historyId, $this->leaseToken)->first();
                if ($state === null) {
                    return;
                }
                if ((int) $state->build_revision !== (int) $state->history_revision) {
                    $this->restartChangedHistory($builds, $exchange, $symbol, $state->period);

                    return;
                }
                $revision = (int) $state->build_revision;
                $this->assertOwnership($builds, $lock, $featureLock);
                $performance = is_string($state->build_performance)
                    ? json_decode($state->build_performance, true, flags: JSON_THROW_ON_ERROR)
                    : (array) ($state->build_performance ?? []);
                $report = $intelligence->build($exchange, $symbol, $state->period,
                    schema: $state->recovery_schema ?? $this->schema ?? (string) config('intelligence.schema'),
                    generation: hash('sha256', 'history:'.$this->historyId.':'.$revision),
                    buildPerformance: $performance, featureLockOwner: $featureLock->owner(),
                    beforePublish: function () use ($builds, $lock, $featureLock, $revision): void {
                        $this->assertOwnership($builds, $lock, $featureLock);
                        if (! $builds->owned($this->historyId, $this->leaseToken)
                            ->where('build_revision', $revision)->where('history_revision', $revision)->exists()) {
                            throw new RuntimeException('Backfill history changed before model publication.');
                        }
                    });
                $this->assertOwnership($builds, $lock, $featureLock);
                $builds->release($this->historyId, $this->leaseToken, [
                    'trained_revision' => $revision, 'build_stage' => null, 'build_revision' => null,
                    'build_failures' => 0, 'build_error' => null, 'recovery_schema' => null, 'model_id' => $report['model_id'], 'last_trained_at' => now(),
                ]);
            } else {
                throw new RuntimeException('Invalid backfill intelligence checkpoint.');
            }
            TrainingRuntime::unregisterLease($leaseKey);
            $builds->dispatchDue($exchange, $symbol, $state->period);
        } catch (Throwable $error) {
            $builds->failure($this->historyId, $this->leaseToken, $error);
            report($error);
        } finally {
            TrainingRuntime::unregisterLease($leaseKey);
            if ($featureLockAcquired) {
                TrainingRuntime::unregisterLock($featureLock);
                $featureLock->release();
            }
            TrainingRuntime::unregisterLock($lock);
            $lock->release();
        }
    }

    private function assertOwnership(BackfillIntelligence $builds, Lock $lock, ?Lock $featureLock = null): void
    {
        TrainingRuntime::heartbeat(true);
        if (! $lock->isOwnedByCurrentProcess() || ($featureLock !== null && ! $featureLock->isOwnedByCurrentProcess())) {
            throw new RuntimeException('Backfill intelligence lock ownership was lost.');
        }
        $builds->assertRunning($this->historyId, $this->leaseToken);
    }

    private function restartChangedHistory(BackfillIntelligence $builds, string $exchange, string $symbol, string $period): void
    {
        $builds->release($this->historyId, $this->leaseToken, [
            'build_stage' => 'features', 'build_revision' => null,
            'build_performance' => null, 'build_error' => null,
        ]);
        TrainingRuntime::unregisterLease('backfill:'.$this->historyId.':'.$this->leaseToken);
        $builds->dispatchDue($exchange, $symbol, $period);
    }

    private function splitFeatureReplay(BackfillIntelligence $builds, int $startMs, int $cutoffMs,
        FeatureReplayTimeout $timeout, int $revision): void
    {
        $resumeMs = max($startMs, $timeout->throughMs ?? $startMs);
        if ($resumeMs >= $cutoffMs - 1) {
            throw $timeout;
        }
        $middleMs = $resumeMs + intdiv($cutoffMs - $resumeMs, 2);
        if ($middleMs <= $resumeMs || $middleMs >= $cutoffMs) {
            throw $timeout;
        }

        $queue = (string) config('intelligence.queue');
        $first = (new self($this->historyId, $this->leaseToken, $this->schema,
            $resumeMs, $middleMs, false, $revision))->onQueue($queue);
        $second = (new self($this->historyId, $this->leaseToken, $this->schema,
            $middleMs, $cutoffMs, $this->featureFinal, $revision))->onQueue($queue);

        // The current job returns successfully after installing its replacements.
        // Laravel deletes it and dispatches the first child. The second child waits
        // in the chain because recursive indicators cannot be calculated in parallel.
        $this->prependToChain([$first, $second]);
        $builds->waiting($this->historyId, $this->leaseToken);
    }

    private function firstCandleMs(string $exchange, string $symbol, string $period, int $cutoffMs): int
    {
        foreach (app(TickerRepository::class)->streamHistory($exchange, $symbol, $period, 0, $cutoffMs) as $timestamp => $_) {
            return (int) $timestamp;
        }

        return 0;
    }

    private function recordFeatureReplay(BackfillIntelligence $builds, int $durationMs, int $rows,
        bool $checkpointUsed, int $fromMs, int $throughMs): void
    {
        $state = $builds->owned($this->historyId, $this->leaseToken)->first();
        if ($state === null) {
            return;
        }
        $performance = is_string($state->build_performance)
            ? json_decode($state->build_performance, true, flags: JSON_THROW_ON_ERROR)
            : (array) ($state->build_performance ?? []);
        $feature = $performance['feature_replay'] ?? [];
        $feature['duration_ms'] = (int) ($feature['duration_ms'] ?? 0) + $durationMs;
        $feature['rows_processed'] = (int) ($feature['rows_processed'] ?? 0) + $rows;
        $feature['chunks'] = (int) ($feature['chunks'] ?? 0) + 1;
        $feature['from_ms'] = min((int) ($feature['from_ms'] ?? $fromMs), $fromMs);
        $feature['through_ms'] = max((int) ($feature['through_ms'] ?? $throughMs), $throughMs);
        $feature['checkpoint_used'] = (bool) ($feature['checkpoint_used'] ?? false) || $checkpointUsed;
        $performance['feature_replay'] = $feature;
        $builds->owned($this->historyId, $this->leaseToken)->update([
            'build_performance' => json_encode($performance, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    private function elapsedMs(int $startedNs): int
    {
        return (int) round((hrtime(true) - $startedNs) / 1_000_000);
    }

    public function failed(?Throwable $exception): void
    {
        app(BackfillIntelligence::class)->failure($this->historyId, $this->leaseToken,
            $exception ?? new RuntimeException('Backfill intelligence worker failed.'));
    }
}
