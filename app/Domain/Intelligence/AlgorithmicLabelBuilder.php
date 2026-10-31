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

    public function build(string $exchange, string $symbol, string $period, int $asOfMs, ?float $deadline = null): array
    {
        if (($cached = $this->sets->find($exchange, $symbol, $period, $asOfMs)) !== null) {
            return $cached;
        }

        $checkpoint = new BuildCheckpoint(['kind' => 'labels', 'exchange' => $exchange, 'symbol' => $symbol,
            'period' => $period, 'as_of_ms' => $asOfMs, 'profile' => $this->sets->profile($exchange, $symbol),
            'implementation' => BuildCheckpoint::implementation('labels')], $deadline);
        try {
            $revision = $this->sets->revision($exchange, $symbol, $period);
            if ($checkpoint->state !== [] && ! $this->sets->unchangedThrough($exchange, $symbol, $period,
                $asOfMs, $checkpoint->state['revision'], $revision)) {
                $checkpoint->reset();
            }
            if ($checkpoint->state === []) {
                $checkpoint->state = ['revision' => $revision, 'stage' => 'action_read'];
                $checkpoint->save(false);
            }
            $revision = $checkpoint->state['revision'];
            $writer = new LabelChunkWriter($checkpoint);
            if (! isset($checkpoint->state['action'])) {
                $checkpoint->state['action'] = $this->actions->analyzeResumable($exchange, $symbol, $period, $asOfMs,
                    function (int $timestamp, string $label, ?int $available) use ($writer): void {
                        $writer->append('action_labels', $timestamp, $label);
                        if ($available !== null) {
                            $writer->append('action_available_at_ms', $timestamp, $available);
                        }
                    }, $checkpoint);
                $checkpoint->state['stage'] = 'outcomes';
                $checkpoint->save();
            }
            $action = $checkpoint->state['action'];
            $horizon = $action['horizon'];
            $lookback = max(2, (int) config('intelligence.lookback'));
            $checkpoint->state['outcome'] ??= ['counts' => array_fill_keys(SemanticLabels::OUTCOMES, 0),
                'skipped' => ['gaps' => 0, 'immature' => 0, 'warmup' => 0],
                'window' => [], 'previous' => null, 'processed' => 0];
            $outcome = &$checkpoint->state['outcome'];
            $counts = &$outcome['counts'];
            $skipped = &$outcome['skipped'];
            if ($horizon !== null && $checkpoint->state['stage'] === 'outcomes') {
                $labeler = new SemanticLabels($horizon, $lookback);
                $fromMs = KnowledgeWindow::trainingFromMs();
                for ($i = 0; $i < $lookback; $i++) {
                    $fromMs = max(0, $this->timeframe->previous($fromMs, $period));
                }
                $window = &$outcome['window'];
                $previous = &$outcome['previous'];
                $processed = &$outcome['processed'];
                if ($previous !== null) {
                    $fromMs = $previous + 1;
                }
                foreach ($this->tickers->streamHistory($exchange, $symbol, $period, $fromMs, $asOfMs) as $timestamp => $bar) {
                    if ($checkpoint->due()) {
                        $checkpoint->save();
                    }
                    $timestamp = (int) $timestamp;
                    if ($processed++ % 1024 === 0) {
                        TrainingRuntime::heartbeat();
                    }
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
                    if ($available <= $asOfMs && $this->timeframe->next($first['microtimestamp'], $period) >= KnowledgeWindow::trainingFromMs()) {
                        $result = $labeler->label($past, $future);
                        $writer->append('outcome_labels', $first['microtimestamp'], [
                            'label' => $result['action'],
                            'label_available_at_ms' => $available,
                            'gross_return' => $result['gross_return'],
                            'buy_price_return' => $result['buy_price_return'],
                            'sell_base_price_return' => $result['sell_base_price_return'],
                            'semantic' => $result['semantic'],
                        ]);
                        $counts[$result['action']]++;
                    } else {
                        $skipped['immature']++;
                    }
                    array_shift($window);
                }
            }

            $checkpoint->state['stage'] = 'label_publish';
            $checkpoint->save(false);
            $payload = [
                'as_of_ms' => $asOfMs,
                'action' => ActionLabelAnalysis::publicDiagnostics($action),
                'outcome_horizon' => $horizon,
                'outcome_status' => $horizon === null ? 'insufficient_distance_observations'
                    : ($skipped['gaps'] > 0 ? 'partial_gaps' : 'ready'),
                'outcome_counts' => $counts,
                'outcome_skipped' => $skipped,
            ];
            $published = $this->sets->publishChunks($exchange, $symbol, $period, $asOfMs, $payload, $writer, $revision);
            $this->reports->publish($exchange, $symbol, $period, $action);

            $checkpoint->complete();

            return $published;
        } finally {
            $checkpoint->close();
        }
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
