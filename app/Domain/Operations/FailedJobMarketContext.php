<?php

namespace App\Domain\Operations;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read-only, bounded extraction of public market identifiers from failed queue payloads. */
final class FailedJobMarketContext
{
    /** @param Collection<int, object> $jobs */
    public function enrich(Collection $jobs): void
    {
        $marketIds = [];
        foreach ($jobs as $job) {
            $job->market_exchange = $job->market_symbol = $job->market_period = null;
            $payload = json_decode((string) ($job->payload ?? ''), true);
            $command = is_array($payload) ? ($payload['data']['command'] ?? null) : null;
            if (! is_string($command) || strlen($command) > 1048576) {
                unset($job->payload);
                continue;
            }

            foreach (['exchange' => 'market_exchange', 'symbol' => 'market_symbol', 'period' => 'market_period'] as $field => $column) {
                $value = $this->stringProperty($command, $field);
                if ($value !== null && preg_match('/\A[A-Za-z0-9_:.\/-]{1,100}\z/D', $value)) {
                    $job->{$column} = $value;
                }
            }
            $marketId = $this->stringProperty($command, 'marketId');
            if ($marketId !== null && preg_match('/\A[0-9a-f-]{36}\z/iD', $marketId)) {
                $marketIds[$marketId] = true;
                $job->context_market_id = $marketId;
            }
            unset($job->payload);
        }

        if ($marketIds === []) {
            return;
        }
        $markets = DB::table('markets')
            ->join('exchanges', 'exchanges.exchange_id', '=', 'markets.exchange_id')
            ->leftJoin('market_feeds', 'market_feeds.market_id', '=', 'markets.market_id')
            ->whereIn('markets.market_id', array_keys($marketIds))
            ->select('markets.market_id', 'markets.symbol', 'exchanges.class', 'market_feeds.selected_period')
            ->get()->keyBy('market_id');
        foreach ($jobs as $job) {
            $market = isset($job->context_market_id) ? $markets->get($job->context_market_id) : null;
            if ($market !== null) {
                $job->market_exchange ??= $market->class;
                $job->market_symbol ??= $market->symbol;
                // This reflects the currently selected period if the job did not store one.
                $job->market_period ??= $market->selected_period;
            }
            unset($job->context_market_id);
        }
    }

    private function stringProperty(string $command, string $name): ?string
    {
        // Do not unserialize arbitrary queue data: it may contain objects and secrets.
        $pattern = '/s:\\d+:"(?:[^"\\x00]*\\x00)?'.preg_quote($name, '/').'";s:\\d+:"([^"\\x00]{1,100})";/';

        return preg_match($pattern, $command, $match) === 1 ? $match[1] : null;
    }
}
