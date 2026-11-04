<?php

use App\Domain\Intelligence\KnnCandidateBudget;
use App\Domain\Intelligence\KnnTrainingProgress;
use App\Domain\Intelligence\KnnTuner;
use App\Domain\Intelligence\OutcomeKnn;
use App\Domain\Intelligence\OutcomeKnnTuner;
use App\Domain\Intelligence\TrainingLease;
use App\Domain\Intelligence\TrainingRows;
use App\Domain\Intelligence\WeightedKnn;
use App\Domain\Operations\ActionContext;
use App\Domain\Operations\ActionLog;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

it('reports fold test and training scan counters at most every ten seconds and on completion', function () {
    $handler = new TestHandler;
    app()->instance(ActionLog::class, new ActionLog(new Logger('test', [$handler]), new ActionContext));
    $time = 0.0;
    $progress = new KnnTrainingProgress('outcome_knn_tuning', static function () use (&$time): float {
        return $time;
    });

    $progress->fold(1, 10000, 65);
    $time = 9.0;
    $progress->search(0, 32, 1024);
    $time = 10.0;
    $progress->search(32, 32, 4096);
    $time = 11.0;
    $progress->fold(2, 10065, 65);
    $progress->search(65, 0, 10065);
    $progress->finish();

    $records = array_map(fn ($record): array => json_decode($record->message, true), $handler->getRecords());
    expect($records)->toHaveCount(3);
    expect($records[0])->toMatchArray(['event' => 'intelligence.knn.progress', 'fold' => 1, 'processed' => 0]);
    expect($records[1])->toMatchArray(['stage' => 'outcome_knn_tuning', 'fold' => 1,
        'processed' => 32, 'total' => 65, 'batch_rows' => 32, 'scanned' => 4096,
        'eligible_rows' => 10000, 'duration_ms' => 10000]);
    expect($records[2])->toMatchArray(['event' => 'intelligence.knn.completed', 'fold' => 2,
        'processed' => 65, 'total' => 65, 'scanned' => 10065]);
});

it('emits progress from both real tuners without changing their evaluated row counts', function (string $mode) {
    $handler = new TestHandler;
    app()->instance(ActionLog::class, new ActionLog(new Logger('test', [$handler]), new ActionContext));
    $settings = ['min_train_size' => 4, 'test_size' => 35, 'gap' => 0, 'k_cap' => 4,
        'min_validation_rows' => 1, 'min_directional_predictions' => 1,
        'min_effective_neighbors' => 1.0, 'min_confidence' => 0.5,
        'min_semantic_precision' => 0.5, 'min_coverage' => 0.01, 'max_contradiction_rate' => 1.0];
    $label = $mode === 'action' ? 'buy' : 'super_bull';
    $rows = [];
    for ($i = 0; $i < 75; $i++) {
        $rows[] = ['vector' => [0.1], 'label' => $label,
            'decision_at_ms' => $i * 10, 'label_available_at_ms' => $i * 10 + 1];
    }
    $knn = new WeightedKnn(1.0, 1.0, 0.5);
    $tuner = $mode === 'action' ? new KnnTuner($knn) : new OutcomeKnnTuner($knn, new OutcomeKnn($settings));

    $result = $tuner->tunePrepared(TrainingRows::from($rows), $settings, microtime(true) + 10);

    $records = array_map(fn ($record): array => json_decode($record->message, true), $handler->getRecords());
    expect($result['folds'])->toHaveCount(3);
    foreach ($result['candidates'] as $candidate) {
        expect($candidate['evaluated'])->toBe(71);
    }
    expect($records[0])->toMatchArray(['event' => 'intelligence.knn.progress', 'stage' => $mode.'_knn_tuning']);
    expect($records[array_key_last($records)])->toMatchArray(['event' => 'intelligence.knn.completed',
        'fold' => 3, 'processed' => 1, 'total' => 1]);
})->with(['action', 'outcome']);

it('aborts a batch when its training lease is lost before any result is yielded', function () {
    $time = 0.0;
    $leases = new TrainingLease(static function () use (&$time): float {
        return $time;
    });
    $leases->registerLease('test-owned-training', static fn (): bool => false);
    $time = 31.0;
    app()->instance(TrainingLease::class, $leases);
    $budget = new KnnCandidateBudget(microtime(true) + 10, null, 'deadline exceeded');
    $rows = TrainingRows::from(array_fill(0, 2050, ['vector' => [0.1], 'label' => 'buy',
        'decision_at_ms' => 1, 'label_available_at_ms' => 2]));

    expect(fn () => iterator_to_array((new WeightedKnn)->neighborBatchesPrepared($rows,
        [['vector' => [0.1], 'decision_at_ms' => 100]], 5, $budget->checkpointShared(...))))
        ->toThrow(RuntimeException::class, 'lease ownership was lost');
});
