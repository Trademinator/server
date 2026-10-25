<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\KnowledgeWindow;
use App\Domain\Intelligence\ModelStore;
use App\Jobs\TrainMarketIntelligence;
use App\Models\MarketFeed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class DispatchMarketIntelligence extends Command
{
    protected $signature = 'trademinator:dispatch-market-intelligence
        {--force : Create a fresh build generation, even if this week was already built or scheduling is disabled}
        {--expired : Queue subscribed selected-period models that have reached their maximum age}
        {--all : With --force, also rebuild every previously published market and period}';

    protected $description = 'Queue weekly or expired-model training for subscribed markets and selected periods';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $expired = (bool) $this->option('expired');
        if ($expired && ($force || $this->option('all'))) {
            $this->error('--expired cannot be combined with --force or --all.');

            return self::FAILURE;
        }
        if ($this->option('all') && ! $force) {
            $this->error('--all requires --force.');

            return self::FAILURE;
        }
        if (! config('intelligence.enabled') && ! $force) {
            return self::SUCCESS;
        }
        if (in_array(config('queue.default'), ['sync', 'null'], true)) {
            $this->error('Intelligence dispatch requires a persistent queue connection.');

            return self::FAILURE;
        }
        $count = 0;
        $seen = [];
        $failed = false;
        $generation = $force ? 'manual:'.Str::uuid7() : now()->startOfWeek()->format('Y-m-d');
        $enqueue = function (string $exchange, string $symbol, string $period, ?string $schema = null, ?string $buildGeneration = null) use (&$seen, &$count, $generation): void {
            $key = ModelStore::marketKey($exchange, $symbol, $period);
            if (isset($seen[$key])) {
                return;
            }
            $job = new TrainMarketIntelligence($exchange, $symbol, $period, $buildGeneration ?? $generation, $schema);
            $job->onQueue(config('intelligence.queue'));
            dispatch($job);
            $seen[$key] = true;
            $count++;
        };
        foreach (MarketFeed::query()->with('market.exchange')->whereNotNull('selected_period')
            ->whereHas('market.subscriptions', fn ($query) => $query->where('active', true))->lazy(100) as $feed) {
            if ($expired) {
                $report = app(ModelStore::class)->currentReport(
                    $feed->market->exchange->class, $feed->market->symbol, $feed->selected_period);
                if ($report === null
                    || (int) ($report['trained_as_of_ms'] ?? 0) > KnowledgeWindow::fromMs(now()->getTimestampMs())) {
                    continue;
                }
                $schema = $report['schema_selection']['requested_schema'] ?? $report['outcome']['schema'] ?? null;
                $schema = in_array($schema, ['core', 'technical', 'enhanced', 'full'], true) ? $schema : null;
                $enqueue($feed->market->exchange->class, $feed->market->symbol, $feed->selected_period, $schema,
                    'expired:'.($report['model_id'] ?? 'unknown').':'.now()->format('Y-m-d'));

                continue;
            }
            $enqueue($feed->market->exchange->class, $feed->market->symbol, $feed->selected_period);
        }
        if ($this->option('all')) {
            foreach (DB::table('intelligence_heads as heads')
                ->join('intelligence_models as models', 'models.model_id', '=', 'heads.model_id')
                ->select('models.model_id', 'models.report')->orderBy('heads.market_key')->lazy(100) as $record) {
                try {
                    $report = json_decode($record->report, true, flags: JSON_THROW_ON_ERROR);
                    foreach (['exchange', 'symbol', 'period'] as $field) {
                        if (! is_string($report[$field] ?? null) || $report[$field] === '') {
                            throw new \RuntimeException('Missing market identity in the model report.');
                        }
                    }
                    $schema = $report['schema_selection']['requested_schema'] ?? $report['outcome']['schema'] ?? null;
                    $schema = in_array($schema, ['core', 'technical', 'enhanced', 'full'], true) ? $schema : null;
                    $enqueue($report['exchange'], $report['symbol'], $report['period'], $schema);
                } catch (Throwable $error) {
                    $failed = true;
                    $this->error('Could not queue model '.$record->model_id.': '.$error->getMessage());
                }
            }
        }
        $this->info("Dispatched {$count} shared-market intelligence builds.");
        if ($force) {
            $this->line('Build generation: '.$generation);
            $this->line('Queue: '.config('intelligence.queue'));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
