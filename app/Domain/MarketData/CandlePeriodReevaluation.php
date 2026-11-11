<?php

namespace App\Domain\MarketData;

use App\Models\MarketFeed;
use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Trademinator\Time\periods_to_seconds;

final class CandlePeriodReevaluation
{
    public function __construct(
        private readonly ExchangeRepository $exchanges,
        private readonly TickerRepository $tickers,
        private readonly CandleQuality $quality,
        private readonly CandlePeriodViability $viability,
        private readonly MarketHistoryBackfill $history,
        private readonly HistoryDepth $depth,
        private readonly CandlePeriodRuns $runs,
    ) {}

    /** @return array<string, mixed> */
    public function evaluate(MarketFeed $feed, bool $dryRun = false, bool $force = false): array
    {
        $feed->loadMissing('market.exchange');
        $market = $feed->market;
        $exchange = $market->exchange;
        $version = max(1, (int) config('candle_period.selection_version', 2));

        if (! $market->subscriptions()->where('active', true)->exists()) {
            return $this->result($feed, 'inactive', 'Market has no active subscriptions.');
        }
        if (! $force && ! $dryRun && $feed->selection_next_attempt_at?->isFuture()) {
            return $this->result($feed, 'deferred', 'The next automatic candle-period evaluation is not due yet.');
        }

        $lock = Cache::lock('trademinator:candle-period-evaluation:'.$feed->market_id, 300);
        if (! $lock->get()) {
            return $this->result($feed, 'busy', 'Another candle-period evaluation is already running.');
        }

        try {
            $this->exchanges->setExchange($exchange, symbol: $market->symbol);
            $configured = array_values(array_filter((array) config('candle_period.periods', CandleTimeframe::SUPPORTED), 'is_string'));
            $supported = array_values(array_intersect($configured, array_keys($this->exchanges->periods()), CandleTimeframe::SUPPORTED));
            usort($supported, fn (string $a, string $b): int => periods_to_seconds($a) <=> periods_to_seconds($b));
            if ($supported === []) {
                return $this->defer($feed, $dryRun, 'no_supported_period', 'The exchange has no Trademinator-supported candle periods.');
            }

            $metadata = $this->exchanges->candleMarketMetadata($market->symbol);
            $takerFee = MarketCatalog::takerFee($metadata['taker'] ?? null, $exchange->class);
            if ($takerFee === null) {
                return $this->defer($feed, $dryRun, 'fee_unavailable', 'A published one-side taker fee is required for economic candle-period evaluation.');
            }

            $primaryDays = max(1, (int) config('candle_period.evaluation_days', 60));
            $fallbackDays = max(0, (int) config('candle_period.fallback_days', 30));
            $maximumTrueFlatRatio = max(0.0, min(1.0, (float) config('candle_period.max_true_flat_ratio', 0.10)));
            $shorterFlatRatio = min($maximumTrueFlatRatio,
                max(0.0, (float) config('candle_period.shorter_reentry_flat_ratio', 0.05)));
            $confirmationWindows = max(1, min(4, (int) config('candle_period.shorter_confirmation_windows', 2)));
            $toMs = now()->getTimestampMs();
            $attempts = $flatFailures = $depthResults = [];

            // Fallback is a separate comparison round: never rank 60-day and 90-day scores together.
            foreach (array_unique([$primaryDays, $primaryDays + $fallbackDays]) as $days) {
                $candidates = [];
                $pending = false;
                foreach ($supported as $period) {
                    if (isset($flatFailures[$period])) {
                        continue;
                    }
                    $isShorter = $feed->selected_period !== null
                        && in_array($feed->selected_period, CandleTimeframe::SUPPORTED, true)
                        && periods_to_seconds($period) < periods_to_seconds($feed->selected_period);
                    $flatLimit = $isShorter ? $shorterFlatRatio : $maximumTrueFlatRatio;
                    $candidate = null;
                    $confirmed = true;
                    for ($window = 1; $window <= ($isShorter ? $confirmationWindows : 1); $window++) {
                        $evaluation = $this->evaluateWindow($feed, $period, $days,
                            $toMs - ($window - 1) * $days * 86_400_000, $flatLimit,
                            $takerFee, $dryRun, $isShorter ? $window : null);
                        $attempts[] = $evaluation['attempt'];
                        $status = $evaluation['attempt']['status'];
                        if ($status !== 'passed') {
                            $pending = $pending || $status === 'needs_backfill';
                            if ($status === 'flat_failed') {
                                // A longer aggregate must not conceal a failed recent/confirmation flat cap.
                                $flatFailures[$period] = true;
                            }
                            $confirmed = false;
                            break;
                        }
                        if ($window === 1) {
                            $candidate = $evaluation['candidate'];
                        }
                    }
                    if (! $confirmed) {
                        continue;
                    }
                    if (! array_key_exists($period, $depthResults)) {
                        $probe = $this->depth->depthProbe($period, $toMs);
                        $depthResults[$period] = $this->exchanges->hasHistoricalData($market->symbol, $period,
                            $probe['from'], $probe['until'], $probe['limit']);
                    }
                    if (! $depthResults[$period]) {
                        $attempts[array_key_last($attempts)]['status'] = 'depth_failed';

                        continue;
                    }
                    $candidate['quality'] += ['max_true_flat_ratio' => $flatLimit,
                        'shorter_reentry' => $isShorter,
                        'confirmation_windows' => $isShorter ? $confirmationWindows : 1,
                        'confirmation_window_days' => $days];
                    $candidates[] = $candidate;
                }

                if ($pending) {
                    if (! $dryRun) {
                        $this->scheduleRetry($feed, (int) config('candle_period.backfill_retry_minutes', 15));
                    }

                    return $this->result($feed, $dryRun ? 'needs_backfill' : 'pending',
                        "Candidate comparison needs history for the {$days}-day evaluation or confirmation windows; existing selection retained.",
                        $attempts);
                }
                if ($candidates !== []) {
                    usort($candidates, static fn (array $a, array $b): int => ($b['runs']['p50'] <=> $a['runs']['p50'])
                        ?: ($b['runs']['p95'] <=> $a['runs']['p95'])
                        ?: ($a['economic']['rejected_reversal_ratio'] <=> $b['economic']['rejected_reversal_ratio'])
                        ?: (periods_to_seconds($a['period']) <=> periods_to_seconds($b['period'])));
                    $winner = $candidates[0];

                    return $this->accept($feed, $winner['period'], $version, $days,
                        $winner['sample_from_ms'], $winner['sample_to_ms'],
                        [...$winner['quality'], 'runs' => $winner['runs']], $winner['economic'],
                        (float) config('candle_period.quality_threshold', 0.7),
                        (float) config('candle_period.minimum_coverage', 0.8), $dryRun, $attempts);
                }
            }

            if (! $dryRun) {
                $this->scheduleRetry($feed, (int) config('candle_period.no_candidate_retry_minutes', 360));
            }

            return $this->result($feed, 'no_candidate',
                'No supported period passed quality, flatness, history depth, BUY/SELL density and rejected-reversal checks with available run statistics.',
                $attempts);
        } finally {
            $lock->release();
        }
    }

