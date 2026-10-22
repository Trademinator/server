<?php

namespace App\Domain\Intelligence;

use App\Domain\Features\FeatureEngine;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\SemanticLabels;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lightweight, read-only Owner status for the existing shared-market pipeline.
 * Colors reflect readiness, NOT a fabricated percentage of historical coverage.
 * None of these reads recalculates history or opens a serialized model artifact.
 */
final class KnowledgeWaterfall
{
    private const WARNING_AFTER_DAYS = 7;

    public function __construct(
        private TickerRepository $tickers,
        private CandleTimeframe $timeframe,
    ) {}

    /**
     * @return array<string, array{state: string, detail: string, through_ms: ?int}>
     */
    public function describe(
        string $exchange,
        string $symbol,
        string $period,
        ?array $report = null,
        ?string $datasetId = null,
        ?string $marketId = null,
    ): array {
        $nowMs = now()->getTimestampMs();
        $scope = ['exchange' => $exchange, 'symbol' => $symbol, 'period' => $period];
        $history = $marketId === null ? null : DB::table('market_history_backfills')
            ->where('market_id', $marketId)->where('period', $period)
            ->first(['history_revision', 'trained_revision']);
        $historyDirty = $history !== null && (int) $history->history_revision > (int) $history->trained_revision;
        $unresolvedGaps = $marketId === null ? 0 : DB::table('candle_gap_repairs')
            ->where('market_id', $marketId)->where('period', $period)->where('status', '!=', 'resolved')->count();
        $feed = $marketId === null ? null : DB::table('market_feeds')
            ->where('market_id', $marketId)->first(['status', 'last_error']);

        $sourceMs = $this->tickers->latestTimestamp($exchange, $symbol, $period);
        if ($sourceMs === null) {
            $ohlcv = $this->stage('red', 'Never collected: no OHLCV candles are available.');
        } elseif ($unresolvedGaps > 0) {
            $ohlcv = $this->stage('red', "{$unresolvedGaps} unresolved historical gap range(s).", $sourceMs);
        } elseif (in_array($feed?->status, ['error', 'blocked'], true)) {
            $ohlcv = $this->stage('red', 'The market collector is blocked or reporting an error.', $sourceMs);
        } else {
            $behind = $this->periodsBehind($sourceMs, $period, $nowMs);
            $ohlcv = match (true) {
                $behind === -1 => $this->stage('yellow', 'The newest OHLCV candle has not closed yet.', $sourceMs),
                $behind === 0 && $feed?->last_error === null => $this->stage('green', 'Latest completed OHLCV is current; no unresolved tracked gaps.', $sourceMs),
                $behind <= 2 => $this->stage('yellow', $feed?->last_error !== null
                    ? 'Collection has a recorded warning; inspect the market feed.' : 'OHLCV is slightly behind the latest closed candle.', $sourceMs),
                default => $this->stage('red', 'OHLCV is more than two candle periods behind.', $sourceMs),
            };
        }

        $featuresMs = DB::table('market_features')->where($scope)
            ->where('version', FeatureEngine::VERSION)->where('available_at_ms', '<=', $nowMs)
            ->max('microtimestamp');
        if ($featuresMs === null) {
            $anyFeatures = DB::table('market_features')->where($scope)->exists();
            $features = $this->stage('red', $anyFeatures
                ? 'Only obsolete feature versions exist: rebuild with the current schema.'
                : 'Never built: current-version features are missing.');
        } elseif ($sourceMs === null) {
            $features = $this->stage('red', 'Feature rows exist but their source OHLCV is unavailable.', (int) $featuresMs);
        } else {
            $behind = $this->stepsBetween((int) $featuresMs, $sourceMs, $period);
            $features = match (true) {
                $behind === 0 && ! $historyDirty => $this->stage('green', 'Features are current with the stored OHLCV.', (int) $featuresMs),
                $behind <= 2 => $this->stage('yellow', $historyDirty
                    ? 'History was revised; affected features may need replay.'
                    : 'Features are catching up with OHLCV.', (int) $featuresMs),
                default => $this->stage('red', 'Features are more than two candles behind OHLCV.', (int) $featuresMs),
            };
        }

        $labelReport = DB::table('market_action_label_analyses')->where($scope)->first(['as_of_ms', 'analysis']);
        if ($labelReport === null) {
            $labels = $this->stage('red', 'Never analyzed: no Action-label diagnostics are published.');
        } else {
            $analysis = json_decode($labelReport->analysis, true);
            $asOf = (int) $labelReport->as_of_ms;
            if (! is_array($analysis) || ($analysis['candles_examined'] ?? 0) === 0) {
                $labels = $this->stage('red', 'Label analysis has no usable observations.', $asOf);
            } elseif ($asOf < $nowMs - KnowledgeWindow::days() * 86_400_000) {
                $labels = $this->stage('red', 'Label analysis is beyond the configured knowledge age.', $asOf);
            } elseif (($analysis['status'] ?? null) !== 'validated') {
                $labels = $this->stage('yellow', 'Action analysis exists, but the Outcome horizon is not validated.', $asOf);
            } elseif ($featuresMs !== null
                && $this->stepsBetween($asOf, (int) $featuresMs, $period) > 2) {
                $labels = $this->stage('red', 'Label analysis is more than two candles behind current features.', $asOf);
            } elseif ($featuresMs === null || $asOf < (int) $featuresMs) {
                $labels = $this->stage('yellow', 'Label analysis predates current features; pending labels still require future confirmation.', $asOf);
            } else {
                $labels = $this->stage('green', 'Latest Action analysis is validated; immature labels remain excluded.', $asOf);
            }
        }

        // Prefer the newest published snapshot for this market, even if it has
        // not yet been consumed by a model. Fall back to a legacy model reference.
        $dataset = DB::table('research_datasets')
            ->where('manifest->exchange', $exchange)
            ->where('manifest->symbol', $symbol)
            ->where('manifest->period', $period)
            ->orderByDesc('created_at')->first(['dataset_id', 'manifest']);
        if ($dataset === null && $datasetId !== null) {
            $dataset = DB::table('research_datasets')->where('dataset_id', $datasetId)
                ->first(['dataset_id', 'manifest']);
        }
        if ($dataset === null) {
            $snapshot = $this->stage('red', 'Never built: there is no immutable research dataset snapshot.');
        } else {
            try {
                // Verify the on-disk manifest against the DB record without loading rows.
                $manifest = app(DatasetStore::class)->manifest((string) $dataset->dataset_id);
            } catch (Throwable) {
                $manifest = null;
            }
            if ($manifest === null) {
                $snapshot = $this->stage('red', 'Snapshot manifest is missing or no longer matches the stored record.');
            } elseif (($manifest['feature_version'] ?? null) !== FeatureEngine::VERSION
                || ($manifest['label_definition']['version'] ?? null) !== SemanticLabels::VERSION) {
                $snapshot = $this->stage('red', 'Snapshot is incomplete or uses an incompatible feature/label version.');
            } elseif (! is_int($manifest['as_of_ms'] ?? null) || ($manifest['rows'] ?? 0) < 1) {
                $snapshot = $this->stage('red', 'Snapshot has no verified knowledge cutoff or eligible rows.');
            } else {
                $snapshot = $this->byAge((int) $manifest['as_of_ms'], $nowMs,
                    'Compatible frozen dataset exists.', 'Snapshot is old; a newer one can be built.',
                    'Snapshot exceeds the retained knowledge age.');
                if ($historyDirty && $snapshot['state'] === 'green') {
                    $snapshot = $this->stage('yellow', 'History was revised after training; a new snapshot is recommended.',
                        (int) $manifest['as_of_ms']);
                }
            }
        }

        if ($report === null) {
            $ai = $this->stage('red', 'Never trained: no published intelligence model exists.');
        } else {
            $readiness = ModelStore::knnReadiness($report);
            $actionReady = $readiness['action']['ready'] ?? false;
            $outcomeReady = $readiness['outcome']['ready'] ?? false;
            $trainedAt = is_int($report['trained_as_of_ms'] ?? null) ? $report['trained_as_of_ms'] : null;
            if (($report['status'] ?? null) !== 'ready') {
                $ai = $this->stage('red', 'Published model is not marked ready: '.($report['reason'] ?? 'not ready').'.', $trainedAt);
            } elseif (! $actionReady) {
                $ai = $this->stage('red', 'Action model is unavailable: '
                    .($readiness['action']['reason'] ?? $report['reason'] ?? 'not ready').'.', $trainedAt);
            } elseif (! $outcomeReady) {
                $ai = $this->stage('yellow', 'Action-only degraded mode: Outcome model is not ready.', $trainedAt);
            } elseif ($trainedAt === null) {
                $ai = $this->stage('red', 'Model has no trustworthy training cutoff.');
            } else {
                $ai = $this->byAge($trainedAt, $nowMs, 'Both KNN components are ready.',
                    'Published model is becoming old but remains usable.', 'Published model is beyond the knowledge age limit.');
            }
            if ($historyDirty && $ai['state'] === 'green') {
                $ai = $this->stage('yellow', 'Live model remains published, but historical changes require retraining.', $trainedAt);
            }
        }

        return ['ohlcv' => $ohlcv, 'features' => $features, 'labels' => $labels,
            'dataset' => $snapshot, 'ai' => $ai];
    }

