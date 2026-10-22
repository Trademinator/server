<?php

namespace App\Domain\Intelligence;

use App\Domain\MarketData\CandleProvenance;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\Research\SemanticLabels;
use App\Repositories\TickerRepository;
use RuntimeException;

/** Derive labels from completed, contiguous OHLCV; no feature vector is read. */
final class AlgorithmicLabelBuilder
{
    public function __construct(
        private ActionLabelAnalysis $actions,
        private TickerRepository $tickers,
        private CandleTimeframe $timeframe,
        private AlgorithmicLabelSets $sets,
        private ActionLabelReportStore $reports,
    ) {}

    public function build(string $exchange, string $symbol, string $period, int $asOfMs): array
    {
        if (($cached = $this->sets->find($exchange, $symbol, $period, $asOfMs)) !== null) {
            return $cached;
        }

        $action = $this->actions->analyze($exchange, $symbol, $period, $asOfMs);
        $horizon = $action['horizon'];
        $lookback = max(2, (int) config('intelligence.lookback'));
        $outcomes = [];
        $counts = array_fill_keys(SemanticLabels::OUTCOMES, 0);
        $skipped = ['gaps' => 0, 'immature' => 0, 'warmup' => 0];
        if ($horizon !== null) {
            $labeler = new SemanticLabels($horizon, $lookback);
            $fromMs = KnowledgeWindow::fromMs($asOfMs);
            for ($i = 0; $i < $lookback; $i++) {
                $fromMs = max(0, $this->timeframe->previous($fromMs, $period));
            }
            $window = [];
            $previous = null;
            foreach ($this->tickers->streamHistory($exchange, $symbol, $period, $fromMs, $asOfMs) as $timestamp => $bar) {
                $timestamp = (int) $timestamp;
                if ($this->timeframe->next($timestamp, $period) > $asOfMs) {
                    break;
                }
                if ($previous !== null && $this->timeframe->next($previous, $period) !== $timestamp) {
                    $window = [];
                    $skipped['gaps']++;
                }
                $this->validateCandle($bar);
                $window[] = [...$bar, 'microtimestamp' => $timestamp];
                $previous = $timestamp;
                if (count($window) < $lookback + $horizon) {
                    $skipped['warmup']++;
                    continue;
                }
                $past = array_slice($window, 0, $lookback);
                $future = array_slice($window, $lookback - 1, $horizon + 1);
                $first = $future[0];
                $available = $this->timeframe->next($future[$horizon]['microtimestamp'], $period);
                foreach ($future as $source) {
                    $available = max($available, CandleProvenance::availableAt($source, $period));
                }
                if ($available <= $asOfMs && $this->timeframe->next($first['microtimestamp'], $period) >= KnowledgeWindow::fromMs($asOfMs)) {
                    $result = $labeler->label($past, $future);
                    $outcomes[$first['microtimestamp']] = [
                        'label' => $result['action'],
                        'label_available_at_ms' => $available,
                        'gross_return' => $result['gross_return'],
                        'buy_price_return' => $result['buy_price_return'],
                        'sell_base_price_return' => $result['sell_base_price_return'],
                        'semantic' => $result['semantic'],
                    ];
                    $counts[$result['action']]++;
                } else {
                    $skipped['immature']++;
                }
                array_shift($window);
            }
        }

        $payload = [
            'as_of_ms' => $asOfMs,
            'action' => ActionLabelAnalysis::publicDiagnostics($action),
            'action_labels' => $action['_action_labels'],
            'action_available_at_ms' => $action['_action_available_at_ms'],
            'outcome_horizon' => $horizon,
            'outcome_status' => $horizon === null ? 'insufficient_distance_observations'
                : ($skipped['gaps'] > 0 ? 'partial_gaps' : 'ready'),
            'outcome_labels' => $outcomes,
            'outcome_counts' => $counts,
            'outcome_skipped' => $skipped,
        ];
        $published = $this->sets->publish($exchange, $symbol, $period, $asOfMs, $payload);
        $this->reports->publish($exchange, $symbol, $period, $action);

        return $published;
    }

    private function validateCandle(array $candle): void
    {
        foreach (['open', 'high', 'low', 'close', 'volume'] as $field) {
            if (! is_numeric($candle[$field] ?? null)
                || ! is_finite((float) $candle[$field])
                || ($field !== 'volume' && (float) $candle[$field] <= 0)
                || ($field === 'volume' && (float) $candle[$field] < 0)) {
                throw new RuntimeException('Invalid source OHLCV in algorithmic label generation.');
            }
        }
        if ($candle['high'] < max($candle['open'], $candle['low'], $candle['close'])
            || $candle['low'] > min($candle['open'], $candle['close'])) {
            throw new RuntimeException('Invalid source OHLCV bounds in algorithmic label generation.');
        }
    }
}
