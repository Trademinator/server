<?php

namespace App\Domain\Intelligence;

use App\Domain\Features\FeatureEngine;
use App\Domain\Research\DatasetRows;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\SemanticLabels;
use App\Models\HumanCandleLabel;
use App\Models\HumanTrainingSnapshot;
use App\Models\User;
use App\Traits\CandleAutoDetection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function Trademinator\BcMath\bcconv;
use function Trademinator\BcMath\bcdec;

final class CandleTraining
{
    use CandleAutoDetection;

    public const ACTIONS = ['buy', 'hold', 'sell'];

    public const PAGE_SIZE = 50;

    public const SUBMIT_BATCH_SIZE = 50;

    public const AUTO_LABEL_BATCH_SIZE = 50;

    public function __construct(
        private DatasetStore $datasets,
        private HumanTraining $snapshots,
        private ActionAutoLabeler $autoLabeler,
        private PublishedTakerFee $takerFees,
        private ActionLabelReportStore $actionReports,
    ) {}

    public static function submissionLimit(): int
    {
        return max(1, (int) (config('human_training.candle_max_changes') ?? 3000));
    }

    public function count(User $trainer): int
    {
        Gate::forUser($trainer)->authorize('train-intelligence');

        return HumanCandleLabel::query()->where('trainer_id', $trainer->user_id)->count();
    }

    /** The start POST only chooses its redirect; the GET prepares the chart. */
    public function start(User $trainer, string $dataset): int
    {
        Gate::forUser($trainer)->authorize('train-intelligence');
        [$manifest, $rows] = $this->load($dataset);

        return (int) $this->unseenSnapshot($trainer, $manifest, $rows)['row']['decision_at_ms'];
    }

    public function review(User $trainer, string $dataset, ?int $decisionAtMs = null): array
    {
        Gate::forUser($trainer)->authorize('train-intelligence');
        [$manifest, $rows] = $this->load($dataset);
        if ($decisionAtMs === null) {
            $candidate = $this->unseenSnapshot($trainer, $manifest, $rows);
            $row = $candidate['row'];
            $snapshot = $candidate['snapshot'];
        } else {
            $row = $rows->findDecision($decisionAtMs);
            if ($row === null) {
                throw ValidationException::withMessages(['decision_at_ms' => 'Choose a candle contained in this dataset.']);
            }
            $snapshot = $this->snapshots->snapshotForRow($manifest, $row);
        }
        if ($snapshot === null) {
            throw CandleTrainingRecovery::exception($trainer, $manifest, 'decision_at_ms',
                'That candle no longer matches the current source history and cannot be used for training.');
        }
        $payload = $snapshot->verifiedPayload();
        $rowIndex = $rows->indexOfDecision($row['decision_at_ms']);
        $first = $rows->at(0);
        $payload['series'] = array_values(array_filter($payload['series'],
            fn (array $candle): bool => $first['microtimestamp'] <= $candle['time'] * 1000));
        $chart = $this->chartData($trainer, $manifest, $rows, $payload['series']);
        $label = HumanCandleLabel::query()->where('snapshot_id', $snapshot->snapshot_id)
            ->where('trainer_id', $trainer->user_id)->first();

        return ['manifest' => $manifest, 'snapshot' => $snapshot, 'payload' => $payload,
            'label' => $label, 'review_required' => $label === null && ($payload['revision']['requires_review'] ?? false), 'visible_labels' => $chart['labels'], 'auto_labels' => $chart['auto_labels'], 'decisions' => $chart['decisions'],
            'allowed_actions' => $chart['allowed_actions'],
            'earliest_time' => intdiv($first['microtimestamp'], 1000),
            'earliest_window_decision_at_ms' => $rows->at(min(count($rows) - 1,
                max(0, (int) config('human_training.chart_candles') - 1)))['decision_at_ms'],
            'latest_decision_at_ms' => $rows->at(count($rows) - 1)['decision_at_ms'],
            'has_more' => $payload['series'][0]['time'] * 1000 > $first['microtimestamp'],
            'label_stats' => $this->labelStats($trainer, $manifest),
            'automatic_label_stats' => $this->automaticLabelStats($manifest),
            'taker_fee' => $this->takerFee($manifest),
            'previous_decision_at_ms' => is_int($rowIndex) && $rowIndex > 0 ? $rows->at(max(0, $rowIndex - self::PAGE_SIZE))['decision_at_ms'] : null,
            'next_decision_at_ms' => is_int($rowIndex) && $rowIndex + 1 < count($rows) ? $rows->at(min(count($rows) - 1, $rowIndex + self::PAGE_SIZE))['decision_at_ms'] : null];
    }

