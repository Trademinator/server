<?php

namespace App\Traits;

use Trademinator\Indicators\Traits\Patterns;

use function Trademinator\BcMath\bcconv;

/**
 * Retrospective helpers for M4 Action Training labels.
 *
 * The order of these routines is intentional: early passes over-label candidate
 * actions and later passes remove or move excess labels. These labels describe
 * training opportunities from historical CLOSE values; they are not Client
 * execution instructions.
 */
trait CandleAutoDetection
{
    use Patterns;

    /**
     * Endpoints provide candle context, never an automatic BUY, SELL or HOLD.
     * Apply to the complete chronological sequence, not a response page.
     */
    public function unlabel_endpoints(array &$tickers): array
    {
        if ($tickers === []) {
            return $tickers;
        }

        foreach ([array_key_first($tickers), array_key_last($tickers)] as $index) {
            unset($tickers[$index]['action'], $tickers[$index]['_auto_hold_doji'], $tickers[$index]['_auto_hold_middle']);
        }

        return $tickers;
    }

    // All black candles are BUY candidates; all white candles are SELL candidates.
    // Broadly mark BUY/SELL candidates, excluding candles already reserved for HOLD.
    public function mark_all_blacks_and_whites(array &$tickers): array
    {
        // Stale endpoint actions must not participate in surviving-pivot cleanup.
        $this->unlabel_endpoints($tickers);
        $count = count($tickers);
        foreach ($tickers as $index => &$ticker) {
            if ($index <= 0 || $index >= $count - 2 || $this->candle_auto_is_reserved_hold($ticker)) {
                continue;
            }
            if (($ticker['is_black()'] ?? 0) === 1) {
                $ticker['action'] = 'buy';
            } elseif (($ticker['is_white()'] ?? 0) === 1) {
                $ticker['action'] = 'sell';
            }
        }
        unset($ticker);

        return $tickers;
    }

    // Remove consecutive identical actions; only the last candidate matters.
    // Collapse consecutive BUY/SELL candidates to the strongest CLOSE extreme.
    // HOLD is invisible to this rule and never resets the surviving trade action.
    // Collapse consecutive BUY/SELL candidates to the strongest CLOSE extreme.
    // HOLD and reserved HOLD candidates are invisible to this rule.
    public function remove_consequitive_actions(array &$tickers): array
    {
        $survivorIndex = null;

        // References avoid both a copied key list and foreach copy-on-write.
        foreach ($tickers as $index => &$ticker) {
            $action = $tickers[$index]['action'] ?? null;
            if (! in_array($action, ['buy', 'sell'], true)) {
                continue;
            }

            if ($survivorIndex === null) {
                $survivorIndex = $index;

                continue;
            }

            $survivorAction = $tickers[$survivorIndex]['action'] ?? null;
            if ($survivorAction !== $action) {
                $survivorIndex = $index;

                continue;
            }

            if ($this->candle_auto_same_action_candidate_wins(
                $tickers[$survivorIndex],
                $tickers[$index],
                $action,
            )) {
                unset($tickers[$survivorIndex]['action']);
                $survivorIndex = $index;
            } else {
                unset($tickers[$index]['action']);
            }
        }
        unset($ticker);

        return $tickers;
    }

    // Remove BUY -> SELL pairs whose CLOSE movement cannot clear twice the one-side taker fee.
    // Deliberately no ATR here: this pass is only the economic floor.
    // Keep only alternating BUY/SELL pivots whose CLOSE movement clears twice the taker fee.
    // A rejected opposite candidate does not replace the last surviving pivot.
    // HOLD labels are ignored completely.
    // Keep alternating BUY/SELL pivots whose CLOSE movement clears twice the taker fee.
    // A rejected opposite candidate does not replace the last surviving pivot.
    // HOLD labels and reserved HOLD candidates are ignored completely.
    public function remove_unprofitable_transactions(array &$tickers, mixed $taker_fee): array
    {
        return $this->candle_auto_prune_trade_actions($tickers, $taker_fee);
    }

    // Remove SELL -> BUY zigzags whose CLOSE movement cannot clear twice the one-side taker fee.
    // Deliberately no ATR here: profitable historical swings should not be removed by volatility.
    // Compatibility cleanup: the surviving-pivot pass already handles SELL -> BUY zigzags.
    // Re-running it is intentionally idempotent and still ignores HOLD labels.
    // The surviving-pivot pass already handles SELL -> BUY zigzags.
    // Re-running it is intentionally idempotent and ignores HOLDs.
    public function remove_zigzags(array &$tickers, mixed $taker_fee): array
    {
        return $this->candle_auto_prune_trade_actions($tickers, $taker_fee);
    }

