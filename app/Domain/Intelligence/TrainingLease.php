<?php

namespace App\Domain\Intelligence;

use App\Domain\Operations\WorkerRegistry;
use Closure;
use Illuminate\Cache\Lock;
use RuntimeException;

/**
 * Renew only leases acquired by this process. A missed renewal must abort the
 * build instead of reclaiming a lock that may now protect another worker.
 */
final class TrainingLease
{
    private const HEARTBEAT_SECONDS = 30;

    /** @var array<int, array{lock: Lock, seconds: int}> */
    private array $locks = [];

    /** @var array<string, Closure(): bool> */
    private array $callbacks = [];

    private ?float $lastHeartbeat = null;

    public function __construct(private readonly ?Closure $clock = null) {}

    public function registerLock(Lock $lock, int $seconds): void
    {
        if (! $this->refresh($lock, $seconds)) {
            throw new RuntimeException('Intelligence training lock ownership was lost.');
        }

        $this->locks[spl_object_id($lock)] = ['lock' => $lock, 'seconds' => $seconds];
        $this->lastHeartbeat ??= $this->now();
    }

    public function unregisterLock(Lock $lock): void
    {
        unset($this->locks[spl_object_id($lock)]);
        $this->resetWhenEmpty();
    }

    /** Register a renewal callback after the caller has acquired its lease. */
    public function registerLease(string $key, Closure $renew): void
    {
        $this->callbacks[$key] = $renew;
        $this->lastHeartbeat ??= $this->now();
    }

    public function unregisterLease(string $key): void
    {
        unset($this->callbacks[$key]);
        $this->resetWhenEmpty();
    }

    public function heartbeat(bool $force = false): void
    {
        if (app()->bound(WorkerRegistry::class)) {
            app(WorkerRegistry::class)->guard(force: $force);
        }
        if ($this->locks === [] && $this->callbacks === []) {
            return;
        }

        $now = $this->now();
        if (! $force && $this->lastHeartbeat !== null
            && $now - $this->lastHeartbeat < self::HEARTBEAT_SECONDS) {
            return;
        }

        foreach ($this->locks as ['lock' => $lock, 'seconds' => $seconds]) {
            if (! $this->refresh($lock, $seconds)) {
                throw new RuntimeException('Intelligence training lock ownership was lost.');
            }
        }
        foreach ($this->callbacks as $renew) {
            if (! $renew()) {
                throw new RuntimeException('Intelligence training lease ownership was lost.');
            }
        }

        $this->lastHeartbeat = $now;
        if (app()->bound(WorkerRegistry::class)) {
            app(WorkerRegistry::class)->progress('training_checkpoint');
        }
    }

    private function refresh(Lock $lock, int $seconds): bool
    {
        // MySQL reports zero changed rows when a same-second refresh keeps the
        // existing expiry. Retry with a different expiry; both operations still
        // require the original owner and a live lease, never reacquiring it.
        return $lock->refresh($seconds) || $lock->refresh($seconds + 1);
    }

    private function resetWhenEmpty(): void
    {
        if ($this->locks === [] && $this->callbacks === []) {
            $this->lastHeartbeat = null;
        }
    }

    private function now(): float
    {
        return $this->clock === null ? hrtime(true) / 1_000_000_000 : ($this->clock)();
    }
}
