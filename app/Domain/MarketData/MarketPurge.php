<?php

namespace App\Domain\MarketData;

use App\Domain\Intelligence\ModelStore;
use App\Domain\Operations\QueuePayload;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Permanently erase one exchange/symbol market and every canonical artifact owned by it. */
final class MarketPurge
{
    /** @return array<string, mixed> */
    public function plan(string $exchange, string $symbol): array
    {
        $exchange = trim($exchange);
        $symbol = trim($symbol);
        if ($exchange === '' || $symbol === '' || strlen($exchange) > 64 || strlen($symbol) > 128) {
            throw new InvalidArgumentException('Exchange and symbol must be non-empty bounded strings.');
        }

        $exchangeIds = $this->set(DB::table('exchanges')->where('class', $exchange)->pluck('exchange_id')->all());
        $marketIds = $this->set(DB::table('markets as markets')
            ->join('exchanges as exchanges', 'exchanges.exchange_id', '=', 'markets.exchange_id')
            ->where('exchanges.class', $exchange)->where('markets.symbol', $symbol)
            ->pluck('markets.market_id')->all());
        $periods = array_fill_keys(CandleTimeframe::SUPPORTED, true);
        $datasetIds = [];
        $modelIds = [];
        $marketKeys = [];
        foreach (array_keys($periods) as $period) {
            $marketKeys[ModelStore::marketKey($exchange, $symbol, $period)] = true;
        }
        $shadowIds = [];
        $snapshotIds = [];

        $this->collectDirectPeriods($exchange, $symbol, array_keys($marketIds), $periods);

        if (Schema::hasTable('research_datasets')) {
            foreach (DB::table('research_datasets')->select('dataset_id', 'manifest')->cursor() as $row) {
                $manifest = $this->json($row->manifest);
                if ($this->belongs($manifest, $exchange, $symbol)) {
                    $datasetIds[(string) $row->dataset_id] = true;
                    $this->addPeriod($periods, $manifest['period'] ?? null);
                }
            }
        }
        $researchRoot = $this->configuredRoot('research.path');
        if (is_dir($researchRoot)) {
            foreach (new \FilesystemIterator($researchRoot, \FilesystemIterator::SKIP_DOTS) as $directory) {
                $id = $directory->getBasename();
                $manifestPath = $directory->getPathname().DIRECTORY_SEPARATOR.'manifest.json';
                if ($directory->isLink() || ! $directory->isDir() || ! Str::isUuid($id) || ! is_file($manifestPath)) {
                    continue;
                }
                $manifest = $this->json((string) file_get_contents($manifestPath));
                if ($this->belongs($manifest, $exchange, $symbol)) {
                    $datasetIds[$id] = true;
                    $this->addPeriod($periods, $manifest['period'] ?? null);
                }
            }
        }
        if (Schema::hasTable('intelligence_models')) {
            foreach (DB::table('intelligence_models')->select('model_id', 'dataset_id', 'market_key', 'report')->cursor() as $row) {
                $report = $this->json($row->report);
                if ($this->belongs($report, $exchange, $symbol)) {
                    $modelIds[(string) $row->model_id] = true;
                    $datasetIds[(string) $row->dataset_id] = true;
                    $marketKeys[(string) $row->market_key] = true;
                    $this->addPeriod($periods, $report['period'] ?? null);
                }
            }
        }
        if (Schema::hasTable('intelligence_tournaments')) {
            foreach (DB::table('intelligence_tournaments')->select('dataset_id', 'market_key', 'report')->cursor() as $row) {
                $report = $this->json($row->report);
                if ($this->belongs($report, $exchange, $symbol)) {
                    $datasetIds[(string) $row->dataset_id] = true;
                    $marketKeys[(string) $row->market_key] = true;
                    $this->addPeriod($periods, $report['period'] ?? null);
                }
            }
        }
        if (Schema::hasTable('tournament_shadow_models')) {
            foreach (DB::table('tournament_shadow_models')->select('shadow_model_id', 'dataset_id', 'market_key', 'metadata')->cursor() as $row) {
                $metadata = $this->json($row->metadata);
                if ($this->belongs($metadata, $exchange, $symbol)) {
                    $shadowIds[(string) $row->shadow_model_id] = true;
                    $datasetIds[(string) $row->dataset_id] = true;
                    $marketKeys[(string) $row->market_key] = true;
                    $this->addPeriod($periods, $metadata['period'] ?? null);
                }
            }
        }

        $targetMarketKeys = $marketKeys;
        for ($pass = 0; $pass < 5; $pass++) {
            $before = [count($periods), count($datasetIds), count($modelIds), count($marketKeys), count($shadowIds), count($snapshotIds)];
            foreach (array_keys($periods) as $period) {
                $key = ModelStore::marketKey($exchange, $symbol, $period);
                $marketKeys[$key] = true;
                $targetMarketKeys[$key] = true;
            }
            $this->collectRelated('intelligence_models', 'model_id', $modelIds, $datasetIds, $marketKeys);
            $unusedIds = null;
            $this->collectRelated('intelligence_tournaments', null, $unusedIds, $datasetIds, $marketKeys);
            $this->collectRelated('tournament_shadow_models', 'shadow_model_id', $shadowIds, $datasetIds, $marketKeys);
            $this->collectRelated('human_training_snapshots', 'snapshot_id', $snapshotIds, $datasetIds, $marketKeys);
            if (Schema::hasTable('market_action_label_analyses')) {
                foreach (DB::table('market_action_label_analyses')->where('exchange', $exchange)->where('symbol', $symbol)
                    ->whereNotNull('dataset_id')->pluck('dataset_id') as $id) {
                    $datasetIds[(string) $id] = true;
                }
            }
            $after = [count($periods), count($datasetIds), count($modelIds), count($marketKeys), count($shadowIds), count($snapshotIds)];
            if ($before === $after) {
                break;
            }
        }

        $foreignMarketKeys = array_diff_key($marketKeys, $targetMarketKeys);
        if ($foreignMarketKeys !== []) {
            throw new RuntimeException('A target dataset is linked to intelligence for another market; repair the cross-market reference before purging.');
        }
        $this->assertSelectedArtifactsBelong($exchange, $symbol, array_keys($modelIds), array_keys($datasetIds),
            array_keys($shadowIds));

        $historyIds = $this->idsForMarkets('market_history_backfills', 'history_id', array_keys($marketIds));
        $gapIds = $this->idsForMarkets('candle_gap_repairs', 'gap_id', array_keys($marketIds));
        $requestIds = $this->idsForMarkets('history_recovery_requests', 'request_id', array_keys($marketIds));
        $subscriptionIds = $this->idsForMarkets('market_subscriptions', 'market_subscription_id', array_keys($marketIds));
        $signalIds = $this->idsForMarkets('market_signals', 'market_signal_id', array_keys($marketIds));
        $paperAccountIds = ! Schema::hasTable('client_paper_accounts') || $subscriptionIds === [] ? []
            : $this->set(DB::table('client_paper_accounts')->whereIn('market_subscription_id', array_keys($subscriptionIds))
                ->pluck('client_paper_account_id')->all());
        $labelSetIds = ! Schema::hasTable('algorithmic_label_sets') ? []
            : $this->set(DB::table('algorithmic_label_sets')->where('exchange', $exchange)->where('symbol', $symbol)
                ->pluck('label_set_id')->all());
        $predictionIds = [];
        if (Schema::hasTable('tournament_shadow_predictions')) {
            $predictionIds = $this->set(DB::table('tournament_shadow_predictions')
                ->where(function ($query) use ($shadowIds, $marketKeys): void {
                    if ($shadowIds !== []) {
                        $query->whereIn('shadow_model_id', array_keys($shadowIds));
                    } else {
                        $query->whereRaw('1 = 0');
                    }
                    $query->orWhereIn('market_key', array_keys($marketKeys));
                })->pluck('shadow_prediction_id')->all());
        }
        $archiveRows = Schema::hasTable('archive_catalog')
            ? DB::table('archive_catalog')->where('exchange', $exchange)->where('symbol', $symbol)
                ->get(['archive_id', 'path'])->map(fn ($row): array => (array) $row)->all()
            : [];
        foreach ([
            'exchange' => $exchangeIds, 'market' => $marketIds, 'dataset' => $datasetIds, 'model' => $modelIds,
            'shadow model' => $shadowIds, 'human snapshot' => $snapshotIds, 'history' => $historyIds,
            'gap' => $gapIds, 'history recovery request' => $requestIds,
            'subscription' => $subscriptionIds, 'signal' => $signalIds,
            'paper account' => $paperAccountIds, 'label set' => $labelSetIds,
            'shadow prediction' => $predictionIds,
        ] as $description => $ids) {
            $this->assertUuidIds(array_keys($ids), $description);
        }
        $this->assertDigestIds(array_keys($marketKeys), 'market key');
        $this->assertUuidIds(array_values(array_filter(array_column($archiveRows, 'archive_id'))), 'archive');

        $checkpointKeys = $this->checkpointKeys($exchange, $symbol, array_keys($datasetIds));
        $this->assertDigestIds($checkpointKeys, 'checkpoint key');
        $failedIds = $this->failedJobIds($exchange, $symbol, array_keys($marketIds), array_keys($datasetIds),
            array_keys($modelIds), array_keys($shadowIds), array_keys($historyIds), array_keys($gapIds), array_keys($requestIds));

        $plan = [
            'exchange' => $exchange,
            'symbol' => $symbol,
            'exchange_ids' => array_keys($exchangeIds),
            'market_ids' => array_keys($marketIds),
            'subscription_ids' => array_keys($subscriptionIds),
            'signal_ids' => array_keys($signalIds),
            'paper_account_ids' => array_keys($paperAccountIds),
            'label_set_ids' => array_keys($labelSetIds),
            'shadow_prediction_ids' => array_keys($predictionIds),
            'periods' => array_keys($periods),
            'market_keys' => array_keys($marketKeys),
            'dataset_ids' => array_keys($datasetIds),
            'model_ids' => array_keys($modelIds),
            'shadow_model_ids' => array_keys($shadowIds),
            'snapshot_ids' => array_keys($snapshotIds),
            'history_ids' => array_keys($historyIds),
            'gap_ids' => array_keys($gapIds),
            'request_ids' => array_keys($requestIds),
            'checkpoint_keys' => $checkpointKeys,
            'failed_job_ids' => $failedIds,
            'archive_rows' => $archiveRows,
        ];
        $plan['files'] = $this->files($plan);
        $plan['counts'] = $this->counts($plan);

        return $plan;
    }

