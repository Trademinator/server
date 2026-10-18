<?php

use App\Domain\Intelligence\HumanTraining;
use App\Domain\Intelligence\HumanTrainingExport;
use App\Domain\Research\DatasetStore;
use App\Models\HumanTrainingReview;
use App\Models\MarketSignal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    config(['research.path' => sys_get_temp_dir().'/human-training-test-'.Str::uuid7(), 'human_training.enabled' => true]);
});

afterEach(function () {
    File::deleteDirectory(config('research.path'));
});

function humanTrainingDataset(): array
{
    $manifest = IntelligenceFixtures::snapshot(20, patterns: true);
    for ($i = 0; $i < 23; $i++) {
        IntelligenceFixtures::feature($i, ($i % 3) / 2);
    }
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    $row = $rows[10];
    $row['candle'] = ['open' => '10', 'close' => '10', 'high' => '11', 'low' => '9', 'volume' => '1'];
    $bytes = json_encode($row)."\n";
    $manifest['rows'] = 1;
    $manifest['rows_sha256'] = hash('sha256', $bytes);
    $path = app(DatasetStore::class)->directory($manifest['dataset_id']);
    file_put_contents($path.'/rows.jsonl', $bytes);
    file_put_contents($path.'/manifest.json', json_encode($manifest));
    DB::table('research_datasets')->where('dataset_id', $manifest['dataset_id'])->update(['manifest' => json_encode($manifest)]);

    return $manifest;
}

it('limits human training to verified active explicitly authorized users', function (string $role, bool $allowed) {
    $user = User::factory()->create();
    config(['operations.owner_uuid' => $role === 'owner' ? $user->user_id : null,
        'human_training.trainer_uuids' => in_array($role, ['trainer', 'unverified', 'suspended']) ? [$user->user_id] : []]);
    if ($role === 'unverified') {
        $user->forceFill(['email_verified_at' => null])->save();
    }
    if ($role === 'suspended') {
        $user->forceFill(['suspended_at' => now()])->save();
    }
    expect(Gate::forUser($user)->allows('train-intelligence'))->toBe($allowed);
})->with([['owner', true], ['trainer', true], ['ordinary', false], ['unverified', false], ['suspended', false]]);

it('protects training routes and keeps export owner-only', function () {
    $this->get('/human-training')->assertRedirect('/login');
    $user = User::factory()->create();
    $this->actingAs($user)->get('/human-training')->assertForbidden();
    config(['human_training.trainer_uuids' => [$user->user_id]]);
    $this->get('/human-training')->assertOk()->assertSee('Human training')->assertHeader('Cache-Control', 'no-store, private');
    $this->post('/human-training/export')->assertForbidden();
    config(['operations.owner_uuid' => $user->user_id]);
    $this->post('/human-training/export')->assertDownload();
});

it('freezes only pre-cutoff candles and strips future targets from the review response', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $user = User::factory()->create();
    config(['operations.owner_uuid' => $user->user_id]);
    $manifest = humanTrainingDataset();
    $cutoff = IntelligenceFixtures::START + 11 * 60000;
    $signal = MarketSignal::factory()->create(['recorded_at_ms' => $cutoff - 60000,
        'decision_at_ms' => $cutoff - 60000, 'action' => 'buy', 'reason' => 'supported']);
    MarketSignal::factory()->create(['market_id' => $signal->market_id, 'recorded_at_ms' => $cutoff + 1,
        'decision_at_ms' => $cutoff, 'action' => 'sell', 'reason' => 'supported']);
    $this->actingAs($user)->post('/human-training', ['dataset' => $manifest['dataset_id']])->assertRedirect();
    $review = HumanTrainingReview::query()->sole();
    $payload = app(HumanTraining::class)->display($review);
    expect($payload)->not->toHaveKeys(['label', 'semantic', 'label_available_at_ms', 'model_observation']);
    expect($payload['patterns'][0])->not->toHaveKeys(['label', 'label_available_at_ms']);
    expect(array_column($payload['series'], 'time'))->toHaveCount(11);
    expect(max(array_column($payload['series'], 'time')))->toBe(intdiv(IntelligenceFixtures::START, 1000) + 600);
    $this->get('/human-training/'.$review->review_id)->assertOk()->assertSee('Outcome chart with highlighted decision and future H candles');
    $this->post('/human-training', ['dataset' => $manifest['dataset_id']])->assertRedirect('/human-training/'.$review->review_id);
    $this->assertDatabaseCount('human_training_reviews', 1);
    $this->put('/human-training/'.$review->review_id, ['label' => 'neutral'])->assertRedirect();
    expect(app(HumanTraining::class)->display($review->fresh())['model_observation']['action'])->toBe('buy');
});

it('refuses a snapshot when its source candle changed after the dataset was frozen', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $user = User::factory()->create();
    config(['operations.owner_uuid' => $user->user_id]);
    $manifest = humanTrainingDataset();
    DB::table('tickers')->where('microtimestamp', IntelligenceFixtures::START + 10 * 60000)
        ->update(['payload' => json_encode(['open' => '10', 'close' => '11', 'high' => '11', 'low' => '9', 'volume' => '1'])]);
    $this->actingAs($user)->post('/human-training', ['dataset' => $manifest['dataset_id']])->assertSessionHasErrors('dataset');
    $this->assertDatabaseCount('human_training_snapshots', 0);
});

