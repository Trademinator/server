<?php

namespace App\Jobs;

use App\Domain\Intelligence\IntelligenceNotReady;
use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\KnnSchemaFallback;
use App\Domain\Intelligence\MarketIntelligence;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Intelligence\TrainingRuntime;
use App\Domain\Operations\ActionLog;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class TrainMarketIntelligence implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const CRON = '0 4 * * 1';

    public int $tries = 3;

    public int $timeout;

    public int $uniqueFor = 0;

    public array $backoff = [300, 900];

    public ?string $schema = null;

    public function __construct(public string $exchange, public string $symbol, public string $period, public string $week, ?string $schema = null)
    {
        $this->schema = $schema ?? (string) config('intelligence.schema');
        $this->timeout = TrainingRuntime::jobTimeout();
    }

    public function uniqueId(): string
    {
        $schema = $this->schema ?? (string) config('intelligence.schema');

        return ModelStore::marketKey($this->exchange, $this->symbol, $this->period).':'.$this->week.':'.$schema.':'.IntelligenceTrainer::VERSION.':'.KnnSchemaFallback::VERSION;
    }

    public function displayName(): string
    {
        return self::class.':'.$this->exchange.':'.$this->symbol.':'.$this->period;
    }

    public function handle(MarketIntelligence $intelligence): void
    {
        $key = 'trademinator:intelligence-week:'.$this->uniqueId();
        $lock = Cache::lock($key.':lock', TrainingRuntime::lockSeconds());
        if (! $lock->get()) {
            throw new RuntimeException('Weekly intelligence work is already running.');
        }
        try {
            if (Cache::has($key)) {
                return;
            }
            app(ActionLog::class)->write('intelligence.training.started', [
                'exchange' => $this->exchange, 'symbol' => $this->symbol, 'period' => $this->period,
                'job_id' => $this->job?->uuid(), 'queue' => $this->job?->getQueue() ?? config('intelligence.queue'),
                'memory_bytes' => memory_get_usage(true),
            ]);
            try {
                $intelligence->build($this->exchange, $this->symbol, $this->period,
                    schema: $this->schema ?? (string) config('intelligence.schema'),
                    generation: hash('sha256', $this->uniqueId()),
                    beforePublish: function () use ($lock): void {
                        if (! $lock->isOwnedByCurrentProcess()) {
                            throw new RuntimeException('Weekly intelligence lock ownership was lost before publication.');
                        }
                    });
            } catch (IntelligenceNotReady $notReady) {
                $selection = $notReady->diagnostics;
                app(ActionLog::class)->write('intelligence.training.skipped', [
                    'exchange' => $this->exchange, 'symbol' => $this->symbol, 'period' => $this->period,
                    'reason' => $selection['reason'] ?? 'insufficient_history', 'outcome' => 'skipped',
                    'feature_rows' => $selection['feature_rows'] ?? 0,
                    'eligible_rows' => max(
                        $selection['potential_history']['core']['potential_mature_rows'] ?? 0,
                        $selection['potential_history']['technical']['potential_mature_rows'] ?? 0,
                    ),
                ]);

                return;
            }
            Cache::put($key, true, now()->addDays(14));
        } finally {
            $lock->release();
        }
    }
}
