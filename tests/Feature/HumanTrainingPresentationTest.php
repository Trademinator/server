<?php

use App\Models\HumanTrainingReview;
use App\Models\HumanTrainingSnapshot;
use App\Models\User;

it('retains the two original training entry points without the duplicate OHLCV section', function () {
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id, 'human_training.enabled' => true]);

    $this->actingAs($trainer)->get(route('human-training.index'))
        ->assertOk()
        ->assertSee('Outcome Training')
        ->assertSee('Action Training')
        ->assertDontSee('Label from OHLCV')
        ->assertDontSee('human-training/ohlcv/');
});

it('hides unavailable indicators but preserves the Outcome assessment', function (?array $features, bool $visible) {
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id, 'human_training.enabled' => true]);
    $snapshot = HumanTrainingSnapshot::factory()->make();
    $payload = $snapshot->payload;
    $payload['features'] = $features;
    $snapshot->payload = $payload;
    $snapshot->sha256 = HumanTrainingSnapshot::digest($payload);
    $snapshot->save();
    $review = HumanTrainingReview::factory()->create([
        'snapshot_id' => $snapshot->snapshot_id,
        'trainer_id' => $trainer->user_id,
    ]);

    $response = $this->actingAs($trainer)->get(route('human-training.show', $review->review_id))
        ->assertOk()->assertSee('Your Outcome assessment');
    if ($visible) {
        $response->assertSee('Indicators and context');
    } else {
        $response->assertDontSee('Indicators and context');
    }
})->with([
    'no features' => [[], false],
    'missing features' => [['candle.body' => null], false],
    'non-numeric features' => [['candle.body' => 'Unavailable'], false],
    'valid features' => [['candle.body' => 0.5], true],
]);
