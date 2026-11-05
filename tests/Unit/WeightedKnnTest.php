<?php

use App\Domain\Intelligence\NormalizedVector;
use App\Domain\Intelligence\RubixNearestNeighbors;
use App\Domain\Intelligence\RubixWeightedRms;
use App\Domain\Intelligence\WeightedKnn;
use Symfony\Component\Process\Process;

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

it('preserves full Rubix neighbors across blocks with ties weights and historical cutoffs', function (int $k, float $maxDistance) {
    $knn = new WeightedKnn($maxDistance, 1, 0.5);
    $asOfMs = 3000;
    $vector = [0.5, 0.5, 0.5];
    $weights = [1.0, 0.5, 0.25];
    $rows = [];
    for ($i = 0; $i < 3073; $i++) {
        $rows[] = [
            'vector' => [($i % 9) / 8, (($i * 3) % 9) / 8, (($i * 5) % 9) / 8],
            'feature_weights' => $i % 23 === 0 ? [0.0, 0.0, 0.0] : [1.0, 0.25, 0.5],
            'label' => ['buy', 'hodl', 'sell'][$i % 3],
            'decision_at_ms' => $i + 1,
            'label_available_at_ms' => $i % 29 === 0 ? $asOfMs : $i + 2,
        ];
    }
    $prepared = $knn->prepareRows($rows);
    $eligible = array_values(array_filter($prepared, static fn (array $row): bool => $row['decision_at_ms'] < $asOfMs && $row['label_available_at_ms'] < $asOfMs));
    $reference = new RubixNearestNeighbors($k, true, new RubixWeightedRms);
    $reference->attach(array_map(static fn (array $row): array => [...$row['vector'], ...$row['feature_weights']], $eligible));
    $expected = [];
    foreach ($reference->locate([...$vector, ...$weights]) as $entry) {
        if ($entry['distance'] <= $maxDistance) {
            $row = $eligible[$entry['index']];
            $expected[] = ['distance' => $entry['distance'], 'label' => $row['label'],
                'decision_at_ms' => $row['decision_at_ms']];
        }
    }
    $stream = static function (array $source): Generator {
        foreach ($source as $index => $row) {
            yield $index * 13 => $row;
        }
    };

    $streamed = $knn->neighborsIterable($stream($rows), $vector, $k, $asOfMs, $weights);
    $preparedStreamed = $knn->neighborsPrepared($stream($prepared), $vector, $k, $asOfMs, $weights);

    expect($streamed)->toBe($expected);
    expect($preparedStreamed)->toBe($expected);
    expect($knn->vote($streamed, $k))->toBe($knn->vote($expected, $k));
})->with([
    'small neighborhood' => [5, 1.0],
    'ordinary neighborhood' => [65, 1.0],
    'more neighbors than one block' => [1280, 1.0],
    'more neighbors than the entire history' => [4000, 1.0],
    'distance-limited neighborhood' => [1280, 0.25],
]);

it('keeps the earliest exact matches across block boundaries and rejects unavailable later matches', function () {
    $rows = [];
    for ($i = 0; $i < 2300; $i++) {
        $rows[] = ['vector' => [0.9], 'label' => 'sell',
            'decision_at_ms' => $i + 1, 'label_available_at_ms' => $i + 2];
    }
    foreach ([1022, 1023, 1025, 2047, 2048] as $index) {
        $rows[$index]['vector'] = [0.1];
        $rows[$index]['label'] = 'buy';
    }
    $rows[0]['vector'] = [0.1];
    $rows[0]['label_available_at_ms'] = 3000;
    $knn = new WeightedKnn(1, 1, 0.5);

    $neighbors = $knn->neighbors($rows, [0.1], 3, 3000);
    $prediction = $knn->vote($neighbors, 3);

    expect($neighbors)->toBe([
        ['distance' => 0.0, 'label' => 'buy', 'decision_at_ms' => 1023],
        ['distance' => 0.0, 'label' => 'buy', 'decision_at_ms' => 1024],
        ['distance' => 0.0, 'label' => 'buy', 'decision_at_ms' => 1026],
    ]);
    expect($prediction)->toMatchArray(['action' => 'buy', 'confidence' => 1.0, 'neighbors' => 3]);
});

it('validates later streamed rows even after finding enough exact neighbors', function () {
    $rows = (static function (): Generator {
        for ($i = 0; $i < 1100; $i++) {
            yield ['vector' => [0.0], 'label' => 'buy',
                'decision_at_ms' => $i + 1, 'label_available_at_ms' => $i + 2];
        }
        yield ['vector' => [0.0, 0.0], 'label' => 'sell',
            'decision_at_ms' => 1101, 'label_available_at_ms' => 1102];
    })();

    expect(fn () => (new WeightedKnn)->neighborsIterable($rows, [0.0], 3, 2000))
        ->toThrow(InvalidArgumentException::class, 'Knowledge feature dimensions do not match.');
});

it('searches all streamed knowledge within a fixed memory budget', function () {
    $code = <<<'PHP'
require 'vendor/autoload.php';
$seen = 0;
$rows = (function () use (&$seen): Generator {
    for ($i = 0; $i < 50000; $i++) {
        $seen++;
        yield ['vector' => array_fill(0, 18, $i === 49999 ? 0.0 : 0.5),
            'label' => 'buy', 'decision_at_ms' => $i + 1, 'label_available_at_ms' => $i + 2,
            'context_features' => ['unused' => str_repeat((string) ($i % 10), 4096)]];
    }
})();
$neighbors = (new App\Domain\Intelligence\WeightedKnn(1, 1, 0.5))
    ->neighborsIterable($rows, array_fill(0, 18, 0.0), 65, 60000);
echo json_encode(['seen' => $seen, 'neighbors' => count($neighbors),
    'nearest_decision_at_ms' => $neighbors[0]['decision_at_ms'],
    'peak_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR);
PHP;
    $process = new Process([PHP_BINARY, '-d', 'memory_limit=64M', '-r', $code], dirname(__DIR__, 2));
    $process->setTimeout(60);

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($result)->toMatchArray(['seen' => 50000, 'neighbors' => 65, 'nearest_decision_at_ms' => 50000]);
    expect($result['peak_bytes'])->toBeLessThan(64 * 1024 * 1024);
});
