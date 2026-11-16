<?php

namespace App\Console\Commands;

use App\Domain\MarketData\MarketPurge;
use App\Domain\Operations\ActionLog;
use App\Domain\Operations\MarketQueuePurge;
use App\Domain\Operations\WorkerRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class PurgeMarket extends Command
{
    private const MAINTENANCE_SECONDS = 21600;

    protected $signature = 'trademinator:purge-market
        {exchange : Configured CCXT exchange ID}
        {symbol : Exact market symbol, e.g. BTC/USD}
        {--apply : Permanently erase the market and every canonical server artifact}
        {--workers-stopped : Confirm that workers with lost contact were stopped on every host}';

    protected $description = 'Audit or permanently erase one exchange market and all canonical server data';

    public function handle(MarketPurge $purge, MarketQueuePurge $queues, WorkerRegistry $workers): int
    {
        set_time_limit(0);
        $maintenance = null;
        try {
            $plan = $purge->plan((string) $this->argument('exchange'), (string) $this->argument('symbol'));
            $queuePlan = $queues->plan($plan);
            $plan = $this->withQueueCheckpoints($plan, $queuePlan['checkpoint_keys']);
            $this->displayPlan($plan, $queuePlan);
            if (! $this->option('apply')) {
                $this->warn('Dry run only. Stop the scheduler and every affected queue worker on all hosts, then use --apply.');

                return self::SUCCESS;
            }

            $queues->assertManaged($workers);
            $maintenance = $workers->beginMaintenance(self::MAINTENANCE_SECONDS);
            $workers->assertQueuesStopped($queues->queueNames(), (bool) $this->option('workers-stopped'));

            // Re-read after the global guard closes the worker-start race.
            $plan = $purge->plan($plan['exchange'], $plan['symbol']);
            $queuePlan = $queues->plan($plan);
            $plan = $this->withQueueCheckpoints($plan, $queuePlan['checkpoint_keys']);
            $auditPlan = [...$plan, 'queue_jobs' => $queuePlan['jobs']];
            $audit = $purge->writeAudit($auditPlan);
            $this->line('Private audit: '.$audit);

            $workers->assertMaintenanceOwner($maintenance);
            $queueBefore = $queues->purge($plan);
            $cache = $purge->purgeCache($plan);
            $this->refresh($workers, $maintenance);
            $database = $purge->applyDatabase($plan);
            $this->refresh($workers, $maintenance);
            $files = $purge->deleteFiles($plan);
            $cache += $purge->purgeCache($plan);
            $this->refresh($workers, $maintenance);
            // Catch a dispatcher that started immediately before the market row disappeared.
            $queueAfter = $queues->purge($plan);

            $remainingCounts = $purge->remaining($plan);
            $freshPlan = $purge->plan($plan['exchange'], $plan['symbol']);
            foreach ($purge->remaining($freshPlan) as $artifact => $count) {
                $remainingCounts[$artifact] = max((int) ($remainingCounts[$artifact] ?? 0), $count);
            }
            $remainingQueue = $queues->plan($plan);
            $freshQueue = $queues->plan($freshPlan);
            $remainingQueue['total'] = max($remainingQueue['total'], $freshQueue['total']);
            $remainingFiles = (int) ($remainingCounts['files_present'] ?? 0);
            unset($remainingCounts['files_present']);
            $remainingRows = array_sum($remainingCounts);

            $this->table(['Result', 'Count'], [
                ['Database records removed', array_sum($database)],
                ['Queue jobs removed', array_sum($queueBefore) + array_sum($queueAfter)],
                ['Files/directories removed', $files['deleted']],
                ['Files already absent', $files['missing']],
                ['Cache keys invalidated', $cache],
            ]);
            if ($files['failed'] > 0 || $remainingRows > 0 || $remainingQueue['total'] > 0 || $remainingFiles > 0) {
                $this->error('Market purge is incomplete. Keep the private audit file; it lists every expected artifact and path.');
                $this->line('Remaining database/file records: '.($remainingRows + $remainingFiles));
                $this->line('Remaining queued jobs: '.$remainingQueue['total']);

                return self::FAILURE;
            }

            app(ActionLog::class)->write('market.purge.completed', [
                'exchange' => $plan['exchange'], 'symbol' => $plan['symbol'],
                'rows' => array_sum($database), 'outcome' => 'completed',
            ]);
            $this->info($plan['exchange'].' '.$plan['symbol'].' was erased from canonical server state.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error instanceof RuntimeException ? $error->getMessage()
                : 'Market purge failed before completion: '.$error->getMessage());

            return self::FAILURE;
        } finally {
            if ($maintenance !== null) {
                try {
                    $workers->endMaintenance($maintenance);
                } catch (Throwable $error) {
                    report($error);
                }
            }
        }
    }

    private function refresh(WorkerRegistry $workers, string $owner): void
    {
        if (! $workers->refreshMaintenance($owner, self::MAINTENANCE_SECONDS)) {
            throw new RuntimeException('Queue maintenance ownership expired during market purge.');
        }
    }

    private function withQueueCheckpoints(array $plan, array $keys): array
    {
        $plan['checkpoint_keys'] = array_values(array_unique([...$plan['checkpoint_keys'], ...$keys]));
        if ($plan['checkpoint_keys'] !== [] && DB::getSchemaBuilder()->hasTable('knowledge_build_checkpoints')) {
            $count = DB::table('knowledge_build_checkpoints')->whereIn('build_key', $plan['checkpoint_keys'])->count();
            if ($count > 0) {
                $plan['counts']['knowledge_build_checkpoints'] = $count;
            }
        }

        return $plan;
    }

    private function displayPlan(array $plan, array $queuePlan): void
    {
        $rows = collect($plan['counts'])->map(fn (int $count, string $name): array => [$name, $count])->values();
        if ($queuePlan['total'] > 0) {
            $rows->push(['queued/delayed/reserved jobs', $queuePlan['total']]);
        }
        $this->line('Market: '.$plan['exchange'].' '.$plan['symbol']);
        $this->line('Periods: '.($plan['periods'] === [] ? 'none' : implode(', ', $plan['periods'])));
        $this->table(['Artifact', 'Count'], $rows->all());
        if ($rows->isEmpty()) {
            $this->info('No canonical server data was found for this market.');
        }
        $this->warn('Portable exports and external backups are immutable and are not rewritten by this command.');
    }
}
