<?php

namespace App\Domain\Features;

use App\Domain\MarketData\HistoryReplayWindow;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Feature replay acknowledges its own source revision, independently of training. */
final class FeatureHistoryRevisions
{
    /** @return array{history_id: string, revision: int, from_ms: ?int}|null */
    public function pending(string $exchange, string $symbol, string $period): ?array
    {
        $state = $this->scope($exchange, $symbol, $period)
            ->whereColumn('history.history_revision', '>', 'history.feature_revision')->first(['history.*']);
        if ($state === null) {
            return null;
        }
        $changes = DB::table('market_history_changes')->where('history_id', $state->history_id)
            ->where('revision', '>', $state->feature_revision)->where('revision', '<=', $state->history_revision)
            ->orderBy('revision')->cursor();

        return ['history_id' => $state->history_id, 'revision' => (int) $state->history_revision,
            'from_ms' => HistoryReplayWindow::earliest((int) $state->feature_revision, (int) $state->history_revision, $changes)];
    }

    public function complete(string $historyId, int $revision): void
    {
        DB::table('market_history_backfills')->where('history_id', $historyId)
            ->where('feature_revision', '<', $revision)->where('history_revision', '>=', $revision)
            ->update(['feature_revision' => $revision, 'updated_at' => now()]);
    }

    private function scope(string $exchange, string $symbol, string $period): Builder
    {
        return DB::table('market_history_backfills as history')
            ->join('markets', 'markets.market_id', '=', 'history.market_id')
            ->join('exchanges', 'exchanges.exchange_id', '=', 'markets.exchange_id')
            ->where('exchanges.class', $exchange)->where('markets.symbol', $symbol)->where('history.period', $period);
    }
}
