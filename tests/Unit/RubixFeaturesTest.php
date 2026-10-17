<?php

use App\Domain\Intelligence\CandleGuidance;
use App\Domain\Intelligence\RubixFeatures;
use App\Domain\Intelligence\SequentialRandomForest;
use Rubix\ML\Classifiers\ClassificationTree;
use Rubix\ML\Classifiers\KNearestNeighbors;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Datasets\Unlabeled;

it('preserves numeric endpoints as continuous Rubix features after a JSON round trip', function () {
    $input = json_decode(json_encode([0.0, 0.5, 1.0]), true);

    expect(RubixFeatures::vector($input))->toBe([0.0, 0.5, 1.0]);
});

it('rejects invalid numeric features instead of coercing them to zero', function (mixed $value) {
    expect(fn () => RubixFeatures::vector([$value]))->toThrow(InvalidArgumentException::class);
})->with([
    'null' => [null], 'boolean' => [false], 'string' => ['0'],
    'not a number' => [NAN], 'infinity' => [INF],
]);

it('predicts candle guidance from integer JSON endpoints using a restored Rubix model', function () {
    $estimator = new KNearestNeighbors(1, true);
    $estimator->train(new Labeled([[0.0], [0.5], [1.0]], ['buy', 'hold', 'sell']));
    $bundle = ['version' => 'm4.4-candle-guidance-v2', 'estimator' => unserialize(serialize($estimator))];

    expect((new CandleGuidance)->features($bundle, [0]))->toBe([1.0, 0.0, 0.0]);
    expect((new CandleGuidance)->features($bundle, [1]))->toBe([0.0, 0.0, 1.0]);
});

it('preserves sequential forest probabilities when spilling trees and restoring a serialized model', function () {
    mt_srand(42);
    $forest = new SequentialRandomForest(new ClassificationTree(5, 1), 4, 0.8);
    $forest->train(new Labeled([[0.0], [0.1], [0.8], [1.0]], ['buy', 'buy', 'sell', 'sell']));
    $query = new Unlabeled([[0.0], [1.0]]);
    $expected = $forest->proba($query);

    $spools = $forest->spillTrees();
    $forest->restoreTrees($spools);
    $restored = unserialize(serialize($forest));

    expect($restored->proba($query))->toBe($expected);
});
