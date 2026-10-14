<?php

namespace App\Domain\Intelligence;

use App\Helpers\Decimal;
use InvalidArgumentException;

/** Deterministic state transition; has no persistence or execution side effects. */
final class SignalMemoryPolicy
{
    public const VERSION = 'signal-memory-atr3-ema-v1';

    public static function displacement(string $side, string $reference, string $current): string
    {
        return $side === 'buy'
            ? bcsub($reference, $current, self::scale($reference, $current))
            : bcsub($current, $reference, self::scale($reference, $current));
    }

    public static function greater(string $left, string $right): bool
    {
        return bccomp($left, $right, self::scale($left, $right)) > 0;
    }

    /**
     * @param array|null $anchor Active same-direction t0, not the previous candle.
     * @return array{emit: bool, path: string, immediate: string, accumulated: ?string, threshold: string, anchor: array}
     */
    public function decide(string $side, int $at, string $price, string $previousPrice,
        string $atr, string $previousAtr, ?array $anchor): array
    {
        if (! in_array($side, ['buy', 'sell'], true)) {
            throw new InvalidArgumentException('Memory decisions require BUY or SELL.');
        }
        [$price, $previousPrice, $atr, $previousAtr] = array_map(Decimal::normalize(...),
            [$price, $previousPrice, $atr, $previousAtr]);
        foreach ([$price, $previousPrice, $atr, $previousAtr] as $value) {
            if (! self::greater($value, '0')) {
                throw new InvalidArgumentException('Prices and ATR must be strictly positive.');
            }
        }
        if (($anchor['direction'] ?? null) !== $side) {
            $anchor = null;
        }
        $immediate = self::displacement($side, $previousPrice, $price);
        $accumulated = $anchor === null ? null : self::displacement($side, $anchor['t0_price'], $price);
        $threshold = $previousAtr;
        $emit = self::greater($immediate, $threshold);
        $path = $emit ? 'immediate_displacement' : 'first_candidate';
        if (! $emit && $anchor !== null) {
            $threshold = $anchor['atr_threshold'];
            $emit = self::greater($accumulated, $threshold);
            $path = $emit ? 'accumulated_displacement' : 'below_threshold';
        }
        if ($anchor === null || $emit) {
            // The diagram saves current ATR at t0. The immediate test uses prior ATR.
            $anchor = [
                'direction' => $side, 't0_ms' => $at, 't0_price' => $price,
                'tn_ms' => $at, 'tn_price' => $price, 'atr_threshold' => $atr,
                'last_emitted_at_ms' => $emit ? $at : null,
                'last_emitted_price' => $emit ? $price : null,
            ];
        } else {
            $anchor['tn_ms'] = $at;
            $anchor['tn_price'] = $price;
        }

        return compact('emit', 'path', 'immediate', 'accumulated', 'threshold', 'anchor');
    }

    private static function scale(string ...$numbers): int
    {
        $scale = 24;
        foreach ($numbers as $number) {
            $dot = strpos($number, '.');
            if ($dot !== false) {
                $scale = max($scale, strlen($number) - $dot - 1);
            }
        }

        return $scale;
    }
}
