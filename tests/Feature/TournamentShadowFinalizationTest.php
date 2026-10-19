<?php

use App\Domain\Intelligence\FinalizedShadowLabels;
use App\Models\Ticker;
use Illuminate\Support\Facades\DB;
use Tests\Support\IntelligenceFixtures;

it('cannot finalize an Outcome before the complete forward horizon is closed', function () {
    $start=IntelligenceFixtures::START;
    $rows=[];
    for ($i=0;$i<12;$i++) {
        $price=100+$i*0.25;
        $rows[]=['exchange'=>'kraken','symbol'=>'BTC/USD','period'=>'1m',
            'microtimestamp'=>$start+$i*60000,
            'payload'=>json_encode(['open'=>$price,'high'=>$price+1,'low'=>$price-1,
                'close'=>$price,'volume'=>10])];
    }
    foreach($rows as $item) Ticker::query()->create($item);
    $decision=$start+8*60000; // the eighth candle has closed
    $service=app(FinalizedShadowLabels::class);
    expect($service->outcome('kraken','BTC/USD','1m',$decision,3,5,$decision+2*60000))->toBeNull();
    $final=$service->outcome('kraken','BTC/USD','1m',$decision,3,5,$decision+3*60000);
    expect($final)->toHaveKeys(['actual','available_at_ms'])
        ->and($final['available_at_ms'])->toBe($decision+3*60000);
});

it('will not score an immature Outcome, even if an abstention is recorded', function () {
    $this->assertDatabaseEmpty('tournament_shadow_scores');
});
