<?php

namespace App\Jobs;

use App\Domain\Intelligence\KnowledgeWindow;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Operations\ActionLog;
use App\Domain\Research\DatasetSnapshotBuilder;
use App\Domain\Research\SemanticLabels;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Owner-triggered immutable snapshot; does not train or promote any model. */
final class BuildOwnerDatasetSnapshot implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout;

    public int $uniqueFor = 7200;

    public bool $failOnTimeout = true;

    public array $backoff = [120, 600];

    public function __construct(
        public readonly string $exchange,
        public readonly string $symbol,
        public readonly string $period,
    ) {
        $this->onQueue((string) config('intelligence.queue'));
        $this->timeout = max(600, (int) config('research.dataset_max_seconds', 540) + 60);
    }

    public function uniqueId(): string
    {
        return ModelStore::marketKey($this->exchange, $this->symbol, $this->period);
    }

    public function handle(DatasetSnapshotBuilder $builder): void
    {
        $asOfMs = now()->getTimestampMs();
        $manifest = $builder->build(
            $this->exchange, $this->symbol, $this->period,
            new SemanticLabels((int) config('intelligence.horizon'), (int) config('intelligence.lookback')),
            (string) config('intelligence.schema'),
            fromMs: KnowledgeWindow::fromMs($asOfMs), toMs: $asOfMs, asOfMs: $asOfMs,
        );
        app(ActionLog::class)->write('knowledge.owner_snapshot_built', [
            'dataset_id' => $manifest['dataset_id'],
            'exchange' => $this->exchange, 'symbol' => $this->symbol, 'period' => $this->period,
            'rows' => $manifest['rows'], 'outcome' => 'completed',
        ]);
    }
}
