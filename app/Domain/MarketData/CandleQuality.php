<?php

namespace App\Domain\MarketData;

use InvalidArgumentException;

final class CandleQuality
{
    /** @return array{score: float, true_flat_ratio: float, longest_flat_run_ratio: float, zero_volume_ratio: float, unique_close_ratio: float, median_range_ticks: float, median_range_relative: float, sample_size: int} */
    public function evaluate(array $candles, float $tickSize): array
    {
        if ($tickSize <= 0 || ! is_finite($tickSize)) {
            throw new InvalidArgumentException('Tick size must be positive and finite.');
        }

        $count = count($candles);
        if ($count === 0) {
            throw new InvalidArgumentException('At least one candle is required.');
        }

        $flats = $zeros = $run = $longestRun = 0;
        $closes = $ranges = $relativeRanges = [];

        foreach ($candles as $candle) {
            foreach (['open', 'high', 'low', 'close', 'volume'] as $field) {
                if (! isset($candle[$field]) || ! is_numeric($candle[$field])) {
                    throw new InvalidArgumentException("Invalid candle field: {$field}");
                }
            }

            $open = (float) $candle['open'];
            $high = (float) $candle['high'];
            $low = (float) $candle['low'];
            $close = (float) $candle['close'];
            $volume = (float) $candle['volume'];

            if (! is_finite($open) || ! is_finite($high) || ! is_finite($low) || ! is_finite($close) || ! is_finite($volume)
                || ! is_finite($high - $low)
                || $high < max($open, $close) || $low > min($open, $close) || $low > $high || $volume < 0) {
                throw new InvalidArgumentException('Invalid OHLCV values.');
            }

            $flat = $open === $high && $high === $low && $low === $close;
            $flats += (int) $flat;
            $run = $flat ? $run + 1 : 0;
            $longestRun = max($longestRun, $run);
            $zeros += (int) ($volume === 0.0);
            // Decimal formatting (1, 1.0, 1.000) does not change uniqueness.
            $closes[(string) (float) $candle['close']] = true;
            $ranges[] = ($high - $low) / $tickSize;
            $relativeRanges[] = ($high - $low) / max(abs($close), $tickSize);
        }

        sort($ranges, SORT_NUMERIC);
        sort($relativeRanges, SORT_NUMERIC);
        $middle = intdiv($count, 2);

        $median = $count % 2
            ? (float) $ranges[$middle]
            : (float) (($ranges[$middle - 1] + $ranges[$middle]) / 2);
        $relativeMedian = $count % 2
            ? (float) $relativeRanges[$middle]
            : (float) (($relativeRanges[$middle - 1] + $relativeRanges[$middle]) / 2);

        $flatRatio = (float) ($flats / $count);
        $runRatio = (float) ($longestRun / $count);
        $zeroRatio = (float) ($zeros / $count);
        $uniqueRatio = (float) (count($closes) / $count);
        $rangeScore = (float) ((min(1.0, $median / 5.0) + min(1.0, $relativeMedian / 0.001)) / 2);
        $score = (float) max(
            0.0,
            min(
                1.0,
                ((1 - $flatRatio) + (1 - $runRatio) + (1 - $zeroRatio) + $uniqueRatio + $rangeScore) / 5
            )
        );

        return [
            'score' => $score,
            'true_flat_ratio' => $flatRatio,
            'longest_flat_run_ratio' => $runRatio,
            'zero_volume_ratio' => $zeroRatio,
            'unique_close_ratio' => $uniqueRatio,
            'median_range_ticks' => $median,
            'median_range_relative' => $relativeMedian,
            'sample_size' => $count,
        ];
    }

    /**
     * The periods must be supplied from shortest to longest.
     * The optional diagnostics include rejection status and measured quality per period.
     *
     * @param  array<string, array<string, mixed>>|null  $diagnostics
     */
    public function choose(array $samplesByPeriod, float $tickSize, float $threshold = 0.7,
        int $minimumCandles = 0, float $minimumCoverage = 0.8,
        float $maximumTrueFlatRatio = 1.0, ?array &$diagnostics = null): ?array
    {
        if (! is_finite($threshold) || $threshold < 0 || $threshold > 1 || $minimumCandles < 0
            || ! is_finite($minimumCoverage) || $minimumCoverage < 0 || $minimumCoverage > 1
            || ! is_finite($maximumTrueFlatRatio) || $maximumTrueFlatRatio < 0 || $maximumTrueFlatRatio > 1) {
            throw new InvalidArgumentException('Invalid selection threshold, coverage, minimum sample size or true-flat limit.');
        }

        foreach ($samplesByPeriod as $period => $candles) {
            if ($candles === [] || count($candles) < $minimumCandles) {
                // Even an undersized recent window must enforce the true-flat cap.
                // Otherwise the fallback aggregate could conceal a bad recent window.
                $metrics = $candles === [] ? ['sample_size' => 0] : $this->evaluate($candles, $tickSize);
                $status = ($metrics['true_flat_ratio'] ?? 0.0) > $maximumTrueFlatRatio
                    ? 'flat_failed' : 'insufficient_candles';
                if ($diagnostics !== null) {
                    $diagnostics[$period] = [...$metrics, 'status' => $status];
                }

                continue;
            }

            $quality = $this->evaluate($candles, $tickSize);
            if (isset($candles[0]['microtimestamp'])) {
                $timestamps = array_column($candles, 'microtimestamp');
                sort($timestamps, SORT_NUMERIC);
                $missing = (new CandleGaps)->between($timestamps, (string) $period);
                $timeframe = new CandleTimeframe;
                $missingCount = 0;
                foreach ($missing as $gap) {
                    for ($at = $gap['from']; $at <= $gap['to']; $at = $timeframe->next($at, (string) $period)) {
                        $missingCount++;
                        if ($missingCount > 100_000) {
                            break 2;
                        }
                    }
                }
                $quality['coverage_ratio'] = (float) (count(array_unique($timestamps)) / (count(array_unique($timestamps)) + $missingCount));
            }
            $status = match (true) {
                $quality['true_flat_ratio'] > $maximumTrueFlatRatio => 'flat_failed',
                $quality['score'] < $threshold => 'quality_failed',
                ($quality['coverage_ratio'] ?? 1.0) < $minimumCoverage => 'coverage_failed',
                default => 'passed',
            };
            if ($diagnostics !== null) {
                $diagnostics[$period] = [...$quality, 'status' => $status];
            }
            if ($status === 'passed') {
                return ['period' => $period, 'quality' => $quality];
            }
        }

        return null;
    }
}