    // Between surviving SELLs, move the BUY label to the lowest black CLOSE.
    // Between surviving SELLs, move the BUY label to the lowest eligible black CLOSE.
    // Candles reserved for HOLD are not eligible BUY candidates.
    public function find_new_bottoms(array &$tickers): array
    {
        $index = 0;
        while (($firstSellIndex = $this->candle_auto_find_next_action_index($tickers, 'sell', $index)) !== null) {
            $index = $firstSellIndex + 1;
            $nextSellIndex = $this->candle_auto_find_next_action_index($tickers, 'sell', $index);
            if ($nextSellIndex === null || $nextSellIndex - $firstSellIndex <= 1) {
                continue;
            }

            $newBottomIndex = null;
            for ($candidate = $firstSellIndex + 1; $candidate < $nextSellIndex; $candidate++) {
                if ($this->candle_auto_is_reserved_hold($tickers[$candidate])
                    || ($tickers[$candidate]['is_black()'] ?? 0) !== 1) {
                    continue;
                }
                if ($newBottomIndex === null || bccomp(
                    bcconv($tickers[$candidate]['close']),
                    bcconv($tickers[$newBottomIndex]['close']),
                    $this->precisionPolicy()->minimumScale
                ) < 0) {
                    $newBottomIndex = $candidate;
                }
            }
            if ($newBottomIndex === null) {
                continue;
            }

            $firstBuyIndex = $this->candle_auto_find_next_action_index($tickers, 'buy', $firstSellIndex + 1);
            if ($firstBuyIndex !== null && $firstBuyIndex < $nextSellIndex && $firstBuyIndex !== $newBottomIndex) {
                unset($tickers[$firstBuyIndex]['action']);
            }
            $tickers[$newBottomIndex]['action'] = 'buy';
        }

        return $tickers;
    }

    // Keep the old behaviour: only completely flat OHLC candles are auto-HOLD.
    // Apply HOLD to candles reserved as completely flat OHLC dojis.
    // Completely flat OHLC candles are HOLD. The reservation flag lets
    // the earlier BUY/SELL passes ignore them, but this method also remains
    // correct when called independently.
    public function hodl_all_dojis(array &$tickers): array
    {
        $count = count($tickers);
        foreach ($tickers as $index => &$ticker) {
            if ($index > 0 && $index < $count - 1
                && (($ticker['_auto_hold_doji'] ?? false) === true
                    || ($ticker['is_super_doji()'] ?? 0) === 1)) {
                $ticker['action'] = 'hold';
            }
            unset($ticker['_auto_hold_doji']);
        }
        unset($ticker);

        return $this->unlabel_endpoints($tickers);
    }

    // Label interior candles in long same-colour chains as HOLD candidates.
    // Apply HOLD to same-colour chain candles reserved before BUY/SELL pruning.
    // Label interior candles in long same-colour chains as HOLD.
    // Reserved flags protect them during earlier BUY/SELL selection, while the
    // direct pattern check preserves this method's standalone behaviour.
    public function hodl_middle_chains(array &$tickers): array
    {
        $count = count($tickers);

        for ($index = 0; $index < $count; $index++) {
            $reserved = ($tickers[$index]['_auto_hold_middle'] ?? false) === true;
            $middleChain = false;

            if ($index >= 2 && $index < $count - 2) {
                $middleChain = (
                    ($tickers[$index]['is_black()'] ?? 0) === 1
                    && ($tickers[$index - 1]['is_black()'] ?? 0) === 1
                    && ($tickers[$index + 1]['is_black()'] ?? 0) === 1
                    && ($tickers[$index + 2]['is_black()'] ?? 0) === 1
                ) || (
                    ($tickers[$index]['is_white()'] ?? 0) === 1
                    && ($tickers[$index - 1]['is_white()'] ?? 0) === 1
                    && ($tickers[$index + 1]['is_white()'] ?? 0) === 1
                    && ($tickers[$index + 2]['is_white()'] ?? 0) === 1
                );
            }

            if ($index > 0 && $index < $count - 1 && ($reserved || $middleChain)) {
                $tickers[$index]['action'] = 'hold';
            }

            unset($tickers[$index]['_auto_hold_middle']);
        }

        return $this->unlabel_endpoints($tickers);
    }

    /**
     * Process BUY/SELL candidates chronologically against the last surviving
     * trade pivot. HOLD and every other action are deliberately invisible.
     */

