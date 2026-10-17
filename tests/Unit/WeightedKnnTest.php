<?php

use App\Domain\Intelligence\NormalizedVector;
use App\Domain\Intelligence\WeightedKnn;

it('normalizes directional coordinates identically without changing ordinary features', function () {
    expect(NormalizedVector::from([-1, 0, 0.7], ['trend.direction', 'candle.direction', 'candle.body']))
        ->toBe([0.0, 0.5, 0.7]);
    expect(fn () => NormalizedVector::from([INF], ['candle.body']))->toThrow(InvalidArgumentException::class);
    expect(fn () => NormalizedVector::from([2], ['trend.direction']))->toThrow(InvalidArgumentException::class);
});

it('weights nearby examples and excludes labels unavailable at decision time', function () {
    $rows = [
        ['vector' => [0.1], 'label' => 'buy', 'decision_at_ms' => 1, 'label_available_at_ms' => 2],
        ['vector' => [0.11], 'label' => 'buy', 'decision_at_ms' => 2, 'label_available_at_ms' => 3],
        ['vector' => [0.8], 'label' => 'sell', 'decision_at_ms' => 3, 'label_available_at_ms' => 4],
        ['vector' => [0.0], 'label' => 'sell', 'decision_at_ms' => 4, 'label_available_at_ms' => 10],
    ];

    $result = (new WeightedKnn(1, 1.5, 0.5))->predict($rows, [0.0], 3, 10);

    expect($result['action'])->toBe('buy');
    expect($result['neighbors'])->toBe(3);
    expect($result['confidence'])->toBeGreaterThan(0.55);
});

it('abstains on distant evidence ties and too few effective exact neighbors', function () {
    $knn = new WeightedKnn(0.2, 2, 0.5);
    $rows = [
        ['vector' => [0.1], 'label' => 'buy', 'decision_at_ms' => 1, 'label_available_at_ms' => 2],
        ['vector' => [0.1], 'label' => 'sell', 'decision_at_ms' => 2, 'label_available_at_ms' => 3],
    ];

    expect($knn->predict($rows, [0.9], 2, 10)['reason'])->toBe('no_similar_history');
    expect($knn->predict($rows, [0.1], 2, 10)['reason'])->toBe('tied_votes');
    $rows[1]['vector'] = [0.11];
    $result = $knn->predict($rows, [0.1], 2, 10);
    expect($result['reason'])->toBe('insufficient_effective_neighbors');
    expect($result['confidence'])->toBe(0.0);
    expect($result['action'])->toBe('hodl');
});

it('rejects incompatible dimensions and malformed evidence thresholds', function () {
    $rows = [['vector' => [0.1, 0.5], 'label' => 'buy', 'decision_at_ms' => 1, 'label_available_at_ms' => 2]];
    expect(fn () => (new WeightedKnn)->predict($rows, [0.1], 3, 10))->toThrow(InvalidArgumentException::class);
    expect(fn () => new WeightedKnn(NAN))->toThrow(InvalidArgumentException::class);
});

it('removes inactive cross exchange dimensions from both distance and its denominator', function () {
    $rows = [
        ['decision_at_ms' => 1, 'label_available_at_ms' => 2, 'vector' => [0.1], 'label' => 'buy'],
        ['decision_at_ms' => 2, 'label_available_at_ms' => 3, 'vector' => [0.2], 'label' => 'buy'],
        ['decision_at_ms' => 3, 'label_available_at_ms' => 4, 'vector' => [0.3], 'label' => 'buy'],
    ];
    $knn = new WeightedKnn;
    $base = $knn->neighbors($rows, [0.15], 3, 10);
    foreach ($rows as &$row) {
        $row['vector'][] = 1.0;
        $row['feature_weights'] = [1.0, 0.25];
    }
    unset($row);

    expect($knn->neighbors($rows, [0.15, 0.0], 3, 10, [1.0, 0.0]))->toBe($base);
    foreach ($rows as &$row) {
        $row['feature_weights'][1] = 0.0;
    }
    unset($row);
    expect($knn->neighbors($rows, [0.15, 0.0], 3, 10, [1.0, 0.25]))->toBe($base);
});

it('prepares knowledge vectors once and preserves weighted neighbor ordering', function () {
    $knn = new WeightedKnn(1, 1, 0.5);
    $rows = [
        ['decision_at_ms' => 1, 'label_available_at_ms' => 2, 'vector' => ['0.10', 0.20], 'feature_weights' => [1, '0.5'], 'label' => 'buy'],
        ['decision_at_ms' => 2, 'label_available_at_ms' => 3, 'vector' => [0.30, '0.40'], 'feature_weights' => ['1', 0.25], 'label' => 'sell'],
        ['decision_at_ms' => 3, 'label_available_at_ms' => 4, 'vector' => [0.15, 0.25], 'feature_weights' => [1.0, 0.5], 'label' => 'buy'],
    ];

    $prepared = $knn->prepareRows($rows);
    expect($prepared[0]['vector'])->toBe([0.1, 0.2]);
    expect($prepared[0]['feature_weights'])->toBe([1.0, 0.5]);

    expect($knn->neighborsPrepared($prepared, [0.12, 0.22], 2, 10, [1.0, 0.5]))
        ->toBe($knn->neighbors($rows, [0.12, 0.22], 2, 10, [1.0, 0.5]));
});

it('prepares rows in place without changing KNN semantics', function () {
    $knn = new WeightedKnn(1, 1, 0.5);
    $rows = [
        ['decision_at_ms' => 1, 'label_available_at_ms' => 2, 'vector' => ['0.10', 0.20],
            'feature_weights' => [1, '0.5'], 'label' => 'buy'],
        ['decision_at_ms' => 2, 'label_available_at_ms' => 3, 'vector' => [0.30, '0.40'],
            'feature_weights' => ['1', 0.25], 'label' => 'sell'],
    ];

    $expected = $knn->prepareRows($rows);
    $knn->prepareRowsInPlace($rows);

    expect($rows)->toBe($expected);
});

it('does not retain empty feature weight arrays on prepared rows', function () {
    $rows = [['decision_at_ms' => 1, 'label_available_at_ms' => 2,
        'vector' => [0.1, 0.2], 'label' => 'buy', 'feature_weights' => []]];

    (new WeightedKnn)->prepareRowsInPlace($rows);

    expect($rows[0])->not->toHaveKey('feature_weights');
});

it('validates already prepared rows without rewriting them', function () {
    $knn = new WeightedKnn;
    $rows = [
        ['decision_at_ms' => 1, 'label_available_at_ms' => 2, 'vector' => [0.1, 0.2], 'label' => 'buy'],
        ['decision_at_ms' => 2, 'label_available_at_ms' => 3, 'vector' => [0.3, 0.4],
            'feature_weights' => [1.0, 0.5], 'label' => 'sell'],
    ];
    $before = $rows;

    $knn->validatePreparedRows($rows);

    expect($rows)->toBe($before);
    expect(fn () => $knn->validatePreparedRows([[...$rows[0], 'vector' => ['0.1', 0.2]]]))
        ->toThrow(InvalidArgumentException::class, 'prepared');
});

it('rejects malformed vectors and weights before repeated neighbor scans', function () {
    $knn = new WeightedKnn;
    $base = ['decision_at_ms' => 1, 'label_available_at_ms' => 2, 'label' => 'buy'];

    expect(fn () => $knn->prepareRows([[...$base, 'vector' => [0.1, INF]]]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $knn->prepareRows([[...$base, 'vector' => [0.1], 'feature_weights' => [1.5]]]))
        ->toThrow(InvalidArgumentException::class);
});