    /** A bounded page of older candles, with only this trainer's compatible labels. */
    public function history(User $trainer, string $dataset, int $decisionAtMs, int $beforeMs): array
    {
        Gate::forUser($trainer)->authorize('train-intelligence');
        [$manifest, $rows] = $this->load($dataset);
        $replay = $rows->findDecision($decisionAtMs);
        if ($replay === null || $beforeMs > $replay['microtimestamp']) {
            throw ValidationException::withMessages(['before_ms' => 'Choose history before the current replay candle.']);
        }
        $candidate = $rows->before($beforeMs, $decisionAtMs);
        if ($candidate === null) {
            return ['series' => [], 'labels' => [], 'auto_labels' => [], 'decisions' => [], 'allowed_actions' => [], 'has_more' => false];
        }
        $snapshot = $this->snapshots->snapshotForRow($manifest, $candidate);
        if ($snapshot === null) {
            throw CandleTrainingRecovery::exception($trainer, $manifest, 'before_ms');
        }
        $first = $rows->at(0);
        $series = array_slice(array_values(array_filter($snapshot->verifiedPayload()['series'],
            fn (array $candle): bool => $beforeMs > $candle['time'] * 1000
                && $first['microtimestamp'] <= $candle['time'] * 1000)), -self::PAGE_SIZE);

        return ['series' => $series, ...$this->chartData($trainer, $manifest, $rows, $series),
            'has_more' => $series !== [] && $series[0]['time'] * 1000 > $first['microtimestamp']];
    }

    /** Advance only within the immutable dataset selected when the page opened. */
    public function nextHistory(User $trainer, string $dataset, int $decisionAtMs, int $afterMs): array
    {
        Gate::forUser($trainer)->authorize('train-intelligence');
        [$manifest, $rows] = $this->load($dataset);
        $replayIndex = $rows->indexOfDecision($decisionAtMs);
        if ($replayIndex === null || $rows->at($replayIndex)['microtimestamp'] !== $afterMs) {
            throw ValidationException::withMessages(['after_ms' => 'Continue from the last loaded replay candle.']);
        }
        if ($replayIndex === count($rows) - 1) {
            return ['series' => [], 'labels' => [], 'auto_labels' => [], 'decisions' => [], 'allowed_actions' => [],
                'decision_at_ms' => $decisionAtMs, 'has_more' => false];
        }
        $pageSize = min(self::PAGE_SIZE, max(1, (int) config('human_training.chart_candles')));
        $nextIndex = min(count($rows) - 1, $replayIndex + $pageSize);
        $row = $rows->at($nextIndex);
        $snapshot = $this->snapshots->snapshotForRow($manifest, $row);
        if ($snapshot === null) {
            throw CandleTrainingRecovery::exception($trainer, $manifest, 'after_ms');
        }
        $series = array_values(array_filter($snapshot->verifiedPayload()['series'],
            fn (array $candle): bool => $afterMs < $candle['time'] * 1000));

        return ['series' => $series, ...$this->chartData($trainer, $manifest, $rows, $series),
            'decision_at_ms' => $row['decision_at_ms'],
            'has_more' => $nextIndex < count($rows) - 1,
            'previous_decision_at_ms' => $rows->at(max(0, $nextIndex - self::PAGE_SIZE))['decision_at_ms'],
            'next_decision_at_ms' => $nextIndex < count($rows) - 1
                ? $rows->at(min(count($rows) - 1, $nextIndex + self::PAGE_SIZE))['decision_at_ms'] : null];
    }

