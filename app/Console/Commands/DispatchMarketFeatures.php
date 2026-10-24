<?php

namespace App\Console\Commands;

use App\Domain\Features\MarketFeatureRefresh;
use App\Models\MarketFeed;
use Illuminate\Console\Command;

final class DispatchMarketFeatures extends Command
{
    protected $signature = 'trademinator:dispatch-market-features';

    protected $description = 'Queue M2 feature builds for subscribed markets whose current feature version is behind';

    public function handle(MarketFeatureRefresh $refresh): int
    {
        if (! config('features.enabled')) {
            return self::SUCCESS;
        }

        $count = 0;
        foreach (MarketFeed::query()->with('market.exchange')->whereNotNull('selected_period')
            ->whereHas('market.subscriptions', fn ($q) => $q->where('active', true)
                ->whereHas('user', fn ($users) => $users->whereNull('suspended_at')))->lazy(100) as $feed) {
            $exchange = $feed->market->exchange->class;
            $symbol = $feed->market->symbol;
            $period = $feed->selected_period;
            $count += (int) $refresh->dispatch($exchange, $symbol, $period);
        }

        $this->info("Dispatched {$count} shared-market feature builds.");

        return self::SUCCESS;
    }
}
