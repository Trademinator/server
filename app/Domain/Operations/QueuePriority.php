<?php

namespace App\Domain\Operations;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class QueuePriority
{
    /** Resolve a bounded batch, including jobs that carry a history, dataset or model identifier. */
    public function enrich(array $rows, string $queue): array
    {
        foreach ([
            'historyId' => ['market_history_backfills', 'history_id'],
            'gapId' => ['candle_gap_repairs', 'gap_id'],
            'requestId' => ['history_recovery_requests', 'request_id'],
        ] as $property => [$table, $column]) {
            $ids = $this->ids($rows, $property);
            if ($ids === []) {
                continue;
            }
            $records = DB::table($table)->whereIn($column, $ids)
                ->get([$column, 'market_id', 'period'])->keyBy($column);
            foreach ($rows as &$row) {
                $record = $records->get($row['_properties'][$property] ?? '');
                if ($record !== null) {
                    $row['market_id'] ??= $record->market_id;
                    $row['period'] ??= $record->period;
                }
            }
            unset($row);
        }
        foreach ([
            'datasetId' => ['research_datasets', 'dataset_id', 'manifest'],
            'modelId' => ['tournament_shadow_models', 'shadow_model_id', 'metadata'],
        ] as $property => [$table, $column, $jsonColumn]) {
            $ids = $this->ids($rows, $property);
            if ($ids === []) {
                continue;
            }
            $records = DB::table($table)->whereIn($column, $ids)
                ->get([$column, $jsonColumn])->keyBy($column);
            foreach ($rows as &$row) {
                $record = $records->get($row['_properties'][$property] ?? '');
                $data = $record === null ? [] : json_decode($record->{$jsonColumn}, true);
                foreach (['exchange', 'symbol', 'period'] as $field) {
                    $row[$field] ??= is_array($data) ? ($data[$field] ?? null) : null;
                }
            }
            unset($row);
        }
        $ids = array_values(array_unique(array_filter(array_column($rows, 'market_id'))));
        $pairs = [];
        foreach ($rows as $row) {
            if ($row['exchange'] !== null && $row['symbol'] !== null) {
                $pairs[$row['exchange'].'|'.$row['symbol']] = [$row['exchange'], $row['symbol']];
            }
        }
        $markets = collect();
        if ($ids !== [] || $pairs !== []) {
            $counts = $this->subscriberCountsQuery();
            $markets = DB::table('markets')
                ->join('exchanges', 'exchanges.exchange_id', '=', 'markets.exchange_id')
                ->leftJoin('market_feeds', 'market_feeds.market_id', '=', 'markets.market_id')
                ->leftJoinSub($counts, 'subscribers', 'subscribers.market_id', '=', 'markets.market_id')
                ->where(function ($query) use ($ids, $pairs): void {
                    $query->whereIn('markets.market_id', $ids);
                    foreach ($pairs as [$exchange, $symbol]) {
                        $query->orWhere(fn ($pair) => $pair->where('exchanges.class', $exchange)->where('markets.symbol', $symbol));
                    }
                })->get(['markets.market_id', 'markets.symbol', 'exchanges.class', 'market_feeds.selected_period', 'subscribers.subscriber_count']);
        }
        $byId = $markets->keyBy('market_id');
        $byPair = $markets->keyBy(fn ($market): string => $market->class.'|'.$market->symbol);
        foreach ($rows as &$row) {
            $market = $row['market_id'] !== null ? $byId->get($row['market_id']) : $byPair->get($row['exchange'].'|'.$row['symbol']);
            if ($market !== null) {
                $row['market_id'] = $market->market_id;
                $row['exchange'] ??= $market->class;
                $row['symbol'] ??= $market->symbol;
                $row['period'] ??= $market->selected_period;
                $row['subscriber_count'] = (int) ($market->subscriber_count ?? 0);
            }
            $wait = max(0, now()->getTimestamp() - ($row['requested_at'] ?? now()->getTimestamp()));
            $budget = $this->agingSeconds($queue);
            $row['aged'] = $wait >= $budget;
            $row['waiting_seconds'] = $wait;
            $row['priority_reason'] = $row['aged'] ? 'Promoted after waiting'
                : $row['subscriber_count'].' active subscriber'.($row['subscriber_count'] === 1 ? '' : 's');
        }
        unset($row);

        return $rows;
    }

