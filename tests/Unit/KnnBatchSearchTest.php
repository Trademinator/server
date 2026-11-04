<?php

use App\Domain\Intelligence\KnnCandidateBudget;
use App\Domain\Intelligence\TrainingRows;
use App\Domain\Intelligence\WeightedKnn;
use Symfony\Component\Process\Process;

it('matches individual searches across blocks and batches with independent historical cutoffs and weights', function (int $k, float $distance) {
    $knn = new WeightedKnn($distance, 1, 0.5);
    $rows = [];
    for ($i = 0; $i < 3073; $i++) {
        $rows[$i * 7] = ['vector' => [($i % 9) / 8, (($i * 3) % 9) / 8],
            'feature_weights' => $i % 23 === 0 ? [0.0, 0.0] : [1.0, 0.25],
            'label' => ['buy', 'hodl', 'sell'][$i % 3], 'decision_at_ms' => $i + 1,
            'label_available_at_ms' => $i % 29 === 0 ? 4000 : $i + 2];
    }
    $queries = [];
    for ($i = 0; $i < 35; $i++) {
        $queries[] = ['vector' => [($i % 5) / 4, 0.5],
            'feature_weights' => $i % 3 === 0 ? [] : [1.0, 0.5],
            'decision_at_ms' => [0, 1500, 3000, 4000, 5000][$i % 5]];
    }
    $expected = array_map(fn (array $query): array => $knn->neighborsPrepared($rows,
        $query['vector'], $k, $query['decision_at_ms'], $query['feature_weights']), $queries);

    $actual = iterator_to_array($knn->neighborBatchesPrepared(TrainingRows::from($rows), $queries, $k));

    expect(array_column($actual, 'neighbors'))->toBe($expected);
    expect(array_column($actual, 'row'))->toBe($queries);
})->with(['small' => [5, 1.0], 'ordinary' => [65, 1.0], 'over one block' => [1280, 1.0],
    'over entire history' => [4000, 1.0], 'distance gated' => [65, 0.25]]);

it('decodes each training row once per batch and still finds the newest eligible exact match', function () {
    $decoded = 0;
    $rows = TrainingRows::from((static function (): Generator {
        for ($i = 1; $i <= 2050; $i++) {
            yield ['vector' => [$i === 2050 ? 0.0 : 0.75], 'label' => 'buy',
                'decision_at_ms' => $i, 'label_available_at_ms' => $i + 1];
        }
    })())->map(static function (array $row) use (&$decoded): array {
        $decoded++;

        return $row;
    });
    $queries = array_fill(0, 65, ['vector' => [0.0], 'decision_at_ms' => 3000]);

    $results = iterator_to_array((new WeightedKnn)->neighborBatchesPrepared($rows, $queries, 5));

    expect($results)->toHaveCount(65);
    expect($decoded)->toBe(2050 * 3);
    foreach ($results as $result) {
        expect($result['neighbors'])->toBe([['distance' => 0.0, 'label' => 'buy', 'decision_at_ms' => 2050]]);
    }
});

it('checks the deadline within a training scan before yielding partial batch results', function () {
    $time = 0.0;
    $decoded = 0;
    $budget = new KnnCandidateBudget(1.0, null, 'expired batch', static function () use (&$time): float {
        return $time;
    });
    $rows = TrainingRows::from(array_fill(0, 5000, ['vector' => [0.1], 'label' => 'buy',
        'decision_at_ms' => 1, 'label_available_at_ms' => 2]))
        ->map(static function (array $row) use (&$time, &$decoded): array {
            $time = 2.0;
            $decoded++;

            return $row;
        });
    $yielded = [];
    $search = function () use ($rows, $budget, &$yielded): void {
        foreach ((new WeightedKnn)->neighborBatchesPrepared($rows,
            [['vector' => [0.1], 'decision_at_ms' => 100]], 5, $budget->checkpointShared(...)) as $result) {
            $yielded[] = $result;
        }
    };

    expect($search)->toThrow(RuntimeException::class, 'expired batch');
    expect($decoded)->toBeLessThan(5000);
    expect($yielded)->toBeEmpty();
});

it('rejects malformed later evidence even when earlier exact matches fill the neighborhood', function () {
    $rows = array_fill(0, 2048, ['vector' => [0.1], 'label' => 'buy',
        'decision_at_ms' => 1, 'label_available_at_ms' => 2]);
    $rows[] = ['vector' => [0.1, 0.2], 'label' => 'sell', 'decision_at_ms' => 3, 'label_available_at_ms' => 4];

    expect(fn () => iterator_to_array((new WeightedKnn)->neighborBatchesPrepared($rows,
        [['vector' => [0.1], 'decision_at_ms' => 100]], 5)))
        ->toThrow(InvalidArgumentException::class, 'Knowledge feature dimensions');
});

it('rejects malformed batch query dimensions weights and K', function (array $queries, int $k) {
    expect(fn () => iterator_to_array((new WeightedKnn)->neighborBatchesPrepared([], $queries, $k)))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'empty vector' => [[['vector' => [], 'decision_at_ms' => 100]], 1],
    'different dimensions' => [[['vector' => [0.1], 'decision_at_ms' => 100], ['vector' => [0.1, 0.2], 'decision_at_ms' => 200]], 1],
    'bad weights' => [[['vector' => [0.1], 'feature_weights' => [1.0, 0.5], 'decision_at_ms' => 100]], 1],
    'invalid K' => [[], 0],
]);

it('returns no results for no queries and empty neighborhoods for no training evidence', function () {
    $knn = new WeightedKnn;
    $query = ['vector' => [0.1], 'decision_at_ms' => 100];

    expect(iterator_to_array($knn->neighborBatchesPrepared([], [], 1)))->toBe([]);
    expect(iterator_to_array($knn->neighborBatchesPrepared([], [$query], 1)))
        ->toBe([['row' => $query, 'neighbors' => []]]);
});

it('searches complete disk backed history in batches within a fixed PHP heap', function () {
    $code = <<<'PHP'
require 'vendor/autoload.php';
$rows = App\Domain\Intelligence\TrainingRows::from((function (): Generator {
    for ($i = 1; $i <= 20000; $i++) {
        yield ['vector' => array_fill(0, 12, $i === 20000 ? 0.0 : 0.5), 'label' => 'buy',
            'decision_at_ms' => $i, 'label_available_at_ms' => $i + 1,
            'unused_context' => str_repeat('x', 2048)];
    }
})());
$queries = array_fill(0, 65, ['vector' => array_fill(0, 12, 0.0), 'decision_at_ms' => 30000]);
$count = 0;
foreach ((new App\Domain\Intelligence\WeightedKnn)->neighborBatchesPrepared($rows, $queries, 65) as $result) {
    if ($result['neighbors'][0]['decision_at_ms'] !== 20000) {
        throw new RuntimeException('Lost latest eligible history.');
    }
    $count++;
}
echo json_encode(['queries' => $count, 'peak_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR);
PHP;
    $process = new Process([PHP_BINARY, '-d', 'memory_limit=64M', '-r', $code], dirname(__DIR__, 2));
    $process->setTimeout(60);

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($result['queries'])->toBe(65);
    expect($result['peak_bytes'])->toBeLessThan(64 * 1024 * 1024);
});
