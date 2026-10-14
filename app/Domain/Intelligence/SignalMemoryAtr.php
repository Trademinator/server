<?php

namespace App\Domain\Intelligence;

use App\Domain\MarketData\CandleProvenance;
use App\Domain\MarketData\CandleTimeframe;
use App\Helpers\Decimal;
use App\Repositories\TickerRepository;
use Trademinator\Indicators\Traits\Technical;

/** Uses the canonical indicator implementation, never a normalized KNN feature. */
final class SignalMemoryAtr
{
    use Technical;

    public function __construct(private TickerRepository $tickers) {}

    /**
     * Cold start streams the model-age window once. A bounded calculated overlap
     * retains the recursive EMA seed (nine rows once mature; at most thirteen
     * during initial seeding), so subsequent signals read only new OHLCV.
     * All timestamps are source candle OPEN times except decisionAt (close time).
     */
    public function context(string $exchange, string $symbol, string $period, int $decisionAt,
        ?array $checkpoint = null): array
    {
        $timeframe = new CandleTimeframe;
        $currentOpen = $timeframe->previous($decisionAt, $period);
        $carry = $checkpoint['carry'] ?? [];
        $through = $checkpoint['through_ms'] ?? null;
        $count = (int) ($checkpoint['count'] ?? 0);
        $historyStart = $checkpoint['history_start_ms'] ?? null;
        $reset = false;
        if ($through !== null && ($through >= $currentOpen || $carry === [])) {
            $carry = [];
            $through = null;
            $count = 0;
            $historyStart = null;
            $reset = true;
        }
        $from = $through === null ? KnowledgeWindow::fromMs($decisionAt) : $timeframe->next($through, $period);
        foreach ($this->tickers->streamHistory($exchange, $symbol, $period, $from, $currentOpen) as $timestamp => $raw) {
            $timestamp = (int) $timestamp;
            $closeAt = $timeframe->next($timestamp, $period);
            if ($timestamp > $currentOpen || $closeAt > $decisionAt) {
                break;
            }
            if ($through !== null && $timestamp !== $timeframe->next($through, $period)) {
                $carry = [];
                $count = 0;
                $historyStart = null;
                $reset = true;
            }
            // A reconstructed candle may have become available AFTER this decision.
            if (CandleProvenance::availableAt($raw, $period) > $decisionAt) {
                $carry = [];
                $count = 0;
                $historyStart = null;
                $through = null;
                $reset = true;

                continue;
            }
            // Do not trust indicator/cache fields supplied in a stored OHLCV row.
            $row = ['microtimestamp' => $timestamp];
            foreach (['open', 'high', 'low', 'close', 'volume'] as $field) {
                $row[$field] = $raw[$field] ?? null;
            }
            $normalized = [$row];
            $this->normalize_ticker($normalized);
            if (! SignalMemoryPolicy::greater($normalized[0]['low'], '0')) {
                return ['error' => 'signal_memory_inputs_unavailable'];
            }
            $carry[$timestamp] = $normalized[0];
            $this->atr($carry, 3, 'ema');
            $carry = $this->ticker_slice($carry, 3, 3);
            $count++;
            $historyStart ??= $timestamp;
            $through = $timestamp;
        }
        $saved = ['carry' => $carry, 'through_ms' => $through, 'count' => $count,
            'history_start_ms' => $historyStart];
        // Prior ATR must itself be mature, requiring four contiguous candles.
        if ($through !== $currentOpen || $count < 4 || count($carry) < 2) {
            return ['error' => 'signal_memory_atr_unavailable', 'checkpoint' => $saved, 'reset' => $reset];
        }
        $lastTwo = array_values(array_slice($carry, -2, null, true));
        $previous = $lastTwo[0];
        $current = $lastTwo[1];
        $atr = Decimal::normalize($current['atr(3,ema)']);
        $previousAtr = Decimal::normalize($previous['atr(3,ema)']);
        if (! SignalMemoryPolicy::greater($atr, '0') || ! SignalMemoryPolicy::greater($previousAtr, '0')) {
            return ['error' => 'signal_memory_atr_unavailable', 'checkpoint' => $saved, 'reset' => $reset];
        }

        return ['close' => $current['close'], 'previous_close' => $previous['close'],
            'atr' => $atr, 'previous_atr' => $previousAtr, 'checkpoint' => $saved,
            'history_start_ms' => $historyStart, 'reset' => $reset];
    }
}