    /** @return array{attempt: array<string, mixed>, candidate?: array<string, mixed>} */
    private function evaluateWindow(MarketFeed $feed, string $period, int $days, int $toMs,
        float $flatLimit, float $takerFee, bool $dryRun, ?int $confirmationWindow): array
    {
        $fromMs = max(0, $toMs - $days * 86_400_000);
        $attempt = ['period' => $period, 'days' => $days, 'flat_limit' => $flatLimit,
            'confirmation_window' => $confirmationWindow];
        $candles = $this->window($feed->market->exchange->class, $feed->market->symbol, $period, $fromMs, $toMs);
        $historyState = $this->historyState($feed, $period, $fromMs, $candles);
        if ($historyState === 'pending') {
            if (! $dryRun) {
                $this->history->dispatchCandidate($feed, $period, $fromMs);
            }

            return ['attempt' => [...$attempt, 'status' => 'needs_backfill']];
        }
        if ($historyState === 'unavailable') {
            return ['attempt' => [...$attempt, 'status' => 'history_unavailable']];
        }
        if (! $this->coversWindowEnd($candles, $period, $toMs)) {
            return ['attempt' => [...$attempt, 'status' => 'stale_sample']];
        }

        $diagnostics = [];
        $quality = $this->quality->choose([$period => $candles], (float) $feed->market->tick_size,
            (float) config('candle_period.quality_threshold', 0.7), 0,
            (float) config('candle_period.minimum_coverage', 0.8), $flatLimit, $diagnostics);
        $attempt['true_flat_ratio'] = $diagnostics[$period]['true_flat_ratio'] ?? null;
        if ($quality === null) {
            return ['attempt' => [...$attempt, 'status' => $diagnostics[$period]['status'] ?? 'quality_failed']];
        }

        $runs = $this->runs->evaluate($candles, $period);
        $economic = $this->viability->evaluate($candles, $takerFee,
            (float) config('candle_period.minimum_action_ratio', 0.02),
            (float) config('candle_period.max_rejected_reversal_ratio', 0.10));
        $status = match (true) {
            $economic['rejected_reversal_ratio'] === null => 'insufficient_reversal_evidence',
            $economic['rejected_reversal_ratio'] > $economic['maximum_rejected_reversal_ratio'] => 'reversal_failed',
            ! $economic['passes'] => 'economic_failed',
            $runs['count'] === 0 => 'insufficient_run_evidence',
            default => 'passed',
        };
        $attempt += ['status' => $status, 'run_p50' => $runs['p50'], 'run_p95' => $runs['p95'],
            'run_count' => $runs['count'], ...$economic];

        return ['attempt' => $attempt, 'candidate' => [
            'period' => $period, 'runs' => $runs, 'economic' => $economic, 'quality' => $quality['quality'],
            'sample_from_ms' => (int) $candles[0]['microtimestamp'],
            'sample_to_ms' => (int) $candles[array_key_last($candles)]['microtimestamp'],
        ]];
    }

