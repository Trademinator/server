<?php

namespace App\Domain\Intelligence;

use App\Domain\Operations\ActionLog;
use App\Domain\Operations\WorkerRegistry;
use Closure;

/** Operational counters for one KNN tuning or evaluation pass, not a saved checkpoint. */
final class KnnTrainingProgress
{
    private float $started;

    private ?float $lastLogged = null;

    private array $metrics = [];

    public function __construct(private string $stage, private ?Closure $clock = null)
    {
        $this->started = $this->now();
    }

    public function fold(int $fold, int $trainingRows, int $testRows): void
    {
        $this->metrics = ['fold' => $fold, 'eligible_rows' => $trainingRows,
            'processed' => 0, 'total' => $testRows, 'batch_rows' => 0, 'scanned' => 0];
        $this->write();
    }

    public function search(int $processed, int $batchRows, int $scanned): void
    {
        $this->metrics = [...$this->metrics, 'processed' => $processed,
            'batch_rows' => $batchRows, 'scanned' => $scanned];
        $this->write();
    }

    public function finish(): void
    {
        $this->write(true);
    }

    private function write(bool $completed = false): void
    {
        $now = $this->now();
        if (! $completed && $this->lastLogged !== null && $now - $this->lastLogged < 10) {
            return;
        }
        $this->lastLogged = $now;
        if (app()->bound(WorkerRegistry::class) && $this->metrics !== []) {
            app(WorkerRegistry::class)->progress(sprintf('%s:fold_%d:tests_%d/%d:scan_%d/%d',
                $this->stage, $this->metrics['fold'], $this->metrics['processed'], $this->metrics['total'],
                $this->metrics['scanned'], $this->metrics['eligible_rows']));
        }
        if (app()->bound(ActionLog::class)) {
            app(ActionLog::class)->write('intelligence.knn.'.($completed ? 'completed' : 'progress'), [
                ...$this->metrics, 'stage' => $this->stage, 'outcome' => $completed ? 'completed' : 'running',
                'duration_ms' => (int) round(max(0, $now - $this->started) * 1000),
                'memory_bytes' => memory_get_usage(true),
            ]);
        }
    }

    private function now(): float
    {
        return $this->clock === null ? hrtime(true) / 1_000_000_000 : ($this->clock)();
    }
}
