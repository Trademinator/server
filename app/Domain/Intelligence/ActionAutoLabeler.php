<?php

namespace App\Domain\Intelligence;

use App\Traits\CandleAutoDetection;
use InvalidArgumentException;

/**
 * Shared retrospective Action auto-labeler.
 *
 * BUY/SELL/HOLD labels feed Action KNN. The spacing between consecutive
 * opposite BUY/SELL pivots also defines Outcome KNN's natural horizon.
 */
final class ActionAutoLabeler
{
    use CandleAutoDetection;

    public function compact(array $candle, ?int $microtimestamp = null): array
    {
        foreach (['open', 'high', 'low', 'close', 'volume'] as $key) {
            if (! is_numeric($candle[$key] ?? null)) {
                throw new InvalidArgumentException('Action auto-labeling requires complete OHLCV candles.');
            }
        }
        $single = [$candle];
        $this->candle_anatomy($single);

        $compact = [
            'close' => $single[0]['close'],
            'is_black()' => $single[0]['is_black()'],
            'is_white()' => $single[0]['is_white()'],
            'is_super_doji()' => $single[0]['is_super_doji()'],
        ];
        if ($microtimestamp !== null) {
            $compact['microtimestamp'] = $microtimestamp;
        }

        return $compact;
    }

    /** @return list<array<string,mixed>> */
    public function labels(array $tickers, float $takerFee): array
    {
        $this->labelInPlace($tickers, $takerFee);

        return $tickers;
    }

    /** Mutate an owned context without retaining a second full candle array. */
    public function labelInPlace(array &$tickers, float $takerFee): void
    {
        if ($tickers === []) {
            return;
        }
        $this->candle_auto_mark_hold_candidates($tickers);
        $this->mark_all_blacks_and_whites($tickers);
        $this->remove_consequitive_actions($tickers);
        $this->remove_unprofitable_transactions($tickers, $takerFee);
        $this->remove_zigzags($tickers, $takerFee);
        $this->find_new_bottoms($tickers);
        $this->remove_consequitive_actions($tickers);
        $this->hodl_all_dojis($tickers);
        $this->hodl_middle_chains($tickers);
        $this->unlabel_endpoints($tickers);
    }

    public function horizon(array $labelled): int
    {
        $previousIndex = null;
        $previousAction = null;
        $frequencies = [];
        foreach ($labelled as $index => $ticker) {
            $action = $ticker['action'] ?? null;
            if (! in_array($action, ['buy', 'sell'], true)) {
                continue;
            }
            if ($previousIndex !== null) {
                if ($action === $previousAction) {
                    throw new InvalidArgumentException('Action pivots must alternate before deriving the Outcome horizon.');
                }
                $distance = $index - $previousIndex;
                if ($distance > 0) {
                    $frequencies[$distance] = ($frequencies[$distance] ?? 0) + 1;
                }
            }
            $previousIndex = $index;
            $previousAction = $action;
        }
        if ($frequencies === []) {
            throw new InvalidArgumentException('At least two opposite Action pivots are required to derive the Outcome horizon.');
        }

        $weighted = $observations = 0;
        foreach ($frequencies as $distance => $frequency) {
            $weighted += $distance * $frequency;
            $observations += $frequency;
        }

        return max(1, (int) round($weighted / $observations, 0, PHP_ROUND_HALF_UP));
    }

    /** @return array<int,string> timestamp => action */
    public function byTimestamp(array $labelled): array
    {
        $result = [];
        foreach ($labelled as $ticker) {
            $action = $ticker['action'] ?? null;
            $timestamp = $ticker['microtimestamp'] ?? null;
            if (is_int($timestamp) && in_array($action, CandleTraining::ACTIONS, true)) {
                $result[$timestamp] = $action === 'hold' ? 'hodl' : $action;
            }
        }

        return $result;
    }
}
