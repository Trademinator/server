<?php

use App\Domain\Intelligence\KnnCandidateBudget;

it('allows independent K voting work to exceed one budget in total', function () {
    $time = (object) ['now' => 0.0];
    $budget = new KnnCandidateBudget(100.0, 10.0, 'budget exceeded', fn (): float => $time->now);
    $time->now = 2.0;
    $budget->checkpointShared();
    $time->now = 8.0;
    $budget->checkpointCandidate(5);
    $time->now = 14.0;

    expect(fn () => $budget->checkpointCandidate(9))->not->toThrow(RuntimeException::class);
});

it('charges later shared neighbor work to every candidate including earlier K values', function () {
    $time = (object) ['now' => 0.0];
    $budget = new KnnCandidateBudget(100.0, 10.0, 'budget exceeded', fn (): float => $time->now);
    $time->now = 6.0;
    $budget->checkpointCandidate(5);
    $time->now = 7.0;
    $budget->checkpointCandidate(9);
    $time->now = 12.0;

    expect(fn () => $budget->checkpointShared())->toThrow(RuntimeException::class, 'budget exceeded');
});

it('keeps a candidates accumulated cost across rows and folds', function () {
    $time = (object) ['now' => 0.0];
    $budget = new KnnCandidateBudget(100.0, 10.0, 'budget exceeded', fn (): float => $time->now);
    foreach ([3.0, 6.0, 9.0] as $elapsed) {
        $time->now = $elapsed;
        $budget->checkpointCandidate(5);
        $budget->checkpointShared();
    }
    $time->now = 11.0;

    expect(fn () => $budget->checkpointCandidate(5))->toThrow(RuntimeException::class, 'budget exceeded');
});

it('honors an earlier outer deadline even when every K still has time', function () {
    $time = (object) ['now' => 0.0];
    $budget = new KnnCandidateBudget(5.0, 100.0, 'outer deadline exceeded', fn (): float => $time->now);
    $time->now = 4.0;
    $budget->checkpointCandidate(5);
    $time->now = 6.0;

    expect(fn () => $budget->checkpointCandidate(9))->toThrow(RuntimeException::class, 'outer deadline exceeded');
});

it('starts a new algorithm run with its full candidate allowance', function () {
    $time = (object) ['now' => 0.0];
    $clock = fn (): float => $time->now;
    $previous = new KnnCandidateBudget(100.0, 10.0, 'budget exceeded', $clock);
    $time->now = 9.0;
    $previous->checkpointShared();
    $next = new KnnCandidateBudget(100.0, 10.0, 'budget exceeded', $clock);
    $time->now = 18.0;

    expect(fn () => $next->checkpointShared())->not->toThrow(RuntimeException::class);
});

it('retains a hard deadline for callers that do not request per-K budgets', function () {
    $time = (object) ['now' => 0.0];
    $budget = new KnnCandidateBudget(5.0, null, 'budget exceeded', fn (): float => $time->now);
    $time->now = 4.0;
    $budget->checkpointShared();
    $time->now = 6.0;

    expect(fn () => $budget->checkpointShared())->toThrow(RuntimeException::class, 'budget exceeded');
});

it('rejects nonpositive or nonfinite candidate allowances', function (float $seconds) {
    expect(fn () => new KnnCandidateBudget(100.0, $seconds, 'budget exceeded', static fn (): float => 0.0))
        ->toThrow(InvalidArgumentException::class, 'finite and positive');
})->with([0.0, -1.0, INF, NAN]);

it('rejects a run whose explicit deadline has already passed', function () {
    expect(fn () => new KnnCandidateBudget(1.0, 100.0, 'budget exceeded', static fn (): float => 2.0))
        ->toThrow(RuntimeException::class, 'budget exceeded');
});
