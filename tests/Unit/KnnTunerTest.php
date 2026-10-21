<?php

use App\Domain\Intelligence\KnnTuner;
use App\Domain\Intelligence\TrainingRows;
use App\Domain\Intelligence\WeightedKnn;

function tuningRows(int $count = 180, bool $contradictory = false): array
{
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $buy = $i % 2 === 0;
        $rows[] = ['vector' => [$buy ? 0.0 : 1.0], 'label' => $buy ? 'buy' : 'sell',
            'decision_at_ms' => $i * 1000, 'label_available_at_ms' => ($i + 3) * 1000,
            'semantic' => ['bottom' => $contradictory ? ! $buy : $buy, 'top' => $contradictory ? $buy : ! $buy]];
    }

    return $rows;
}

function tuningSettings(): array
{
    return ['min_train_size' => 36, 'test_size' => 12, 'gap' => 2, 'k_cap' => 99,
        'min_validation_rows' => 10, 'min_directional_predictions' => 3,
        'min_semantic_precision' => 0.55, 'min_coverage' => 0.01, 'max_contradiction_rate' => 0.05];
}

it('sizes K from the first-fold training capacity and purges unfinished labels', function () {
    $report = (new KnnTuner(new WeightedKnn))->tune(tuningRows(), tuningSettings(), microtime(true) + 10);

    expect($report['k_min'])->toBe(4);
    expect($report['k_max'])->toBe(36);
    expect($report['k'])->toBeGreaterThanOrEqual(4)->toBeLessThanOrEqual(36);
    expect($report['folds'][0]['train_rows'])->toBe(36);
    expect($report['folds'][1]['train_rows'])->toBe(48);
    expect(end($report['folds'])['train_rows'])->toBeGreaterThan(100);
    foreach ($report['folds'] as $fold) {
        expect($fold['labels_available_by_ms'])->toBeLessThan($fold['test_from_ms']);
    }
});

it('does not evaluate K values without effective-neighbor headroom and reports failed gates', function () {
    $settings = tuningSettings();
    $settings['min_semantic_precision'] = 1.1;
    $report = (new KnnTuner(new WeightedKnn(1, 3, 0.5)))->tune(tuningRows(), $settings, microtime(true) + 10);

    expect($report['k_min'])->toBe(4);
    expect(array_column($report['candidates'], 'k'))->not->toContain(1)->not->toContain(2)->not->toContain(3);
    $directional = collect($report['candidates'])->first(fn (array $candidate): bool => $candidate['directional'] > 0);
    expect($directional['gates']['semantic_precision'])->toBeFalse();
    expect($directional['failed_gates'])->toContain('semantic_precision');
});

it('produces the same tuning result from already prepared rows', function () {
    $knn = new WeightedKnn;
    $rows = tuningRows();
    $prepared = $knn->prepareRows($rows);
    $tuner = new KnnTuner($knn);

    $normal = $tuner->tune($rows, tuningSettings(), microtime(true) + 10);
    $ready = $tuner->tunePrepared($prepared, tuningSettings(), microtime(true) + 10);

    expect($ready)->toBe($normal);
});

it('disqualifies semantically contradictory candidates even with perfect classification', function () {
    $report = (new KnnTuner(new WeightedKnn))->tune(tuningRows(180, true), tuningSettings(), microtime(true) + 10);

    expect($report['k'])->toBeNull();
    $scored = array_values(array_filter($report['candidates'], fn ($row) => $row['directional'] > 0));
    expect($scored)->not->toBeEmpty();
    expect($scored[0]['semantic_precision'])->toBe(1);
    expect($scored[0]['contradiction_rate'])->toBe(1);
});

it('accepts compact semantic flags without changing tuning results', function () {
    $rows = tuningRows();
    $compact = array_map(function (array $row): array {
        $row['semantic_bottom'] = $row['semantic']['bottom'];
        $row['semantic_top'] = $row['semantic']['top'];
        unset($row['semantic']);

        return $row;
    }, $rows);
    $tuner = new KnnTuner(new WeightedKnn);

    expect($tuner->tune($compact, tuningSettings(), microtime(true) + 10))
        ->toBe($tuner->tune($rows, tuningSettings(), microtime(true) + 10));
});

it('refuses an expired compute budget without selecting a partial winner', function () {
    expect(fn () => (new KnnTuner(new WeightedKnn))->tune(tuningRows(), tuningSettings(), microtime(true) - 1))
        ->toThrow(RuntimeException::class, 'time budget');
});

it('preserves Action tuning and holdout reports through filtered views with projected label endpoints', function () {
    $knn = new WeightedKnn;
    $source = $knn->prepareRows(tuningRows());
    foreach ($source as $position => &$row) {
        $row['action_label'] = $position % 17 === 0 ? null : $row['label'];
        $row['action_label_available_at_ms'] = $row['label_available_at_ms'];
        $row['label'] = 'neutral';
        $row['label_available_at_ms'] += 50000;
    }
    unset($row);
    $eligible = static fn (array $row): bool => $row['action_label'] !== null;
    $project = static function (array $row): array {
        $row['label'] = $row['action_label'];
        $row['label_available_at_ms'] = $row['action_label_available_at_ms'];

        return $row;
    };
    $expected = array_map($project, array_values(array_filter($source, $eligible)));
    $views = TrainingRows::from($source)->filter($eligible)->map($project);
    $tuner = new KnnTuner($knn);
    $settings = tuningSettings();

    expect($tuner->tunePrepared($views, $settings, microtime(true) + 10))
        ->toBe($tuner->tunePrepared($expected, $settings, microtime(true) + 10));
    expect($tuner->evaluateWalkForwardPrepared($views, $settings, 9, microtime(true) + 10))
        ->toBe($tuner->evaluateWalkForwardPrepared($expected, $settings, 9, microtime(true) + 10));
    expect($tuner->evaluatePrepared($views->slice(0, 80), $views->slice(90), 9, $settings, microtime(true) + 10))
        ->toBe($tuner->evaluatePrepared(array_slice($expected, 0, 80), array_slice($expected, 90), 9, $settings, microtime(true) + 10));
});
