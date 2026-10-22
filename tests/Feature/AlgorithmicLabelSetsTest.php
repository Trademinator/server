<?php

use App\Domain\Intelligence\AlgorithmicLabelBuilder;
use App\Domain\Intelligence\AlgorithmicLabelSets;
use App\Domain\Research\DatasetSnapshotBuilder;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\SemanticLabels;
use Illuminate\Support\Facades\DB;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    config([
        'intelligence.knn.min_train_size' => 36,
        'intelligence.knn.test_size' => 12,
        'intelligence.knn.min_validation_rows' => 5,
        'intelligence.patterns.enabled' => false,
        'intelligence.horizon' => 2,
        'intelligence.lookback' => 3,
        'intelligence.min_horizon_distance_observations' => 1,
        'research.path' => sys_get_temp_dir().'/labels-'.\Illuminate\Support\Str::uuid7(),
    ]);
});

afterEach(function () {
    \Illuminate\Support\Facades\File::deleteDirectory(config('research.path'));
});

it('freezes labels independently of M2 features and reuses identical immutable results', function () {
    IntelligenceFixtures::candles();
    $until = now()->getTimestampMs();
    DB::table('market_features')->delete();

    $builder = app(AlgorithmicLabelBuilder::class);
    $first = $builder->build('kraken', 'BTC/USD', '1m', $until);
    expect(count($first['action_labels']))->toBeGreaterThan(0)
        ->and(count($first['outcome_labels']))->toBeGreaterThan(0)
        ->and($first['outcome_horizon'])->toBeGreaterThan(0)
        ->and(DB::table('market_features')->count())->toBe(0);

    $second = $builder->build('kraken', 'BTC/USD', '1m', $until);
    expect($second['label_set_id'])->toBe($first['label_set_id'])
        ->and($second['label_set_sha256'])->toBe($first['label_set_sha256']);
    $this->assertDatabaseCount('algorithmic_label_sets', 1);
});

it('keeps semantic label outcomes stable when a feature dataset uses a frozen set', function () {
    IntelligenceFixtures::candles();
    $until = now()->getTimestampMs();
    $set = app(AlgorithmicLabelBuilder::class)->build('kraken', 'BTC/USD', '1m', $until);
    $snapshot = app(DatasetSnapshotBuilder::class)->build('kraken', 'BTC/USD', '1m',
        new SemanticLabels(2, 3), asOfMs: $until);

    [, $rows] = app(DatasetStore::class)->load($snapshot['dataset_id']);
    foreach ($rows as $row) {
        if (!isset($set['outcome_labels'][$row['microtimestamp']])) {
            continue;
        }
        expect($row['label'])->toBe($set['outcome_labels'][$row['microtimestamp']]['label']);
    }
    $this->assertDatabaseCount('algorithmic_label_sets', 1);
});