    /** @param array<string, mixed> $plan */
    public function applyDatabase(array $plan): array
    {
        return DB::transaction(function () use ($plan): array {
            $deleted = [];
            $delete = function (string $table, callable $scope) use (&$deleted): void {
                if (! Schema::hasTable($table)) {
                    return;
                }
                $query = DB::table($table);
                $scope($query);
                $deleted[$table] = ($deleted[$table] ?? 0) + $query->delete();
            };
            $in = static function ($query, string $column, array $ids): void {
                $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn($column, $ids);
            };

            $delete('failed_jobs', fn ($q) => $in($q, 'id', $plan['failed_job_ids']));
            $delete('knowledge_build_checkpoints', fn ($q) => $in($q, 'build_key', $plan['checkpoint_keys']));

            $delete('tournament_shadow_scores', fn ($q) => $in($q, 'shadow_prediction_id', $plan['shadow_prediction_ids']));
            $delete('tournament_shadow_predictions', fn ($q) => $in($q, 'shadow_prediction_id', $plan['shadow_prediction_ids']));
            $delete('tournament_shadow_models', fn ($q) => $in($q, 'shadow_model_id', $plan['shadow_model_ids']));

            $delete('human_candle_labels', fn ($q) => $in($q, 'snapshot_id', $plan['snapshot_ids']));
            $delete('human_training_reviews', fn ($q) => $in($q, 'snapshot_id', $plan['snapshot_ids']));
            $delete('human_training_snapshots', fn ($q) => $in($q, 'snapshot_id', $plan['snapshot_ids']));

            $delete('intelligence_heads', fn ($q) => $in($q, 'market_key', $plan['market_keys']));
            $delete('intelligence_tournaments', function ($q) use ($in, $plan): void {
                $q->where(function ($nested) use ($in, $plan): void {
                    $in($nested, 'dataset_id', $plan['dataset_ids']);
                    if ($plan['market_keys'] !== []) {
                        $nested->orWhereIn('market_key', $plan['market_keys']);
                    }
                });
            });
            $delete('intelligence_models', fn ($q) => $in($q, 'model_id', $plan['model_ids']));
            $delete('research_backtests', fn ($q) => $in($q, 'dataset_id', $plan['dataset_ids']));
            $delete('research_datasets', fn ($q) => $in($q, 'dataset_id', $plan['dataset_ids']));

            $delete('algorithmic_label_chunks', fn ($q) => $in($q, 'label_set_id', $plan['label_set_ids']));
            foreach (['tickers', 'knowledge', 'market_features', 'candle_period_selections', 'algorithmic_label_sets',
                'knowledge_stage_requests', 'independent_human_annotations', 'feature_checkpoints',
                'market_action_label_analyses', 'market_suggestion_dismissals'] as $table) {
                $delete($table, fn ($q) => $q->where('exchange', $plan['exchange'])->where('symbol', $plan['symbol']));
            }
            $delete('client_paper_events', function ($q) use ($in, $plan): void {
                $q->where(function ($nested) use ($in, $plan): void {
                    $in($nested, 'client_paper_account_id', $plan['paper_account_ids']);
                    if ($plan['signal_ids'] !== []) {
                        $nested->orWhereIn('market_signal_id', $plan['signal_ids']);
                    }
                });
            });
            $delete('client_paper_accounts', fn ($q) => $in($q, 'client_paper_account_id', $plan['paper_account_ids']));
            $delete('client_execution_reports', function ($q) use ($in, $plan): void {
                $q->where(function ($nested) use ($in, $plan): void {
                    $in($nested, 'market_subscription_id', $plan['subscription_ids']);
                    if ($plan['signal_ids'] !== []) {
                        $nested->orWhereIn('market_signal_id', $plan['signal_ids']);
                    }
                });
            });
            $delete('client_market_settings', fn ($q) => $in($q, 'market_subscription_id', $plan['subscription_ids']));
            $delete('dashboard_market_favorites', fn ($q) => $in($q, 'market_subscription_id', $plan['subscription_ids']));
            $delete('market_signals', fn ($q) => $in($q, 'market_signal_id', $plan['signal_ids']));
            $delete('market_subscriptions', fn ($q) => $in($q, 'market_subscription_id', $plan['subscription_ids']));
            $delete('coin_gecko_market_mappings', fn ($q) => $in($q, 'market_id', $plan['market_ids']));
            $delete('market_history_changes', fn ($q) => $in($q, 'history_id', $plan['history_ids']));
            $delete('history_recovery_requests', fn ($q) => $in($q, 'request_id', $plan['request_ids']));
            $delete('candle_gap_repairs', fn ($q) => $in($q, 'gap_id', $plan['gap_ids']));
            $delete('market_history_backfills', fn ($q) => $in($q, 'history_id', $plan['history_ids']));
            $delete('market_feeds', fn ($q) => $in($q, 'market_id', $plan['market_ids']));
            $archiveIds = array_values(array_filter(array_column($plan['archive_rows'], 'archive_id')));
            $delete('archive_catalog', fn ($q) => $in($q, 'archive_id', $archiveIds));
            $delete('markets', fn ($q) => $in($q, 'market_id', $plan['market_ids']));

            return $deleted;
        });
    }

