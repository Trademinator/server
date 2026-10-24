<?php

namespace App\Domain\Features;

use App\Domain\Intelligence\KnowledgeRequestJournal;
use App\Domain\MarketData\CandleTimeframe;
use App\Jobs\BuildMarketFeatures;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\DB;

final class MarketFeatureRefresh
{
    public function dispatch(string $exchange, string $symbol, string $period): bool
    {
        if (! config('features.enabled')) {
            return false;
        }
        $latestCandle = app(TickerRepository::class)->latestTimestamp($exchange, $symbol, $period);
        if ($latestCandle === null) {
            return false;
        }
        $timeframe = new CandleTimeframe;
        if ($timeframe->next($latestCandle, $period) > now()->getTimestampMs()) {
            $latestCandle = $timeframe->previous($latestCandle, $period);
        }
        if ($latestCandle < 0) {
            return false;
        }
        $latestFeature = DB::table('market_features')->where('exchange', $exchange)->where('symbol', $symbol)
            ->where('period', $period)->where('version', FeatureEngine::VERSION)->max('microtimestamp');
        if ($latestFeature !== null && (int) $latestFeature >= $latestCandle
            && app(FeatureHistoryRevisions::class)->pending($exchange, $symbol, $period) === null) {
            return false;
        }
        app(KnowledgeRequestJournal::class)->request($exchange, $symbol, $period, 'features', $latestCandle);
        BuildMarketFeatures::dispatch($exchange, $symbol, $period)->afterCommit();

        return true;
    }
}
