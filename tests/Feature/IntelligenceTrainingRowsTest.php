<?php

use App\Domain\Intelligence\CoinGeckoInsight;
use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\FeatureSchema;
use App\Domain\Research\SemanticLabels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    $path = sys_get_temp_dir().'/trademinator-training-rows-'.Str::uuid7();
    config(['research.path' => $path.'/research', 'intelligence.path' => $path.'/models',
        'intelligence.knn.min_train_size' => 1000, 'intelligence.patterns.enabled' => false,
        'intelligence.coingecko_insight.enabled' => false, 'lead_lag.enabled' => false]);
    $this->travelTo('2024-01-01 04:10:00 UTC');
});

afterEach(function () {
    File::deleteDirectory(dirname(config('research.path')));
});

function writeTrainingRowsSnapshot(array $manifest, array $rows): array
{
    $bytes = implode('', array_map(fn (array $row): string => json_encode(
        $row, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
    )."\n", $rows));
    $manifest['rows_sha256'] = hash('sha256', $bytes);
    $path = app(DatasetStore::class)->directory($manifest['dataset_id']);
    file_put_contents($path.'/rows.jsonl', $bytes);
    file_put_contents($path.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    DB::table('research_datasets')->where('dataset_id', $manifest['dataset_id'])
        ->update(['manifest' => json_encode($manifest, JSON_THROW_ON_ERROR)]);

    return $manifest;
}

it('preserves every knowledge value while loading patterns from their original source rows', function () {
    config(['intelligence.patterns.enabled' => true, 'intelligence.patterns.min_samples' => 1000]);
    $manifest = IntelligenceFixtures::snapshot(patterns: true);
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    $manifest['keys'] = ['candle.body', 'candle.direction'];
    $expected = [];
    foreach ($rows as $index => &$row) {
        $body = 0.12345678901234566 + ($index % 5) / 10;
        $direction = $index % 3 - 1;
        $row['vector'] = [$body, $direction];
        $row['action_label_available_at_ms'] = $row['decision_at_ms'] + ($index % 2 + 1) * 60000;
        $row['context_features'] = ['context.activity' => $index / count($rows)];
        $row['source'] = ['context_snapshot_id' => 'context-'.$index];
        $expected[] = [
            'decision_at_ms' => $row['decision_at_ms'],
            'label_available_at_ms' => $row['label_available_at_ms'],
            'vector' => [$body, (float) (($direction + 1) / 2)],
            'label' => $row['label'],
            'action_label' => $row['action_label'],
            'action_label_available_at_ms' => $row['action_label_available_at_ms'],
            'feature_weights' => [],
        ];
    }
    unset($row);
    $manifest = writeTrainingRowsSnapshot($manifest, $rows);

    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    $store = app(ModelStore::class);
    $model = $store->forPrediction($report['model_id']);

    expect(iterator_to_array($store->knowledge($model), false))->toBe($expected)
        ->and($report['knowledge_rows'])->toBe(240)
        ->and($report['training_data']['action_rows'])->toBe(240)
        ->and($model['patterns']['report']['bullish_engulfing'])->toMatchArray([
            'status' => 'insufficient_samples', 'samples' => 96,
        ])
        ->and(hash_file('sha256', app(DatasetStore::class)->directory($manifest['dataset_id']).'/rows.jsonl'))
        ->toBe($manifest['rows_sha256']);
});

it('preserves context associations after Outcome filtering and keeps them out of KNN knowledge', function () {
    config(['intelligence.coingecko_insight.enabled' => true,
        'intelligence.coingecko_insight.min_snapshots' => 40,
        'intelligence.coingecko_insight.min_holdout' => 10,
        'intelligence.coingecko_insight.trees' => 4]);
    $manifest = IntelligenceFixtures::snapshot();
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    $manifest['schema'] = 'enhanced';
    $manifest['keys'] = FeatureSchema::keys('enhanced');
    $contextRows = [];
    foreach ($rows as $index => &$row) {
        $snapshot = intdiv($index, 2);
        $class = $snapshot % count(SemanticLabels::OUTCOMES);
        $value = 0.1 + $class * 0.2;
        $row['vector'] = array_map(fn (string $key): float => str_ends_with($key, '.direction') ? 0.0 : $value,
            $manifest['keys']);
        $row['label'] = $index % 4 === 0 ? null : SemanticLabels::OUTCOMES[$class];
        $row['source'] = ['context_snapshot_id' => 'context-'.$snapshot];
        $row['context_features'] = [
            'context.global_regime' => $value, 'context.btc_dominance' => $value, 'context.activity' => $value,
        ];
        if ($row['label'] !== null) {
            $contextRows[] = $row + ['context_snapshot_id' => $row['source']['context_snapshot_id']];
        }
    }
    unset($row);
    $manifest = writeTrainingRowsSnapshot($manifest, $rows);
    mt_srand(42);
    $expected = app(CoinGeckoInsight::class)->train($contextRows, 'enhanced', microtime(true) + 30);
    mt_srand(42);

    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    $model = app(ModelStore::class)->forPrediction($report['model_id']);
    $advisor = $model['coingecko_insight'];
    unset($advisor['estimator'], $advisor['estimator_sha256']);

    expect($advisor)->toBe($expected['bundle'])
        ->and($advisor['snapshots'])->toBe(120)
        ->and($advisor['input_keys'])->toBe([
            'context.global_regime', 'context.btc_dominance', 'context.activity',
        ])
        ->and($advisor['status'])->toBeIn(['validated', 'holdout_failed'])
        ->and($report['knowledge_rows'])->toBe(180);
    foreach (app(ModelStore::class)->knowledge($model) as $row) {
        expect($row)->not->toHaveKeys(['context_features', 'context_snapshot_id', '_dataset_position'])
            ->and($row['vector'])->toHaveCount(count($manifest['keys']));
    }
});
