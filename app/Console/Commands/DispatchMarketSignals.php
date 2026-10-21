<?php

namespace App\Console\Commands;

use App\Jobs\RecordMarketSignal;
use App\Models\MarketFeed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class DispatchMarketSignals extends Command
{
    protected $signature = 'trademinator:dispatch-market-signals';

    protected $description = 'Queue current signal recording once per actively subscribed market';

    public function handle(): int
    {
        if (! config('dashboard.signals_enabled') || ! config('intelligence.enabled')) {
            return self::SUCCESS;
        }
        $connection = config('intelligence.signals.connection') ?: config('queue.default');
        $driver = config('queue.connections.'.$connection.'.driver');
        if (! in_array($driver, ['database', 'beanstalkd', 'sqs', 'redis'], true)) {
            $this->error('Signal recording requires a persistent queue connection.');

            return self::FAILURE;
        }
        $lock = Cache::lock('trademinator:signal-dispatch', 600);
        if (! $lock->get()) {
            $this->info('Signal dispatch is already running or undergoing recovery; skipping.');

            return self::SUCCESS;
        }
        try {
            $count = 0;
            foreach (MarketFeed::query()->whereNotNull('selected_period')
                ->whereHas('market.subscriptions', fn ($query) => $query->where('active', true))
                ->lazyById(100, column: 'market_id') as $feed) {
                dispatch(new RecordMarketSignal($feed->market_id));
                $count++;
            }
            $this->info("Dispatched {$count} shared-market signal recordings.");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
