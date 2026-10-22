<?php

namespace App\Domain\Intelligence;

use Closure;
use Illuminate\Cache\Lock;

final class TrainingRuntime
{
    public static function algorithmSeconds(): float
    {
        $seconds = app()->bound('config') ? config('intelligence.max_seconds', 1800) : 1800;

        return max(0.001, (float) $seconds);
    }

    public static function algorithmDeadline(?float $outerDeadline = null): float
    {
        self::heartbeat();
        $deadline = microtime(true) + self::algorithmSeconds();

        return $outerDeadline === null ? $deadline : min($deadline, $outerDeadline);
    }

    /**
     * Reserve every independently bounded run, including each K in the shared
     * neighbor pass. Disabled stages need no allowance; fewer eligible rows or
     * peers can reduce actual work but must never shorten another run's budget.
     *
     * @param  array<string, mixed>  $intelligence
     * @param  array<string, mixed>  $leadLag
     * @param  array<string, mixed>  $humanTraining
     * @return array<string, int>
     */
    public static function runCounts(array $intelligence, array $leadLag, array $humanTraining): array
    {
        $knn = $intelligence['knn'] ?? [];
        $minimumK = max(1, (int) floor((float) ($knn['min_effective_neighbors'] ?? 3)) + 1);
        $maximumK = min((int) ($knn['k_cap'] ?? 65), (int) ($knn['min_train_size'] ?? 250));
        $candidates = max(0, $maximumK - $minimumK + 1);
        $humanEnabled = (bool) ($humanTraining['enabled'] ?? true);

        return [
            'preparation' => 6,
            // Each pattern type prepares its data and fits two learners.
            'patterns' => ($intelligence['patterns']['enabled'] ?? true)
                ? 1 + count(PatternCatalog::TYPES) * 3 : 0,
            'lead_lag' => ($leadLag['enabled'] ?? true)
                ? max(1, max(0, (int) ($leadLag['max_peers'] ?? 8))
                    * (3 + max(0, (int) ($leadLag['max_lag'] ?? 6)) * 6)) : 0,
            'outcome_candidates' => $candidates,
            'outcome_holdout' => 1,
            'outcome_ablation' => 2,
            'action_candidates' => $candidates,
            'action_holdout' => 1,
            'human_outcome' => $humanEnabled && ($humanTraining['trend_enabled'] ?? false) ? 1 : 0,
            'human_action' => $humanEnabled && ($humanTraining['candle_enabled'] ?? true) ? 5 : 0,
            'coingecko_insight' => ($intelligence['coingecko_insight']['enabled'] ?? false) ? 1 : 0,
        ];
    }

    /**
     * This pure calculation is shared by config loading and running workers.
     * It does not depend on Laravel's configuration-file loading order.
     *
     * @param  array<string, mixed>  $intelligence
     * @param  array<string, mixed>  $leadLag
     * @param  array<string, mixed>  $humanTraining
     * @param  array<string, mixed>  $research
     * @return array{runs: array<string, int>, algorithm_seconds: float, dataset_seconds: float, publication_seconds: float, job_timeout: int, lock_seconds: int, retry_after: int}
     */
    public static function limits(array $intelligence, array $leadLag, array $humanTraining, array $research): array
    {
        $runs = self::runCounts($intelligence, $leadLag, $humanTraining);
        $algorithmSeconds = max(0.001, (float) ($intelligence['max_seconds'] ?? 1800));
        $datasetSeconds = max(0.001, (float) ($research['dataset_max_seconds'] ?? 540));
        $publicationSeconds = max(1, (float) ($humanTraining['publication_reserve_seconds'] ?? 10));
        $lockSeconds = self::leaseSeconds($algorithmSeconds, $datasetSeconds, $publicationSeconds);
        $requiredSeconds = (int) ceil(array_sum($runs) * $algorithmSeconds + $datasetSeconds
            + max(600, $publicationSeconds + 120));
        $jobTimeout = max((int) ($intelligence['job_timeout'] ?? 0), $requiredSeconds, $lockSeconds);

        return [
            'runs' => $runs,
            'algorithm_seconds' => $algorithmSeconds,
            'dataset_seconds' => $datasetSeconds,
            'publication_seconds' => $publicationSeconds,
            'job_timeout' => $jobTimeout,
            'lock_seconds' => $lockSeconds,
            'retry_after' => $jobTimeout + 300,
        ];
    }

    public static function jobTimeout(): int
    {
        return self::limits(
            (array) config('intelligence', []),
            (array) config('lead_lag', []),
            (array) config('human_training', []),
            (array) config('research', []),
        )['job_timeout'];
    }

    public static function lockSeconds(): int
    {
        return self::leaseSeconds(
            self::algorithmSeconds(),
            app()->bound('config') ? (float) config('research.dataset_max_seconds', 540) : 540,
            app()->bound('config') ? (float) config('human_training.publication_reserve_seconds', 10) : 10,
        );
    }

    public static function retryAfter(): int
    {
        return self::jobTimeout() + 300;
    }

    public static function registerLock(Lock $lock): void
    {
        self::leases()->registerLock($lock, self::lockSeconds());
    }

    public static function unregisterLock(Lock $lock): void
    {
        if (app()->bound(TrainingLease::class)) {
            app(TrainingLease::class)->unregisterLock($lock);
        }
    }

    public static function registerLease(string $key, Closure $renew): void
    {
        self::leases()->registerLease($key, $renew);
    }

    public static function unregisterLease(string $key): void
    {
        if (app()->bound(TrainingLease::class)) {
            app(TrainingLease::class)->unregisterLease($key);
        }
    }

    public static function heartbeat(bool $force = false): void
    {
        // Standalone trainers and signal consumers own no build leases.
        if (app()->bound(TrainingLease::class)) {
            app(TrainingLease::class)->heartbeat($force);
        }
    }

    private static function leases(): TrainingLease
    {
        if (! app()->bound(TrainingLease::class)) {
            app()->instance(TrainingLease::class, new TrainingLease);
        }

        return app(TrainingLease::class);
    }

    private static function leaseSeconds(float $algorithmSeconds, float $datasetSeconds, float $publicationSeconds): int
    {
        // A non-cooperative fit or feature replay can occupy one complete run.
        // Renew across runs; crashed workers leave only this bounded lease.
        return (int) ceil(max($algorithmSeconds, $datasetSeconds, $publicationSeconds, 540) + 600);
    }
}
