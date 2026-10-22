<?php

namespace App\Domain\Intelligence;

use Illuminate\Support\Facades\DB;

/** Distinguishes availability of the four labeling sources from model readiness. */
final class KnowledgeLabelSources
{
    /** @return array<string, array{state: string, detail: string, through_ms: ?int}> */
    public function describe(string $exchange, string $symbol, string $period): array
    {
        $scope = compact('exchange', 'symbol', 'period');
        $latest = DB::table('algorithmic_label_sets')->where($scope)
            ->where('history_revision', app(AlgorithmicLabelSets::class)->revision($exchange, $symbol, $period))
            ->where('action_version', AlgorithmicLabelSets::ACTION_VERSION)
            ->where('outcome_version', \App\Domain\Research\SemanticLabels::VERSION)
            ->where('profile_sha256', app(AlgorithmicLabelSets::class)->profile($exchange, $symbol))
            ->orderByDesc('as_of_ms')->first();
        $set = $latest === null ? null : app(AlgorithmicLabelSets::class)
            ->find($exchange, $symbol, $period, (int) $latest->as_of_ms);
        $actionRows = count($set['action_labels'] ?? []);
        $outcomeRows = count($set['outcome_labels'] ?? []);
        $marketKey = ModelStore::marketKey($exchange, $symbol, $period);
        $humanAction = DB::table('human_candle_labels as labels')
            ->join('human_training_snapshots as snapshots', 'snapshots.snapshot_id', '=', 'labels.snapshot_id')
            ->where('snapshots.market_key', $marketKey)->where('snapshots.version', HumanTraining::VERSION)
            ->selectRaw('COUNT(*) as labels, MAX(snapshots.decision_at_ms) as through_ms')->first();
        $humanOutcome = DB::table('human_training_reviews as reviews')
            ->join('human_training_snapshots as snapshots', 'snapshots.snapshot_id', '=', 'reviews.snapshot_id')
            ->where('snapshots.market_key', $marketKey)->where('snapshots.version', HumanTraining::VERSION)
            ->whereNotNull('reviews.submitted_at')->whereIn('reviews.label', HumanTraining::acceptedLabels())
            ->selectRaw('COUNT(*) as labels, MAX(snapshots.decision_at_ms) as through_ms')->first();

        $independent = DB::table('independent_human_annotations')->where($scope)
            ->selectRaw('source, COUNT(*) as labels, MAX(microtimestamp) as through_ms')
            ->groupBy('source')->get()->keyBy('source');
        $directAction = $independent->get('human_action');
        $directOutcome = $independent->get('human_outcome');
        $actionCount = (int) ($humanAction->labels ?? 0) + (int) ($directAction?->labels ?? 0);
        $outcomeCount = (int) ($humanOutcome->labels ?? 0) + (int) ($directOutcome?->labels ?? 0);

        return [
            'algorithm_action' => [
                'state' => $set === null ? 'gray' : 'green',
                'detail' => $set === null ? 'No Action label set requested yet.' : "$actionRows finalized Action labels",
                'through_ms' => $set === null ? null : (int) $set['as_of_ms'],
            ],
            'algorithm_outcome' => [
                'state' => $set === null || ($set['outcome_horizon'] ?? null) === null ? 'gray' : 'green',
                'detail' => $set === null ? 'No Outcome label set requested yet.'
                    : (($set['outcome_horizon'] ?? null) === null
                        ? 'Insufficient Action pivots to define a mature Outcome horizon.'
                        : "$outcomeRows finalized Outcome labels; immature candles excluded"),
                'through_ms' => $set === null ? null : (int) $set['as_of_ms'],
            ],
            'human_action' => [
                'state' => $actionCount > 0 ? 'green' : 'gray',
                'detail' => $actionCount.' recorded Human Action opinions (optional)',
                'through_ms' => max((int) ($humanAction->through_ms ?? 0), (int) ($directAction?->through_ms ?? 0)) ?: null,
            ],
            'human_outcome' => [
                'state' => $outcomeCount > 0 ? 'green' : 'gray',
                'detail' => $outcomeCount.' recorded Human Outcome opinions (optional)',
                'through_ms' => max((int) ($humanOutcome->through_ms ?? 0), (int) ($directOutcome?->through_ms ?? 0)) ?: null,
            ],
        ];
    }
}
