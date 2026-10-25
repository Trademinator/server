<?php

namespace App\Domain\MarketData;

use App\Domain\Intelligence\TrainingRequirements;
use InvalidArgumentException;
use RuntimeException;

final class HistoryDepth
{
    /** Minimum retained depth needed before a period is useful for normal model training. */
    public function requiredStartMs(string $period, int $untilMs): int
    {
        if ($untilMs <= 0 || ! in_array($period, CandleTimeframe::SUPPORTED, true)) {
            throw new InvalidArgumentException('Invalid historical depth boundary.');
        }

        $timeframe = new CandleTimeframe;
        $horizon = max(1, (int) config('intelligence.horizon'));
        $candles = TrainingRequirements::minimumRows(config('intelligence.knn'), $horizon, true)
            + max(1, (int) config('intelligence.lookback')) + $horizon;
        $start = $untilMs;
        for ($i = 0; $i < $candles && $start > 0; $i++) {
            $previous = $timeframe->previous($start, $period);
            if ($previous >= $start) {
                throw new RuntimeException('Historical depth timeframe did not move backward.');
            }
            $start = max(0, $previous);
        }

        $minimumDays = max(0, min(3650, (int) config('history_backfill.minimum_days', 7)));
        $daysStart = max(0, $untilMs - $minimumDays * 86_400_000);

        return min($start, $daysStart);
    }

    /** @return array{from: int, until: int, limit: int} */
    public function depthProbe(string $period, int $untilMs): array
    {
        $from = $this->requiredStartMs($period, $untilMs);

        return $this->forwardWindow($period, $from, $untilMs);
    }

    /** @return array{from: int, until: int, limit: int} */
    public function recentProbe(string $period, int $knownCandleMs): array
    {
        if ($knownCandleMs < 0 || ! in_array($period, CandleTimeframe::SUPPORTED, true)) {
            throw new InvalidArgumentException('Invalid recent history probe.');
        }

        $timeframe = new CandleTimeframe;
        $limit = $this->probeCandles();
        $from = $knownCandleMs;
        for ($i = 1; $i < $limit; $i++) {
            $previous = $timeframe->previous($from, $period);
            if ($previous >= $from) {
                throw new RuntimeException('Recent history probe timeframe did not move backward.');
            }
            $from = max(0, $previous);
        }
        $until = $timeframe->next($knownCandleMs, $period);

        return ['from' => $from, 'until' => $until, 'limit' => $limit];
    }

    public function spanIsSufficient(string $period, int $oldestMs, int $latestMs): bool
    {
        if ($oldestMs < 0 || $latestMs <= 0 || $oldestMs > $latestMs) {
            return false;
        }

        return $oldestMs <= $this->requiredStartMs($period, $latestMs);
    }

    /** @return array{from: int, until: int, limit: int} */
    private function forwardWindow(string $period, int $from, int $maximumUntil): array
    {
        $timeframe = new CandleTimeframe;
        $limit = $this->probeCandles();
        $until = $from;
        $actual = 0;
        while ($actual < $limit && $until < $maximumUntil) {
            $next = $timeframe->next($until, $period);
            if ($next <= $until) {
                throw new RuntimeException('Historical depth probe timeframe did not advance.');
            }
            $until = min($next, $maximumUntil);
            $actual++;
        }
        if ($actual < 1 || $until <= $from) {
            throw new InvalidArgumentException('Historical depth probe contains no request interval.');
        }

        return ['from' => $from, 'until' => $until, 'limit' => $actual];
    }

    private function probeCandles(): int
    {
        return max(1, min(90, (int) config('history_backfill.depth_probe_candles', 12)));
    }
}
