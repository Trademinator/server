<?php

use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\MarketIntelligence;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Research\DatasetStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    $path = sys_get_temp_dir().'/trademinator-age-window-'.Str::uuid7();
    config(['research.path' => $path.'/research', 'intelligence.path' => $path.'/models',
        'intelligence.knn.min_train_size' => 36, 'intelligence.knn.test_size' => 100,
        'intelligence.knn.min_validation_rows' => 5, 'intelligence.knn.min_directional_predictions' => 1,
        'intelligence.patterns.enabled' => false, 'intelligence.horizon' => 2, 'intelligence.lookback' => 3]);
});

afterEach(function () {
    File::deleteDirectory(dirname(config('research.path')));
});

it('loads semantic training rows through the disk-backed dataset index', function () {
    $this->travelTo('2024-01-04 00:00:00 UTC');
    $manifest = IntelligenceFixtures::snapshot(3105);

    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);

    expect($report['knowledge_rows'])->toBe(3105)
        ->and($report['training_data']['snapshot_rows'])->toBe(3105)
        ->and($report['training_data']['deduplication'])->toBe([
            'input_rows' => 3105, 'unique_rows' => 3105, 'duplicates' => 0,
        ]);
});

it('retains more than 3000 eligible examples independently of the research row budget', function () {
    $this->travelTo('2024-01-04 00:00:00 UTC');
    config(['research.max_rows' => 5]);
    $manifest = IntelligenceFixtures::snapshot(3105);

    $report = app(MarketIntelligence::class)->build('kraken', 'BTC/USD', '1m', $manifest['dataset_id']);
    $artifact = app(ModelStore::class)->load($report['model_id']);

    expect($report['status'])->toBe('ready')
        ->and($artifact['knowledge'])->toHaveCount(3105)
        ->and($artifact['knowledge'][0]['decision_at_ms'])->toBe(IntelligenceFixtures::START + 60000)
        ->and($report['training_data']['age_excluded_rows'])->toBe(0)
        ->and($report['holdout_training_labels_available_by_ms'])->toBeLessThan($report['holdout_from_ms']);
});

it('includes the age boundary and excludes older rows from explicit datasets', function (int $days) {
    $this->travelTo('2025-03-01 00:00:00 UTC');
    config(['intelligence.max_model_age_days' => $days]);
    $manifest = IntelligenceFixtures::snapshot(400, period: '1d');
    $fromMs = $manifest['as_of_ms'] - $days * 86_400_000;

    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    $artifact = app(ModelStore::class)->load($report['model_id']);

    expect($artifact['knowledge'])->toHaveCount($days - 2)
        ->and($artifact['knowledge'][0]['decision_at_ms'])->toBe($fromMs)
        ->and($report['training_data']['window'])->toBe([
            'days' => $days, 'from_ms' => $fromMs, 'as_of_ms' => $manifest['as_of_ms'],
        ])
        ->and($report['training_data']['snapshot_rows'])->toBe(400)
        ->and($report['training_data']['age_excluded_rows'])->toBe(402 - $days);
})->with([14, 366]);

it('moves the retained history forward on each rebuild without accumulating duplicates', function () {
    $this->travelTo('2025-03-01 00:00:00 UTC');
    config(['intelligence.max_model_age_days' => 14]);
    $first = IntelligenceFixtures::snapshot(20, period: '1d');
    $firstReport = app(IntelligenceTrainer::class)->train($first['dataset_id']);
    $second = IntelligenceFixtures::snapshot(27, period: '1d');

    $secondReport = app(IntelligenceTrainer::class)->train($second['dataset_id']);
    // A saved candidate remains inspectable even if it does not pass
    // publication gates; history retention is independent of promotion.
    $artifact = app(ModelStore::class)->load($secondReport['model_id']);
    $decisions = array_column($artifact['knowledge'], 'decision_at_ms');

    expect($artifact['knowledge_rows'])->toBe(12)
        ->and($decisions[0])->toBe($second['as_of_ms'] - 14 * 86_400_000)
        ->and(end($decisions))->toBe(IntelligenceFixtures::START + 27 * 86_400_000)
        ->and(array_unique($decisions))->toHaveCount(12)
        ->and(app(ModelStore::class)->load($firstReport['model_id'])['knowledge_rows'])->toBe(12);
});