    /** @param list<array<string, mixed>> $candles */
    private function coversWindowEnd(array $candles, string $period, int $untilMs): bool
    {
        if ($candles === []) {
            return false;
        }
        // Allow the last completed candle to be up to one timeframe behind the
        // window cutoff. Do not treat a small burst at the beginning as a full week.
        $timeframe = new CandleTimeframe;
        $lastMs = (int) $candles[array_key_last($candles)]['microtimestamp'];

        return $timeframe->next($timeframe->next($lastMs, $period), $period) >= $untilMs;
    }

    /** @return list<array<string, mixed>> */
    private function window(string $exchange, string $symbol, string $period, int $fromMs, int $toMs): array
    {
        $timeframe = new CandleTimeframe;
        $candles = [];
        foreach ($this->tickers->streamHistory($exchange, $symbol, $period, $fromMs, $toMs) as $candle) {
            if ($timeframe->next((int) $candle['microtimestamp'], $period) <= $toMs) {
                $candles[] = [
                    'microtimestamp' => (int) $candle['microtimestamp'],
                    'open' => $candle['open'] ?? null,
                    'high' => $candle['high'] ?? null,
                    'low' => $candle['low'] ?? null,
                    'close' => $candle['close'] ?? null,
                    'volume' => $candle['volume'] ?? null,
                ];
            }
        }

        return $candles;
    }

    /** @param list<array<string, mixed>> $candles */
    private function historyState(MarketFeed $feed, string $period, int $fromMs, array $candles): string
    {
        $timeframe = new CandleTimeframe;
        if ($candles !== [] && (int) $candles[0]['microtimestamp'] <= $timeframe->next($fromMs, $period)) {
            return 'ready';
        }

        $state = DB::table('market_history_backfills')->where('market_id', $feed->market_id)
            ->where('period', $period)->first();
        if ($state === null) {
            return 'pending';
        }
        if ($state->before_ms !== null && (int) $state->before_ms <= $fromMs) {
            return 'ready';
        }
        if ($state->status === 'paused') {
            return 'unavailable';
        }
        if ($state->status === 'complete' && $state->reason !== 'target_reached') {
            return 'unavailable';
        }

        return 'pending';
    }

