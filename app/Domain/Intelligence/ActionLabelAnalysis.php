<?php

namespace App\Domain\Intelligence;

use App\Domain\MarketData\CandleTimeframe;
use App\Domain\Operations\ActionLog;
use App\Repositories\TickerRepository;
use Closure;
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

    public function analyze(string $exchange, string $symbol, string $period, int $asOfMs, ?Closure $emit = null): array
    {
        $fields = ['exchange' => $exchange, 'symbol' => $symbol, 'period' => $period, 'stage' => 'action_labeling'];
        app(ActionLog::class)->write('intelligence.action_labels.started', [
            ...$fields, 'memory_bytes' => memory_get_usage(true),
        ]);
        $fromMs = KnowledgeWindow::trainingFromMs();
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
        $run = $emit === null ? [] : new ActionCandleBuffer;

        $consume = function () use (
            &$run, &$runs, &$actionLabels, &$actionAvailability, &$actionCounts,
            &$frequencies, &$pivotCount, $fee, $period, $fields, $emit
        ): void {
            if (count($run) === 0) {
                return;
            }
            $runs++;
            app(ActionLog::class)->write('intelligence.action_labels.run', [
                ...$fields, 'rows' => count($run), 'memory_bytes' => memory_get_usage(true),
            ]);
            $summary = $emit === null
                ? $this->summarizeRun($run, $fee, $period, $actionLabels, $actionAvailability)
                : $this->streamRun($run, $fee, $period, $emit);
            $run = $emit === null ? [] : new ActionCandleBuffer;
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
            if ($candles % 1024 === 0) {
                TrainingRuntime::heartbeat();
            }
            if ($previous !== null && $this->timeframe->next($previous, $period) !== $timestamp) {
                $consume();
                $gaps++;
            }
            $run[] = $this->labels->compact($candle, $timestamp);
            $previous = $timestamp;
            $candles++;
        }
        $consume();

        return $this->diagnostics(compact('frequencies', 'actionCounts', 'pivotCount', 'candles', 'gaps', 'runs',
            'actionLabels', 'actionAvailability'), $asOfMs, $fee);
    }

    private function diagnostics(array $state, int $asOfMs, float $fee): array
    {
        ['frequencies' => $frequencies, 'actionCounts' => $actionCounts, 'pivotCount' => $pivotCount,
            'candles' => $candles, 'gaps' => $gaps, 'runs' => $runs] = $state;
        $actionLabels = $state['actionLabels'] ?? [];
        $actionAvailability = $state['actionAvailability'] ?? [];
        $fromMs = KnowledgeWindow::trainingFromMs();
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
            'max_history_days' => null,
            'history_policy' => KnowledgeWindow::TRAINING_POLICY,
            'taker_fee' => $fee,
            '_action_labels' => $actionLabels,
            '_action_available_at_ms' => $actionAvailability,
        ];
    }

    /** Resume source ingestion, each Action pass, and chronological emission independently. */
    public function analyzeResumable(string $exchange, string $symbol, string $period, int $asOfMs,
        Closure $emit, BuildCheckpoint $checkpoint): array
    {
        $fee = $this->takerFees->for($exchange, $symbol);
        if ($fee === null) {
            throw new InvalidArgumentException('Published taker fee is unavailable for Action auto-labeling.');
        }
        $checkpoint->state['analysis'] ??= ['frequencies' => [],
            'actionCounts' => ['buy' => 0, 'hold' => 0, 'sell' => 0],
            'pivotCount' => 0, 'candles' => 0, 'gaps' => 0, 'runs' => 0, 'previous' => null];
        $state = &$checkpoint->state['analysis'];
        $run = new ActionCandleBuffer($checkpoint);
        while ($checkpoint->state['stage'] !== 'actions_done') {
            if ($checkpoint->state['stage'] === 'action_read') {
                $complete = true;
                foreach ($this->tickers->streamHistory($exchange, $symbol, $period,
                    $state['previous'] === null ? KnowledgeWindow::trainingFromMs() : $state['previous'] + 1, $asOfMs) as $timestamp => $candle) {
                    $timestamp = (int) $timestamp;
                    if ($state['previous'] !== null && $this->timeframe->next($state['previous'], $period) !== $timestamp) {
                        if (count($run) > 0) {
                            $complete = false;
                            break;
                        }
                        $state['gaps']++;
                    }
                    $run[] = $this->labels->compact($candle, $timestamp);
                    $state['previous'] = $timestamp;
                    $state['candles']++;
                    $checkpoint->state['processed'] = $state['candles'];
                    if ($checkpoint->due()) {
                        $checkpoint->save();
                    }
                }
                $state['source_complete'] = $complete;
                $checkpoint->state['stage'] = count($run) > 0 ? 'action_passes' : 'actions_done';
                $checkpoint->state['action_pass'] = 0;
                $checkpoint->state['action_pass_state'] = [];
                $checkpoint->save();
            }
            if ($checkpoint->state['stage'] === 'action_passes') {
                $this->labels->labelInPlace($run, $fee, $checkpoint);
                $state['emission'] = ['scan' => 0, 'segment' => 0, 'previous' => null, 'until' => null, 'available' => null];
                $checkpoint->state['stage'] = 'action_emit';
                $checkpoint->save();
            }
            if ($checkpoint->state['stage'] === 'action_emit') {
                $cursor = &$state['emission'];
                while (true) {
                    if ($cursor['until'] !== null) {
                        while ($cursor['segment'] < $cursor['until']) {
                            $row = $run[$cursor['segment']++];
                            $action = $row['action'] ?? null;
                            if (in_array($action, ['buy', 'hold', 'sell'], true)) {
                                $state['actionCounts'][$action]++;
                                $emit($row['microtimestamp'], $action === 'hold' ? 'hodl' : $action, $cursor['available']);
                            }
                            if ($checkpoint->due()) {
                                $checkpoint->save();
                            }
                        }
                        $cursor['until'] = null;
                    }
                    if ($cursor['scan'] >= count($run)) {
                        if ($cursor['segment'] < count($run)) {
                            $cursor['until'] = count($run);
                            $cursor['available'] = null;

                            continue;
                        }
                        break;
                    }
                    $index = $cursor['scan']++;
                    $row = $run[$index];
                    $action = $row['action'] ?? null;
                    if (in_array($action, ['buy', 'sell'], true)) {
                        $state['pivotCount']++;
                        $cursor['until'] = $index;
                        $cursor['available'] = null;
                        if ($cursor['previous'] !== null) {
                            if ($cursor['previous']['action'] === $action) {
                                throw new InvalidArgumentException('Finalized Action pivots must alternate before deriving Outcome horizon.');
                            }
                            $distance = $index - $cursor['previous']['index'];
                            $state['frequencies'][$distance] = ($state['frequencies'][$distance] ?? 0) + 1;
                            $cursor['available'] = $this->timeframe->next($row['microtimestamp'], $period);
                        }
                        $cursor['previous'] = ['index' => $index, 'action' => $action];
                    }
                    if ($checkpoint->due()) {
                        $checkpoint->save();
                    }
                }
                $state['runs']++;
                unset($state['emission']);
                $checkpoint->state['stage'] = $state['source_complete'] ? 'actions_done' : 'action_read';
                $run = new ActionCandleBuffer($checkpoint, restore: false);
                $checkpoint->save();
            }
        }
        $checkpoint->onSave('action-candles', null);

        return $this->diagnostics($state, $asOfMs, $fee);
    }

    public static function publicDiagnostics(array $analysis): array
    {
        unset($analysis['_action_labels'], $analysis['_action_available_at_ms']);

        return $analysis;
    }

    /** Emit complete swings chronologically without retaining a pending timestamp list. */
    private function streamRun(ActionCandleBuffer $run, float $fee, string $period, Closure $emit): array
    {
        $this->labels->labelInPlace($run, $fee);
        $counts = ['buy' => 0, 'hold' => 0, 'sell' => 0];
        $frequencies = [];
        $previous = null;
        $segmentStart = 0;
        $pivots = 0;
        $emitSegment = function (int $until, ?int $availableAt) use ($run, $emit, &$segmentStart, &$counts): void {
            for (; $segmentStart < $until; $segmentStart++) {
                $row = $run[$segmentStart];
                $action = $row['action'] ?? null;
                if (in_array($action, ['buy', 'hold', 'sell'], true)) {
                    $counts[$action]++;
                    $emit($row['microtimestamp'], $action === 'hold' ? 'hodl' : $action, $availableAt);
                }
            }
        };
        foreach ($run as $index => $row) {
            if ($index % 1024 === 0) {
                TrainingRuntime::heartbeat();
            }
            $action = $row['action'] ?? null;
            if (! in_array($action, ['buy', 'sell'], true)) {
                continue;
            }
            $pivots++;
            if ($previous === null) {
                $emitSegment($index, null);
            } else {
                if ($previous['action'] === $action) {
                    throw new InvalidArgumentException('Finalized Action pivots must alternate before deriving Outcome horizon.');
                }
                $distance = $index - $previous['index'];
                $frequencies[$distance] = ($frequencies[$distance] ?? 0) + 1;
                $emitSegment($index, $this->timeframe->next($row['microtimestamp'], $period));
            }
            $previous = ['index' => $index, 'action' => $action];
        }
        $emitSegment(count($run), null);

        return ['action_counts' => $counts, 'distance_frequencies' => $frequencies, 'pivot_count' => $pivots];
    }

    private function summarizeRun(array &$labelled, float $fee, string $period,
        array &$actionLabels, array &$actionAvailability): array
    {
        $this->labels->labelInPlace($labelled, $fee);
        // Write into the final maps, rather than copying two maps for every run.
        $actionCounts = ['buy' => 0, 'hold' => 0, 'sell' => 0];
        $previousPivot = null;
        $pendingAvailability = [];
        $pivotCount = 0;
        $frequencies = [];

        // Label passes retain the original contiguous indices. Free each
        // finalized candle before the two full-history output maps grow.
        $count = count($labelled);
        for ($index = 0; $index < $count; $index++) {
            $ticker = $labelled[$index];
            unset($labelled[$index]);
            $action = $ticker['action'] ?? null;
            $timestamp = $ticker['microtimestamp'] ?? null;
            $hasLabel = is_int($timestamp) && in_array($action, ['buy', 'hold', 'sell'], true);
            if ($hasLabel) {
                $actionLabels[$timestamp] = $action === 'hold' ? 'hodl' : $action;
                $actionCounts[$action]++;
            }
            if (! is_int($timestamp) || ! in_array($action, ['buy', 'sell'], true)) {
                if ($hasLabel && $previousPivot !== null) {
                    $pendingAvailability[] = $timestamp;
                }

                continue;
            }
            $pivotCount++;
            $left = $previousPivot;
            $right = $previousPivot = ['index' => $index, 'timestamp' => $timestamp, 'action' => $action];
            if ($left === null) {
                $pendingAvailability = [$timestamp];

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
            foreach ($pendingAvailability as $labelTimestamp) {
                $actionAvailability[$labelTimestamp] = $availableAt;
            }
            $pendingAvailability = [$timestamp];
        }

        return [
            'action_counts' => $actionCounts,
            'distance_frequencies' => $frequencies,
            'pivot_count' => $pivotCount,
        ];
    }
}
