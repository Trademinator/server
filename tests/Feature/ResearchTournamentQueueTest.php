<?php

use App\Jobs\TrainIntelligenceTournament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

it('dispatches a single dataset to the tournament queue without running training inline', function () {
    Queue::fake();
    config(['queue.default' => 'database']);
    $dataset = (string) Str::uuid7();
    $this->artisan('trademinator:tournament', ['dataset' => $dataset, '--max-rows' => '200'])->assertSuccessful();
    Queue::assertPushed(TrainIntelligenceTournament::class, fn ($job) =>
        $job->datasetId === $dataset && $job->maxRows === 200 && $job->queue === 'tournament');
    $this->assertDatabaseEmpty('intelligence_tournaments');
});

it('refuses sync queue and invalid caps without dispatching', function () {
    Queue::fake();
    config(['queue.default' => 'sync']);
    $this->artisan('trademinator:tournament', ['dataset' => (string) Str::uuid7()])->assertFailed();
    config(['queue.default' => 'database']);
    $this->artisan('trademinator:tournament', ['dataset' => (string) Str::uuid7(), '--max-rows' => '5000'])->assertFailed();
    Queue::assertNotPushed(TrainIntelligenceTournament::class);
});

it('dispatches each published dataset using --all and preserves published model heads', function () {
    Queue::fake();
    config(['queue.default' => 'database']);
    $a = (string) Str::uuid7();
    $b = (string) Str::uuid7();
    foreach ([$a, $b] as $id) {
        $market = hash('sha256', $id);
        $model = (string) Str::uuid7();
        DB::table('research_datasets')->insert(['dataset_id' => $id, 'manifest' => '{}', 'created_at' => now()]);
        DB::table('intelligence_models')->insert(['model_id' => $model, 'dataset_id' => $id,
            'market_key' => $market, 'status' => 'ready', 'sha256' => str_repeat('a', 64),
            'report' => '{}', 'created_at' => now()]);
        DB::table('intelligence_heads')->insert(['market_key' => $market, 'model_id' => $model, 'updated_at' => now()]);
    }
    $this->artisan('trademinator:tournament', ['--all' => true])->assertSuccessful();
    Queue::assertPushed(TrainIntelligenceTournament::class, 2);
    $this->assertDatabaseCount('intelligence_heads', 2);
    $this->assertDatabaseEmpty('intelligence_tournaments');
});
