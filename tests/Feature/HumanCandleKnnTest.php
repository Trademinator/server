<?php

use App\Domain\Features\ContextFeatures;
use App\Domain\Intelligence\HumanCandleKnn;
use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\MarketIntelligence;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Intelligence\NormalizedVector;
use App\Domain\Operations\ActionContext;
use App\Domain\Operations\ActionLog;
use App\Domain\Research\DatasetStore;
use App\Models\HumanCandleLabel;
use App\Models\HumanTrainingSnapshot;
use App\Models\MarketFeature;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    $path = sys_get_temp_dir().'/two-knn-'.Str::uuid7();
    config(['research.path' => $path.'/research', 'intelligence.path' => $path.'/models',
        'intelligence.knn.min_train_size' => 36, 'intelligence.knn.test_size' => 12,
        'intelligence.knn.min_validation_rows' => 5, 'intelligence.knn.min_directional_predictions' => 1,
        'intelligence.patterns.enabled' => false, 'human_training.enabled' => true,
        'human_training.candle_enabled' => true, 'human_training.trend_enabled' => true,
        'human_training.candle_min_samples' => 50, 'human_training.candle_k' => 9]);
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $this->trainer = User::factory()->create();
    config(['operations.owner_uuid' => $this->trainer->user_id]);
});

afterEach(function () {
    File::deleteDirectory(dirname(config('research.path')));
});

function twoKnnAnnotation(array $manifest, array $row, User $trainer, string $action): HumanTrainingSnapshot
{
    $payload = array_replace(HumanTrainingSnapshot::factory()->make()->payload, [
        'feature_version' => $manifest['feature_version'], 'decision_at_ms' => $row['decision_at_ms'],
        'microtimestamp' => $row['microtimestamp'], 'keys' => $manifest['keys'],
        'exchange' => $manifest['exchange'], 'symbol' => $manifest['symbol'], 'period' => $manifest['period'],
        'vector' => NormalizedVector::from($row['vector'], $manifest['keys']),
        'features' => array_combine($manifest['keys'], $row['vector']),
        'horizon_candles' => $manifest['label_definition']['horizon'],
        'feature_sha256' => $row['source']['feature_sha256'] ?? null,
    ]);
    $snapshot = HumanTrainingSnapshot::factory()->create(['dataset_id' => $manifest['dataset_id'], 'payload' => $payload,
        'market_key' => ModelStore::marketKey($manifest['exchange'], $manifest['symbol'], $manifest['period']),
        'snapshot_key' => hash('sha256', (string) Str::uuid7())]);
    HumanCandleLabel::factory()->create(['snapshot_id' => $snapshot->snapshot_id, 'trainer_id' => $trainer->user_id,
        'action' => $action, 'created_at' => now(), 'updated_at' => now()]);

    return $snapshot;
}

function twoKnnRewrite(array $manifest, array $rows): array
{
    $bytes = implode('', array_map(fn (array $row): string => json_encode($row)."\n", $rows));
    $manifest['rows_sha256'] = hash('sha256', $bytes);
    $path = app(DatasetStore::class)->directory($manifest['dataset_id']);
    file_put_contents($path.'/manifest.json', json_encode($manifest));
    file_put_contents($path.'/rows.jsonl', $bytes);
    DB::table('research_datasets')->where('dataset_id', $manifest['dataset_id'])->update(['manifest' => json_encode($manifest)]);

    return $manifest;
}

