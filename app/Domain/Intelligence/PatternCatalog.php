<?php

namespace App\Domain\Intelligence;

use App\Domain\MarketData\CandleProvenance;
use App\Domain\MarketData\CandleTimeframe;

/** Explicit gap-free crypto variants; target geometry is not an extra indicator pipeline. */
final class PatternCatalog
{
    public const VERSION = 'm4-patterns-v1';

    public const TYPES = ['bullish_engulfing', 'bearish_engulfing', 'morning_star', 'evening_star'];

    /** @return list<array<string, mixed>> Causal candidates, with no future target fields. */
    public function candidates(array $history, string $period): array
    {
        if ($history === []) {
            return [];
        }
        $current = $history[array_key_last($history)];
        $features = $current['features'];
        $body = $features['candle.body'] ?? null;
        $direction = $features['candle.direction'] ?? null;
        $trend = $features['trend.direction'] ?? null;
        if ($body === null || $direction === null || $trend === null) {
            return [];
        }
        $candidates = [];
        foreach (self::TYPES as $type) {
            $bullish = in_array($type, ['bullish_engulfing', 'morning_star'], true);
            $sign = $bullish ? -1 : 1;
            $length = str_contains($type, 'engulfing') ? 2 : 3;
            if ($direction === $sign && $body >= 0.5 && $trend * $sign >= 0) {
                $candidates[] = $this->candidate($type, $length, 1, (float) $body, [$current['candle']]);
            }
            if ($length === 3 && count($history) >= 2 && $body <= 0.3) {
                $previous = $history[count($history) - 2];
                if ((new CandleTimeframe)->next($previous['candle']['microtimestamp'], $period) !== $current['candle']['microtimestamp']) {
                    continue;
                }
                $first = $previous['features'];
                if (($first['candle.direction'] ?? null) === $sign && ($first['candle.body'] ?? 0) >= 0.5
                    && ($first['trend.direction'] ?? -$sign) * $sign >= 0) {
                    $candidates[] = $this->candidate($type, 3, 2, (float) (1 - $body),
                        [$previous['candle'], $current['candle']]);
                }
            }
        }

        return $candidates;
    }

    public function observations(array $history, array $future, string $period): array
    {
        $candidates = $this->candidates($history, $period);
        foreach ($candidates as &$candidate) {
            $remaining = $candidate['length'] - $candidate['stage'];
            $sequence = [...$candidate['prefix'], ...array_slice($future, 1, $remaining)];
            $candidate['label'] = $this->completed($candidate['type'], $sequence) ? 'completed' : 'failed';
            $candidate['label_available_at_ms'] = (new CandleTimeframe)->next($future[$remaining]['microtimestamp'], $period);
            foreach ($sequence as $bar) {
                $candidate['label_available_at_ms'] = max($candidate['label_available_at_ms'],
                    CandleProvenance::availableAt($bar, $period));
            }
            unset($candidate['prefix']);
        }
        unset($candidate);

        return $candidates;
    }

    public function vector(array $vector, array $candidate): array
    {
        return RubixFeatures::vector([...$vector, $candidate['length'] / 3, $candidate['stage'] / 3,
            $candidate['progress'], $candidate['similarity']]);
    }

    private function candidate(string $type, int $length, int $stage, float $similarity, array $prefix): array
    {
        return ['type' => $type, 'length' => $length, 'stage' => $stage,
            'progress' => $stage / $length, 'similarity' => $similarity, 'prefix' => $prefix];
    }

    private function completed(string $type, array $sequence): bool
    {
        $first = $sequence[0];
        $last = $sequence[array_key_last($sequence)];
        $bullish = in_array($type, ['bullish_engulfing', 'morning_star'], true);
        $direction = $bullish ? 1 : -1;
        if (((float) $last['close'] - (float) $last['open']) * $direction <= 0) {
            return false;
        }
        if (str_contains($type, 'engulfing')) {
            return min($last['open'], $last['close']) <= min($first['open'], $first['close'])
                && max($last['open'], $last['close']) >= max($first['open'], $first['close']);
        }
        $middle = $sequence[1];
        $range = (float) $middle['high'] - (float) $middle['low'];
        $small = $range <= 0 || abs((float) $middle['close'] - (float) $middle['open']) / $range <= 0.3;
        $midpoint = ((float) $first['open'] + (float) $first['close']) / 2;

        return $small && ((float) $last['close'] - $midpoint) * $direction > 0;
    }
}