    public function save(User $trainer, string $dataset, int $decisionAtMs, string $action): HumanCandleLabel
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw ValidationException::withMessages(['action' => 'Choose BUY, HOLD or SELL.']);
        }
        $state = $this->review($trainer, $dataset, $decisionAtMs);
        $time = intdiv($state['payload']['microtimestamp'], 1000);
        if (! in_array($action, $state['allowed_actions'][(string) $time] ?? [], true)) {
            throw ValidationException::withMessages(['action' => 'BUY requires a red candle (open > close); SELL requires a green candle (open < close). HOLD is allowed on any candle.']);
        }

        return DB::transaction(function () use ($trainer, $state, $action): HumanCandleLabel {
            $label = HumanCandleLabel::query()->where('snapshot_id', $state['snapshot']->snapshot_id)
                ->where('trainer_id', $trainer->user_id)->lockForUpdate()->first();
            if ($label === null) {
                $label = new HumanCandleLabel;
                $label->forceFill(['snapshot_id' => $state['snapshot']->snapshot_id, 'trainer_id' => $trainer->user_id,
                    'action' => $action]);
                $label->save();

                return $label;
            }
            $label->forceFill(['action' => $action])->save();

            return $label;
        });
    }

    public function delete(User $trainer, string $dataset, int $decisionAtMs): void
    {
        $state = $this->review($trainer, $dataset, $decisionAtMs);
        HumanCandleLabel::query()->where('snapshot_id', $state['snapshot']->snapshot_id)
            ->where('trainer_id', $trainer->user_id)->delete();
    }

    /**
     * Preserve the complete chronological context without retaining full OHLCV
     * rows and ten pattern fields per candle. Classification is candle-local;
     * subsequent auto-label passes use only CLOSE and these three flags.
     * This remains O(n) state, but avoids the large expanded history that can
     * exhaust a 128 MiB request before response pagination even begins.
     *
     * @return list<array{close: mixed, 'is_black()': int, 'is_white()': int, 'is_super_doji()': int}>
     */
    private function autoLabelContext(DatasetRows $rows): array
    {
        $tickers = [];
        // Some low-level memory/regression tests intentionally instantiate this
        // class without its container constructor; keep this pure helper usable.
        $labeler = isset($this->autoLabeler) ? $this->autoLabeler : new ActionAutoLabeler;
        foreach ($rows as $row) {
            $candle = $row['candle'] ?? null;
            if (! is_array($candle) || array_diff(['open', 'high', 'low', 'close', 'volume'], array_keys($candle)) !== []) {
                throw ValidationException::withMessages(['dataset' => 'This frozen dataset is missing candle values required for auto-labeling.']);
            }

            $tickers[] = $labeler->compact($candle);
        }

        return $tickers;
    }

    /** Build retrospective BUY/HOLD/SELL suggestions without storing any label. */
    public function autoLabels(User $trainer, string $dataset, bool $includeExisting = false, int $offset = 0): array
    {
        Gate::forUser($trainer)->authorize('train-intelligence');
        [$manifest, $rows] = $this->load($dataset);
        if ($offset < 0 || $offset >= count($rows)) {
            throw ValidationException::withMessages(['offset' => 'The auto-label position is outside this frozen dataset.']);
        }
        $takerFee = $this->takerFee($manifest);
        if ($takerFee === null) {
            throw ValidationException::withMessages([
                'dataset' => 'Auto-label requires a published exchange taker fee so unprofitable movements can be removed.',
            ]);
        }

        $tickers = $this->autoLabelContext($rows);

        $this->autoLabeler->labelInPlace($tickers, $takerFee);

        // Keep the full algorithm context, but verify only one chronological
        // page per request. Snapshot UUIDs reflect review order, not candle time.
        $rowBatch = $rows->slice($offset, self::AUTO_LABEL_BATCH_SIZE);
        $tickers = array_slice($tickers, $offset, count($rowBatch));
        $processed = $offset + count($rowBatch);
        $existing = collect();
        if (! $includeExisting) {
            $labelled = HumanTrainingSnapshot::query()
                ->select(['snapshot_id', 'decision_at_ms', 'sha256', 'payload'])
                ->where('market_key', ModelStore::marketKey($manifest['exchange'], $manifest['symbol'], $manifest['period']))
                ->where('version', HumanTraining::VERSION)
                ->whereIn('decision_at_ms', array_column($rowBatch, 'decision_at_ms'))
                ->whereHas('candleLabels', fn ($query) => $query->where('trainer_id', $trainer->user_id))
                ->lazyById(50, 'snapshot_id');
            foreach ($labelled->chunk(50) as $batch) {
                $compatible = $this->snapshots->compatibleSnapshotIds($manifest, $batch, $rowBatch);
                foreach ($batch as $snapshot) {
                    if (isset($compatible[$snapshot->snapshot_id])) {
                        $existing->put($snapshot->decision_at_ms, true);
                    }
                }
            }
        }

        $labels = [];
        foreach ($tickers as $index => $ticker) {
            $row = $rowBatch[$index];
            $action = $ticker['action'] ?? null;
            if (! in_array($action, self::ACTIONS, true) || $existing->has($row['decision_at_ms'])) {
                continue;
            }
            $labels[] = [
                'time' => intdiv((int) $row['microtimestamp'], 1000),
                'decision_at_ms' => (int) $row['decision_at_ms'],
                'action' => $action,
            ];
        }

        return ['labels' => $labels, 'count' => count($labels), 'processed' => $processed,
            'total' => count($rows), 'next_offset' => $processed < count($rows) ? $processed : null];
    }

    /**
     * Persist the browser-reviewed final delta in one transaction. Until this
     * method is called, manual clicks, auto-label suggestions and bulk deletion
     * exist only in the browser and cannot influence a model build.
     */
    public function submitLabels(User $trainer, string $dataset, array $changes, bool $deleteAll = false): array
    {
        Gate::forUser($trainer)->authorize('train-intelligence');
        [$manifest, $rows] = $this->load($dataset);
        if (count($changes) > self::submissionLimit()) {
            throw ValidationException::withMessages(['changes' => 'Too many candle-label changes were submitted at once.']);
        }

        $prepared = [];
        foreach ($changes as $change) {
            $decision = (int) ($change['decision_at_ms'] ?? 0);
            $action = $change['action'] ?? null;
            $row = $rows->findDecision($decision);
            if ($row === null || ($action !== null && ! in_array($action, self::ACTIONS, true))) {
                throw ValidationException::withMessages(['changes' => 'A staged candle label no longer belongs to this frozen dataset.']);
            }
            if ($action !== null && ! in_array($action, $this->allowedActions($row['candle']), true)) {
                throw ValidationException::withMessages([
                    'changes' => 'BUY requires a red candle; SELL requires a green candle; HOLD is allowed on any candle.',
                ]);
            }
            $prepared[$decision] = ['row' => $row, 'action' => $action];
        }

        // Verify staged changes in bounded chronological batches before label
        // writes; retain IDs rather than thousands of hydrated chart payloads.
        $snapshotRows = [];
        foreach ($prepared as $decision => $change) {
            $snapshotRows[$decision] = $change['row'];
        }
        $snapshotIds = $this->snapshots->snapshotIdsForRows($manifest, array_values($snapshotRows));
        foreach ($snapshotRows as $decision => $_row) {
            if (($snapshotIds[$decision] ?? null) === null) {
                throw CandleTrainingRecovery::exception($trainer, $manifest, 'changes',
                    'A staged candle no longer matches the current source history and cannot be submitted.');
            }
        }

        $marketKey = ModelStore::marketKey($manifest['exchange'], $manifest['symbol'], $manifest['period']);
        $saved = DB::transaction(function () use ($trainer, $prepared, $deleteAll, $marketKey, $snapshotIds): int {
            if ($deleteAll) {
                HumanCandleLabel::query()->where('trainer_id', $trainer->user_id)
                    ->whereHas('snapshot', fn ($query) => $query->where('market_key', $marketKey)
                        ->where('version', HumanTraining::VERSION))
                    ->delete();
            } else {
                $deleteIds = [];
                foreach ($prepared as $decision => $change) {
                    if ($change['action'] === null) {
                        $deleteIds[] = $snapshotIds[$decision];
                    }
                }
                foreach (array_chunk($deleteIds, 500) as $chunk) {
                    HumanCandleLabel::query()->where('trainer_id', $trainer->user_id)
                        ->whereIn('snapshot_id', $chunk)->delete();
                }
            }

            $now = now()->format('Y-m-d H:i:s.v');
            $upserts = [];
            foreach ($prepared as $decision => $change) {
                if ($change['action'] === null) {
                    continue;
                }
                $snapshotId = $snapshotIds[$decision];
                $upserts[] = [
                    'candle_label_id' => (string) Str::uuid7(),
                    'snapshot_id' => $snapshotId,
                    'trainer_id' => $trainer->user_id,
                    'action' => $change['action'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($upserts, 500) as $chunk) {
                HumanCandleLabel::query()->upsert(
                    $chunk,
                    ['snapshot_id', 'trainer_id'],
                    ['action', 'updated_at']
                );
            }

            return count($upserts);
        });

        return ['saved' => $saved, 'deleted_all' => $deleteAll, 'stats' => $this->labelStats($trainer, $manifest)];
    }

    private function chartData(User $trainer, array $manifest, DatasetRows $rows, array $series): array
    {
        $decisions = [];
        $allowedActions = [];
        $visibleTimes = array_flip(array_column($series, 'time'));
        $available = $rows->forTimestamps(array_map(fn (array $candle): int => $candle['time'] * 1000, $series));
        foreach ($series as $candle) {
            $allowedActions[(string) $candle['time']] = $this->allowedActions($candle);
            $microtimestamp = $candle['time'] * 1000;
            if (isset($available[$microtimestamp])) {
                $decisions[(string) $candle['time']] = $available[$microtimestamp]['decision_at_ms'];
            }
        }
        $decisionValues = array_values($decisions);
        $fromDecision = $decisionValues === [] ? 0 : min($decisionValues);
        $visibleLabels = HumanTrainingSnapshot::query()
            ->where('market_key', ModelStore::marketKey($manifest['exchange'], $manifest['symbol'], $manifest['period']))
            ->where('version', HumanTraining::VERSION)
            ->whereBetween('decision_at_ms', [$fromDecision, $decisionValues === [] ? 0 : max($decisionValues)])
            ->whereHas('candleLabels', fn ($query) => $query->where('trainer_id', $trainer->user_id))
            ->with(['candleLabels' => fn ($query) => $query->where('trainer_id', $trainer->user_id)])
            ->orderBy('decision_at_ms')->get();
        $compatible = $this->snapshots->compatibleSnapshotIds($manifest, $visibleLabels, array_values($available));
        $visibleLabels = $visibleLabels->filter(fn (HumanTrainingSnapshot $snapshot): bool => isset($compatible[$snapshot->snapshot_id]))
            ->map(function (HumanTrainingSnapshot $item) use ($available, $manifest, $visibleTimes): ?array {
                $label = $item->candleLabels->first();
                if ($label === null || ! in_array($label->action, self::ACTIONS, true)) {
                    return null;
                }
                $itemPayload = $item->verifiedPayload();
                $source = $available[$itemPayload['microtimestamp']] ?? null;
                if (! isset($visibleTimes[intdiv($itemPayload['microtimestamp'], 1000)])
                    || $source === null || $itemPayload['decision_at_ms'] !== $source['decision_at_ms']
                    || ($itemPayload['feature_version'] ?? null) !== $manifest['feature_version']
                    || ($itemPayload['keys'] ?? null) !== $manifest['keys']
                    || ($itemPayload['normalization'] ?? null) !== NormalizedVector::VERSION
                    || ($itemPayload['horizon_candles'] ?? null) !== $manifest['label_definition']['horizon']
                    || ($itemPayload['vector'] ?? null) != NormalizedVector::from($source['vector'], $manifest['keys'])
                    || ($itemPayload['feature_sha256'] ?? null) !== ($source['source']['feature_sha256'] ?? null)) {
                    return null;
                }

                return ['time' => intdiv($itemPayload['microtimestamp'], 1000), 'action' => $label->action];
            })->filter()->unique('time')->values()->all();

        $autoLabels = [];
        foreach ($available as $row) {
            $action = $row['action_label'] ?? null;
            if ($action === 'hodl') {
                $action = 'hold';
            }
            if (in_array($action, self::ACTIONS, true)) {
                $autoLabels[] = ['time' => intdiv((int) $row['microtimestamp'], 1000), 'action' => $action];
            }
        }

        return ['labels' => $visibleLabels, 'auto_labels' => $autoLabels, 'decisions' => $decisions, 'allowed_actions' => $allowedActions];
    }

    /** @return list<string> */
    private function allowedActions(array $candle): array
    {
        $open = bcconv($candle['open']);
        $close = bcconv($candle['close']);
        $direction = bccomp($close, $open, max(2, bcdec([$open, $close])));

        return match ($direction) {
            -1 => ['buy', 'hold'],
            1 => ['hold', 'sell'],
            default => ['hold'],
        };
    }

    private function load(string $dataset): array
    {
        [$manifest, $rows] = $this->datasets->open($dataset);
        if (($manifest['feature_version'] ?? null) !== FeatureEngine::VERSION
            || ($manifest['label_definition']['version'] ?? null) !== SemanticLabels::VERSION
            || ($manifest['as_of_ms'] ?? PHP_INT_MAX) > now()->getTimestampMs()
            || count($rows) === 0) {
            throw ValidationException::withMessages(['dataset' => 'Choose a current semantic dataset built from closed candles.']);
        }

        return [$manifest, $rows];
    }

    /** @return array{row: array, snapshot: HumanTrainingSnapshot} */
    private function unseenSnapshot(User $trainer, array $manifest, DatasetRows $rows): array
    {
        $candidate = $this->snapshots->candidateSnapshot($trainer, $manifest, $rows, 'candleLabels', allowReviewed: true);
        if ($candidate !== null) {
            return $candidate;
        }

        throw ValidationException::withMessages(['dataset' => 'No intact candle was found in this bounded search. Retry, choose another dataset or collect more history.']);
    }

    /** Latest shared market analysis, independent of this immutable chart dataset. */
    private function automaticLabelStats(array $manifest): array
    {
        $published = $this->actionReports->latest($manifest['exchange'], $manifest['symbol'], $manifest['period']);
        $raw = $published['analysis']['action_counts'] ?? null;
        $available = is_array($raw) && array_diff(self::ACTIONS, array_keys($raw)) === [];
        $counts = array_fill_keys(self::ACTIONS, 0);
        if ($available) {
            foreach (self::ACTIONS as $action) {
                $counts[$action] = max(0, (int) $raw[$action]);
            }
        }

        return [
            'available' => $available, 'counts' => $counts, 'total' => array_sum($counts),
            'as_of_ms' => $available ? $published['as_of_ms'] : null,
            'computed_at' => $available ? $published['computed_at'] : null,
            'source' => $available ? $published['source'] : null,
            'dataset_id' => $available ? $published['dataset_id'] : null,
        ];
    }

    /** Counts this trainer's distinct recorded candle opinions, not model eligibility. */
    private function labelStats(User $trainer, array $manifest): array
    {
        $counts = array_fill_keys(self::ACTIONS, 0);
        // Count a trainer/candle once across revisions without loading chart payloads.
        // These are recorded-opinion milestones, not current KNN eligibility counts.
        $ranked = DB::table('human_candle_labels as labels')
            ->join('human_training_snapshots as snapshots', 'snapshots.snapshot_id', '=', 'labels.snapshot_id')
            ->where('labels.trainer_id', $trainer->user_id)
            ->where('snapshots.market_key', ModelStore::marketKey($manifest['exchange'], $manifest['symbol'], $manifest['period']))
            ->where('snapshots.version', HumanTraining::VERSION)->whereIn('labels.action', self::ACTIONS)
            ->select('labels.action')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY snapshots.decision_at_ms ORDER BY labels.updated_at DESC, labels.candle_label_id DESC) AS opinion_rank');
        $stored = DB::query()->fromSub($ranked, 'recorded_opinions')->where('opinion_rank', 1)
            ->selectRaw('action, COUNT(*) AS aggregate')->groupBy('action')->pluck('aggregate', 'action');
        foreach (self::ACTIONS as $action) {
            $counts[$action] = (int) ($stored[$action] ?? 0);
        }
        $total = array_sum($counts);
        $percentages = [];
        foreach ($counts as $action => $count) {
            $percentages[$action] = $total === 0 ? 0.0 : round($count * 100 / $total, 1);
        }
        $minimum = min($counts);

        return [
            'counts' => $counts,
            'percentages' => $percentages,
            'total' => $total,
            'recorded_distinct_candles' => $total,
            'sampling' => 'all_eligible_distinct_candles',
            // Kept as a legacy UI statistic, never used to cap training.
            'balanced_per_action' => $minimum,
            'balanced_samples' => $minimum * count(self::ACTIONS),
            'least_represented' => array_values(array_keys(array_filter($counts, fn (int $count): bool => $count === $minimum))),
        ];
    }

    /** Published CCXT spot taker fee used by the shared Action auto-labeler. */
    private function takerFee(array $manifest): ?float
    {
        return $this->takerFees->for($manifest['exchange'], $manifest['symbol']);
    }
}