it('publishes independent Human Action knowledge and applies the dynamic source weight', function () {
    $handler = new TestHandler;
    app()->instance(ActionLog::class, new ActionLog(new Logger('test', [$handler]), app(ActionContext::class)));
    $manifest = IntelligenceFixtures::snapshot();
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    foreach ($rows as $row) {
        twoKnnAnnotation($manifest, $row, $this->trainer, ['buy' => 'sell', 'hodl' => 'hold', 'sell' => 'buy'][$row['action_label']]);
    }
    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    $records = array_values(array_filter(array_map(fn ($record): array => json_decode($record->message, true), $handler->getRecords()),
        fn (array $record): bool => str_starts_with($record['event'], 'intelligence.human_training.')));
    expect(array_column($records, 'stage'))->toContain('loading_annotations', 'loading_dataset', 'validating_snapshots',
        'tuning_natural', 'tuning_target_priors', 'holdout', 'finalizing');
    expect($records[0])->toMatchArray(['event' => 'intelligence.human_training.started',
        'budget_seconds' => 5 * config('intelligence.max_seconds')]);
    expect($records[array_key_last($records)])->toMatchArray(['event' => 'intelligence.human_training.completed',
        'reason' => 'validated', 'knowledge_rows' => 240]);
    $artifact = app(ModelStore::class)->current('kraken', 'BTC/USD', '1m');
    expect($report['status'])->toBe('ready')
        ->and($report['action']['algorithmic']['status'])->toBe('ready')
        ->and($report['action']['human']['status'])->toBe('validated')
        ->and($report['action']['human']['validation_target'])->toBe('human_candle_annotations')
        ->and($report['action']['human']['holdout']['directional_annotation_agreement'])->toBe(1)
        ->and($report['action']['human'])->not->toHaveKey('knowledge')
        ->and(count($artifact['knowledge']))->toBe(240)
        ->and(count($artifact['action']['human']['knowledge']))->toBe(240)
        ->and($artifact['knowledge'][0]['action_label'])->toBe('buy')
        ->and($artifact['action']['human']['knowledge'][0]['label'])->toBe('sell')
        ->and(count($artifact['knowledge'][0]['vector']))->toBe(1)
        ->and(count($artifact['action']['human']['knowledge'][0]['vector']))->toBe(1);
    $store = app(ModelStore::class);
    $metadata = $store->currentForPrediction('kraken', 'BTC/USD', '1m');
    expect($metadata['action']['human'])->not->toHaveKey('knowledge')
        ->and($metadata['candle_guidance'])->not->toHaveKey('knowledge')
        ->and(iterator_count($store->humanKnowledge($metadata, 'action')))->toBe(240);
    IntelligenceFixtures::feature(250, 0.0);
    $this->travelTo('2024-01-01 04:11:00 UTC');
    $signal = app(MarketIntelligence::class)->predict('kraken', 'BTC/USD', '1m');
    $expectedHuman = 0.60 * sqrt(240 / 750);
    expect($signal['reason'])->toBe('supported')
        ->and($signal['action'])->toBe('buy')
        ->and($signal['action_knn']['sources']['components']['algorithmic']['action'])->toBe('buy')
        ->and($signal['action_knn']['sources']['components']['human']['action'])->toBe('sell')
        ->and($signal['action_knn']['sources']['effective_weights']['human'])->toEqualWithDelta($expectedHuman, 1e-12)
        ->and($signal['action_knn']['sources']['effective_weights']['algorithmic'])->toEqualWithDelta(1 - $expectedHuman, 1e-12);
    config(['human_training.candle_enabled' => false]);
    $disabled = app(MarketIntelligence::class)->predict('kraken', 'BTC/USD', '1m');
    expect($disabled['action'])->toBe('buy')
        ->and($disabled['action_knn']['sources']['effective_weights'])->toBe(['algorithmic' => 1.0, 'human' => 0.0]);
});