    private function stage(string $state, string $detail, ?int $throughMs = null): array
    {
        return compact('state', 'detail') + ['through_ms' => $throughMs];
    }

    /** -1 means the latest stored candle is not closed; 4 means very stale. */
    private function periodsBehind(int $openMs, string $period, int $nowMs): int
    {
        $closedAt = $this->timeframe->next($openMs, $period);
        if ($closedAt > $nowMs) {
            return -1;
        }
        $behind = 0;
        while ($behind < 4 && $this->timeframe->next($closedAt, $period) <= $nowMs) {
            $behind++;
            $closedAt = $this->timeframe->next($closedAt, $period);
        }

        return $behind;
    }

    private function stepsBetween(int $fromMs, int $toMs, string $period): int
    {
        $steps = 0;
        while ($fromMs < $toMs && $steps < 4) {
            $next = $this->timeframe->next($fromMs, $period);
            if ($next <= $fromMs) {
                return 4;
            }
            $fromMs = $next;
            $steps++;
        }

        return $steps;
    }

    private function byAge(int $asOfMs, int $nowMs, string $healthy, string $warning, string $expired): array
    {
        $days = ($nowMs - $asOfMs) / 86_400_000;
        return match (true) {
            $days < 0 => $this->stage('red', 'Knowledge cutoff lies in the future.', $asOfMs),
            $days > KnowledgeWindow::days() => $this->stage('red', $expired, $asOfMs),
            $days > self::WARNING_AFTER_DAYS => $this->stage('yellow', $warning, $asOfMs),
            default => $this->stage('green', $healthy, $asOfMs),
        };
    }
}
