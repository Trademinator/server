<?php

use App\Domain\Features\FeatureEngine;
use App\Domain\Intelligence\TrainingRows;
use App\Domain\Research\BaselineBacktester;
use App\Domain\Research\WalkForward;

function researchRows(int $count = 16): array
{
    return array_map(fn ($i) => ['decision_at_ms' => ($i + 1) * 60000, 'entry_at_ms' => ($i + 1) * 60000,
        'label_available_at_ms' => ($i + 3) * 60000, 'vector' => [1], 'label' => 'buy', 'buy_net_return' => -0.01], range(0, $count - 1));
}

function researchManifest(): array
{
    return ['dataset_id' => 'test', 'rows_sha256' => 'hash', 'keys' => ['trend.direction'],
        'feature_version' => FeatureEngine::VERSION, 'label_definition' => ['horizon' => 2]];
}

it('purges labels reaching the validation boundary and never shuffles or overlaps test blocks', function () {
    $rows = researchRows();
    $folds = iterator_to_array((new WalkForward)->folds($rows, 3, 4));
    expect($folds[0]['train'])->toBe([0, 1, 2])->and($folds[0]['test'])->toBe([5, 6, 7, 8]);
    $tested = [];
    foreach ($folds as $fold) {
        foreach ($fold['train'] as $index) {
            expect($rows[$index]['label_available_at_ms'])->toBeLessThan($rows[$fold['test'][0]]['decision_at_ms']);
        }
        $tested = array_merge($tested, $fold['test']);
    }
    expect(count($tested))->toBe(count(array_unique($tested)))->and($tested)->toBe(range(5, 15));
});

it('supports an additional row gap and fixed rolling training windows', function () {
    $folds = iterator_to_array((new WalkForward)->folds(researchRows(), 3, 4, 4, false));
    expect($folds[0]['train'])->toBe([0, 1, 2])->and($folds[0]['test'][0])->toBe(7)
        ->and($folds[1]['train'])->toBe([4, 5, 6])->and($folds[0]['purged_or_gap_rows'])->toBe(4);
});

it('allows intelligence to evaluate all folds while preserving the research fold budget', function () {
    $rows = researchRows(1007);
    $count = 0;
    foreach ((new WalkForward)->folds($rows, 3, 1, maxFolds: null) as $fold) {
        $count++;
    }

    expect($count)->toBe(1002)
        ->and($fold['test'])->toBe([1006])
        ->and(fn () => iterator_to_array((new WalkForward)->folds($rows, 3, 1)))
        ->toThrow(InvalidArgumentException::class, '1000 folds');
});

it('fits the majority baseline only to mature training labels', function () {
    $rows = researchRows();
    $original = (new BaselineBacktester)->run(researchManifest(), $rows, 'majority', 3, 4);
    foreach (range(3, 15) as $index) {
        $rows[$index]['label'] = 'sell';
    }
    $changed = (new BaselineBacktester)->run(researchManifest(), $rows, 'majority', 3, 4);
    expect($changed['folds'][0]['majority_label'])->toBe('buy')
        ->and(array_column(array_slice($changed['predictions'], 0, 4), 'prediction'))
        ->toBe(array_column(array_slice($original['predictions'], 0, 4), 'prediction'));
});

it('does not compound overlapping horizons even across fold boundaries', function () {
    $result = (new BaselineBacktester)->run(researchManifest(), researchRows(), 'buy', 3, 3);
    expect($result['portfolio']['trades'])->toBe(6)
        ->and($result['portfolio']['overlapping_buy_signals_skipped'])->toBe(5)
        ->and($result['portfolio']['equity'])->toEqualWithDelta(0.99 ** 6, 1e-12)
        ->and($result['portfolio']['max_drawdown'])->toEqualWithDelta(1 - 0.99 ** 6, 1e-12)
        ->and($result['classification']['samples'])->toBe(11);
});

it('keeps sell and hodl in cash and reports no undefined win rate as a number', function () {
    $rows = researchRows();
    foreach ($rows as &$row) {
        $row['vector'] = [-1];
    }
    $result = (new BaselineBacktester)->run(researchManifest(), $rows, 'trend', 3, 4);
    expect($result['portfolio']['trades'])->toBe(0)->and($result['portfolio']['win_rate'])->toBeNull()
        ->and($result['portfolio']['equity'])->toBe(1.0)->and($result['predictions'][0]['prediction'])->toBe('sell');
});

it('rejects insufficient history invalid strategies and unsorted rows', function () {
    expect(fn () => (new BaselineBacktester)->run(researchManifest(), researchRows(3), 'majority', 3, 4))->toThrow(RuntimeException::class)
        ->and(fn () => (new BaselineBacktester)->run(researchManifest(), researchRows(), 'knn', 3, 4))->toThrow(InvalidArgumentException::class)
        ->and(fn () => iterator_to_array((new WalkForward)->folds(array_reverse(researchRows()), 3, 4)))->toThrow(InvalidArgumentException::class);
});

it('preserves purged chronological folds when using disk-backed row views', function (int $gap, bool $expanding) {
    $rows = researchRows(31);
    $rows[5]['label_available_at_ms'] = $rows[18]['decision_at_ms'];
    $rows[12]['label_available_at_ms'] = $rows[21]['decision_at_ms'];
    $disk = TrainingRows::from($rows);
    $expected = [];
    foreach ((new WalkForward)->folds($rows, 3, 4, $gap, $expanding) as $fold) {
        $expected[] = ['fold' => $fold['fold'],
            'train' => array_map(static fn (int $index): array => $rows[$index], $fold['train']),
            'test' => array_map(static fn (int $index): array => $rows[$index], $fold['test']),
            'purged_or_gap_rows' => $fold['purged_or_gap_rows']];
    }

    $actual = [];
    foreach ((new WalkForward)->rowFolds($disk, 3, 4, $gap, $expanding) as $fold) {
        $actual[] = ['fold' => $fold['fold'], 'train' => iterator_to_array($fold['train']),
            'test' => iterator_to_array($fold['test']), 'purged_or_gap_rows' => $fold['purged_or_gap_rows']];
    }

    expect($actual)->toBe($expected);
})->with([
    'expanding without a gap' => [0, true],
    'expanding with a gap' => [4, true],
    'rolling without a gap' => [0, false],
    'rolling with a gap' => [4, false],
]);

it('enforces chronology and fold budgets for training row views', function () {
    $valid = TrainingRows::from(researchRows(31));
    $reversed = TrainingRows::from(array_reverse(researchRows()));

    expect(fn () => iterator_to_array((new WalkForward)->rowFolds($reversed, 3, 4)))
        ->toThrow(InvalidArgumentException::class, 'chronological');
    expect(fn () => iterator_to_array((new WalkForward)->rowFolds($valid, 3, 4, maxFolds: 2)))
        ->toThrow(InvalidArgumentException::class, '1000 folds');
    expect(fn () => iterator_to_array((new WalkForward)->rowFolds($valid, 0, 4)))
        ->toThrow(InvalidArgumentException::class, 'positive');
    expect(iterator_to_array((new WalkForward)->rowFolds(TrainingRows::from([]), 3, 4)))->toBe([]);
});
