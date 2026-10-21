<?php

/** Isolated synthetic snapshot and model: only temporary files and SQLite :memory:. */

use App\Domain\Features\ContextFeatures;
use App\Domain\Features\FeatureEngine;
use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Intelligence\PatternCatalog;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\FeatureSchema;
use App\Domain\Research\SemanticLabels;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\TestEnvironment;

require dirname(__DIR__, 2).'/vendor/autoload.php';

TestEnvironment::prepare();
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->afterBootstrapping(LoadConfiguration::class, function ($app): void {
    TestEnvironment::guard($app['config']);
});
$app->make(Kernel::class)->bootstrap();
Http::preventStrayRequests();
Artisan::call('migrate', ['--force' => true]);

$count = (int) ($argv[1] ?? 60000);
$root = sys_get_temp_dir().'/trademinator-training-memory-'.Str::uuid7();
config(['research.path' => $root.'/research', 'intelligence.path' => $root.'/models',
    'intelligence.max_model_age_days' => 366, 'intelligence.max_seconds' => 120,
    'intelligence.knn.min_train_size' => $count + 1, 'intelligence.patterns.enabled' => false,
    'intelligence.coingecko_insight.enabled' => false, 'lead_lag.enabled' => false]);
$id = (string) Str::uuid7();
$path = app(DatasetStore::class)->directory($id);
mkdir($path, 0700, true);
$file = fopen($path.'/rows.jsonl', 'wb');
if ($file === false) {
    throw new RuntimeException('Cannot create synthetic dataset.');
}

try {
    $keys = FeatureSchema::keys('core');
    $sourceHash = hash_init('sha256');
    $expectedHash = hash_init('sha256');
    $start = 1704067200000;
    for ($index = 0; $index < $count; $index++) {
        $decision = $start + ($index + 1) * 60000;
        $direction = $index % 3 - 1;
        $value = 0.12345678901234566 + ($index % 5) / 10;
        $vector = $normalized = [];
        foreach ($keys as $key) {
            $signed = in_array($key, ['trend.direction', 'candle.direction'], true);
            $vector[] = $signed ? $direction : $value;
            $normalized[] = $signed ? (float) (($direction + 1) / 2) : $value;
        }
        $patterns = [];
        foreach (PatternCatalog::TYPES as $type) {
            $patterns[] = ['type' => $type, 'length' => 3, 'stage' => 1, 'progress' => 1 / 3,
                'similarity' => $value, 'label' => $index % 2 === 0 ? 'completed' : 'failed',
                'label_available_at_ms' => $decision + 60000];
        }
        $row = [
            'decision_at_ms' => $decision, 'label_available_at_ms' => $decision + 120000,
            'vector' => $vector, 'label' => SemanticLabels::OUTCOMES[$index % count(SemanticLabels::OUTCOMES)],
            'action_label' => ['buy', 'hodl', 'sell'][$index % 3],
            'action_label_available_at_ms' => $decision + 60000,
            'patterns' => $patterns,
            'context_features' => array_fill_keys(ContextFeatures::KEYS, $value),
            'source' => ['context_snapshot_id' => 'context-'.intdiv($index, 20)],
        ];
        $line = json_encode($row, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)."\n";
        DatasetStore::write($file, $line);
        hash_update($sourceHash, $line);
        hash_update($expectedHash, json_encode([
            'decision_at_ms' => $decision, 'label_available_at_ms' => $decision + 120000,
            'vector' => $normalized, 'label' => $row['label'], 'action_label' => $row['action_label'],
            'action_label_available_at_ms' => $decision + 60000, 'feature_weights' => [],
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)."\n");
    }
    fclose($file);
    unset($row, $line, $vector, $normalized, $patterns);
    $manifest = [
        'dataset_id' => $id, 'format_version' => 'm3-dataset-v1', 'exchange' => 'binance',
        'symbol' => 'XRP/USDC', 'period' => '1m', 'keys' => $keys, 'schema' => 'core',
        'feature_version' => FeatureEngine::VERSION,
        'label_definition' => (new SemanticLabels(2, 3))->metadata(),
        'as_of_ms' => $start + ($count + 3) * 60000, 'rows' => $count,
        'rows_sha256' => hash_final($sourceHash),
    ];
    $json = json_encode($manifest, JSON_THROW_ON_ERROR);
    file_put_contents($path.'/manifest.json', $json);
    DB::table('research_datasets')->insert(['dataset_id' => $id, 'manifest' => $json, 'created_at' => now()]);

    $report = app(IntelligenceTrainer::class)->train($id);
    $store = app(ModelStore::class);
    $model = $store->forPrediction($report['model_id']);
    $actualHash = hash_init('sha256');
    $published = 0;
    foreach ($store->knowledge($model) as $row) {
        hash_update($actualHash, json_encode($row, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)."\n");
        $published++;
    }

    echo json_encode([
        'memory_limit' => ini_get('memory_limit'), 'peak_bytes' => memory_get_peak_usage(true),
        'source_rows' => $report['training_data']['snapshot_rows'],
        'knowledge_rows' => $report['knowledge_rows'], 'published_rows' => $published,
        'knowledge_sha256' => hash_final($actualHash), 'expected_sha256' => hash_final($expectedHash),
        'source_unchanged' => hash_file('sha256', $path.'/rows.jsonl') === $manifest['rows_sha256'],
        'outcome_folds' => count($report['selection']['folds']),
        'action_folds' => count($report['action_selection']['folds']),
    ], JSON_THROW_ON_ERROR)."\n";
} finally {
    if (is_resource($file)) {
        fclose($file);
    }
    File::deleteDirectory($root);
}
