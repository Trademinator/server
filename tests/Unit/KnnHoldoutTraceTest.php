<?php

use App\Domain\Intelligence\KnnTuner;
use App\Domain\Intelligence\WeightedKnn;

it('retains a bounded candle-level action trace in the aggregated holdout', function () {
    $base=1704067200000;
    $training=[];
    for ($i=0;$i<60;$i++) {
        $training[]=['decision_at_ms'=>$base+$i*60000,
            'label_available_at_ms'=>$base+($i+2)*60000,
            'vector'=>[$i%2?0.1:0.9],
            'label'=>$i%2?'buy':'sell'];
    }
    $test=[];
    for ($i=70;$i<80;$i++) {
        $test[]=['decision_at_ms'=>$base+$i*60000,
            'label_available_at_ms'=>$base+($i+2)*60000,
            'vector'=>[$i%2?0.1:0.9],
            'label'=>$i%2?'buy':'sell'];
    }
    $settings=['min_validation_rows'=>1, 'min_directional_opportunities'=>1, 'min_directional_predictions'=>1, 'min_semantic_precision'=>0.1, 'min_directional_wilson_lower'=>0.1, 'min_directional_baseline_lift'=>0.0, 'max_contradiction_rate'=>1.0, 'min_coverage'=>0.0];
    $result=(new KnnTuner(new WeightedKnn))->evaluatePrepared(
        $training,$test,9,$settings,microtime(true)+15
    );
    expect($result['trace'])->toHaveCount(10)
        ->and($result['trace'][0])->toHaveKeys(['decision_at_ms','predicted','actual','supported','reason','confidence'])
        ->and($result['evaluated'])->toBe(10);
});