it('records immutable labels with server-owned identity and refuses cross-trainer or replayed submissions', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $other = User::factory()->create();
    config(['operations.owner_uuid' => $user->user_id, 'human_training.trainer_uuids' => [$other->user_id]]);
    $review = HumanTrainingReview::factory()->create(['trainer_id' => $user->user_id]);
    $url = '/human-training/'.$review->review_id;
    $this->actingAs($other)->get($url)->assertNotFound();
    $this->put($url, ['label' => 'bull'])->assertNotFound();
    $this->actingAs($user)->put($url, ['label' => 'super_bull', 'confidence' => 80,
        'reason' => '<script>unsafe</script>', 'trainer_id' => $other->user_id, 'payload' => ['label' => 'buy']])->assertRedirect($url);
    $this->assertDatabaseHas('human_training_reviews', ['review_id' => $review->review_id, 'trainer_id' => $user->user_id, 'label' => 'super_bull', 'confidence' => 80]);
    $this->get($url)->assertOk()->assertSee('&lt;script&gt;unsafe&lt;/script&gt;', false)->assertDontSee('<script>unsafe</script>', false);
    $this->put($url, ['label' => 'bear'])->assertConflict();
    expect(fn () => $review->fresh()->forceFill(['label' => 'bear'])->save())->toThrow(LogicException::class);
    expect(fn () => $review->snapshot->forceFill(['payload' => []])->save())->toThrow(LogicException::class);
});

it('rejects invalid review inputs without recording an answer', function (array $input, string $field) {
    $this->freezeTime();
    $user = User::factory()->create();
    config(['operations.owner_uuid' => $user->user_id]);
    $review = HumanTrainingReview::factory()->create(['trainer_id' => $user->user_id]);
    $this->actingAs($user)->put('/human-training/'.$review->review_id, $input)->assertSessionHasErrors($field);
    expect($review->fresh()->submitted_at)->toBeNull();
})->with([[[], 'label'], [['label' => 'buy'], 'label'], [['label' => 'bull', 'confidence' => 101], 'confidence'],
    [['label' => 'bull', 'confidence' => -1], 'confidence'], [['label' => 'bull', 'confidence' => 0.5], 'confidence'],
    [['label' => 'bull', 'reason' => str_repeat('x', 2001)], 'reason']]);

it('rejects expired assignments and supports skipping without a directional label', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    config(['operations.owner_uuid' => $user->user_id]);
    $expired = HumanTrainingReview::factory()->create(['trainer_id' => $user->user_id, 'expires_at' => now()->subSecond()]);
    $active = HumanTrainingReview::factory()->create(['trainer_id' => $user->user_id]);
    $this->actingAs($user)->put('/human-training/'.$expired->review_id, ['label' => 'bull'])->assertConflict();
    $this->put('/human-training/'.$active->review_id, ['label' => 'skip'])->assertRedirect();
    expect($active->fresh()->label)->toBe('skip');
    expect($expired->fresh()->submitted_at)->toBeNull();
});

it('keeps independent reviews on one snapshot and reports disagreement without modifying objective data', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $users = User::factory()->count(2)->create();
    config(['operations.owner_uuid' => $users[0]->user_id, 'human_training.trainer_uuids' => [$users[1]->user_id]]);
    $manifest = humanTrainingDataset();
    foreach ($users as $index => $user) {
        $review = app(HumanTraining::class)->assign($user, $manifest['dataset_id']);
        app(HumanTraining::class)->submit($user, $review->review_id, $index ? 'bear' : 'bull', null, null);
    }
    $this->assertDatabaseCount('human_training_snapshots', 1);
    $this->assertDatabaseCount('human_training_reviews', 2);
    expect(app(DatasetStore::class)->manifest($manifest['dataset_id'])['rows_sha256'])->toBe($manifest['rows_sha256']);
    $this->actingAs($users[0])->get('/human-training')->assertOk()->assertSee('1 with differing labels')->assertSee('0.0%');
    $this->post('/human-training', ['dataset' => $manifest['dataset_id']])->assertSessionHasErrors('dataset');
});

it('exports verifiable versioned snapshots and submitted labels with no account secrets', function () {
    $this->freezeTime();
    $user = User::factory()->create(['api_key' => 'SECRET-DO-NOT-EXPORT']);
    $review = HumanTrainingReview::factory()->create(['trainer_id' => $user->user_id, 'label' => 'bear', 'submitted_at' => now()]);
    $path = config('research.path');
    mkdir($path, 0700, true);
    $target = $path.'/human.jsonl';
    $this->artisan('trademinator:human-training-export', ['path' => $target])->assertSuccessful();
    $lines = file($target);
    $footer = json_decode(array_pop($lines), true);
    expect($footer['sha256'])->toBe(hash('sha256', implode('', $lines)));
    expect($footer['snapshots'])->toBe(1);
    expect($lines[0])->toContain(HumanTrainingExport::FORMAT);
    $record = json_decode($lines[1], true);
    expect($record['reviews'][0]['trainer_id'])->toBe($user->user_id);
    expect($record['payload'])->not->toHaveKey('label');
    expect(implode('', $lines))->not->toContain('SECRET-DO-NOT-EXPORT', $user->email, $user->password);
    $this->artisan('trademinator:human-training-export', ['path' => $target])->assertFailed();
    DB::table('human_training_snapshots')->where('snapshot_id', $review->snapshot_id)->update(['sha256' => str_repeat('0', 64)]);
    expect(fn () => app(HumanTrainingExport::class)->write($path.'/corrupt.jsonl'))->toThrow(LogicException::class, 'checksum');
    expect(is_file($path.'/corrupt.jsonl'))->toBeFalse();
});
