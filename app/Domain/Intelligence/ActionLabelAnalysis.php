<?php

namespace App\Domain\Intelligence;

use App\Domain\MarketData\CandleTimeframe;
use App\Domain\Operations\ActionLog;
use App\Repositories\TickerRepository;
use InvalidArgumentException;

final class ActionLabelAnalysis
{
    public const DEFAULT_MIN_DISTANCE_OBSERVATIONS = 30;

    public function __construct(
        private TickerRepository $tickers,
        private ActionAutoLabeler $labels,
        private PublishedTakerFee $takerFees,
        private CandleTimeframe $timeframe,
    ) {}

    public function analyze(string $exchange, string $symbol, string $period, int $asOfMs): array
    {
        $fields = ['exchange' => $exchange, 'symbol' => $symbol, 'period' => $period, 'stage' => 'action_labeling'];
        app(ActionLog::class)->write('intelligence.action_labels.started', [
            ...$fields, 'memory_bytes' => memory_get_usage(true),
        ]);
        $fromMs = KnowledgeWindow::fromMs($asOfMs);
        $fee = $this->takerFees->for($exchange, $symbol);
        if ($fee === null) {
            throw new InvalidArgumentException('Published taker fee is unavailable for Action auto-labeling.');
        }

        $actionLabels = [];
        $actionAvailability = [];
        $actionCounts = ['buy' => 0, 'hold' => 0, 'sell' => 0];
        $frequencies = [];
        $pivotCount = 0;
        $candles = 0;
        $gaps = 0;
        $runs = 0;
        $previous = null;
        $run = [];

        $consume = function () use (
            &$run, &$runs, &$actionLabels, &$actionAvailability, &$actionCounts,
            &$frequencies, &$pivotCount, $fee, $period, $fields
        ): void {
            if ($run === []) {
                return;
            }
            $runs++;
            app(ActionLog::class)->write('intelligence.action_labels.run', [
                ...$fields, 'rows' => count($run), 'memory_bytes' => memory_get_usage(true),
            ]);
            $summary = $this->summarizeRun($run, $fee, $period, $actionLabels, $actionAvailability);
            $run = [];
            foreach ($actionCounts as $action => $_) {
                $actionCounts[$action] += $summary['action_counts'][$action];
            }
            foreach ($summary['distance_frequencies'] as $distance => $frequency) {
                $frequencies[$distance] = ($frequencies[$distance] ?? 0) + $frequency;
            }
            $pivotCount += $summary['pivot_count'];
        };

        foreach ($this->tickers->streamHistory($exchange, $symbol, $period, $fromMs, $asOfMs) as $timestamp => $candle) {
            $timestamp = (int) $timestamp;
            if ($previous !== null && $this->timeframe->next($previous, $period) !== $timestamp) {
                $consume();
                $gaps++;
            }
            $run[] = $this->labels->compact($candle, $timestamp);
            $previous = $timestamp;
            $candles++;
        }
        $consume();

        ksort($frequencies, SORT_NUMERIC);
        $observations = array_sum($frequencies);
        $weighted = 0;
        foreach ($frequencies as $distance => $frequency) {
            $weighted += $distance * $frequency;
        }
        $mean = $observations > 0 ? $weighted / $observations : null;
        $median = null;
        if ($observations > 0) {
            $lowerPosition = intdiv($observations - 1, 2);
            $upperPosition = intdiv($observations, 2);
            $seen = 0;
            $lower = null;
            foreach ($frequencies as $distance => $frequency) {
                $seen += $frequency;
                if ($lower === null && $seen > $lowerPosition) {
                    $lower = $distance;
                }
                if ($seen > $upperPosition) {
                    $median = $observations % 2 ? (float) $distance : ($lower + $distance) / 2;
                    break;
                }
            }
        }
        $minimum = max(1, (int) config(
            'intelligence.min_horizon_distance_observations',
            self::DEFAULT_MIN_DISTANCE_OBSERVATIONS
        ));
        $horizon = $observations >= $minimum
            ? max(1, (int) round($mean, 0, PHP_ROUND_HALF_UP))
            : null;

        return [
            'status' => $horizon === null ? 'insufficient_distance_observations' : 'validated',
            'minimum_distance_observations' => $minimum,
            'distance_observations' => $observations,
            'distance_frequencies' => $frequencies,
            'distance_min' => $observations ? array_key_first($frequencies) : null,
            'distance_max' => $observations ? array_key_last($frequencies) : null,
            'distance_mean' => $mean,
            'distance_median' => $median,
            'horizon' => $horizon,
            'formula' => 'H=round(sum(d*freq)/sum(freq))',
            'candles_examined' => $candles,
            'contiguous_runs' => $runs,
            'gaps' => $gaps,
            'pivot_count' => $pivotCount,
            'action_counts' => $actionCounts,
            'from_ms' => $fromMs,
            'as_of_ms' => $asOfMs,
            'max_history_days' => KnowledgeWindow::days(),
            'taker_fee' => $fee,
            '_action_labels' => $actionLabels,
            '_action_available_at_ms' => $actionAvailability,
        ];
    }

    public static function publicDiagnostics(array $analysis): array
    {
        unset($analysis['_action_labels'], $analysis['_action_available_at_ms']);

        return $analysis;
    }

    private function summarizeRun(array &$labelled, float $fee, string $period,
        array &$actionLabels, array &$actionAvailability): array
    {
        $this->labels->labelInPlace($labelled, $fee);
        // Write into the final maps, rather than copying two maps for every run.
        $actionCounts = ['buy' => 0, 'hold' => 0, 'sell' => 0];
        $previousPivot = null;
        $pivotCount = 0;
        $frequencies = [];

        foreach ($labelled as $index => $ticker) {
            $action = $ticker['action'] ?? null;
            $timestamp = $ticker['microtimestamp'] ?? null;
            if (is_int($timestamp) && in_array($action, ['buy', 'hold', 'sell'], true)) {
                $actionLabels[$timestamp] = $action === 'hold' ? 'hodl' : $action;
                $actionCounts[$action]++;
            }
            if (! is_int($timestamp) || ! in_array($action, ['buy', 'sell'], true)) {
                continue;
            }
            $pivotCount++;
            $left = $previousPivot;
            $right = $previousPivot = ['index' => $index, 'timestamp' => $timestamp, 'action' => $action];
            if ($left === null) {
                continue;
            }
            if ($left['action'] === $right['action']) {
                throw new InvalidArgumentException(
                    'Finalized Action pivots must alternate before deriving Outcome horizon.'
                );
            }
            $distance = $right['index'] - $left['index'];
            if ($distance < 1) {
                continue;
            }
            $frequencies[$distance] = ($frequencies[$distance] ?? 0) + 1;

            // Retrospective Action labels inside this completed swing become
            // available only when the opposite pivot candle has closed.
            $availableAt = $this->timeframe->next($right['timestamp'], $period);
            for ($j = $left['index']; $j < $right['index']; $j++) {
                $labelTimestamp = $labelled[$j]['microtimestamp'] ?? null;
                if (is_int($labelTimestamp) && isset($actionLabels[$labelTimestamp])) {
                    $actionAvailability[$labelTimestamp] = $availableAt;
                }
            }
        }

        return [
            'action_counts' => $actionCounts,
            'distance_frequencies' => $frequencies,
            'pivot_count' => $pivotCount,
        ];
    }
}