    /** @param array<string, mixed> $plan */
    public function deleteFiles(array $plan): array
    {
        $deleted = $missing = $failed = 0;
        foreach ($plan['files'] as $entry) {
            $path = $entry['path'];
            if (! file_exists($path) && ! is_link($path)) {
                $missing++;

                continue;
            }
            if (is_link($path)) {
                $ok = @unlink($path);
            } else {
                $ok = $entry['type'] === 'directory' ? $this->deleteDirectory($path) : File::delete($path);
            }
            $ok ? $deleted++ : $failed++;
        }

        return compact('deleted', 'missing', 'failed');
    }

    /** @param array<string, mixed> $plan */
    public function purgeCache(array $plan): int
    {
        $keys = [];
        foreach ($plan['exchange_ids'] as $id) {
            $keys[] = MarketCatalog::PAIRS_CACHE_PREFIX.$id;
        }
        foreach ($plan['market_ids'] as $id) {
            array_push($keys, 'trademinator:market-feed:'.$id, 'trademinator:candle-period-evaluation:'.$id,
                'trademinator:signal:'.$id);
        }
        foreach ($plan['market_keys'] as $key) {
            array_push($keys, 'trademinator:features:'.$key, 'trademinator:history-intelligence:'.$key,
                'trademinator:intelligence-build:'.$key, 'trademinator:intelligence:'.$key,
                'trademinator:snapshot-labels:'.$key, 'trademinator:snapshot-labels:'.$key.':lock',
                'trademinator:snapshot-labels:'.$key.':status', 'trademinator:snapshot-labels:'.$key.':summary',
                'trademinator:snapshot-labels:'.$key.':error');
        }
        foreach ($plan['dataset_ids'] as $id) {
            $keys[] = 'trademinator:tournament:status:'.$id;
        }
        foreach ($plan['checkpoint_keys'] as $key) {
            $keys[] = 'trademinator:checkpoint:'.$key;
        }
        $defaultRepository = Cache::store();
        foreach (array_unique($keys) as $key) {
            $this->forget($defaultRepository, $key);
        }
        $historyHashes = [];
        $historyRepository = Cache::store((string) config('market_data.history_cache.store', 'redis'));
        foreach ($plan['periods'] as $period) {
            $hash = hash('sha256', $plan['exchange']."\0".$plan['symbol']."\0".$period);
            $historyHashes[] = $hash;
            $this->forget($historyRepository, 'ticker-history:g:'.$hash);
        }

        $removed = count(array_unique($keys)) + count($historyHashes);
        $needles = [...$plan['market_keys'], ...$plan['dataset_ids'], ...$plan['model_ids'],
            ...$plan['shadow_model_ids'], ...$plan['market_ids'], ...$plan['history_ids'],
            ...$plan['gap_ids'], ...$plan['request_ids'], ...$plan['checkpoint_keys'], ...$historyHashes];
        $removed += $this->purgeRedisNeedles($defaultRepository, $needles);
        $removed += $this->purgeRedisNeedles($historyRepository, $historyHashes);

        return $removed;
    }