    private function accept(MarketFeed $feed, string $period, int $version, int $days,
        int $sampleFromMs, int $sampleToMs,
        array $quality, array $economic, float $threshold, float $coverage, bool $dryRun, array $attempts): array
    {
        $current = $feed->selected_period;
        if ($dryRun) {
            return $this->result($feed, $current === $period ? 'unchanged' : 'would_switch',
                $current === $period ? "{$period} remains the selected period." : "Would switch {$current} to {$period}.",
                $attempts, $period, $days, $economic);
        }

        DB::transaction(function () use ($feed, $period, $version, $sampleFromMs, $sampleToMs, $quality, $economic, $threshold, $coverage): void {
            $locked = MarketFeed::query()->whereKey($feed->market_id)->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'selected_period' => $period,
                'selection_version' => $version,
                'selection_checked_at' => now(),
                'selection_next_attempt_at' => null,
                'next_pull_at' => now(),
            ])->save();

            DB::table('candle_period_selections')->insert([
                'selection_id' => (string) Str::uuid7(),
                'exchange' => $feed->market->exchange->class,
                'symbol' => $feed->market->symbol,
                'period' => $period,
                'sample_from_ms' => $sampleFromMs,
                'sample_to_ms' => $sampleToMs,
                'threshold' => $threshold,
                'minimum_coverage' => $coverage,
                'quality' => json_encode([...$quality, 'economic' => $economic, 'selection_version' => $version], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            DB::table('market_history_backfills')->where('market_id', $feed->market_id)->where('period', $period)
                ->whereNotNull('target_start_ms')->update([
                    'target_start_ms' => null,
                    'status' => 'active',
                    'reason' => null,
                    'failures' => 0,
                    'last_error' => null,
                    'next_attempt_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        $feed->refresh();

        $result = $this->result($feed, $current === $period ? 'unchanged' : 'switched',
            $current === $period ? "{$period} remains the selected period." : "Selected period changed from {$current} to {$period}.",
            $attempts, $period, $days, $economic);
        $result['current_period'] = $current;

        return $result;
    }

    private function defer(MarketFeed $feed, bool $dryRun, string $status, string $reason): array
    {
        if (! $dryRun) {
            $this->scheduleRetry($feed, (int) config('candle_period.no_candidate_retry_minutes', 360));
        }

        return $this->result($feed, $status, $reason);
    }

    private function scheduleRetry(MarketFeed $feed, int $minutes): void
    {
        MarketFeed::query()->whereKey($feed->market_id)->update([
            'selection_checked_at' => now(),
            'selection_next_attempt_at' => now()->addMinutes(max(1, $minutes)),
        ]);
        $feed->refresh();
    }

    /** @param list<array<string, mixed>> $attempts
     * @return array<string, mixed>|null
     */
    private function latestEconomicAttempt(array $attempts): ?array
    {
        for ($index = count($attempts) - 1; $index >= 0; $index--) {
            $attempt = $attempts[$index];
            if (! array_key_exists('buy_ratio', $attempt) || ! array_key_exists('sell_ratio', $attempt)) {
                continue;
            }

            return $attempt;
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function result(MarketFeed $feed, string $status, string $reason, array $attempts = [],
        ?string $period = null, ?int $days = null, ?array $economic = null): array
    {
        $lastEconomic = $economic ?? $this->latestEconomicAttempt($attempts);
        $selectedAttempt = null;
        foreach ($attempts as $attempt) {
            if ($period !== null && $attempt['period'] === $period
                && ($days === null || $attempt['days'] === $days)
                && ($attempt['confirmation_window'] ?? 1) === 1) {
                $selectedAttempt = $attempt;
            }
        }

        return [
            'market_id' => $feed->market_id,
            'exchange' => $feed->market->exchange->class,
            'symbol' => $feed->market->symbol,
            'current_period' => $feed->selected_period,
            // Result means a candidate that actually passed. Keep it null while
            // evaluation is pending, blocked or waiting for candidate history.
            'selected_period' => $period,
            'status' => $status,
            'reason' => $reason,
            'window_days' => $days ?? ($lastEconomic['days'] ?? null),
            'buy_ratio' => $lastEconomic['buy_ratio'] ?? null,
            'sell_ratio' => $lastEconomic['sell_ratio'] ?? null,
            'true_flat_ratio' => $selectedAttempt['true_flat_ratio'] ?? null,
            'run_p50' => $selectedAttempt['run_p50'] ?? null,
            'run_p95' => $selectedAttempt['run_p95'] ?? null,
            'run_count' => $selectedAttempt['run_count'] ?? null,
            'rejected_reversal_ratio' => $selectedAttempt['rejected_reversal_ratio'] ?? null,
            'reversals_tested' => $selectedAttempt['reversals_tested'] ?? null,
            'attempts' => $attempts,
        ];
    }
}
