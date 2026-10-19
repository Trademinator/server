<?php

namespace App\Domain\Intelligence;

use App\Domain\MarketData\CandleProvenance;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\Research\SemanticLabels;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\DB;

/**
 * On-demand labels from CLOSED immutable-at-evaluation market observations.
 * Reuses the same SemanticLabels / ActionLabelAnalysis used for training.
 * Never scores a label before its label_available_at_ms.
 */
final class FinalizedShadowLabels
{
    public function __construct(
        private TickerRepository $tickers,
        private CandleTimeframe $timeframe,
        private ActionLabelAnalysis $actions,
    ) {}

    /** @return array{actual: string, available_at_ms: int}|null */
    public function outcome(string $exchange, string $symbol, string $period, int $decisionMs,
        int $horizon, int $lookback, int $asOfMs): ?array
    {
        if ($horizon < 1 || $horizon > 1000 || $lookback < 2 || $lookback > 1000
            || $decisionMs > $asOfMs) return null;
        $start = $this->timeframe->previous($decisionMs, $period);
        // Historical 'past' includes the decision candle, exactly as DatasetSnapshotBuilder does.
        for ($i = 1; $i < $lookback; $i++) {
            $start = $this->timeframe->previous($start, $period);
        }
        $last = $decisionMs;
        for ($i = 1; $i < $horizon; $i++) $last = $this->timeframe->next($last, $period);
        $maturity = $this->timeframe->next($last, $period);
        if ($maturity > $asOfMs) return null;

        $candles = [];
        $cursor = $start;
        foreach ($this->tickers->streamHistory($exchange, $symbol, $period, $start, $last) as $stamp => $bar) {
            if ((int)$stamp !== $cursor || !is_array($bar)) return null;
            foreach (['open','high','low','close'] as $key) {
                if (!is_numeric($bar[$key] ?? null) || (float)$bar[$key] <= 0) return null;
            }
            $bar['microtimestamp'] = (int)$stamp;
            $maturity = max($maturity, CandleProvenance::availableAt($bar, $period));
            $candles[] = $bar;
            $cursor = $this->timeframe->next($cursor, $period);
        }
        if ($cursor !== $this->timeframe->next($last, $period)
            || count($candles) !== $lookback + $horizon || $maturity > $asOfMs) return null;
        $past = array_slice($candles, 0, $lookback);
        $future = array_slice($candles, $lookback - 1);
        $label = (new SemanticLabels($horizon, $lookback))->label($past, $future);
        return ['actual' => $label['action'], 'available_at_ms' => $maturity];
    }

    /**
     * Action BUY/SELL pivots are retrospective. No fixed H can finalize them;
     * use opposite-pivot confirmations and the original action labeler.
     * @return array<int, array{actual: string, available_at_ms: int}>
     */
    public function actions(string $exchange, string $symbol, string $period, int $asOfMs): array
    {
        $analysis = $this->actions->analyze($exchange, $symbol, $period, $asOfMs);
        $result = [];
        foreach ($analysis['_action_available_at_ms'] as $candleMs => $availableAt) {
            $actual = $analysis['_action_labels'][$candleMs] ?? null;
            if (is_string($actual) && $availableAt <= $asOfMs) {
                $decision = $this->timeframe->next((int)$candleMs, $period);
                $result[$decision] = ['actual'=>$actual,'available_at_ms'=>(int)$availableAt];
            }
        }
        return $result;
    }
}
