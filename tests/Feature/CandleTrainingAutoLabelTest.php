<?php

use App\Jobs\AnalyzeMarketAutoLabels;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    config([
        'research.path' => sys_get_temp_dir().'/candle-job-'.Str::uuid7(),
        'intelligence.enabled' => true,
        'queue.default' => 'database',
        'operations.owner_uuid' => null,
        'operations.owner_uuids' => [],
        'human_training.trainer_uuids' => [],
    ]);
});

afterEach(function () {
    File::deleteDirectory(config('research.path'));
});

it('queues exactly one market-wide analysis and leaves human labels unchanged', function () {
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id]);
    $manifest = IntelligenceFixtures::snapshot(3);
    Bus::fake([AnalyzeMarketAutoLabels::class]);
    $url = route('human-training.candles.auto-label', $manifest['dataset_id']);

    $this->actingAs($trainer)->postJson($url)->assertStatus(202)
        ->assertJsonPath('status', 'queued');
    Bus::assertDispatchedTimes(AnalyzeMarketAutoLabels::class, 1);
    Bus::assertDispatched(AnalyzeMarketAutoLabels::class, fn ($job) =>
        $job->exchange === $manifest['exchange'] &&
        $job->symbol === $manifest['symbol'] &&
        $job->period === $manifest['period']);
    $this->postJson($url)->assertStatus(409);
    Bus::assertDispatchedTimes(AnalyzeMarketAutoLabels::class, 1);
    $this->assertDatabaseEmpty('human_candle_labels');
    $this->assertDatabaseEmpty('human_training_snapshots');
});

it('reports queued status without running inference or changing labels', function () {
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id]);
    $manifest = IntelligenceFixtures::snapshot(3);
    Bus::fake([AnalyzeMarketAutoLabels::class]);
    $this->actingAs($trainer)->postJson(route('human-training.candles.auto-label', $manifest['dataset_id']))
        ->assertStatus(202);
    $this->getJson(route('human-training.candles.auto-label.status', $manifest['dataset_id']))
        ->assertOk()->assertJsonPath('status', 'queued')->assertJsonPath('locked', true)
        ->assertJsonPath('counts', null);
    $this->assertDatabaseEmpty('human_candle_labels');
});

it('rejects a disabled queue without dispatching a job', function () {
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id, 'queue.default' => 'sync']);
    $manifest = IntelligenceFixtures::snapshot(3);
    Bus::fake([AnalyzeMarketAutoLabels::class]);
    $this->actingAs($trainer)->postJson(route('human-training.candles.auto-label', $manifest['dataset_id']))
        ->assertStatus(422)->assertJsonPath('message', 'Intelligence queue is not enabled.');
    Bus::assertNotDispatched(AnalyzeMarketAutoLabels::class);
});

it('protects auto-label endpoints with authentication and trainer permissions', function () {
    $dataset = (string) Str::uuid7();
    $url = route('human-training.candles.auto-label', $dataset);
    $status = route('human-training.candles.auto-label.status', $dataset);
    $this->postJson($url)->assertUnauthorized();
    $this->getJson($status)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson($url)->assertForbidden();
    $this->getJson($status)->assertForbidden();
});
