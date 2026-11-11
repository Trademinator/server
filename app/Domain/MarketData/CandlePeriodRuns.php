<?php

namespace App\Domain\MarketData;

use Trademinator\Indicators\Traits\Technical;

/** Temporary, bounded-memory run statistics; never a model feature or candle cache. */
final class CandlePeriodRuns
{
    use Technical;

    /**
     * One observation per completed run, with boundary/gap-censored runs excluded.
     *
     * @param  iterable<array<string, mixed>>  $candles
     * @return array{count: int, p50: ?float, p95: ?float}
     */
    public function evaluate(iterable $candles, string $period): array
    {
        $timeframe = new CandleTimeframe;
        $histogram = [];
        $count = 0;
        $seed = null;
        $knownStart = false;
        $pending = [];
        $previousTimestamp = null;

        $flush = function () use (&$pending, &$seed, &$knownStart, &$histogram, &$count): void {
            if ($pending === []) {
                return;
            }
            $batch = $seed === null ? $pending : [$seed, ...$pending];
            $direction = $this->compare($batch, 'close', 'open');
            $consecutive = $this->consecutive($batch, $direction);
            $previous = $seed;
            for ($i = $seed === null ? 0 : 1; $i < count($batch); $i++) {
                $row = $batch[$i];
                if ($previous !== null && $row[$direction] !== $previous[$direction]) {
                    if ($previous[$direction] !== 0 && $knownStart) {
                        $length = $previous[$consecutive] + 1;
                        $histogram[$length] = ($histogram[$length] ?? 0) + 1;
                        $count++;
                    }
                    $knownStart = true;
                }
                $previous = $row;
            }
            $seed = [...$previous, '__ticker_cached' => true];
            $pending = [];
        };

        foreach ($candles as $candle) {
            $timestamp = (int) $candle['microtimestamp'];
            if ($previousTimestamp !== null && $timestamp !== $timeframe->next($previousTimestamp, $period)) {
                $flush();
                $seed = null;
                $knownStart = false;
            }
            $pending[] = ['open' => $candle['open'], 'close' => $candle['close']];
            if (count($pending) >= 500) {
                $flush();
            }
            $previousTimestamp = $timestamp;
        }
        $flush();
        ksort($histogram, SORT_NUMERIC);

        return ['count' => $count,
            'p50' => $this->percentile($histogram, $count, 0.50),
            'p95' => $this->percentile($histogram, $count, 0.95)];
    }

    /** Linear interpolation of order statistics (R7), without expanding the histogram. */
    private function percentile(array $histogram, int $count, float $quantile): ?float
    {
        if ($count === 0) {
            return null;
        }
        $position = ($count - 1) * $quantile;
        $lower = (int) floor($position);
        $upper = (int) ceil($position);
        $seen = 0;
        $lowValue = null;
        foreach ($histogram as $length => $frequency) {
            $seen += $frequency;
            if ($lowValue === null && $seen > $lower) {
                $lowValue = $length;
            }
            if ($seen > $upper) {
                return (float) ($lowValue + ($position - $lower) * ($length - $lowValue));
            }
        }

        return null;
    }
}
