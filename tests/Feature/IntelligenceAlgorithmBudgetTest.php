<?php

use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\MarketIntelligence;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Intelligence\OptionalGuidance;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceClock;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    IntelligenceClock::reset();
    $path = sys_get_temp_dir().'/trademinator-algorithm-budget-'.Str::uuid7();
    config([
        'research.path' => $path.'/research', 'intelligence.path' => $path.'/models',
        'intelligence.max_seconds' => 2, 'intelligence.job_timeout' => 0,
        'intelligence.knn.min_train_size' => 36, 'intelligence.knn.test_size' => 12,
        'intelligence.knn.min_validation_rows' => 5, 'intelligence.knn.min_directional_predictions' => 1,
        'intelligence.patterns.enabled' => false, 'lead_lag.enabled' => false,
        'human_training.enabled' => false,
    ]);
});

afterEach(function () {
    IntelligenceClock::reset();
    File::deleteDirectory(dirname(config('research.path')));
});

it('finishes independent algorithm runs beyond one allowance without changing their knowledge or validation', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $manifest = IntelligenceFixtures::snapshot();
    $reference = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    $time = 1000.0;
    IntelligenceClock::useClock(function () use (&$time): float {
        return $time += 0.001;
    });

    $report = app(MarketIntelligence::class)->build('kraken', 'BTC/USD', '1m', $manifest['dataset_id']);

    expect($time - 1000)->toBeGreaterThan(2.0);
    expect($report['status'])->toBe('ready');
    foreach (['selection', 'holdout', 'action_selection', 'action_holdout', 'training_data', 'knowledge_rows'] as $key) {
        expect($report[$key])->toBe($reference[$key]);
    }
    $this->assertDatabaseHas('intelligence_models', ['model_id' => $report['model_id']]);
    $this->assertDatabaseCount('intelligence_models', 2);
});

it('honors an explicit outer deadline and keeps the last published model after expiry', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $manifest = IntelligenceFixtures::snapshot();
    $reference = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    IntelligenceClock::freeze(1000.0);
    $published = false;

    expect(function () use ($manifest, &$published): void {
        app(IntelligenceTrainer::class)->train($manifest['dataset_id'], 999.0,
            beforePublish: function () use (&$published): void {
                $published = true;
            });
    })->toThrow(RuntimeException::class, 'time budget exceeded');

    expect($published)->toBeFalse();
    expect(app(ModelStore::class)->current('kraken', 'BTC/USD', '1m')['model_id'])->toBe($reference['model_id']);
    $this->assertDatabaseCount('intelligence_models', 1);
});

it('cleans incomplete knowledge and retains the published head when finalization exhausts its own allowance', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $manifest = IntelligenceFixtures::snapshot();
    $reference = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    $filesBefore = File::files(config('intelligence.path'));
    IntelligenceClock::freeze(1000.0);
    $publicationChecked = false;

    expect(function () use ($manifest, &$publicationChecked): void {
        app(IntelligenceTrainer::class)->train($manifest['dataset_id'], 1100.0,
            beforePublish: function () use (&$publicationChecked): void {
                $publicationChecked = true;
                IntelligenceClock::advance(3.0);
            });
    })->toThrow(RuntimeException::class, 'Intelligence model finalization time budget exceeded.');

    expect($publicationChecked)->toBeTrue();
    expect(app(ModelStore::class)->current('kraken', 'BTC/USD', '1m')['model_id'])->toBe($reference['model_id']);
    expect(array_map(fn (SplFileInfo $file): string => $file->getFilename(), File::files(config('intelligence.path'))))
        ->toBe(array_map(fn (SplFileInfo $file): string => $file->getFilename(), $filesBefore));
    $this->assertDatabaseCount('intelligence_models', 1);
});

it('rolls back persisted files and model rows when the allowance expires before committing publication', function (bool $hasIncumbent) {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $manifest = IntelligenceFixtures::snapshot();
    $reference = $hasIncumbent ? app(IntelligenceTrainer::class)->train($manifest['dataset_id']) : null;
    if ($hasIncumbent) {
        $manifest = IntelligenceFixtures::snapshot(contradictory: true);
    }
    $filesBefore = $hasIncumbent ? File::files(config('intelligence.path')) : [];
    IntelligenceClock::freeze(1000.0);
    $inserted = false;
    $publicationChecks = 0;
    DB::listen(function (QueryExecuted $query) use (&$inserted): void {
        if (! $inserted && str_starts_with(strtolower($query->sql), 'insert')
            && str_contains($query->sql, 'intelligence_models')) {
            $inserted = true;
            IntelligenceClock::advance(3.0);
        }
    });

    expect(function () use ($manifest, &$publicationChecks): void {
        app(IntelligenceTrainer::class)->train($manifest['dataset_id'], 1100.0,
            beforePublish: function () use (&$publicationChecks): void {
                $publicationChecks++;
            });
    })->toThrow(RuntimeException::class, 'Intelligence model finalization time budget exceeded.');

    expect($inserted)->toBeTrue();
    expect($publicationChecks)->toBe(1);
    expect(app(ModelStore::class)->currentReport('kraken', 'BTC/USD', '1m')['model_id'] ?? null)
        ->toBe($reference['model_id'] ?? null);
    expect(array_map(fn (SplFileInfo $file): string => $file->getFilename(), File::files(config('intelligence.path'))))
        ->toBe(array_map(fn (SplFileInfo $file): string => $file->getFilename(), $filesBefore));
    $this->assertDatabaseCount('intelligence_models', $hasIncumbent ? 1 : 0);
})->with([
    'first validated model would be promoted' => false,
    'abstaining candidate retains the incumbent' => true,
]);

it('allows the human model container to reserve independent algorithm runs while retaining a publication margin', function () {
    config(['human_training.enabled' => true, 'human_training.candle_enabled' => true]);
    IntelligenceClock::freeze(1000.0);
    $receivedDeadline = null;

    $result = OptionalGuidance::compare('candle', 'test', 2000.0,
        function (float $deadline) use (&$receivedDeadline): array {
            $receivedDeadline = $deadline;

            return ['bundle' => ['status' => 'validated', 'influence' => true]];
        }, budgetSeconds: 10.0);

    expect($receivedDeadline)->toBe(1010.0);
    expect($result['bundle'])->toMatchArray(['status' => 'validated', 'influence' => true, 'optional' => true]);
});
