<?php

use App\Domain\Intelligence\NormalizedVector;
use App\Domain\Intelligence\OutcomeAudit;
use App\Domain\Intelligence\OutcomeKnn;
use App\Domain\Intelligence\OutcomeKnnTuner;
use App\Domain\Intelligence\WeightedKnn;
use App\Domain\Research\DatasetStore;
use App\Models\Ticker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    $path = sys_get_temp_dir().'/trademinator-outcome-audit-'.Str::uuid7();
    config([
        'research.path' => $path.'/research',
        'intelligence.path' => $path.'/models',
        'archive.enabled' => false,
        'intelligence.patterns.enabled' => false,
        'intelligence.knn.min_train_size' => 36,
        'intelligence.knn.test_size' => 12,
        'intelligence.knn.min_validation_rows' => 5,
        'intelligence.knn.min_directional_predictions' => 1,
        'intelligence.outcome.min_supported_predictions' => 5,
    ]);
});

afterEach(function () {
    File::deleteDirectory(dirname(config('research.path')));
});

it('audits Outcome research without publishing or evaluating the reserved final holdout', function () {
    $this->travelTo('2024-01-01 06:00:00 UTC');
    IntelligenceFixtures::candles(280);
    $manifest = IntelligenceFixtures::snapshot(240);

    $result = app(OutcomeAudit::class)->run($manifest['dataset_id'], 5, microtime(true) + 60);

    expect($result['read_only'])->toBeTrue()
        ->and($result['selection_policy'])->toBe('fixed_k_research_only')
        ->and($result['horizon_candidates'])->toBe([1, 2, 3, 4])
        ->and($result['research']['horizons'])->toHaveKeys(['1', '2', '3', '4'])
        ->and($result['research']['horizons']['2']['five_class'])->toHaveKeys(['supported', 'accuracy', 'macro_f1'])
        ->and($result['research']['horizons']['2']['three_class'])->toHaveKeys(['accuracy', 'macro_f1', 'per_class'])
        ->and($result['research']['horizons']['2']['ordinal'])->toHaveKeys([
            'exact_accuracy', 'within_one_class_accuracy', 'mean_absolute_class_error',
            'same_direction_accuracy', 'opposite_direction_rate', 'extreme_opposite_rate',
        ])
        ->and($result['research']['feature_groups_at_current_horizon'])->toHaveKeys([
            'core', 'technical', 'patterns',
        ])
        ->and($result['research']['feature_groups_at_current_horizon']['patterns'])->toMatchArray([
            'status' => 'informational_only',
            'influence' => false,
        ])
        ->and($result['final_holdout']['evaluated'])->toBeFalse()
        ->and($result['final_holdout']['reason'])->toBe('reserved_for_post_research_validation');

    $this->assertDatabaseCount('research_datasets', 1);
    $this->assertDatabaseCount('intelligence_models', 0);
    $this->assertDatabaseCount('intelligence_heads', 0);

    $this->artisan('trademinator:outcome-audit', [
        'exchange' => 'kraken',
        'symbol' => 'BTC/USD',
        'period' => '1m',
        '--dataset' => $manifest['dataset_id'],
        '--k' => 5,
        '--timeout' => 60,
    ])->expectsOutputToContain('"read_only": true')->assertSuccessful();
});

it('matches production-normalized directional features without evaluating the reserved holdout', function () {
    $this->travelTo('2024-01-01 06:00:00 UTC');
    config(['intelligence.knn.max_distance' => 0.75, 'intelligence.knn.min_confidence' => 0.5]);
    for ($index = 0; $index < 105; $index++) {
        Ticker::query()->create([
            'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
            'microtimestamp' => IntelligenceFixtures::START + $index * 60000,
            'payload' => json_encode(['open' => '100', 'high' => '101', 'low' => '99',
                'close' => '100', 'volume' => '10'], JSON_THROW_ON_ERROR),
        ]);
    }
    $manifest = IntelligenceFixtures::snapshot(100);
    $store = app(DatasetStore::class);
    [, $rows] = $store->load($manifest['dataset_id']);
    $manifest['keys'] = ['trend.direction', 'candle.direction'];
    foreach ($rows as $index => &$row) {
        $direction = $index < 40 ? -1 : ($index < 64 ? 0 : 1);
        $row['vector'] = [$direction, -$direction];
        $row['label'] = 'neutral';
    }
    unset($row);
    $bytes = implode('', array_map(fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR)."\n", $rows));
    $manifest['rows_sha256'] = hash('sha256', $bytes);
    $directory = $store->directory($manifest['dataset_id']);
    file_put_contents($directory.'/rows.jsonl', $bytes);
    file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    DB::table('research_datasets')->where('dataset_id', $manifest['dataset_id'])
        ->update(['manifest' => json_encode($manifest, JSON_THROW_ON_ERROR)]);

    // H=2 and a three-candle lookback leave rows 2–77 before the reserved 80–99 block.
    $prepared = array_map(fn (array $row): array => array_replace($row, [
        'vector' => NormalizedVector::from($row['vector'], $manifest['keys']),
    ]), array_slice($rows, 2, 76));
    $settings = config('intelligence.knn');
    $settings['outcome'] = config('intelligence.outcome');
    $knn = new WeightedKnn($settings['max_distance'], $settings['min_effective_neighbors'], $settings['min_confidence']);
    $expected = (new OutcomeKnnTuner($knn, new OutcomeKnn($settings)))
        ->evaluateWalkForwardPrepared($prepared, $settings, 5, microtime(true) + 60)['report'];

    $result = app(OutcomeAudit::class)->run($manifest['dataset_id'], 5, microtime(true) + 60);

    $actual = $result['research']['horizons']['2'];
    expect($actual['rows'])->toBe(76);
    expect($actual['five_class'])->toBe([
        'supported' => 38, 'abstained' => 0, 'accuracy' => $expected['supported_accuracy'], 'macro_f1' => 0.2,
        'per_class' => $expected['per_class'],
    ]);
    expect($actual['coverage'])->toBe($expected['coverage'])
        ->and($actual['mean_confidence'])->toBe($expected['mean_confidence']);
    expect($result['final_holdout'])->toMatchArray(['rows' => 20, 'evaluated' => false]);
    $this->assertDatabaseCount('intelligence_models', 0);
    $this->assertDatabaseCount('intelligence_heads', 0);
});
