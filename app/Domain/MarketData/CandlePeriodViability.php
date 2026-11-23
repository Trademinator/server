<?php

namespace App\Domain\MarketData;

use App\Domain\Intelligence\ActionAutoLabeler;
use InvalidArgumentException;

/** Run the shared retrospective Action pipeline without persisting candle data. */
final class CandlePeriodViability
{
    public function __construct(private readonly ActionAutoLabeler $labeler) {}

    /**
     * Replace the owned sample with compact, finalized labels. Each gap starts a new context.
     *
     * @param  list<array<string, mixed>>  $candles
     * @return array<string, mixed>
     */
    public function evaluate(array &$candles, float $takerFee, float $minimumRatio = 0.01, ?string $period = null): array
    {
        if (! is_finite($takerFee) || $takerFee < 0 || $takerFee >= 1
            || ! is_finite($minimumRatio) || $minimumRatio < 0 || $minimumRatio > 1) {
            throw new InvalidArgumentException('Invalid candle-period economic viability settings.');
        }

        $reversals = ['tested' => 0, 'rejected' => 0];
        $counts = ['buy' => 0, 'sell' => 0, 'hold' => 0];
        $movements = $run = [];
        $start = 0;
        $previousMs = null;
        $timeframe = new CandleTimeframe;
        foreach ($candles as $index => $candle) {
            $timestamp = isset($candle['microtimestamp']) ? (int) $candle['microtimestamp'] : null;
            if ($period !== null && $previousMs !== null && $timestamp !== $timeframe->next($previousMs, $period)) {
                $this->finishRun($run, $candles, $start, $takerFee, $reversals, $counts, $movements);
                $run = [];
                $start = $index;
            }
            $run[] = $this->labeler->compact($candle, $timestamp);
            $previousMs = $timestamp;
        }
        $this->finishRun($run, $candles, $start, $takerFee, $reversals, $counts, $movements);

        $total = array_sum($counts);
        $buyRatio = $total === 0 ? 0.0 : $counts['buy'] / $total;
        $sellRatio = $total === 0 ? 0.0 : $counts['sell'] / $total;
        $rejectedRatio = $reversals['tested'] === 0 ? null : $reversals['rejected'] / $reversals['tested'];
        sort($movements, SORT_NUMERIC);
        $p25 = $this->percentile($movements, 0.25);
        $p50 = $this->percentile($movements, 0.50);
        $roundTripFee = 2 * $takerFee;

        return [
            'passes' => $total > 0 && $buyRatio >= $minimumRatio && $sellRatio >= $minimumRatio
                && $rejectedRatio !== null && $movements !== [],
            'reversals_tested' => $reversals['tested'],
            'reversals_rejected' => $reversals['rejected'],
            'rejected_reversal_ratio' => $rejectedRatio,
            ...$counts,
            'total' => $total,
            'buy_ratio' => (float) $buyRatio,
            'sell_ratio' => (float) $sellRatio,
            'minimum_ratio' => $minimumRatio,
            'swings' => [
                'count' => count($movements),
                'mean' => $movements === [] ? null : array_sum($movements) / count($movements),
                'p25' => $p25,
                'p50' => $p50,
                'round_trip_fee' => $roundTripFee,
                'fee_margin_p25' => $p25 === null || $roundTripFee === 0.0 ? null : ($p25 - $roundTripFee) / $roundTripFee,
                'fee_margin_p50' => $p50 === null || $roundTripFee === 0.0 ? null : ($p50 - $roundTripFee) / $roundTripFee,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $run
     * @param  list<array<string, mixed>>  $candles
     * @param  array{tested: int, rejected: int}  $reversals
     * @param  array{buy: int, sell: int, hold: int}  $counts
     * @param  list<float>  $movements
     */
    private function finishRun(array &$run, array &$candles, int $start, float $takerFee,
        array &$reversals, array &$counts, array &$movements): void
    {
        $this->labeler->labelInPlace($run, $takerFee, reversals: $reversals);
        $previous = null;
        foreach ($run as $index => $candle) {
            $candles[$start + $index] = $candle;
            $action = $candle['action'] ?? null;
            if (isset($counts[$action ?? ''])) {
                $counts[$action]++;
            }
            if (! in_array($action, ['buy', 'sell'], true)) {
                continue;
            }
            $close = (float) $candle['close'];
            if ($previous !== null && $previous['action'] !== $action && $previous['close'] > 0 && $close > 0) {
                $ratio = $close / $previous['close'];
                $movements[] = $previous['action'] === 'buy' ? $ratio - 1 : 1 - $ratio;
            }
            $previous = ['action' => $action, 'close' => $close];
        }
    }

    /** @param list<float> $sorted */
    private function percentile(array $sorted, float $quantile): ?float
    {
        if ($sorted === []) {
            return null;
        }
        $position = (count($sorted) - 1) * $quantile;
        $lower = (int) floor($position);
        $upper = (int) ceil($position);

        return $sorted[$lower] + ($sorted[$upper] - $sorted[$lower]) * ($position - $lower);
    }
}