it('uses technical snapshots independently of CoinGecko and normalizes directional features identically at inference', function () {
    $technical = IntelligenceFixtures::snapshot();
    [, $rows] = app(DatasetStore::class)->load($technical['dataset_id']);
    $technical['keys'][] = 'trend.direction';
    foreach ($rows as &$row) {
        $row['vector'][] = $row['action_label'] === 'buy' ? -1 : ($row['action_label'] === 'sell' ? 1 : 0);
    }
    unset($row);
    $technical = twoKnnRewrite($technical, $rows);
    foreach ($rows as $row) {
        twoKnnAnnotation($technical, $row, $this->trainer, $row['action_label'] === 'hodl' ? 'hold' : $row['action_label']);
    }
    $full = IntelligenceFixtures::snapshot();
    $full['keys'] = [...$technical['keys'], ContextFeatures::KEYS[0]];
    $fullRows = array_map(fn (array $row): array => [...$row, 'vector' => [...$row['vector'], 0.8]], $rows);
    $full = twoKnnRewrite($full, $fullRows);
    $report = app(IntelligenceTrainer::class)->train($full['dataset_id']);
    $artifact = app(ModelStore::class)->current('kraken', 'BTC/USD', '1m');
    expect($report['action']['human']['input_keys'])->toBe($technical['keys'])
        ->and($report['action']['human']['knowledge_rows'])->toBe(240)
        ->and($artifact['action']['human']['knowledge'][0]['vector'])->toBe([0.0, 0.0]);
    IntelligenceFixtures::feature(250, 0.0);
    $feature = MarketFeature::query()->first();
    $payload = $feature->payload;
    $payload['features']['trend.direction'] = -1;
    DB::table('market_features')->where('feature_id', $feature->feature_id)->update(['payload' => json_encode($payload)]);
    $this->travelTo('2024-01-01 04:11:00 UTC');
    $signal = app(MarketIntelligence::class)->predict('kraken', 'BTC/USD', '1m');
    expect($signal['action'])->toBe('buy')->and($signal['reason'])->toBe('degraded_action_only')
        ->and($signal['outcome_knn']['sources']['components']['algorithmic']['reason'])->toBe('missing_selected_features')
        ->and($signal['action_knn']['sources']['components']['human']['reason'])->toBe('supported')
        ->and($signal['action_knn']['sources']['effective_weights'])->toBe(['algorithmic' => 0.0, 'human' => 1.0]);
});

it('keeps the combined model abstaining when Outcome validation fails despite validated Human Action', function () {
    $manifest = IntelligenceFixtures::snapshot(contradictory: true);
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    foreach ($rows as $row) {
        twoKnnAnnotation($manifest, $row, $this->trainer, $row['action_label'] === 'hodl' ? 'hold' : $row['action_label']);
    }
    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    expect($report['status'])->toBe('abstaining')
        ->and($report['outcome']['status'])->toBe('abstaining')
        ->and($report['action']['human']['status'])->toBe('validated');
    IntelligenceFixtures::feature(250, 1.0);
    $this->travelTo('2024-01-01 04:11:00 UTC');
    // The rejected candidate remains available for diagnostics, but failed
    // Outcome validation cannot promote a new active model.
    $signal = app(MarketIntelligence::class)->predict('kraken', 'BTC/USD', '1m');
    expect($signal['action'])->toBe('hodl')
        ->and($signal['confidence'])->toBe(0.0)
        ->and(app(ModelStore::class)->currentReport('kraken', 'BTC/USD', '1m'))->toBeNull();
    $this->assertDatabaseCount('intelligence_models', 1);
    $this->assertDatabaseCount('intelligence_heads', 0);
});

it('keeps final holdout annotations out of policy selection and purges both chronology boundaries', function () {
    $manifest = IntelligenceFixtures::snapshot();
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    $snapshots = [];
    foreach ($rows as $row) {
        $snapshots[] = twoKnnAnnotation($manifest, $row, $this->trainer, $row['action_label'] === 'hodl' ? 'hold' : $row['action_label']);
    }
    $service = app(HumanCandleKnn::class);
    $first = $service->train($manifest, config('intelligence.knn'), microtime(true) + 30)['bundle'];
    HumanCandleLabel::query()->whereIn('snapshot_id', array_map(fn ($snapshot) => $snapshot->snapshot_id, array_slice($snapshots, 192)))
        ->update(['action' => 'hold']);
    $second = $service->train($manifest, config('intelligence.knn'), microtime(true) + 30)['bundle'];
    // The modified holdout has only HOLD annotations: there are no directional
    // opportunities to judge. This is insufficient evidence, not bad predictions.
    expect($first['status'])->toBe('validated')
        ->and($second['status'])->toBe('insufficient_directional_evidence')
        ->and($second['holdout']['validation_status'])->toBe('insufficient_evidence')
        ->and($second['weight_candidates'])->toBe($first['weight_candidates'])
        ->and($second['weight_policy'])->toBe($first['weight_policy'])
        ->and($first['tuning_training_labels_available_by_ms'])->toBeLessThan($first['tuning_from_ms'])
        ->and($first['holdout_training_labels_available_by_ms'])->toBeLessThan($first['holdout_from_ms']);
    expect($service->predict($first, ['features' => ['candle.body' => 0]], $first['available_at_ms'])['reason'])->toBe('no_post_annotation_candle');
});