    /** @param array<string, mixed> $plan */
    public function writeAudit(array $plan): string
    {
        $directory = storage_path('app/private/market-purges');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the private market-purge audit directory.');
        }
        $path = $directory.'/'.now('UTC')->format('Ymd-His').'-'.bin2hex(random_bytes(6)).'.json';
        $bytes = json_encode(['created_at' => now('UTC')->toIso8601String(), 'plan' => $plan],
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
        if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes) || ! chmod($path, 0600)) {
            throw new RuntimeException('Unable to persist the private market-purge audit file.');
        }

        return $path;
    }

    /** @param array<string, mixed> $plan @return array<string, int> */
    public function remaining(array $plan): array
    {
        return $this->counts($plan);
    }

    private function collectDirectPeriods(string $exchange, string $symbol, array $marketIds, array &$periods): void
    {
        foreach (['tickers', 'knowledge', 'market_features', 'candle_period_selections', 'algorithmic_label_sets',
            'knowledge_stage_requests', 'independent_human_annotations', 'feature_checkpoints',
            'market_action_label_analyses', 'archive_catalog'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'period')) {
                continue;
            }
            foreach (DB::table($table)->where('exchange', $exchange)->where('symbol', $symbol)->distinct()->pluck('period') as $period) {
                $this->addPeriod($periods, $period);
            }
        }
        if ($marketIds === []) {
            return;
        }
        foreach ([['market_feeds', 'selected_period'], ['market_history_backfills', 'period'],
            ['candle_gap_repairs', 'period'], ['history_recovery_requests', 'period'], ['market_signals', 'period']] as [$table, $column]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (DB::table($table)->whereIn('market_id', $marketIds)->whereNotNull($column)->distinct()->pluck($column) as $period) {
                $this->addPeriod($periods, $period);
            }
        }
    }

    private function collectRelated(string $table, ?string $idColumn, ?array &$ids, array &$datasetIds, array &$marketKeys): void
    {
        if (! Schema::hasTable($table) || ($datasetIds === [] && $marketKeys === [])) {
            return;
        }
        $columns = array_values(array_filter([$idColumn, 'dataset_id', 'market_key']));
        $query = DB::table($table)->where(function ($q) use ($datasetIds, $marketKeys): void {
            if ($datasetIds !== []) {
                $q->whereIn('dataset_id', array_keys($datasetIds));
            }
            if ($marketKeys !== []) {
                $datasetIds === [] ? $q->whereIn('market_key', array_keys($marketKeys))
                    : $q->orWhereIn('market_key', array_keys($marketKeys));
            }
        });
        foreach ($query->get($columns) as $row) {
            if ($idColumn !== null && $ids !== null) {
                $ids[(string) $row->{$idColumn}] = true;
            }
            $datasetIds[(string) $row->dataset_id] = true;
            $marketKeys[(string) $row->market_key] = true;
        }
    }

    private function checkpointKeys(string $exchange, string $symbol, array $datasetIds): array
    {
        if (! Schema::hasTable('knowledge_build_checkpoints')) {
            return [];
        }
        $keys = [];
        if (Schema::hasColumn('knowledge_build_checkpoints', 'exchange')) {
            foreach (DB::table('knowledge_build_checkpoints')->where('exchange', $exchange)->where('symbol', $symbol)->pluck('build_key') as $key) {
                $keys[(string) $key] = true;
            }
        }
        if ($datasetIds !== []) {
            $lookup = array_fill_keys($datasetIds, true);
            foreach (DB::table('knowledge_build_checkpoints')->whereNull('exchange')->select('build_key', 'state')->cursor() as $row) {
                $state = $this->json($row->state);
                if (isset($state['id'], $lookup[(string) $state['id']])) {
                    $keys[(string) $row->build_key] = true;
                }
            }
        }

        return array_keys($keys);
    }

    private function failedJobIds(string $exchange, string $symbol, array $marketIds, array $datasetIds,
        array $modelIds, array $shadowIds, array $historyIds, array $gapIds, array $requestIds): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [];
        }
        $sets = array_map('array_fill_keys', [$marketIds, $datasetIds, [...$modelIds, ...$shadowIds], $historyIds, $gapIds, $requestIds],
            array_fill(0, 6, true));
        $ids = [];
        $payloads = app(QueuePayload::class);
        foreach (DB::table('failed_jobs')->select('id', 'payload')->cursor() as $row) {
            if (! is_string($row->payload)) {
                continue;
            }
            $parsed = $payloads->read($row->payload, 'failed');
            $properties = $parsed['_properties'] ?? [];
            $direct = ($parsed['exchange'] ?? null) === $exchange && ($parsed['symbol'] ?? null) === $symbol;
            $linked = isset($sets[0][(string) ($properties['marketId'] ?? '')])
                || isset($sets[1][(string) ($properties['datasetId'] ?? '')])
                || isset($sets[1][(string) ($properties['snapshotDataset'] ?? '')])
                || isset($sets[2][(string) ($properties['modelId'] ?? '')])
                || isset($sets[3][(string) ($properties['historyId'] ?? '')])
                || isset($sets[4][(string) ($properties['gapId'] ?? '')])
                || isset($sets[5][(string) ($properties['requestId'] ?? '')]);
            if ($direct || $linked) {
                $ids[] = $row->id;
            }
        }

        return $ids;
    }

    private function files(array $plan): array
    {
        $files = [];
        $add = static function (array &$files, string $path, string $type): void {
            $files[$type.'|'.$path] = ['path' => $path, 'type' => $type];
        };
        $intelligence = $this->configuredRoot('intelligence.path');
        foreach ($plan['model_ids'] as $id) {
            foreach (['.model', '.knowledge.jsonl', '.outcome.knowledge.jsonl', '.action.knowledge.jsonl', '.coingecko-rf'] as $suffix) {
                $add($files, $intelligence.DIRECTORY_SEPARATOR.$id.$suffix, 'file');
            }
        }
        foreach ($plan['shadow_model_ids'] as $id) {
            $add($files, $intelligence.DIRECTORY_SEPARATOR.'shadow-'.$id.'.model', 'file');
        }
        $research = $this->configuredRoot('research.path');
        foreach ($plan['dataset_ids'] as $id) {
            $add($files, $research.DIRECTORY_SEPARATOR.$id, 'directory');
        }
        $archiveRoot = $this->configuredRoot('archive.root');
        foreach ($plan['archive_rows'] as $row) {
            $manifest = $this->safeArchivePath($archiveRoot, (string) $row['path']);
            $add($files, $manifest, 'file');
            $data = null;
            if (is_file($manifest)) {
                $decoded = $this->json((string) file_get_contents($manifest));
                if (is_string($decoded['data_file'] ?? null)) {
                    $data = $this->safeArchivePath($archiveRoot,
                        trim(dirname((string) $row['path']), '.').'/'.$decoded['data_file']);
                }
            }
            if ($data === null && str_ends_with($manifest, '.manifest.json')) {
                $data = substr($manifest, 0, -strlen('.manifest.json')).'.jsonl.gz';
            }
            if ($data !== null) {
                $add($files, $data, 'file');
            }
        }
        $tickerArchiveDirectory = $this->safeArchivePath($archiveRoot,
            'tickers/'.rawurlencode($plan['exchange']).'/'.rawurlencode($plan['symbol']));
        $add($files, $tickerArchiveDirectory, 'directory');

        return array_values($files);
    }

    private function assertSelectedArtifactsBelong(string $exchange, string $symbol, array $modelIds,
        array $datasetIds, array $shadowIds): void
    {
        foreach ([
            ['research_datasets', 'dataset_id', $datasetIds, 'manifest'],
            ['intelligence_models', 'model_id', $modelIds, 'report'],
            ['intelligence_tournaments', 'dataset_id', $datasetIds, 'report'],
            ['tournament_shadow_models', 'shadow_model_id', $shadowIds, 'metadata'],
        ] as [$table, $column, $ids, $json]) {
            if (! Schema::hasTable($table) || $ids === []) {
                continue;
            }
            foreach (DB::table($table)->whereIn($column, $ids)->pluck($json) as $encoded) {
                $payload = $this->json($encoded);
                if (isset($payload['exchange'], $payload['symbol']) && ! $this->belongs($payload, $exchange, $symbol)) {
                    throw new RuntimeException('A selected artifact identifies another market; repair the cross-market reference before purging.');
                }
            }
        }
    }

    private function deleteDirectory(string $path): bool
    {
        if (! is_dir($path) || is_link($path)) {
            return false;
        }
        try {
            foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
                $entryPath = $entry->getPathname();
                if ($entry->isLink()) {
                    if (! @unlink($entryPath)) {
                        return false;
                    }
                } elseif ($entry->isDir()) {
                    if (! $this->deleteDirectory($entryPath)) {
                        return false;
                    }
                } elseif (! @unlink($entryPath)) {
                    return false;
                }
            }

            return @rmdir($path);
        } catch (Throwable) {
            return false;
        }
    }

    private function forget(Repository $repository, string $key): void
    {
        $last = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $repository->forget($key);

                return;
            } catch (Throwable $error) {
                $last = $error;
                usleep(50000 * ($attempt + 1));
            }
        }

        throw new RuntimeException('Unable to invalidate cache state required for market purge.', previous: $last);
    }

    private function purgeRedisNeedles($repository, array $needles): int
    {
        try {
            $store = $repository->getStore();
            if (! $store instanceof RedisStore) {
                return 0;
            }
            $connection = $store->lockConnection();
            if ($connection->isCluster()) {
                return 0;
            }
            $removed = 0;
            $prefix = $store->getPrefix();
            foreach (array_unique(array_filter($needles, fn ($needle): bool => is_string($needle) && $needle !== '')) as $needle) {
                $cursor = '0';
                do {
                    $scan = $connection->scan($cursor, ['match' => $prefix.'*'.$needle.'*', 'count' => 250]);
                    if (! is_array($scan) || count($scan) !== 2) {
                        break;
                    }
                    [$cursor, $matches] = $scan;
                    if ($matches !== []) {
                        $removed += (int) $connection->del($matches);
                    }
                } while ((string) $cursor !== '0');
            }

            return $removed;
        } catch (Throwable) {
            // Known keys were removed directly; unknown cache entries expire normally.
            return 0;
        }
    }

    private function assertUuidIds(array $ids, string $description): void
    {
        foreach ($ids as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                throw new RuntimeException('Market purge found an invalid '.$description.' identifier.');
            }
        }
    }

    private function assertDigestIds(array $ids, string $description): void
    {
        foreach ($ids as $id) {
            if (! is_string($id) || preg_match('/\A[0-9a-f]{64}\z/D', $id) !== 1) {
                throw new RuntimeException('Market purge found an invalid '.$description.'.');
            }
        }
    }

    private function configuredRoot(string $key): string
    {
        $root = rtrim((string) config($key), DIRECTORY_SEPARATOR);
        if ($root === '' || $root === DIRECTORY_SEPARATOR || ! str_starts_with($root, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Market purge requires a safe absolute '.$key.' directory.');
        }

        return $root;
    }

    private function safeArchivePath(string $root, string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);
        $parts = explode('/', $relative);
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, "\0")
            || in_array('..', $parts, true)) {
            throw new RuntimeException('Unsafe archive path in catalog.');
        }

        return $root.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, array_values(array_filter($parts, fn ($part) => $part !== '' && $part !== '.')));
    }

    private function counts(array $plan): array
    {
        $counts = [];
        $scope = fn (string $table) => Schema::hasTable($table)
            ? DB::table($table)->where('exchange', $plan['exchange'])->where('symbol', $plan['symbol'])->count() : 0;
        foreach (['tickers', 'knowledge', 'market_features', 'candle_period_selections', 'algorithmic_label_sets',
            'knowledge_stage_requests', 'independent_human_annotations', 'feature_checkpoints',
            'market_action_label_analyses', 'market_suggestion_dismissals'] as $table) {
            $counts[$table] = $scope($table);
        }
        foreach ([
            'markets' => ['market_id', $plan['market_ids']],
            'market_subscriptions' => ['market_id', $plan['market_ids']],
            'market_feeds' => ['market_id', $plan['market_ids']],
            'market_history_backfills' => ['market_id', $plan['market_ids']],
            'candle_gap_repairs' => ['market_id', $plan['market_ids']],
            'history_recovery_requests' => ['market_id', $plan['market_ids']],
            'market_signals' => ['market_id', $plan['market_ids']],
            'intelligence_heads' => ['market_key', $plan['market_keys']],
            'intelligence_models' => ['model_id', $plan['model_ids']],
            'intelligence_tournaments' => ['dataset_id', $plan['dataset_ids']],
            'tournament_shadow_models' => ['shadow_model_id', $plan['shadow_model_ids']],
            'human_training_snapshots' => ['snapshot_id', $plan['snapshot_ids']],
            'research_datasets' => ['dataset_id', $plan['dataset_ids']],
            'research_backtests' => ['dataset_id', $plan['dataset_ids']],
            'knowledge_build_checkpoints' => ['build_key', $plan['checkpoint_keys']],
            'failed_jobs' => ['id', $plan['failed_job_ids']],
            'archive_catalog' => ['archive_id', array_values(array_filter(array_column($plan['archive_rows'], 'archive_id')))],
        ] as $table => [$column, $ids]) {
            $counts[$table] = ! Schema::hasTable($table) || $ids === [] ? 0 : DB::table($table)->whereIn($column, $ids)->count();
        }
        foreach ([
            'algorithmic_label_chunks' => ['label_set_id', $plan['label_set_ids']],
            'tournament_shadow_predictions' => ['shadow_prediction_id', $plan['shadow_prediction_ids']],
            'tournament_shadow_scores' => ['shadow_prediction_id', $plan['shadow_prediction_ids']],
            'human_training_reviews' => ['snapshot_id', $plan['snapshot_ids']],
            'human_candle_labels' => ['snapshot_id', $plan['snapshot_ids']],
            'dashboard_market_favorites' => ['market_subscription_id', $plan['subscription_ids']],
            'client_market_settings' => ['market_subscription_id', $plan['subscription_ids']],
            'client_paper_accounts' => ['client_paper_account_id', $plan['paper_account_ids']],
            'market_history_changes' => ['history_id', $plan['history_ids']],
            'coin_gecko_market_mappings' => ['market_id', $plan['market_ids']],
        ] as $table => [$column, $ids]) {
            $counts[$table] = ! Schema::hasTable($table) || $ids === [] ? 0 : DB::table($table)->whereIn($column, $ids)->count();
        }
        if (Schema::hasTable('client_execution_reports')) {
            $counts['client_execution_reports'] = DB::table('client_execution_reports')->where(function ($query) use ($plan): void {
                $plan['subscription_ids'] === [] ? $query->whereRaw('1 = 0')
                    : $query->whereIn('market_subscription_id', $plan['subscription_ids']);
                if ($plan['signal_ids'] !== []) {
                    $query->orWhereIn('market_signal_id', $plan['signal_ids']);
                }
            })->count();
        }
        if (Schema::hasTable('client_paper_events')) {
            $counts['client_paper_events'] = DB::table('client_paper_events')->where(function ($query) use ($plan): void {
                $plan['paper_account_ids'] === [] ? $query->whereRaw('1 = 0')
                    : $query->whereIn('client_paper_account_id', $plan['paper_account_ids']);
                if ($plan['signal_ids'] !== []) {
                    $query->orWhereIn('market_signal_id', $plan['signal_ids']);
                }
            })->count();
        }
        $counts['files_present'] = count(array_filter($plan['files'], fn ($entry) => file_exists($entry['path']) || is_link($entry['path'])));

        return array_filter($counts, fn ($count) => $count > 0);
    }

    private function idsForMarkets(string $table, string $column, array $marketIds): array
    {
        return ! Schema::hasTable($table) || $marketIds === [] ? []
            : $this->set(DB::table($table)->whereIn('market_id', $marketIds)->pluck($column)->all());
    }

    private function belongs(array $payload, string $exchange, string $symbol): bool
    {
        return ($payload['exchange'] ?? null) === $exchange && ($payload['symbol'] ?? null) === $symbol;
    }

    private function addPeriod(array &$periods, mixed $period): void
    {
        if (is_string($period) && $period !== '') {
            $periods[$period] = true;
        }
    }

    private function json(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function set(array $values): array
    {
        $set = [];
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                $set[(string) $value] = true;
            }
        }

        return $set;
    }
}
