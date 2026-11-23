<?php

use App\Domain\Intelligence\BuildCheckpoint;
use App\Domain\Intelligence\ModelStore;
use App\Domain\MarketData\MarketPurge;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->purgeRoot = sys_get_temp_dir().'/trademinator-market-purge-'.Str::uuid7();
    config([
        'research.path' => $this->purgeRoot.'/research',
        'intelligence.path' => $this->purgeRoot.'/intelligence',
        'archive.root' => $this->purgeRoot.'/archive',
        'market_data.history_cache.enabled' => false,
    ]);
    File::ensureDirectoryExists(config('research.path'), 0700);
    File::ensureDirectoryExists(config('intelligence.path'), 0700);
    File::ensureDirectoryExists(config('archive.root'), 0700);
});

afterEach(function () {
    File::deleteDirectory($this->purgeRoot);
});

function purgeMarketFixture(string $symbol): array
{
    $exchange = Exchange::query()->firstOrCreate(['class' => 'kraken'], ['name' => 'Kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => $symbol, 'tick_size' => '0.01']);
    MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1m', 'status' => 'ready']);
    $user = User::factory()->create();
    $subscription = MarketSubscription::query()->create([
        'user_id' => $user->user_id, 'market_id' => $market->market_id, 'active' => true,
    ]);

    $dataset = (string) Str::uuid7();
    $model = (string) Str::uuid7();
    $shadow = (string) Str::uuid7();
    $prediction = (string) Str::uuid7();
    $marketKey = ModelStore::marketKey('kraken', $symbol, '1m');
    $manifest = ['dataset_id' => $dataset, 'format_version' => 'm3-dataset-v1',
        'exchange' => 'kraken', 'symbol' => $symbol, 'period' => '1m'];
    DB::table('research_datasets')->insert([
        'dataset_id' => $dataset, 'manifest' => json_encode($manifest), 'created_at' => now(),
    ]);
    DB::table('intelligence_models')->insert([
        'model_id' => $model, 'dataset_id' => $dataset, 'market_key' => $marketKey,
        'status' => 'ready', 'generation_key' => null, 'sha256' => str_repeat('a', 64),
        'report' => json_encode([...$manifest, 'model_id' => $model]), 'created_at' => now(),
    ]);
    DB::table('intelligence_heads')->insert(['market_key' => $marketKey, 'model_id' => $model, 'updated_at' => now()]);
    DB::table('intelligence_tournaments')->insert([
        'dataset_id' => $dataset, 'market_key' => $marketKey, 'report' => json_encode($manifest),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('tournament_shadow_models')->insert([
        'shadow_model_id' => $shadow, 'dataset_id' => $dataset, 'market_key' => $marketKey,
        'task' => 'action', 'algorithm' => 'weighted_knn', 'version' => 'test', 'sha256' => str_repeat('b', 64),
        'trained_until_ms' => 1, 'published_at_ms' => 2, 'metadata' => json_encode($manifest),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('tournament_shadow_predictions')->insert([
        'shadow_prediction_id' => $prediction, 'shadow_model_id' => $shadow, 'market_key' => $marketKey,
        'decision_at_ms' => 3, 'predicted_at_ms' => 4, 'prediction' => 'buy', 'supported' => true,
        'confidence' => '0.9', 'probabilities' => '{}', 'reason' => 'supported',
        'feature_sha256' => str_repeat('c', 64), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('tournament_shadow_scores')->insert([
        'shadow_prediction_id' => $prediction, 'actual' => 'buy', 'label_available_at_ms' => 5,
        'scored_at_ms' => 6, 'correct' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $orphanPrediction = (string) Str::uuid7();
    DB::table('tournament_shadow_predictions')->insert([
        'shadow_prediction_id' => $orphanPrediction, 'shadow_model_id' => (string) Str::uuid7(),
        'market_key' => $marketKey, 'decision_at_ms' => 7, 'predicted_at_ms' => 8,
        'prediction' => 'sell', 'supported' => true, 'confidence' => '0.8',
        'probabilities' => '{}', 'reason' => 'supported', 'feature_sha256' => str_repeat('3', 64),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('tournament_shadow_scores')->insert([
        'shadow_prediction_id' => $orphanPrediction, 'actual' => 'sell', 'label_available_at_ms' => 9,
        'scored_at_ms' => 10, 'correct' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('tickers')->insert([
        'ticker_id' => (string) Str::uuid7(), 'exchange' => 'kraken', 'symbol' => $symbol, 'period' => '1m',
        'microtimestamp' => 60000, 'payload' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('market_features')->insert([
        'feature_id' => (string) Str::uuid7(), 'exchange' => 'kraken', 'symbol' => $symbol, 'period' => '1m',
        'microtimestamp' => 60000, 'available_at_ms' => 120000, 'version' => 'test', 'payload' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('knowledge_stage_requests')->insert([
        'request_id' => (string) Str::uuid7(), 'exchange' => 'kraken', 'symbol' => $symbol, 'period' => '1m',
        'stage' => 'ai', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $history = (string) Str::uuid7();
    DB::table('market_history_backfills')->insert([
        'history_id' => $history, 'market_id' => $market->market_id, 'period' => '1m',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('market_history_changes')->insert([
        'change_id' => (string) Str::uuid7(), 'history_id' => $history, 'revision' => 1,
        'source' => 'fixture', 'created_at' => now(),
    ]);
    DB::table('history_recovery_requests')->insert([
        'request_id' => (string) Str::uuid7(), 'market_id' => $market->market_id, 'period' => '1m',
        'schema' => 'core', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('candle_gap_repairs')->insert([
        'gap_id' => (string) Str::uuid7(), 'market_id' => $market->market_id, 'period' => '1m',
        'from_ms' => 0, 'to_ms' => 60000, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('coin_gecko_market_mappings')->updateOrInsert(
        ['market_id' => $market->market_id],
        ['coin_gecko_market_mapping_id' => (string) Str::uuid7(), 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now()]
    );
    DB::table('knowledge')->insert([
        'knowledge_id' => (string) Str::uuid7(), 'microtimestamp' => 60000, 'exchange' => 'kraken',
        'symbol' => $symbol, 'period' => '1m', 'action' => 'hodl', 'value' => '0',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('candle_period_selections')->insert([
        'selection_id' => (string) Str::uuid7(), 'exchange' => 'kraken', 'symbol' => $symbol, 'period' => '1m',
        'sample_from_ms' => 0, 'sample_to_ms' => 60000, 'threshold' => '0.1', 'minimum_coverage' => '0.9',
        'quality' => '{}', 'created_at' => now(),
    ]);
    $labelSet = (string) Str::uuid7();
    DB::table('algorithmic_label_sets')->insert([
        'label_set_id' => $labelSet, 'exchange' => 'kraken', 'symbol' => $symbol, 'period' => '1m',
        'as_of_ms' => 120000, 'history_revision' => 1, 'action_version' => 'fixture',
        'outcome_version' => 'fixture', 'profile_sha256' => str_repeat('e', 64),
        'sha256' => hash('sha256', '{}'), 'payload' => '{}', 'created_at' => now(),
    ]);
    DB::table('algorithmic_label_chunks')->insert([
        'label_set_id' => $labelSet, 'kind' => 'action_labels', 'chunk' => 0,
        'first_ms' => 60000, 'last_ms' => 60000, 'row_count' => 1,
        'sha256' => hash('sha256', '{}'), 'payload' => '{}',
    ]);
    DB::table('market_action_label_analyses')->insert([
        'exchange' => 'kraken', 'symbol' => $symbol, 'period' => '1m', 'as_of_ms' => 120000,
        'analysis' => '{}', 'dataset_id' => $dataset, 'source' => 'fixture',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('feature_checkpoints')->insert([
        'checkpoint_id' => (string) Str::uuid7(), 'exchange' => 'kraken', 'symbol' => $symbol,
        'period' => '1m', 'feature_version' => 'fixture', 'checkpoint_version' => 1,
        'through_ms' => 60000, 'state' => '{}', 'sha256' => hash('sha256', '{}'),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('independent_human_annotations')->insert([
        'annotation_id' => (string) Str::uuid7(), 'trainer_id' => $user->user_id,
        'exchange' => 'kraken', 'symbol' => $symbol, 'period' => '1m', 'source' => 'action',
        'microtimestamp' => 60000, 'candle_sha256' => str_repeat('f', 64), 'label' => 'buy',
        'recorded_at' => now(),
    ]);
    DB::table('market_suggestion_dismissals')->insert([
        'user_id' => $user->user_id, 'exchange' => 'kraken', 'symbol' => $symbol, 'dismissed_at' => now(),
    ]);
    $snapshot = (string) Str::uuid7();
    DB::table('human_training_snapshots')->insert([
        'snapshot_id' => $snapshot, 'snapshot_key' => hash('sha256', 'snapshot|'.$symbol),
        'market_key' => $marketKey, 'dataset_id' => $dataset, 'decision_at_ms' => 60000,
        'version' => 'fixture', 'sha256' => hash('sha256', '{}'), 'payload' => '{}', 'created_at' => now(),
    ]);
    DB::table('human_training_reviews')->insert([
        'review_id' => (string) Str::uuid7(), 'snapshot_id' => $snapshot, 'trainer_id' => $user->user_id,
        'label' => 'bull', 'shown_at' => now(), 'expires_at' => now()->addHour(), 'submitted_at' => now(),
    ]);
    DB::table('human_candle_labels')->insert([
        'candle_label_id' => (string) Str::uuid7(), 'snapshot_id' => $snapshot,
        'trainer_id' => $user->user_id, 'action' => 'buy', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $signal = (string) Str::uuid7();
    DB::table('market_signals')->insert([
        'market_signal_id' => $signal, 'market_id' => $market->market_id,
        'snapshot_key' => hash('sha256', 'signal|'.$symbol), 'period' => '1m', 'model_id' => $model,
        'decision_at_ms' => 60000, 'recorded_at_ms' => 120000, 'is_change' => true,
        'action' => 'hodl', 'reason' => 'fixture', 'payload' => '{}',
    ]);
    DB::table('dashboard_market_favorites')->insert([
        'user_id' => $user->user_id, 'market_subscription_id' => $subscription->market_subscription_id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('client_market_settings')->insert([
        'client_market_setting_id' => (string) Str::uuid7(), 'user_id' => $user->user_id,
        'market_subscription_id' => $subscription->market_subscription_id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('client_execution_reports')->insert([
        'client_execution_report_id' => (string) Str::uuid7(), 'user_id' => $user->user_id,
        'market_subscription_id' => $subscription->market_subscription_id, 'market_signal_id' => $signal,
        'idempotency_key' => 'fixture-'.$symbol, 'request_hash' => str_repeat('1', 64),
        'event' => 'skipped', 'reason' => 'fixture', 'occurred_at_ms' => 120000, 'recorded_at_ms' => 120001,
    ]);
    $paperAccount = (string) Str::uuid7();
    DB::table('client_paper_accounts')->insert([
        'client_paper_account_id' => $paperAccount, 'user_id' => $user->user_id,
        'market_subscription_id' => $subscription->market_subscription_id,
        'quote_balance' => '1000', 'base_balance' => '0', 'initial_quote_balance' => '1000',
        'benchmark_base_quantity' => '0', 'benchmark_start_price' => '1', 'peak_equity' => '1000',
        'started_at_ms' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('client_paper_events')->insert([
        'client_paper_event_id' => (string) Str::uuid7(), 'client_paper_account_id' => $paperAccount,
        'market_signal_id' => $signal, 'idempotency_key' => 'fixture-'.$symbol,
        'request_hash' => str_repeat('2', 64), 'event' => 'skipped', 'reason' => 'fixture',
        'occurred_at_ms' => 120000, 'recorded_at_ms' => 120001, 'result' => '{}',
    ]);

    $datasetDir = config('research.path').'/'.$dataset;
    File::ensureDirectoryExists($datasetDir, 0700);
    file_put_contents($datasetDir.'/manifest.json', json_encode($manifest));
    $orphanDataset = (string) Str::uuid7();
    $orphanDatasetDir = config('research.path').'/'.$orphanDataset;
    File::ensureDirectoryExists($orphanDatasetDir, 0700);
    file_put_contents($orphanDatasetDir.'/manifest.json', json_encode([
        ...$manifest, 'dataset_id' => $orphanDataset,
    ]));
    foreach (['.model', '.knowledge.jsonl', '.outcome.knowledge.jsonl', '.action.knowledge.jsonl', '.coingecko-rf'] as $suffix) {
        file_put_contents(config('intelligence.path').'/'.$model.$suffix, 'fixture');
    }
    file_put_contents(config('intelligence.path').'/shadow-'.$shadow.'.model', 'fixture');

    $relative = 'tickers/kraken/'.rawurlencode($symbol).'/1m/2026-01';
    $data = config('archive.root').'/'.$relative.'.jsonl.gz';
    $manifestPath = config('archive.root').'/'.$relative.'.manifest.json';
    File::ensureDirectoryExists(dirname($data), 0700);
    file_put_contents($data, 'archive');
    file_put_contents($manifestPath, json_encode(['data_file' => basename($data)]));
    $orphanArchive = dirname($data).'/uncatalogued.fixture';
    file_put_contents($orphanArchive, 'orphan');
    $outsideArchive = dirname(config('archive.root')).'/outside-'.hash('sha256', $symbol).'.fixture';
    file_put_contents($outsideArchive, 'preserve');
    $archiveSymlink = dirname($data).'/outside-link.fixture';
    symlink($outsideArchive, $archiveSymlink);
    DB::table('archive_catalog')->insert([
        'archive_id' => (string) Str::uuid7(), 'logical_type' => 'tickers', 'exchange' => 'kraken',
        'symbol' => $symbol, 'period' => '1m', 'range_start_ms' => 0, 'range_end_ms' => 60000,
        'row_count' => 1, 'format_version' => 1, 'schema_version' => 1, 'compression' => 'gzip',
        'path' => $relative.'.manifest.json', 'sha256' => str_repeat('d', 64), 'compressed_size' => 7,
        'verification_state' => 'verified', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return compact('market', 'dataset', 'orphanDataset', 'model', 'shadow', 'prediction', 'orphanPrediction',
        'marketKey', 'datasetDir', 'orphanDatasetDir', 'data', 'manifestPath', 'orphanArchive',
        'outsideArchive', 'archiveSymlink');
}

it('defaults purge-market to a read-only audit', function () {
    $target = purgeMarketFixture('BTC/USD');

    $this->artisan('trademinator:purge-market', ['exchange' => 'kraken', 'symbol' => 'BTC/USD'])
        ->expectsOutputToContain('Dry run only')
        ->assertSuccessful();

    $this->assertDatabaseHas('markets', ['market_id' => $target['market']->market_id]);
    $this->assertDatabaseHas('intelligence_models', ['model_id' => $target['model']]);
    expect(is_file($target['data']))->toBeTrue();
});

it('erases the selected market while preserving another market', function () {
    $target = purgeMarketFixture('BTC/USD');
    $other = purgeMarketFixture('ETH/USD');
    $service = app(MarketPurge::class);

    $plan = $service->plan('kraken', 'BTC/USD');
    expect($plan['market_ids'])->toContain($target['market']->market_id)
        ->and($plan['dataset_ids'])->toContain($target['dataset'])
        ->and($plan['dataset_ids'])->toContain($target['orphanDataset'])
        ->and($plan['model_ids'])->toContain($target['model'])
        ->and($plan['shadow_model_ids'])->toContain($target['shadow'])
        ->and($plan['counts']['tickers'])->toBe(1);

    $service->applyDatabase($plan);
    $files = $service->deleteFiles($plan);

    expect($files['failed'])->toBe(0);
    $this->assertDatabaseMissing('markets', ['market_id' => $target['market']->market_id]);
    $this->assertDatabaseMissing('market_subscriptions', ['market_id' => $target['market']->market_id]);
    $this->assertDatabaseMissing('market_feeds', ['market_id' => $target['market']->market_id]);
    $this->assertDatabaseMissing('market_signals', ['market_id' => $target['market']->market_id]);
    $this->assertDatabaseMissing('market_history_backfills', ['market_id' => $target['market']->market_id]);
    $this->assertDatabaseMissing('coin_gecko_market_mappings', ['market_id' => $target['market']->market_id]);
    $this->assertDatabaseMissing('tickers', ['exchange' => 'kraken', 'symbol' => 'BTC/USD']);
    $this->assertDatabaseMissing('algorithmic_label_sets', ['exchange' => 'kraken', 'symbol' => 'BTC/USD']);
    $this->assertDatabaseMissing('feature_checkpoints', ['exchange' => 'kraken', 'symbol' => 'BTC/USD']);
    $this->assertDatabaseMissing('independent_human_annotations', ['exchange' => 'kraken', 'symbol' => 'BTC/USD']);
    $this->assertDatabaseMissing('intelligence_models', ['model_id' => $target['model']]);
    $this->assertDatabaseMissing('research_datasets', ['dataset_id' => $target['dataset']]);
    $this->assertDatabaseMissing('tournament_shadow_models', ['shadow_model_id' => $target['shadow']]);
    $this->assertDatabaseMissing('tournament_shadow_predictions', ['shadow_prediction_id' => $target['prediction']]);
    $this->assertDatabaseMissing('tournament_shadow_predictions', ['shadow_prediction_id' => $target['orphanPrediction']]);
    $this->assertDatabaseMissing('tournament_shadow_scores', ['shadow_prediction_id' => $target['orphanPrediction']]);
    expect(DB::table('client_market_settings')->count())->toBe(1)
        ->and(DB::table('client_execution_reports')->count())->toBe(1)
        ->and(DB::table('client_paper_accounts')->count())->toBe(1)
        ->and(DB::table('client_paper_events')->count())->toBe(1)
        ->and(DB::table('human_training_snapshots')->where('market_key', $target['marketKey'])->count())->toBe(0)
        ->and(DB::table('human_training_reviews')->count())->toBe(1)
        ->and(DB::table('human_candle_labels')->count())->toBe(1);
    expect(is_dir($target['datasetDir']))->toBeFalse()
        ->and(is_dir($target['orphanDatasetDir']))->toBeFalse()
        ->and(is_file($target['data']))->toBeFalse()
        ->and(is_file($target['manifestPath']))->toBeFalse()
        ->and(is_file($target['orphanArchive']))->toBeFalse()
        ->and(is_link($target['archiveSymlink']))->toBeFalse()
        ->and(file_get_contents($target['outsideArchive']))->toBe('preserve');

    $this->assertDatabaseHas('markets', ['market_id' => $other['market']->market_id]);
    $this->assertDatabaseHas('tickers', ['exchange' => 'kraken', 'symbol' => 'ETH/USD']);
    $this->assertDatabaseHas('intelligence_models', ['model_id' => $other['model']]);
    expect(is_dir($other['datasetDir']))->toBeTrue()
        ->and(is_file($other['data']))->toBeTrue();
});

it('keeps large signal and shadow history out of the purge plan', function () {
    $target = purgeMarketFixture('BTC/USD');
    $signals = [];
    $predictions = [];
    $scores = [];
    for ($i = 0; $i < 2000; $i++) {
        $prediction = (string) Str::uuid7();
        $signals[] = [
            'market_signal_id' => (string) Str::uuid7(), 'market_id' => $target['market']->market_id,
            'snapshot_key' => hash('sha256', 'bulk-signal|'.$i), 'period' => '1m', 'model_id' => $target['model'],
            'decision_at_ms' => 100000 + $i, 'recorded_at_ms' => 200000 + $i, 'is_change' => false,
            'action' => 'hodl', 'reason' => 'fixture', 'payload' => '{}',
        ];
        $predictions[] = [
            'shadow_prediction_id' => $prediction, 'shadow_model_id' => $target['shadow'],
            'market_key' => $target['marketKey'], 'decision_at_ms' => 100000 + $i,
            'predicted_at_ms' => 200000 + $i, 'prediction' => 'buy', 'supported' => true,
            'confidence' => '0.9', 'probabilities' => '{}', 'reason' => 'supported',
            'feature_sha256' => str_repeat('c', 64), 'created_at' => now(), 'updated_at' => now(),
        ];
        $scores[] = [
            'shadow_prediction_id' => $prediction, 'actual' => 'buy', 'label_available_at_ms' => 300000 + $i,
            'scored_at_ms' => 400000 + $i, 'correct' => true, 'created_at' => now(), 'updated_at' => now(),
        ];
    }
    foreach (array_chunk($signals, 250) as $rows) {
        DB::table('market_signals')->insert($rows);
    }
    foreach (array_chunk($predictions, 250) as $rows) {
        DB::table('tournament_shadow_predictions')->insert($rows);
    }
    foreach (array_chunk($scores, 250) as $rows) {
        DB::table('tournament_shadow_scores')->insert($rows);
    }

    $service = app(MarketPurge::class);
    $plan = $service->plan('kraken', 'BTC/USD');

    expect($plan['signal_ids'])->toBe([])
        ->and($plan['shadow_prediction_ids'])->toBe([])
        ->and($plan['counts']['market_signals'])->toBe(2001)
        ->and($plan['counts']['tournament_shadow_predictions'])->toBe(2002)
        ->and($plan['counts']['tournament_shadow_scores'])->toBe(2002);

    $service->applyDatabase($plan);

    expect(DB::table('market_signals')->where('market_id', $target['market']->market_id)->count())->toBe(0)
        ->and(DB::table('tournament_shadow_predictions')->where('market_key', $target['marketKey'])->count())->toBe(0)
        ->and(DB::table('tournament_shadow_scores')->count())->toBe(0);
});

it('stores checkpoint scope so a market purge can remove resumable work', function () {
    $checkpoint = new BuildCheckpoint([
        'kind' => 'labels', 'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
        'as_of_ms' => 120000, 'profile' => 'fixture', 'implementation' => 'fixture',
    ]);
    try {
        $this->assertDatabaseHas('knowledge_build_checkpoints', [
            'build_key' => $checkpoint->key, 'kind' => 'labels', 'exchange' => 'kraken',
            'symbol' => 'BTC/USD', 'period' => '1m',
        ]);
    } finally {
        $checkpoint->close();
    }
});

it('refuses to purge a dataset that is also linked to another market', function () {
    $target = purgeMarketFixture('BTC/USD');
    $other = purgeMarketFixture('ETH/USD');
    DB::table('intelligence_models')->where('model_id', $other['model'])
        ->update(['dataset_id' => $target['dataset']]);

    expect(fn () => app(MarketPurge::class)->plan('kraken', 'BTC/USD'))
        ->toThrow(RuntimeException::class, 'cross-market reference');
});

it('ignores checkpoint metadata source edits but invalidates identities when build dependencies change', function () {
    $originalBase = base_path();
    $fixture = $this->purgeRoot.'/implementation';
    File::ensureDirectoryExists($fixture, 0700);
    File::copyDirectory(base_path('app'), $fixture.'/app');
    File::copy(base_path('composer.lock'), $fixture.'/composer.lock');

    try {
        $this->app->setBasePath($fixture);
        $labels = BuildCheckpoint::implementation('labels');
        $snapshot = BuildCheckpoint::implementation('snapshot');

        // Scope metadata is excluded through the explicit checkpoint protocol digest.
        File::append(base_path('app/Domain/Intelligence/BuildCheckpoint.php'), "\n// Scope metadata edit.\n");
        expect(BuildCheckpoint::implementation('labels'))->toBe($labels);
        expect(BuildCheckpoint::implementation('snapshot'))->toBe($snapshot);

        File::append(base_path('app/Domain/Intelligence/ActionAutoLabeler.php'), "\n// Label implementation edit.\n");
        $changedLabels = BuildCheckpoint::implementation('labels');
        $changedSnapshot = BuildCheckpoint::implementation('snapshot');
        expect($changedLabels)->not->toBe($labels);
        expect($changedSnapshot)->not->toBe($snapshot);

        File::append(base_path('app/Domain/Research/DatasetSnapshotBuilder.php'), "\n// Snapshot implementation edit.\n");
        expect(BuildCheckpoint::implementation('labels'))->toBe($changedLabels);
        expect(BuildCheckpoint::implementation('snapshot'))->not->toBe($changedSnapshot);
    } finally {
        $this->app->setBasePath($originalBase);
    }
});

it('refuses malformed persisted identifiers before constructing artifact paths', function () {
    $exchange = Exchange::query()->create(['class' => 'kraken', 'name' => 'Kraken', 'config' => '{}']);
    DB::table('markets')->insert([
        'market_id' => '../outside', 'exchange_id' => $exchange->exchange_id,
        'symbol' => 'BTC/USD', 'tick_size' => '0.01', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => app(MarketPurge::class)->plan('kraken', 'BTC/USD'))
        ->toThrow(RuntimeException::class, 'invalid market identifier');
});