it('does not publish a model when the age window excludes every frozen example', function () {
    $this->travelTo('2024-03-01 00:00:00 UTC');
    config(['intelligence.max_model_age_days' => 1]);
    $manifest = IntelligenceFixtures::snapshot(20, period: '1d');

    expect(fn () => app(IntelligenceTrainer::class)->train($manifest['dataset_id']))
        ->toThrow(InvalidArgumentException::class, 'No eligible history within INTELLIGENCE_MAX_MODEL_AGE_DAYS');
    $this->assertDatabaseCount('intelligence_models', 0);
});

it('rejects a nonpositive history age without publishing a model', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    config(['intelligence.max_model_age_days' => 0]);
    $manifest = IntelligenceFixtures::snapshot();

    expect(fn () => app(IntelligenceTrainer::class)->train($manifest['dataset_id']))
        ->toThrow(InvalidArgumentException::class, 'INTELLIGENCE_MAX_MODEL_AGE_DAYS must be a positive number of days');
    $this->assertDatabaseCount('intelligence_models', 0);
});

it('uses the same number of days for readiness and prediction expiry', function (int $days) {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    config(['intelligence.max_model_age_days' => $days]);
    $manifest = IntelligenceFixtures::snapshot();
    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    $expires = CarbonImmutable::createFromTimestampMs($manifest['as_of_ms'])->addDays($days);

    $this->travelTo($expires);
    expect(ModelStore::isReadyReport($report))->toBeTrue();
    $this->travelTo($expires->addMillisecond());

    expect(ModelStore::isReadyReport($report))->toBeFalse()
        ->and(app(MarketIntelligence::class)->predict('kraken', 'BTC/USD', '1m')['reason'])->toBe('stale_model');
})->with([14, 366]);

it('clamps an explicit build range to the age window while retaining earlier candle warmup', function () {
    $this->travelTo('2024-01-03 00:00:00 UTC');
    config(['intelligence.max_model_age_days' => 1, 'research.max_rows' => 5]);
    IntelligenceFixtures::candles(1505);
    $cutoff = IntelligenceFixtures::START + 1500 * 60000;
    $fromMs = $cutoff - 86_400_000;

    $report = app(MarketIntelligence::class)->build('kraken', 'BTC/USD', '1m',
        fromMs: IntelligenceFixtures::START, asOfMs: $cutoff);
    [, $rows] = app(DatasetStore::class)->load($report['dataset_id']);

    expect($rows[0]['decision_at_ms'])->toBe($fromMs)
        ->and(count($rows))->toBeGreaterThan(250)
        ->and(max(array_column($rows, 'label_available_at_ms')))->toBeLessThanOrEqual($cutoff)
        ->and($report['knowledge_rows'])->toBe(count($rows));
});

it('builds default knowledge from every eligible feature instead of the latest 3000 timestamps', function () {
    $this->travelTo('2024-01-04 00:00:00 UTC');
    IntelligenceFixtures::candles(3105);

    $report = app(MarketIntelligence::class)->build('kraken', 'BTC/USD', '1m');
    $artifact = app(ModelStore::class)->load($report['model_id']);

    expect($report['knowledge_rows'])->toBeGreaterThan(3000)
        ->and($artifact['knowledge'][0]['decision_at_ms'])->toBeLessThan(IntelligenceFixtures::START + 100 * 60000)
        ->and($report['trained_as_of_ms'])->toBe(IntelligenceFixtures::START + 3104 * 60000);
});
