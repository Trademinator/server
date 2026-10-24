<?php

namespace App\Domain\MarketData;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Candle boundaries use the observed exchange grid, with UTC calendar fallbacks. */
final class CandleFeedSchedule
{
    public function lastClosedOpening(string $period, int $nowMs, ?int $anchorMs = null): int
    {
        return (new CandleTimeframe)->previous($this->currentOpening($period, $nowMs, $anchorMs), $period);
    }

    public function nextPull(string $period, int $nowMs, ?int $anchorMs, bool $expectedReceived,
        int $graceSeconds = 5, int $retrySeconds = 60): int
    {
        $graceMs = max(0, min(300, $graceSeconds)) * 1000;
        $opening = $this->currentOpening($period, $nowMs, $anchorMs);
        $next = $opening + $graceMs > $nowMs
            ? $opening + $graceMs
            : (new CandleTimeframe)->next($opening, $period) + $graceMs;

        return $expectedReceived ? $next : min($next, $nowMs + max(1, min(300, $retrySeconds)) * 1000);
    }

    private function currentOpening(string $period, int $nowMs, ?int $anchorMs): int
    {
        if (! in_array($period, CandleTimeframe::SUPPORTED, true)
            || ! preg_match('/^([1-9]\d*)([mhdwMy])$/D', $period, $matches)) {
            throw new InvalidArgumentException("Invalid candle period: {$period}");
        }
        $quantity = (int) $matches[1];
        $unit = $matches[2];
        if ($unit === 'M' || $unit === 'y') {
            $months = $quantity * ($unit === 'y' ? 12 : 1);
            $date = (new DateTimeImmutable('@'.intdiv($nowMs, 1000)))->setTimezone(new DateTimeZone('UTC'));
            $anchor = (new DateTimeImmutable('@'.intdiv($anchorMs ?? 0, 1000)))->setTimezone(new DateTimeZone('UTC'));
            $month = (int) $date->format('Y') * 12 + (int) $date->format('n') - 1;
            $anchorMonth = (int) $anchor->format('Y') * 12 + (int) $anchor->format('n') - 1;
            $openingMonth = $anchorMonth + (int) floor(($month - $anchorMonth) / $months) * $months;

            return $date->setDate(intdiv($openingMonth, 12), $openingMonth % 12 + 1, 1)->setTime(0, 0)->getTimestamp() * 1000;
        }
        $width = $quantity * match ($unit) {
            'm' => 60_000,
            'h' => 3_600_000,
            'd' => 86_400_000,
            'w' => 604_800_000,
        };
        $anchorMs ??= $unit === 'w' ? 345_600_000 : 0;

        return $anchorMs + (int) floor(($nowMs - $anchorMs) / $width) * $width;
    }
}