it('filters unauthorized, future, disputed and incompatible annotations and counts a candle only once', function () {
    $manifest = IntelligenceFixtures::snapshot();
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    $other = User::factory()->create();
    twoKnnAnnotation($manifest, $rows[0], $other, 'buy');
    $future = twoKnnAnnotation($manifest, $rows[1], $this->trainer, 'buy');
    $future->candleLabels()->update(['updated_at' => now()->addMinute()]);
    $bad = $rows[2];
    $bad['vector'] = [0.123];
    twoKnnAnnotation($manifest, $bad, $this->trainer, 'sell');
    $disputed = twoKnnAnnotation($manifest, $rows[3], $this->trainer, 'buy');
    config(['human_training.trainer_uuids' => [$other->user_id]]);
    HumanCandleLabel::factory()->create(['snapshot_id' => $disputed->snapshot_id, 'trainer_id' => $other->user_id,
        'action' => 'sell', 'updated_at' => now()]);
    // Revoke only the earlier unauthorized label by a third, non-authorized trainer.
    $revoked = User::factory()->create();
    twoKnnAnnotation($manifest, $rows[4], $revoked, 'buy');
    twoKnnAnnotation($manifest, $rows[5], $this->trainer, 'buy');
    $revision = IntelligenceFixtures::snapshot();
    twoKnnAnnotation($revision, $rows[5], $this->trainer, 'sell');
    $bundle = app(HumanCandleKnn::class)->train($manifest, config('intelligence.knn'), microtime(true) + 30)['bundle'];
    expect($bundle['samples'])->toBe(2)->and($bundle['class_counts'])->toBe(['buy' => 1, 'hold' => 0, 'sell' => 1]);
    config(['operations.owner_uuid' => null, 'human_training.trainer_uuids' => []]);
    expect(app(HumanCandleKnn::class)->train($manifest, config('intelligence.knn'), microtime(true) + 30)['bundle']['samples'])->toBe(0);
});

it('uses the shared age window without limiting retained human examples', function () {
    $manifest = IntelligenceFixtures::snapshot(300, period: '1h');
    $this->travelTo('2024-01-14 00:00:00 UTC');
    config(['intelligence.max_model_age_days' => 10]);
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    foreach ($rows as $row) {
        twoKnnAnnotation($manifest, $row, $this->trainer, $row['action_label'] === 'hodl' ? 'hold' : $row['action_label']);
    }
    $bundle = app(HumanCandleKnn::class)->train($manifest, config('intelligence.knn'), microtime(true) + 30)['bundle'];
    $eligible = array_values(array_filter($rows, fn ($row) => $row['decision_at_ms'] >= $bundle['window']['from_ms']));
    expect($bundle['knowledge_rows'])->toBe(count($eligible))->and($bundle['knowledge_rows'])->toBeLessThan(300)
        ->and($bundle['knowledge'][0]['decision_at_ms'])->toBe($eligible[0]['decision_at_ms']);
    config(['intelligence.max_model_age_days' => 14]);
    $all = app(HumanCandleKnn::class)->train($manifest, config('intelligence.knn'), microtime(true) + 30)['bundle'];
    expect($all['knowledge_rows'])->toBe(300);
});

it('does not silently hide a corrupt human snapshot behind the automatic fallback', function () {
    $manifest = IntelligenceFixtures::snapshot();
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    $snapshot = twoKnnAnnotation($manifest, $rows[0], $this->trainer, 'buy');
    DB::table('human_training_snapshots')->where('snapshot_id', $snapshot->snapshot_id)->update(['sha256' => str_repeat('0', 64)]);
    expect(fn () => app(IntelligenceTrainer::class)->train($manifest['dataset_id']))
        ->toThrow(LogicException::class, 'Human training snapshot checksum mismatch.');
});
