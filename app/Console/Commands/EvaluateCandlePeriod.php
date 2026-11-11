<?php

namespace App\Console\Commands;

use App\Domain\MarketData\CandlePeriodReevaluation;
use App\Helpers\Decimal;
use App\Models\MarketFeed;
use Illuminate\Console\Command;
use Throwable;

final class EvaluateCandlePeriod extends Command
{
    protected $signature = 'trademinator:evaluate-candle-period {--exchange=} {--pair=} {--dry-run} {--outdated-only}';

    protected $description = 'Evaluate and optionally update automatic candle periods for subscribed markets';

    public function handle(CandlePeriodReevaluation $evaluation): int
    {
        $exchange = $this->option('exchange');
        $pair = $this->option('pair');
        $dryRun = (bool) $this->option('dry-run');
        $outdatedOnly = (bool) $this->option('outdated-only');
        $version = max(1, (int) config('candle_period.selection_version', 2));

        $query = MarketFeed::query()->with('market.exchange')
            ->whereHas('market.subscriptions', fn ($query) => $query->where('active', true))
            ->when($exchange !== null, fn ($query) => $query->whereHas('market.exchange',
                fn ($query) => $query->where('class', $exchange)))
            ->when($pair !== null, fn ($query) => $query->whereHas('market',
                fn ($query) => $query->where('symbol', $pair)));

        if ($outdatedOnly) {
            $query->where(fn ($query) => $query->whereNull('selected_period')->orWhere('selection_version', '<', $version))
                ->where(fn ($query) => $query->whereNull('selection_next_attempt_at')->orWhere('selection_next_attempt_at', '<=', now()));
        }

        $maximumFeeds = $outdatedOnly
            ? max(1, (int) config('candle_period.scheduled_markets_per_run', 10))
            : null;
        $rows = [];
        $candidateRows = [];
        $failed = false;
        $processed = 0;
        foreach ($query->orderBy('market_id')->lazy(5) as $feed) {
            if ($maximumFeeds !== null && $processed >= $maximumFeeds) {
                break;
            }
            $processed++;
            try {
                $result = $evaluation->evaluate($feed, $dryRun, ! $outdatedOnly);
                $rows[] = [
                    $result['exchange'], $result['symbol'], $result['current_period'] ?? '-',
                    $result['selected_period'] ?? '-', $result['status'],
                    $result['window_days'] ?? '-', $this->percent($result['buy_ratio']),
                    $this->percent($result['sell_ratio']), $this->percent($result['true_flat_ratio'] ?? null),
                    $result['run_p50'] ?? '-', $result['run_p95'] ?? '-',
                    $this->percent($result['rejected_reversal_ratio'] ?? null), $result['reason'],
                ];
                if ($dryRun) {
                    foreach ($result['attempts'] ?? [] as $attempt) {
                        $window = $attempt['confirmation_window'] ?? null;
                        $candidateRows[] = [
                            $result['exchange'], $result['symbol'], $attempt['period'],
                            $window === null ? "{$attempt['days']}d" : "{$window}: {$attempt['days']}d",
                            $attempt['status'], $this->percent($attempt['true_flat_ratio'] ?? null),
                            $this->percent($attempt['flat_limit'] ?? null),
                            $this->percent($attempt['buy_ratio'] ?? null),
                            $this->percent($attempt['sell_ratio'] ?? null),
                            $attempt['run_p50'] ?? '-', $attempt['run_p95'] ?? '-', $attempt['run_count'] ?? '-',
                            $this->percent($attempt['rejected_reversal_ratio'] ?? null),
                            $attempt['reversals_tested'] ?? '-',
                        ];
                    }
                }
            } catch (Throwable $error) {
                report($error);
                $failed = true;
                $rows[] = [
                    $feed->market->exchange->class, $feed->market->symbol, $feed->selected_period ?? '-', '-',
                    'error', '-', '-', '-', '-', '-', '-', '-', $error->getMessage(),
                ];
            } finally {
                $feed->unsetRelations();
                gc_collect_cycles();
                gc_mem_caches();
            }
        }

        if ($rows === []) {
            $this->info('No active subscribed markets match the requested filters.');

            return self::SUCCESS;
        }

        $this->table(['Exchange', 'Pair', 'Current', 'Result', 'Status', 'Days', 'BUY', 'SELL', 'True flats', 'P50', 'P95', 'Rejected', 'Reason'], $rows);
        if ($dryRun) {
            if ($candidateRows !== []) {
                $this->info('Candidate windows (confirmation windows are numbered from newest to oldest):');
                $this->table(['Exchange', 'Pair', 'Period', 'Window', 'Status', 'True flats', 'Flat cap', 'BUY', 'SELL', 'P50', 'P95', 'Runs', 'Rejected', 'Tested'],
                    $candidateRows);
            }
            $this->info('Dry run: no period was changed and no history backfill was queued.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function percent(mixed $ratio): string
    {
        return is_numeric($ratio) ? Decimal::format((float) $ratio * 100, 2).'%' : '-';
    }
}
