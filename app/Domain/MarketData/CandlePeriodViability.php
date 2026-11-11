<?php

namespace App\Domain\MarketData;

use App\Traits\CandleAutoDetection;
use InvalidArgumentException;

/** Run the M4/M5 retrospective auto-label pipeline without persisting labels. */
final class CandlePeriodViability
{
    use CandleAutoDetection;

    /**
     * Mutate the candle buffer in place so evaluation samples are not duplicated.
     *
     * @param  list<array<string, mixed>>  $candles
     * @return array<string, mixed>
     */
    public function evaluate(array &$candles, float $takerFee, float $minimumRatio = 0.01, float $maximumRejectedRatio = 0.10): array
    {
        if (! is_finite($takerFee) || $takerFee < 0 || $takerFee >= 1
            || ! is_finite($minimumRatio) || $minimumRatio < 0 || $minimumRatio > 1
            || ! is_finite($maximumRejectedRatio) || $maximumRejectedRatio < 0 || $maximumRejectedRatio > 1) {
            throw new InvalidArgumentException('Invalid candle-period economic viability settings.');
        }

        $reversals = ['tested' => 0, 'rejected' => 0];
        if ($candles === []) {
            return $this->summary([], $minimumRatio, $reversals, $maximumRejectedRatio);
        }

        $this->candle_anatomy($candles);
        $this->mark_all_blacks_and_whites($candles);
        $this->remove_consequitive_actions($candles);
        $this->remove_unprofitable_transactions($candles, $takerFee, null, $reversals);
        $this->remove_zigzags($candles, $takerFee);
        $this->find_new_bottoms($candles);
        $this->hodl_all_dojis($candles);
        $this->hodl_middle_chains($candles);
        // Context-only endpoints must never contribute to finalized-label ratios.
        $this->unlabel_endpoints($candles);

        return $this->summary($candles, $minimumRatio, $reversals, $maximumRejectedRatio);
    }

    /** @param list<array<string, mixed>> $tickers */
    private function summary(array $tickers, float $minimumRatio, array $reversals, float $maximumRejectedRatio): array
    {
        $counts = ['buy' => 0, 'sell' => 0, 'hold' => 0];
        foreach ($tickers as $ticker) {
            $action = $ticker['action'] ?? null;
            if (is_string($action) && isset($counts[$action])) {
                $counts[$action]++;
            }
        }

        // Deliberately exclude unlabelled candles. The gate is defined over
        // finalized BUY + SELL + HOLD auto-detected signals.
        $total = array_sum($counts);
        $buyRatio = $total === 0 ? 0.0 : $counts['buy'] / $total;
        $sellRatio = $total === 0 ? 0.0 : $counts['sell'] / $total;

        $rejectedRatio = $reversals['tested'] === 0 ? null : $reversals['rejected'] / $reversals['tested'];

        return [
            'passes' => $total > 0 && $buyRatio >= $minimumRatio && $sellRatio >= $minimumRatio
                && $rejectedRatio !== null && $rejectedRatio <= $maximumRejectedRatio,
            'reversals_tested' => $reversals['tested'],
            'reversals_rejected' => $reversals['rejected'],
            'rejected_reversal_ratio' => $rejectedRatio,
            'maximum_rejected_reversal_ratio' => $maximumRejectedRatio,
            ...$counts,
            'total' => $total,
            'buy_ratio' => (float) $buyRatio,
            'sell_ratio' => (float) $sellRatio,
            'minimum_ratio' => $minimumRatio,
        ];
    }
}
