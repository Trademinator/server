<?php

use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Research\FeatureSchema;

it('shows Outcome and Action KNN readiness independently of the combined build status', function (
    array $overrides, bool $outcome, bool $action
) {
    $this->freezeTime();
    config(['intelligence.max_model_age_days' => 14]);
    $report = array_replace_recursive([
        'status' => 'ready',
        'keys' => FeatureSchema::keys('core'),
        'training_data' => ['schema' => 'core'],
        'validation_version' => IntelligenceTrainer::VERSION,
        'rubix_ml_major' => \Rubix\ML\VERSION,
        'trained_as_of_ms' => now()->getTimestampMs(),
        'outcome' => ['status' => 'ready', 'reason' => 'validated'],
        'action' => ['status' => 'ready', 'reason' => 'validated'],
    ], $overrides);

    $view = $this->blade('<x-knn-readiness :report="$report" />', ['report' => $report]);

    $view->assertSee('aria-label="Outcome KNN: '.($outcome ? 'Ready' : 'Not ready'), false)
        ->assertSee('aria-label="Action KNN: '.($action ? 'Ready' : 'Not ready'), false);
    expect(substr_count((string) $view, '>✓</span>'))->toBe((int) $outcome + (int) $action);
})->with([
    'both models ready' => [[], true, true],
    'outcome only' => [['action' => ['status' => 'abstaining', 'reason' => 'holdout_failed']], true, false],
    'action only' => [['outcome' => ['status' => 'abstaining', 'reason' => 'holdout_failed']], false, true],
    'status takes precedence over a contradictory reason' => [
        ['outcome' => ['status' => 'abstaining', 'reason' => 'validated']], false, true,
    ],
    'neither validated' => [[
        'status' => 'abstaining',
        'outcome' => ['status' => 'abstaining', 'reason' => 'no_eligible_k'],
        'action' => ['status' => 'abstaining', 'reason' => 'tuning_failed'],
    ], false, false],
    'combined status cannot stand in for component results' => [
        ['outcome' => null, 'action' => null], false, false,
    ],
    'expired build' => [['trained_as_of_ms' => 0], false, false],
    'obsolete build version' => [['validation_version' => 'old'], false, false],
]);

it('keeps both labelled placeholders when no model has been built', function () {
    $this->blade('<x-knn-readiness />')
        ->assertSee('Outcome KNN: Not ready — No model built yet')
        ->assertSee('Action KNN: Not ready — No model built yet')
        ->assertDontSee('✓');
});

it('shows accessible separate readiness totals including zero', function () {
    $this->blade('<x-knn-readiness :counts="$counts" :total="4" />', [
        'counts' => ['outcome' => 3, 'action' => 0],
    ])
        ->assertSee('Outcome KNN: 3 of 4 ready')
        ->assertSee('Action KNN: 0 of 4 ready');
});

it('escapes diagnostic reasons in readiness tooltips', function () {
    $this->freezeTime();
    $report = [
        'status' => 'abstaining',
        'keys' => FeatureSchema::keys('core'),
        'training_data' => ['schema' => 'core'],
        'validation_version' => IntelligenceTrainer::VERSION,
        'rubix_ml_major' => \Rubix\ML\VERSION,
        'trained_as_of_ms' => now()->getTimestampMs(),
        'outcome' => ['status' => 'abstaining', 'reason' => '<script>alert(1)</script>'],
        'action' => ['status' => 'ready', 'reason' => 'validated'],
    ];

    $this->blade('<x-knn-readiness :report="$report" />', ['report' => $report])
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false);
});
