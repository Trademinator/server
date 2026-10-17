<?php

use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\MarketIntelligence;
use App\Domain\Intelligence\ModelRebuildRequired;
use App\Domain\Intelligence\ModelStore;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Rubix\ML\Classifiers\ClassificationTree;
use Rubix\ML\Classifiers\RandomForest;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Datasets\Unlabeled;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    $path = sys_get_temp_dir().'/rubix-models-'.Str::uuid7();
    config(['research.path' => $path.'/research', 'intelligence.path' => $path.'/models',
        'intelligence.knn.min_train_size' => 36, 'intelligence.knn.test_size' => 12,
        'intelligence.knn.min_validation_rows' => 5, 'intelligence.knn.min_directional_predictions' => 1,
        'intelligence.patterns.enabled' => false]);
});

afterEach(function () {
    File::deleteDirectory(dirname(config('research.path')));
});

it('abstains and marks old models unavailable before decoding incompatible serialized bytes', function (?string $major) {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $manifest = IntelligenceFixtures::snapshot();
    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    $store = app(ModelStore::class);
    $metadata = $store->currentForPrediction('kraken', 'BTC/USD', '1m');
    $report['rubix_ml_major'] = $major;
    // A valid digest authenticates bytes but does not make old serializer layouts compatible.
    $legacy = 'These legacy serialized bytes must never be decoded by Rubix 3.';
    file_put_contents($store->path($report['model_id']), $legacy);
    DB::table('intelligence_models')->where('model_id', $report['model_id'])->update([
        'report' => json_encode($report), 'sha256' => hash('sha256', $legacy),
    ]);

    $signal = app(MarketIntelligence::class)->predict('kraken', 'BTC/USD', '1m');

    expect($signal['reason'])->toBe('model_version_mismatch')
        ->and($signal['action'])->toBe('hodl')
        ->and($signal['confidence'])->toBe(0.0)
        ->and($signal['rebuild_required'])->toBeTrue()
        ->and($signal['model_id'])->toBe($report['model_id']);
    expect(ModelStore::isReadyReport($report))->toBeFalse();
    expect(ModelStore::knnReadiness($report))->toBe([
        'outcome' => ['ready' => false, 'reason' => 'model_version_mismatch'],
        'action' => ['ready' => false, 'reason' => 'model_version_mismatch'],
    ]);
    expect(fn () => $store->load($report['model_id']))->toThrow(ModelRebuildRequired::class, 'requires rebuilding');
    expect(iterator_count($store->knowledge($metadata)))->toBe($report['knowledge_rows']);
    $this->assertDatabaseCount('research_datasets', 1);
})->with(['unversioned' => [null], 'Rubix 2' => ['2'], 'another major' => ['4']]);

it('records the Rubix version and reloads a fresh forest sidecar with identical predictions', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    mt_srand(42);
    $manifest = IntelligenceFixtures::snapshot();
    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    $store = app(ModelStore::class);
    $artifact = $store->load($report['model_id']);
    $forest = new RandomForest(new ClassificationTree(5, 1), 4, 0.8);
    $forest->train(new Labeled([[0.0], [0.1], [0.9], [1.0]], ['bear', 'bear', 'bull', 'bull']));
    $query = new Unlabeled([[0.0], [1.0]]);
    $expected = $forest->proba($query);
    $artifact['coingecko_insight'] = ['status' => 'validated', 'estimator' => $forest];

    $saved = $store->save($artifact);
    $loaded = $store->load($saved['model_id']);

    expect($saved['rubix_ml_major'])->toBe('3')
        ->and($saved['rubix_ml_version'])->toBe(InstalledVersions::getPrettyVersion('rubix/ml'));
    expect($store->coinGeckoEstimator($loaded)->proba($query))->toBe($expected);
    $store->verify($saved['model_id']);
    $loaded['rubix_ml_major'] = '2';
    expect(fn () => $store->coinGeckoEstimator($loaded))->toThrow(ModelRebuildRequired::class);
});
