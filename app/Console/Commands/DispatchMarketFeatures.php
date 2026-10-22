<?php

namespace App\Console\Commands;

use App\Domain\Features\FeatureEngine;
use App\Domain\Intelligence\KnowledgeRequestJournal;
use App\Domain\MarketData\CandleTimeframe;
use App\Jobs\BuildMarketFeatures;
use App\Models\MarketFeed;
use App\Repositories\TickerRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class DispatchMarketFeatures extends Command
{
    protected $signature = 'trademinator:dispatch-market-features';

    protected $description = 'Queue M2 feature builds for subscribed markets whose current feature version is behind';

    public function handle(TickerRepository $tickers): int
    {
        if (! config('features.enabled')) {
            return self::SUCCESS;
        }

        $count = 0;
        foreach (MarketFeed::query()->with('market.exchange')->whereNotNull('selected_period')
            ->whereHas('market.subscriptions', fn ($q) => $q->where('active', true))->lazy(100) as $feed) {
            $exchange = $feed->market->exchange->class;
            $symbol = $feed->market->symbol;
            $period = $feed->selected_period;
            $latestCandle = $tickers->latestTimestamp($exchange, $symbol, $period);

            if ($latestCandle === null) {
                continue;
            }
            $timeframe = new CandleTimeframe;
            if ($timeframe->next($latestCandle, $period) > now()->getTimestampMs()) {
                $latestCandle = $timeframe->previous($latestCandle, $period);
            }
            if ($latestCandle < 0) {
                continue;
            }

            $latestFeature = DB::table('market_features')
                ->where('exchange', $exchange)
                ->where('symbol', $symbol)
                ->where('period', $period)
                ->where('version', FeatureEngine::VERSION)
                ->max('microtimestamp');

            if ($latestFeature !== null && (int) $latestFeature >= $latestCandle) {
                continue;
            }

            app(KnowledgeRequestJournal::class)
                ->request($exchange, $symbol, $period, 'features', $latestCandle);
            BuildMarketFeatures::dispatch($exchange, $symbol, $period);
            $count++;
        }

        $this->info("Dispatched {$count} shared-market feature builds.");

        return self::SUCCESS;
    }
}