    /** Reserve future HOLDs before broad BUY/SELL assignment. */
    private function candle_auto_mark_hold_candidates(array &$tickers): array
    {
        $count = count($tickers);
        foreach ($tickers as $index => &$ticker) {
            if ($index > 0 && $index < $count - 1 && ($ticker['is_super_doji()'] ?? 0) === 1) {
                $ticker['_auto_hold_doji'] = true;
            }
        }
        unset($ticker);

        for ($index = 2; $index < $count - 2; $index++) {
            $blackChain = ($tickers[$index]['is_black()'] ?? 0) === 1
                && ($tickers[$index - 1]['is_black()'] ?? 0) === 1
                && ($tickers[$index + 1]['is_black()'] ?? 0) === 1
                && ($tickers[$index + 2]['is_black()'] ?? 0) === 1;
            $whiteChain = ($tickers[$index]['is_white()'] ?? 0) === 1
                && ($tickers[$index - 1]['is_white()'] ?? 0) === 1
                && ($tickers[$index + 1]['is_white()'] ?? 0) === 1
                && ($tickers[$index + 2]['is_white()'] ?? 0) === 1;

            if ($blackChain || $whiteChain) {
                $tickers[$index]['_auto_hold_middle'] = true;
            }
        }

        return $tickers;
    }

    private function candle_auto_is_reserved_hold(array $ticker): bool
    {
        return ($ticker['_auto_hold_doji'] ?? false) === true
            || ($ticker['_auto_hold_middle'] ?? false) === true;
    }

    /** Process BUY/SELL candidates against the last surviving trade pivot. */
    private function candle_auto_prune_trade_actions(array &$tickers, mixed $taker_fee): array
    {
        $minimumMovement = $this->candle_auto_double_taker_fee($taker_fee);
        $scale = $this->precisionPolicy()->minimumScale;
        $survivorIndex = null;

        foreach ($tickers as $index => &$ticker) {
            $action = $tickers[$index]['action'] ?? null;
            if (! in_array($action, ['buy', 'sell'], true)
                || $this->candle_auto_is_reserved_hold($tickers[$index])) {
                continue;
            }

            if ($survivorIndex === null) {
                $survivorIndex = $index;

                continue;
            }

            $survivorAction = $tickers[$survivorIndex]['action'] ?? null;
            if (! in_array($survivorAction, ['buy', 'sell'], true)) {
                $survivorIndex = $index;

                continue;
            }

            if ($survivorAction === $action) {
                if ($this->candle_auto_same_action_candidate_wins(
                    $tickers[$survivorIndex],
                    $tickers[$index],
                    $action,
                )) {
                    unset($tickers[$survivorIndex]['action']);
                    $survivorIndex = $index;
                } else {
                    unset($tickers[$index]['action']);
                }

                continue;
            }

            $survivorClose = bcconv($tickers[$survivorIndex]['close']);
            $candidateClose = bcconv($tickers[$index]['close']);

            if (bccomp($survivorClose, '0', $scale) <= 0) {
                unset($tickers[$survivorIndex]['action']);
                $survivorIndex = $index;

                continue;
            }
            if (bccomp($candidateClose, '0', $scale) <= 0) {
                unset($tickers[$index]['action']);

                continue;
            }

            $movement = $survivorAction === 'buy'
                ? bcsub(bcdiv($candidateClose, $survivorClose, $scale), '1', $scale)
                : bcsub('1', bcdiv($candidateClose, $survivorClose, $scale), $scale);

            if (bccomp($movement, $minimumMovement, $scale) <= 0) {
                unset($tickers[$index]['action']);

                continue;
            }

            $survivorIndex = $index;
        }
        unset($ticker);

        return $tickers;
    }

    private function candle_auto_same_action_candidate_wins(array $survivor, array $candidate, string $action): bool
    {
        $comparison = bccomp(
            bcconv($candidate['close']),
            bcconv($survivor['close']),
            $this->precisionPolicy()->minimumScale,
        );

        return $action === 'buy' ? $comparison <= 0 : $comparison >= 0;
    }

    private function candle_auto_double_taker_fee(mixed $taker_fee): string
    {
        $scale = $this->precisionPolicy()->minimumScale;
        $fee = bcconv($taker_fee);
        if (bccomp($fee, '0', $scale) < 0 || bccomp($fee, '1', $scale) >= 0) {
            throw new \InvalidArgumentException('Taker fee must be a decimal fraction between zero and one.');
        }

        return bcmul($fee, '2', $scale);
    }

    private function candle_auto_find_next_action_index(array $tickers, string $action, int $start = 0): ?int
    {
        for ($index = max(0, $start); $index < count($tickers); $index++) {
            if (($tickers[$index]['action'] ?? null) === $action) {
                return $index;
            }
        }

        return null;
    }
}
