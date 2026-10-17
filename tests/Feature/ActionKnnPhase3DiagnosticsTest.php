<?php

use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\KnnTuner;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Intelligence\WeightedKnn;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function phase3PersistedHoldout(): array
{
    $rows = $predictions = [];
    foreach ([...array_fill(0, 20, 'buy'), ...array_fill(0, 160, 'hodl'), ...array_fill(0, 20, 'sell')] as $i => $label) {
        $rows[] = ['label' => $label, 'decision_at_ms' => ($i + 1) * 1000,
            'label_available_at_ms' => ($i + 3) * 1000,
            'semantic_bottom' => $label === 'buy', 'semantic_top' => $label === 'sell'];
        $predictions[] = ['action' => $label, 'reason' => 'supported', 'confidence' => 0.9];
    }

    return (new KnnTuner(new WeightedKnn))->evaluatePredictions($rows, $predictions, [
        'min_validation_rows' => 50, 'min_directional_predictions' => 5,
        'min_directional_opportunities' => 5, 'min_semantic_precision' => 0.55,
        'min_directional_wilson_lower' => 0.55, 'min_directional_baseline_lift' => 0.02,
        'max_contradiction_rate' => 0.05,
    ]);
}

function phase3PersistedModel(array $overrides = [], bool $current = true): string
{
    $model = (string) Str::uuid();
    $dataset = (string) Str::uuid();
    $report = array_replace_recursive([
        'model_id' => $model, 'dataset_id' => $dataset,
        'exchange' => 'bitso', 'symbol' => 'ATOM/USD', 'period' => '15m',
        'validation_version' => IntelligenceTrainer::VERSION,
        'rubix_ml_major' => \Rubix\ML\VERSION,
        'status' => 'abstaining', 'reason' => 'outcome_knn_unavailable',
        // Confusion belongs to Outcome, and is intentionally not a 3-class matrix.
        'holdout' => ['macro_f1' => 0.18, 'failed_gates' => ['macro_f1']],
        'outcome' => ['status' => 'abstaining', 'reason' => 'holdout_failed',
            'algorithmic' => ['status' => 'abstaining', 'reason' => 'holdout_failed', 'k' => 7,
                'holdout' => ['macro_f1' => 0.18, 'coverage' => 0.05, 'failed_gates' => ['macro_f1']]]],
        'action' => ['status' => 'ready', 'reason' => 'validated',
            'algorithmic' => ['status' => 'ready', 'reason' => 'validated', 'k' => 9,
                'holdout' => phase3PersistedHoldout()],
            'human' => ['status' => 'insufficient_candle_labels', 'samples' => 0]],
    ], $overrides);
    DB::table('research_datasets')->insert([
        'dataset_id' => $dataset, 'manifest' => '{}', 'created_at' => now(),
    ]);
    $key = ModelStore::marketKey($report['exchange'], $report['symbol'], $report['period']);
    DB::table('intelligence_models')->insert([
        'model_id' => $model, 'dataset_id' => $dataset, 'market_key' => $key,
        'status' => $report['status'], 'sha256' => str_repeat('a', 64),
        'created_at' => now(), 'report' => json_encode($report, JSON_THROW_ON_ERROR),
    ]);
    if ($current) {
        DB::table('intelligence_heads')->insert([
            'market_key' => $key, 'model_id' => $model, 'updated_at' => now(),
        ]);
    }

    return $model;
}

it('calibrates saved Action holdouts without accessing missing model and dataset sidecars', function () {
    phase3PersistedModel();
    $code = Artisan::call('trademinator:analyze-validation-gates', ['--json' => true]);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($code)->toBe(0)
        ->and($output['models'])->toHaveCount(1)
        ->and($output['models'][0]['scoring_component'])->toBe('action')
        ->and($output['models'][0]['directional'])->toBe(40)
        ->and($output['models'][0]['correct_directional'])->toBe(40)
        ->and($output['models'][0]['natural_class_counts'])->toBe(['buy' => 20, 'hodl' => 160, 'sell' => 20])
        ->and($output['models'][0]['coverage_gate_applied'])->toBeFalse()
        ->and($output['skipped'])->toBe([]);
});

it('skips older Outcome-only reports instead of reinterpreting their five-class confusion', function () {
    $model = phase3PersistedModel(['action' => ['algorithmic' => ['holdout' => null]]]);
    Artisan::call('trademinator:analyze-validation-gates', ['--json' => true]);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($output['models'])->toBe([])
        ->and($output['skipped'])->toHaveCount(1)
        ->and($output['skipped'][0]['model_id'])->toBe($model);
});

it('shows Outcome and Action failures separately, with HOLD and abstention diagnostics, on owner pages', function () {
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id]);
    $this->withSession(['auth.password_confirmed_at' => time()]);
    $model = phase3PersistedModel();

    $this->actingAs($owner)->get(route('owner.intelligence'))
        ->assertOk()->assertSee('Outcome / Action KNN holdout diagnostics')
        ->assertSee('Algorithmic Action KNN')
        ->assertSee('Supported HOLD versus abstention')
        ->assertSee('Natural BUY / HOLD / SELL labels');

    $this->get(route('owner.intelligence.show', $model))
        ->assertOk()->assertSee('Independent Outcome / Action holdout diagnostics')
        ->assertSee('holdout_failed')
        ->assertSee('macro_f1')
        ->assertSee('Supported HOLD versus abstention');
});

it('prints compact validation details from the saved report without verifying sidecar files', function () {
    $model = phase3PersistedModel();
    expect(Artisan::call('trademinator:model-info', ['model' => $model, '--validation-summary' => true]))->toBe(0);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($output['model_id'])->toBe($model)
        ->and($output['outcome']['algorithmic']['reason'])->toBe('holdout_failed')
        ->and($output['action']['algorithmic']['holdout']['directional'])->toBe(40)
        ->and($output['action']['algorithmic']['holdout']['supported_holds'])->toBe(160)
        ->and($output['action']['algorithmic']['holdout']['abstained'])->toBe(0)
        ->and($output['action']['algorithmic']['holdout']['evaluation_basis'])->toBe('finalized_historical_action_labels');
});
