<?php

namespace App\Domain\MarketData;

use App\Domain\Features\MarketFeatureRefresh;
use App\Domain\Intelligence\BackfillIntelligence;
use App\Domain\Operations\ActionLog;
use App\Models\MarketFeed;
use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

final class MarketDataSynchronizer
{
    public function __construct(
        private readonly ExchangeRepository $exchanges,
        private readonly TickerRepository $tickers,
        private readonly CandleGaps $gaps,
        private readonly ActionLog $log,
    ) {}

    /** @return array{fetched: int, repaired: int, missing_ranges: int, reconstructed?: int, reconstruction_details?: array} */
    public function sync(string $exchange, string $symbol, string $period, int $from, int $to, bool $incremental = false, bool $repairGaps = false, int $requestLimit = 100): array
    {
        if (! $repairGaps) {
            $feed = MarketFeed::query()->where('selected_period', $period)
                ->whereHas('market', fn ($query) => $query->where('symbol', $symbol)
                    ->whereHas('exchange', fn ($query) => $query->where('class', $exchange)))
                ->whereHas('market.subscriptions', fn ($query) => $query->where('active', true)
                    ->whereHas('user', fn ($users) => $users->whereNull('suspended_at')))->first();
            $result = $this->perform($exchange, $symbol, $period, $from, $to, $incremental, false, $requestLimit, $feed);
            if ($feed !== null && ! in_array(config('queue.default'), ['sync', 'null'], true)) {
                app(MarketFeatureRefresh::class)->dispatch($exchange, $symbol, $period);
            }

            return $result;
        }
        $key = hash('sha256', "$exchange|$symbol|$period");
        $feed = MarketFeed::query()->with('market.exchange')->where('selected_period', $period)
            ->whereHas('market', fn ($query) => $query->where('symbol', $symbol)
                ->whereHas('exchange', fn ($query) => $query->where('class', $exchange)))->first();
        $names = $feed === null ? [] : ['trademinator:market-feed:'.$feed->market_id];
        $names = [...$names, 'trademinator:history-intelligence:'.$key, 'trademinator:history-exchange:'.$exchange, 'trademinator:features:'.$key];
        $locks = [];
        try {
            foreach ($names as $name) {
                $lock = Cache::lock($name, 720);
                if (! $lock->get()) {
                    throw new InvalidArgumentException('Market history or features are busy. Retry after the current job finishes.');
                }
                $locks[] = $lock;
            }
            $result = $this->perform($exchange, $symbol, $period, $from, $to, $incremental, true, $requestLimit, $feed);
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
        app(BackfillIntelligence::class)->dispatchDue($exchange, $symbol, $period);

        return $result;
    }

    private function perform(string $exchange, string $symbol, string $period, int $from, int $to, bool $incremental,
        bool $repairGaps, int $requestLimit, ?MarketFeed $feed = null): array
    {
        if ($from > $to) {
            throw new InvalidArgumentException('The start must not follow the end.');
        }

        $model = $this->exchanges->findByClass($exchange)?->first();
        if ($model === null) {
            throw new InvalidArgumentException("Exchange {$exchange} is not configured.");
        }

        $this->exchanges->setExchange($model, $repairGaps ? ['timeout' => 15000] : [], symbol: $symbol);
        if (! in_array($period, CandleTimeframe::SUPPORTED, true)
            || ! array_key_exists($period, $this->exchanges->periods())) {
            throw new InvalidArgumentException('The exchange does not support this symbol and period.');
        }
        $this->exchanges->prepareCandleMarket($symbol);

        $start = $from;
        if ($incremental) {
            $latest = $this->tickers->latestTimestamp($exchange, $symbol, $period);
            if ($latest !== null) {
                // Revisit the most recent stored candle: it may still be active.
                $start = max($from, intdiv($latest, 1000));
            }
        }

        $correctionFrom = $to * 1000;
        if (! $repairGaps && $feed !== null) {
            $timeframe = new CandleTimeframe;
            for ($i = 0; $i < min(1000, max(1, $requestLimit)); $i++) {
                $correctionFrom = $timeframe->previous($correctionFrom, $period);
            }
            $correctionFrom = max($from * 1000, $correctionFrom);
        }
        $before = $repairGaps || $feed !== null
            ? iterator_to_array($this->tickers->streamHistory($exchange, $symbol, $period,
                $repairGaps ? $from * 1000 : $correctionFrom, $to * 1000)) : [];
        $fetched = $start <= $to ? count($this->exchanges->fetch($symbol, $period, $start, $to, $requestLimit)) : 0;
        $timestamps = $this->tickers->timestamps($exchange, $symbol, $period, $from * 1000, $to * 1000);
        $missing = $this->gaps->between($timestamps, $period);
        $repaired = 0;
        $reconstructed = 0;
        $details = [];

        if (! $repairGaps && $feed !== null && $before !== []) {
            $nativeCorrections = [];
            foreach ($this->tickers->streamHistory($exchange, $symbol, $period, $correctionFrom, $to * 1000) as $at => $bar) {
                if (isset($before[$at]) && ! isset($before[$at]['reconstruction']) && ! isset($bar['reconstruction'])
                    && $before[$at] !== $bar && (new CandleTimeframe)->next($at, $period) <= now()->getTimestampMs()) {
                    $nativeCorrections[] = $at;
                }
            }
            if ($nativeCorrections !== []) {
                app(HistoryChanges::class)->record($feed->market_id, $period, min($nativeCorrections), max($nativeCorrections),
                    'live_candle_correction', immediate: true);
            }
        }

        if ($repairGaps) {
            // Limit repair calls within a page as well as the candle request.
            // Exchanges may omit no-trade candles altogether.
            foreach (array_slice($missing, 0, 5) as $gap) {
                $this->exchanges->fetch(
                    $symbol, $period, intdiv($gap['from'], 1000), intdiv($gap['to'], 1000), $requestLimit
                );
                $timeframe = new CandleTimeframe;
                if ($gap['to'] < $timeframe->next($gap['from'], $period)) {
                    $attempt = app(CandleReconstructor::class)->attempt($this->exchanges, $exchange, $symbol, $period,
                        $gap['from'], now()->getTimestampMs());
                    $details[] = $attempt['diagnostics'];
                    $bar = $attempt['candle'];
                    if ($bar !== null) {
                        $this->tickers->saveTickers($exchange, $symbol, $period, [$bar]);
                    }
                }
            }
            $timestamps = $this->tickers->timestamps($exchange, $symbol, $period, $from * 1000, $to * 1000);
            $missing = $this->gaps->between($timestamps, $period);
            $after = iterator_to_array($this->tickers->streamHistory($exchange, $symbol, $period, $from * 1000, $to * 1000));
            $nativeChanges = [];
            foreach ($after as $at => $bar) {
                if (! isset($before[$at])) {
                    $repaired++;
                    $reconstructed += (int) isset($bar['reconstruction']);
                }
                if (! isset($bar['reconstruction']) && ! isset($before[$at]['reconstruction']) && ($before[$at] ?? null) !== $bar
                    && (new CandleTimeframe)->next($at, $period) <= now()->getTimestampMs()) {
                    $nativeChanges[] = $at;
                }
            }
            if ($feed !== null) {
                $repairs = app(CandleGapRepairs::class);
                if ($nativeChanges !== []) {
                    app(HistoryChanges::class)->record($feed->market_id, $period, min($nativeChanges), max($nativeChanges),
                        'manual_candle_repair', immediate: true);
                }
                $repairs->recordManualResult($feed, $from * 1000, $to * 1000, $before, $after);
            }
        }

        $result = ['fetched' => $fetched, 'repaired' => $repaired, 'missing_ranges' => count($missing)];
        if ($repairGaps) {
            $result['reconstructed'] = $reconstructed;
            $result['reconstruction_details'] = $details;
        }
        $this->log->write('candles.synchronized',
            ['exchange' => $exchange, 'symbol' => $symbol, 'period' => $period, 'outcome' => 'completed', ...$result]);

        return $result;
    }
}
