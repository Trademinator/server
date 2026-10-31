<?php

namespace App\Repositories;

use App\Domain\Archive\ArchiveIntegrityException;
use App\Domain\Archive\PortableJson;
use App\Domain\Archive\TickerArchive;
use App\Domain\MarketData\CandleGaps;
use App\Domain\MarketData\CandleProvenance;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\MarketData\CandleTimestampIndex;
use App\Domain\MarketData\ExchangeCredentials;
use App\Domain\MarketData\HistoryChanges;
use App\Domain\MarketData\TickerHistoryCache;
use App\Domain\Operations\ActionLog;
use App\Models\Market;
use App\Models\Ticker;
use ccxt\Exchange;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JasonGuru\LaravelMakeRepository\Repository\BaseRepository;
use Trademinator\Indicators\Traits\TickerManipulation;

/**
 * Class TickerRepository.
 */
class TickerRepository extends BaseRepository
{
    use TickerManipulation;

    protected ?Ticker $ticker;

    protected ?Exchange $ccxtExchange = null;

    public function __construct(?Ticker $ticker = null)
    {
        parent::__construct();
        $this->ticker = $ticker;
    }

    public function fetch(string $symbol, string $period, int $startFetching, int $records, array $params): array
    {
        $log = app(ActionLog::class);
        $started = hrtime(true);
        $fields = ['exchange' => $this->ccxtExchange?->id, 'symbol' => $symbol, 'period' => $period];
        try {
            $myTickers = $this->ccxtExchange->fetch_ohlcv($symbol, $period, $startFetching, $records, $params);
            $this->normalize_ticker($myTickers, true);
        } catch (\Throwable $error) {
            $log->write('exchange.ohlcv_fetched', [...$fields, 'outcome' => 'failed',
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000), ...$log->exception($error)]);
            throw ExchangeCredentials::safeFailure($error);
        }
        $log->write('exchange.ohlcv_fetched', [...$fields, 'outcome' => 'completed', 'rows' => count($myTickers),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000)]);

        if (App::hasDebugModeEnabled()) {
            Log::debug('myTickers: '.print_r($myTickers, true));
        }

        return $myTickers;
    }

    /**
     * Chronological hot+cold OHLCV history. This is the canonical application
     * entry point for ticker payload reads.
     *
     * Small explicitly bounded ranges may be materialized into the shared
     * cache. Large or unbounded requests remain generator-driven.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function streamHistory(string $exchange, string $symbol, string $period, ?int $fromMs = null, ?int $toMs = null): \Generator
    {
        $bounded = $fromMs !== null && $toMs !== null;
        $fromMs ??= 0;
        $toMs ??= PHP_INT_MAX;

        if ($bounded && $this->historyCache()->shouldCacheRange($period, $fromMs, $toMs)) {
            $cached = $this->historyCache()->range($exchange, $symbol, $period, $fromMs, $toMs);
            if ($cached !== null) {
                foreach ($cached as $timestamp => $payload) {
                    yield (int) $timestamp => $payload;
                }

                return;
            }

            $rows = iterator_to_array(
                $this->streamUncachedHistory($exchange, $symbol, $period, $fromMs, $toMs),
                true
            );
            $this->historyCache()->putRange($exchange, $symbol, $period, $fromMs, $toMs, $rows);

            foreach ($rows as $timestamp => $payload) {
                yield (int) $timestamp => $payload;
            }

            return;
        }

        yield from $this->streamUncachedHistory($exchange, $symbol, $period, $fromMs, $toMs);
    }

    /**
     * Metadata-only paging. OHLCV payloads selected by these timestamps must be
     * read through streamHistory().
     *
     * @return list<int>
     */
    public function pageTimestamps(
        string $exchange,
        string $symbol,
        string $period,
        string $direction,
        ?int $anchorMs,
        int $untilMs,
        int $limit
    ): array {
        if ($limit < 1 || ! in_array($direction, ['latest', 'older', 'newer', 'initial'], true)
            || (in_array($direction, ['older', 'newer'], true) && $anchorMs === null)) {
            throw new \InvalidArgumentException('Invalid ticker timestamp page request.');
        }

        $scopeParts = [$direction, $anchorMs, $untilMs, $limit];
        $scope = 'page:'.hash('sha256', json_encode($scopeParts, JSON_THROW_ON_ERROR));
        $cached = $this->historyCache()->metadata($exchange, $symbol, $period, $scope);
        if (is_array($cached)) {
            return array_map('intval', $cached);
        }

        $query = Ticker::query()->where('exchange', $exchange)->where('symbol', $symbol)
            ->where('period', $period)->where('microtimestamp', '<=', $untilMs);

        if ($direction === 'older') {
            $query->where('microtimestamp', '<', $anchorMs)->orderByDesc('microtimestamp');
        } elseif ($direction === 'newer') {
            $query->where('microtimestamp', '>', $anchorMs)->orderBy('microtimestamp');
        } elseif ($direction === 'latest') {
            $query->orderByDesc('microtimestamp');
        } else {
            $query->orderBy('microtimestamp');
        }

        $timestamps = $query->limit($limit)->pluck('microtimestamp')
            ->map(fn ($value): int => (int) $value)->all();

        if (in_array($direction, ['latest', 'older'], true)) {
            $timestamps = array_reverse($timestamps);
        }

        $this->historyCache()->putMetadata($exchange, $symbol, $period, $scope, $timestamps);

        return $timestamps;
    }

    public function invalidateHistory(string $exchange, string $symbol, string $period): void
    {
        $this->historyCache()->invalidate($exchange, $symbol, $period);
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function streamUncachedHistory(
        string $exchange,
        string $symbol,
        string $period,
        int $fromMs,
        int $toMs
    ): \Generator {
        $query = Ticker::query()->where('exchange', $exchange)->where('symbol', $symbol)->where('period', $period)
            ->whereBetween('microtimestamp', [$fromMs, $toMs])->orderBy('microtimestamp');
        if (App::hasDebugModeEnabled()) {
            Log::debug('Hot ticker SQL: '.$query->toRawSql());
        }
        $hot = (function () use ($query): \Generator {
            foreach ($query->lazy(500) as $ticker) {
                $payload = json_decode($ticker->payload, true, flags: JSON_THROW_ON_ERROR);
                $payload['microtimestamp'] = (int) $ticker->microtimestamp;
                yield (int) $ticker->microtimestamp => $payload;
            }
        })();
        $cold = config('archive.enabled')
            ? app(TickerArchive::class)->stream($exchange, $symbol, $period, $fromMs, $toMs)
            : (function (): \Generator {
                if (false) {
                    yield;
                }
            })();
        $hot->rewind();
        $cold->rewind();
        while ($hot->valid() || $cold->valid()) {
            if (! $cold->valid() || ($hot->valid() && $hot->key() < $cold->key())) {
                yield (int) $hot->key() => $hot->current();
                $hot->next();

                continue;
            }
            $coldRecord = $cold->current();
            $coldPayload = $coldRecord['payload'];
            $coldPayload['microtimestamp'] = (int) $cold->key();
            if (! $hot->valid() || $cold->key() < $hot->key()) {
                yield (int) $cold->key() => $coldPayload;
                $cold->next();

                continue;
            }
            if (PortableJson::encode($hot->current()) !== PortableJson::encode($coldPayload)) {
                // A later exchange candle supersedes an archived reconstruction;
                // all other conflicting source duplicates remain integrity errors.
                if (CandleProvenance::method($coldPayload) === null
                    || CandleProvenance::method($hot->current()) !== null) {
                    throw new ArchiveIntegrityException("Hot/cold ticker conflict at {$exchange}|{$symbol}|{$period}|{$hot->key()}.");
                }
            }
            yield (int) $hot->key() => $hot->current();
            $hot->next();
            $cold->next();
        }
    }

    public function latestTimestamp(string $exchange, string $symbol, string $period): ?int
    {
        $cached = $this->historyCache()->metadata($exchange, $symbol, $period, 'latest');
        if (is_int($cached)) {
            return $cached;
        }

        $timestamp = Ticker::query()->where('exchange', $exchange)->where('symbol', $symbol)
            ->where('period', $period)->max('microtimestamp');

        $archived = config('archive.enabled') ? DB::table('archive_catalog')->where('logical_type', 'tickers')
            ->where('verification_state', 'verified')->where('exchange', $exchange)->where('symbol', $symbol)
            ->where('period', $period)->max('range_end_ms') : null;
        if ($timestamp === null && $archived === null) {
            return null;
        }

        $latest = max((int) ($timestamp ?? 0), (int) ($archived ?? 0));
        $this->historyCache()->putMetadata($exchange, $symbol, $period, 'latest', $latest);

        return $latest;
    }

    /** Latest actual candle closed at a frozen cutoff, including cold-only history. */
    public function latestClosedTimestamp(string $exchange, string $symbol, string $period, int $asOfMs): ?int
    {
        $timeframe = new CandleTimeframe;
        $before = $asOfMs;
        do {
            $timestamp = Ticker::query()->where(compact('exchange', 'symbol', 'period'))
                ->where('microtimestamp', '<', $before)->max('microtimestamp');
            $latest = $timestamp === null ? null : (int) $timestamp;
            $before = $latest;
        } while ($latest !== null && $timeframe->next($latest, $period) > $asOfMs);

        if (config('archive.enabled')) {
            $archives = DB::table('archive_catalog')->where('logical_type', 'tickers')
                ->where('verification_state', 'verified')->where(compact('exchange', 'symbol', 'period'))
                ->where('range_start_ms', '<', $asOfMs)->where('range_end_ms', '>', $latest ?? -1)
                ->orderByDesc('range_end_ms')->lazy();
            foreach ($archives as $archive) {
                // Catalog bounds cover calendar partitions, not actual candle
                // timestamps. Read the latest relevant partition for gaps.
                foreach ($this->streamHistory($exchange, $symbol, $period,
                    max(($latest ?? -1) + 1, (int) $archive->range_start_ms), min($asOfMs - 1, (int) $archive->range_end_ms)) as $timestamp => $candle) {
                    if ($timeframe->next((int) $timestamp, $period) <= $asOfMs) {
                        $latest = (int) $timestamp;
                    }
                }
                if ($latest !== null && $latest >= (int) $archive->range_start_ms) {
                    break;
                }
            }
        }

        return $latest;
    }

    /**
     * Recursive metadata-only discovery, from the first stored candle through
     * the last closed slot. No history payloads are read for database-only feeds.
     *
     * @return list<array{from: int, to: int}> Inclusive missing candle start times.
     */
    public function missingClosedCandleRanges(string $exchange, string $symbol, string $period, int $untilMs): array
    {
        return DB::transaction(function () use ($exchange, $symbol, $period, $untilMs): array {
            $gaps = new CandleGaps;
            $archived = config('archive.enabled') && DB::table('archive_catalog')->where('logical_type', 'tickers')
                ->where('verification_state', 'verified')->where('exchange', $exchange)->where('symbol', $symbol)
                ->where('period', $period)->where('range_start_ms', '<', $untilMs)->exists();

            if ($archived) {
                // Verify and merge hot/cold history once, retaining only eight bytes
                // per timestamp. Recursive probes never reopen compressed shards.
                $timestamps = (function () use ($exchange, $symbol, $period, $untilMs): \Generator {
                    foreach ($this->streamHistory($exchange, $symbol, $period, 0, $untilMs) as $timestamp => $_) {
                        yield $timestamp;
                    }
                })();
                $index = new CandleTimestampIndex($timestamps, $period);
                $first = $index->first();

                return $first === null ? [] : $gaps->find($first, $untilMs, $period, $index->summary(...));
            }

            $query = Ticker::query()->where('exchange', $exchange)->where('symbol', $symbol)
                ->where('period', $period)->where('microtimestamp', '<', $untilMs)->toBase();
            $first = (clone $query)->min('microtimestamp');
            if ($first === null) {
                return [];
            }

            $timeframe = new CandleTimeframe;
            $calendar = str_ends_with($period, 'M') || str_ends_with($period, 'y');
            $step = $timeframe->next((int) $first, $period) - (int) $first;
            $summary = function (int $fromMs, int $toMs) use ($query, $period, $timeframe, $calendar, $step): array {
                $range = (clone $query)->whereBetween('microtimestamp', [$fromMs, $toMs]);
                if ($calendar) {
                    $starts = [];
                    for ($cursor = $fromMs; $cursor <= $toMs; $cursor = $timeframe->next($cursor, $period)) {
                        $starts[] = $cursor;
                    }
                    $range->whereIn('microtimestamp', $starts);
                } else {
                    // An off-grid row must not disguise a missing expected candle.
                    $range->whereRaw('microtimestamp % ? = ?', [$step, $fromMs % $step]);
                }
                $row = $range->selectRaw('COUNT(*) AS candle_count, MIN(microtimestamp) AS first_ms, MAX(microtimestamp) AS last_ms')->first();

                return ['count' => (int) $row->candle_count,
                    'first' => $row->first_ms === null ? null : (int) $row->first_ms,
                    'last' => $row->last_ms === null ? null : (int) $row->last_ms];
            };

            return $gaps->find((int) $first, $untilMs, $period, $summary);
        });
    }

    /** @return list<int> */
    public function timestamps(string $exchange, string $symbol, string $period, int $fromMs, int $toMs): array
    {
        $timestamps = [];
        foreach ($this->streamHistory($exchange, $symbol, $period, $fromMs, $toMs) as $timestamp => $_) {
            $timestamps[] = $timestamp;
        }

        return $timestamps;
    }

    // array_merge breaks timestamp keys, so normalize and reindex canonically.
    public function fixTickerIndex(array $tickers): array
    {
        return $this->normalize_ticker($tickers, true);
    }

    /**
     * @return string Return the model
     */
    public function model(): string
    {
        return Ticker::class;
    }

    public function update(array $data, array $unique, array $update): int
    {
        // Eloquent's bulk upsert bypasses model creating events. Generate UUIDs
        // before the insert path, while preserving explicitly supplied IDs.
        foreach ($data as &$row) {
            if (empty($row['ticker_id'])) {
                $row['ticker_id'] = (string) Str::uuid7();
            }
        }
        unset($row);

        return Ticker::query()->upsert($data, $unique, $update);
    }

    public function updateTickers(string $exchange, string $symbol, string $period, array $tickers): int
    {
        $unique = ['exchange', 'symbol', 'period', 'microtimestamp'];
        $update = ['payload'];
        $affected = 0;

        foreach (array_chunk($tickers, 100) as $chunk) {
            $affected += DB::transaction(function () use ($exchange, $symbol, $period, $chunk, $unique, $update): int {
                $timestamps = array_column($chunk, 'microtimestamp');
                $wanted = array_fill_keys($timestamps, true);
                $before = [];
                $hasReconstruction = array_any($chunk, fn ($row) => isset($row['reconstruction']))
                    || Ticker::query()->where('exchange', $exchange)->where('symbol', $symbol)->where('period', $period)
                        ->whereIn('microtimestamp', $timestamps)->whereNotNull('payload->reconstruction')->exists();
                $hasArchive = config('archive.enabled') && DB::table('archive_catalog')->where('logical_type', 'tickers')
                    ->where('verification_state', 'verified')->where('exchange', $exchange)->where('symbol', $symbol)->where('period', $period)
                    ->where('range_start_ms', '<=', max($timestamps))->where('range_end_ms', '>=', min($timestamps))->exists();
                if ($hasReconstruction || $hasArchive) {
                    foreach ($this->streamHistory($exchange, $symbol, $period, min($timestamps), max($timestamps)) as $at => $raw) {
                        if (isset($wanted[$at])) {
                            $before[$at] = $raw;
                        }
                    }
                }
                $data = $changed = [];
                $inserted = 0;
                foreach ($chunk as $ticker) {
                    $at = $ticker['microtimestamp'];
                    $row = ['exchange' => $exchange, 'symbol' => $symbol, 'period' => $period,
                        'microtimestamp' => $at, 'payload' => json_encode($ticker, JSON_THROW_ON_ERROR)];
                    if (CandleProvenance::method($ticker) !== null) {
                        if (isset($before[$at])) {
                            continue;
                        }
                        // Even a concurrent native insert wins over a reconstruction.
                        $saved = Ticker::query()->insertOrIgnore([...$row, 'ticker_id' => (string) Str::uuid7(),
                            'created_at' => now(), 'updated_at' => now()]);
                        $inserted += $saved;
                        if ($saved) {
                            $changed[] = $at;
                        }
                    } else {
                        $data[] = $row;
                        if (isset($before[$at]['reconstruction'])) {
                            $changed[] = $at;
                        }
                    }
                }
                $saved = $inserted + ($data === [] ? 0 : $this->update($data, $unique, $update));
                if ($changed !== []) {
                    $markets = Market::query()->where('symbol', $symbol)
                        ->whereHas('exchange', fn ($query) => $query->where('class', $exchange))->pluck('market_id');
                    foreach ($markets as $marketId) {
                        app(HistoryChanges::class)->record($marketId, $period, min($changed), max($changed),
                            'candle_reconstruction', immediate: true);
                    }
                }

                return $saved;
            });
        }

        if ($affected > 0) {
            $this->invalidateHistory($exchange, $symbol, $period);
        }

        return $affected;
    }

    public function saveTickers(string $exchange, string $symbol, string $period, array $tickers): int
    {
        return $this->updateTickers($exchange, $symbol, $period, $tickers);
    }

    public function setExchange(?Exchange $exchange): void
    {
        $this->ccxtExchange = $exchange;
    }

    public function setTicker(Ticker $ticker): void
    {
        $this->ticker = $ticker;
    }

    private function historyCache(): TickerHistoryCache
    {
        return app(TickerHistoryCache::class);
    }
}