    public function subscriberCountsQuery(): Builder
    {
        return DB::table('market_subscriptions as active_subscriptions')
            ->join('users as subscriber_users', 'subscriber_users.user_id', '=', 'active_subscriptions.user_id')
            ->where('active_subscriptions.active', true)->whereNull('subscriber_users.suspended_at')
            ->groupBy('active_subscriptions.market_id')->select('active_subscriptions.market_id')
            ->selectRaw('COUNT(DISTINCT active_subscriptions.user_id) AS subscriber_count');
    }

    /** @param array<string, array> $overrides */
    public function order(array $rows, array $overrides = [], int $claimed = 0): array
    {
        if (config('queue_management.priority_enabled', true)) {
            usort($rows, static function (array $left, array $right): int {
                return [! $left['aged'], $left['aged'] ? ($left['requested_at'] ?? PHP_INT_MAX) : -$left['subscriber_count'],
                    $left['requested_at'] ?? PHP_INT_MAX, $left['_index']]
                    <=> [! $right['aged'], $right['aged'] ? ($right['requested_at'] ?? PHP_INT_MAX) : -$right['subscriber_count'],
                        $right['requested_at'] ?? PHP_INT_MAX, $right['_index']];
            });
        }
        uasort($overrides, static fn (array $left, array $right): int => ($left['order'] ?? 0) <=> ($right['order'] ?? 0));
        foreach ($overrides as $uuid => $override) {
            $index = array_search($uuid, array_column($rows, 'uuid'), true);
            if ($index === false) {
                continue;
            }
            $row = $rows[$index];
            array_splice($rows, $index, 1);
            $position = match ($override['direction'] ?? '') {
                'top' => 0,
                'bottom' => count($rows),
                default => max(0, (int) ($override['position'] ?? 0) - max(0, $claimed - (int) ($override['claimed'] ?? 0))),
            };
            $row['manual'] = $override['direction'] ?? 'position';
            $row['priority_reason'] = 'Manually moved '.$row['manual'];
            array_splice($rows, min(count($rows), $position), 0, [$row]);
        }
        foreach ($rows as $index => &$row) {
            $row['position'] = $index + 1;
        }

        return $rows;
    }

    public function agingSeconds(string $queue): int
    {
        $budgets = (array) config('queue_management.aging_seconds', []);
        $normalize = static fn (string $name): string => preg_match('/\A\{([^{}]+)\}\z/D', $name, $match) ? $match[1] : $name;
        if (isset($budgets[$queue])) {
            return max(1, (int) $budgets[$queue]);
        }
        $settings = ['features' => 'features.queue', 'signals' => 'intelligence.signals.queue',
            'intelligence' => 'intelligence.queue', 'history' => 'history_backfill.queue',
            'tournament' => 'intelligence.tournament.queue'];
        foreach ($settings as $stage => $setting) {
            if ($queue === (string) config($setting, $stage)) {
                return max(1, (int) ($budgets[$stage] ?? $budgets['default'] ?? 3600));
            }
        }
        $name = $normalize($queue);
        if (isset($budgets[$name])) {
            return max(1, (int) $budgets[$name]);
        }
        foreach ($settings as $stage => $setting) {
            if ($name === $normalize((string) config($setting, $stage))) {
                return max(1, (int) ($budgets[$stage] ?? $budgets['default'] ?? 3600));
            }
        }

        return max(1, (int) ($budgets['default'] ?? 3600));
    }

    private function ids(array $rows, string $property): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = $row['_properties'][$property] ?? null;
            if (is_string($id) && preg_match('/\A[0-9a-f-]{36}\z/iD', $id)) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }
}
