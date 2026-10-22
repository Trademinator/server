<?php

namespace App\Jobs;

use App\Domain\Features\FeatureEngine;
use App\Domain\Intelligence\KnowledgeRequestJournal;
use App\Domain\Intelligence\KnowledgeWindow;
use App\Domain\Intelligence\ModelStore;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\Operations\ActionLog;
use App\Domain\Research\DatasetSnapshotBuilder;
use App\Domain\Research\SemanticLabels;
use App\Repositories\TickerRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Owner-triggered immutable snapshot; does not train or promote any model. */
final class BuildOwnerDatasetSnapshot implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public ?int $requestedAsOfMs = null;

    public int $timeout;

    public int $uniqueFor = 86400;

    public bool $failOnTimeout = true;

    public array $backoff = [120, 600];

    public function __construct(
        public readonly string $exchange,
        public readonly string $symbol,
        public readonly string $period,
        public readonly bool $trainAfter = false,
    ) {
        // Freeze the request cutoff in the serialized queue payload so releases
        // cannot chase newly collected candles indefinitely.
        $this->requestedAsOfMs = now()->getTimestampMs();
        $this->onQueue((string) config('intelligence.queue'));
        $this->timeout = max(600, (int) config('research.dataset_max_seconds', 540) + 60);
    }

    public function uniqueId(): string
    {
        return ModelStore::marketKey($this->exchange, $this->symbol, $this->period);
    }

    public function handle(DatasetSnapshotBuilder $builder, TickerRepository $tickers, KnowledgeRequestJournal $journal): void
    {
        $asOfMs = $this->requestedAsOfMs ?? now()->getTimestampMs();
        $latest = $tickers->latestTimestamp($this->exchange, $this->symbol, $this->period);
        $timeframe = new CandleTimeframe;
        if ($latest !== null && $timeframe->next($latest, $this->period) > $asOfMs) {
            $latest = $timeframe->previous($latest, $this->period);
        }
        if ($latest === null || $latest < 0) {
            $journal->markBlocked($this->exchange, $this->symbol, $this->period, 'snapshot');
            $this->release(120);

            return;
        }
        $targetMs = min($asOfMs, $timeframe->next($latest, $this->period));
        $scope = ['exchange' => $this->exchange, 'symbol' => $this->symbol, 'period' => $this->period];
        $label = app(\App\Domain\Intelligence\AlgorithmicLabelSets::class)
            ->find($this->exchange, $this->symbol, $this->period, $asOfMs);
        if ($label === null) {
            $journal->request($this->exchange, $this->symbol, $this->period, 'labels', $targetMs);
            $cacheKey = 'trademinator:snapshot-labels:'.ModelStore::marketKey($this->exchange, $this->symbol, $this->period);
            if (Cache::add($cacheKey.':lock', true, now()->addMinutes(20))) {
                AnalyzeMarketAutoLabels::dispatch($this->exchange, $this->symbol, $this->period, $cacheKey, $asOfMs)
                    ->onQueue((string) config('intelligence.queue'));
            }
            $this->release(120);

            return;
        }
        $featureMs = DB::table('market_features')->where($scope)->where('version', FeatureEngine::VERSION)
            ->max('microtimestamp');
        if ($featureMs === null || (int) $featureMs < $latest) {
            $journal->request($this->exchange, $this->symbol, $this->period, 'features', $latest);
            BuildMarketFeatures::dispatch($this->exchange, $this->symbol, $this->period)
                ->onQueue((string) config('features.queue', 'features'));
            $this->release(120);

            return;
        }
        $manifest = $builder->build(
            $this->exchange, $this->symbol, $this->period,
            new SemanticLabels((int) config('intelligence.horizon'), (int) config('intelligence.lookback')),
            (string) config('intelligence.schema'),
            fromMs: KnowledgeWindow::fromMs($asOfMs), toMs: $asOfMs, asOfMs: $asOfMs,
        );
        $journal->markSatisfied($this->exchange, $this->symbol, $this->period, 'snapshot', (int) $manifest['as_of_ms']);
        if ($this->trainAfter || $journal->outstanding(
            $this->exchange, $this->symbol, $this->period, 'ai') > 0) {
            $job = new TrainMarketIntelligence($this->exchange, $this->symbol, $this->period,
                'owner:'.Str::uuid7());
            $job->snapshotDataset = (string) $manifest['dataset_id'];
            dispatch($job->onQueue((string) config('intelligence.queue')));
        }
        app(ActionLog::class)->write('knowledge.owner_snapshot_built', [
            'dataset_id' => $manifest['dataset_id'],
            'exchange' => $this->exchange, 'symbol' => $this->symbol, 'period' => $this->period,
            'rows' => $manifest['rows'], 'outcome' => 'completed',
        ]);
    }

    public function failed(?\Throwable $error): void
    {
        app(KnowledgeRequestJournal::class)->fail($this->exchange, $this->symbol,
            $this->period, 'snapshot', $error?->getMessage() ?? 'Snapshot failed.');
    }
}
