<?php

use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\ModelStore;
use App\Models\ClientApiKey;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $path = sys_get_temp_dir().'/m4-availability-'.Str::uuid7();
    config(['client.enabled' => true, 'intelligence.path' => $path.'/models',
        'research.path' => $path.'/research', 'intelligence.max_model_age_days' => 14,
        'intelligence.knn.min_train_size' => 36, 'intelligence.knn.test_size' => 100,
        'intelligence.knn.min_validation_rows' => 5, 'intelligence.knn.min_directional_predictions' => 1,
        'intelligence.patterns.enabled' => false, 'intelligence.horizon' => 2, 'intelligence.lookback' => 3]);
});

afterEach(function () {
    File::deleteDirectory(dirname(config('intelligence.path')));
});

function m4AvailabilityAccount(): array
{
    $user = User::factory()->create();
    $secret = 'tmk_'.Str::random(43);
    ClientApiKey::query()->create(['user_id' => $user->getKey(), 'label' => 'm4-availability',
        'prefix' => substr($secret, 0, 12), 'secret_hash' => hash('sha256', $secret)]);
    $exchange = Exchange::query()->firstOrCreate(['class' => 'kraken'], ['name' => 'Kraken', 'config' => '{}']);
    $market = Market::query()->firstOrCreate(['exchange_id' => $exchange->getKey(), 'symbol' => 'BTC/USD'], ['tick_size' => '1']);
    $subscription = MarketSubscription::query()->create(['user_id' => $user->getKey(), 'market_id' => $market->getKey(), 'active' => true]);

    return [$secret, '/api/v1/client/markets/'.$subscription->getKey().'/replay'];
}

function m4AvailabilityModel(): array
{
    $manifest = IntelligenceFixtures::snapshot();

    return app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
}

function m4AvailabilityInput(string $id, string $decision = '2024-01-01 04:12:00 UTC', string $intelligence = 'current'): array
{
    $at = CarbonImmutable::parse($decision)->getTimestampMs();

    return ['source_exchange' => 'kraken', 'model_exchange' => 'kraken', 'period' => '1m',
        'model_id' => $id, 'family' => 'production', 'intelligence' => $intelligence, 'decision_at_ms' => $at,
        'candles' => [[$at - 120000, '100', '102', '99', '101', '10'], [$at - 60000, '101', '103', '100', '102', '10']]];
}

it('advertises and infers a usable current production head', function () {
    [$token, $path] = m4AvailabilityAccount();
    $report = m4AvailabilityModel();
    $this->travelTo('2024-01-01 04:12:00 UTC');

    $this->withToken($token)->getJson($path.'/models?model_exchange=kraken&period=1m')
        ->assertOk()->assertJsonPath('models.0.model_id', $report['model_id'])
        ->assertJsonPath('models.0.current', true)->assertJsonPath('models.0.status', 'ready')
        ->assertJsonPath('models.0.unavailable_reason', null);
    $this->postJson($path.'/infer', m4AvailabilityInput($report['model_id']))
        ->assertOk()->assertJsonPath('provenance.model_id', $report['model_id'])
        ->assertJsonPath('signal.model_id', $report['model_id']);
    $this->assertDatabaseCount('market_signals', 0);
});

it('returns 409 for an expired current head and preserves its publication metadata', function (int $days, string $expires) {
    [$token, $path] = m4AvailabilityAccount();
    $report = m4AvailabilityModel();
    config(['intelligence.max_model_age_days' => $days]);
    $this->travelTo($expires);

    $this->withToken($token)->getJson($path.'/models?model_exchange=kraken&period=1m')
        ->assertOk()->assertJsonPath('models.0.current', false)
        ->assertJsonPath('models.0.status', 'ready')->assertJsonPath('models.0.unavailable_reason', 'stale_model');
    $this->postJson($path.'/infer', m4AvailabilityInput($report['model_id']))
        ->assertConflict()->assertJsonPath('code', 'model_unavailable')
        ->assertJsonPath('reason', 'stale_model')->assertJsonPath('model_id', $report['model_id'])
        ->assertJsonMissingPath('signal');
    $this->assertDatabaseHas('intelligence_models', ['model_id' => $report['model_id'], 'status' => 'ready']);
    $this->assertDatabaseHas('intelligence_heads', ['model_id' => $report['model_id']]);
    $this->assertDatabaseCount('market_signals', 0);
})->with([
    'one day configured' => [1, '2024-01-02 04:03:00 UTC'],
    'fourteen days configured' => [14, '2024-01-15 04:03:00 UTC'],
]);

it('returns 409 for a missing production artifact and excludes the retained head from current discovery', function () {
    [$token, $path] = m4AvailabilityAccount();
    $report = m4AvailabilityModel();
    unlink(app(ModelStore::class)->path($report['model_id']));
    $this->travelTo('2024-01-01 04:12:00 UTC');

    $this->withToken($token)->getJson($path.'/models?model_exchange=kraken&period=1m')
        ->assertOk()->assertJsonPath('models.0.current', false)
        ->assertJsonPath('models.0.status', 'ready')->assertJsonPath('models.0.unavailable_reason', 'model_artifact_missing');
    $this->postJson($path.'/infer', m4AvailabilityInput($report['model_id']))
        ->assertConflict()->assertJsonPath('code', 'model_unavailable')
        ->assertJsonPath('reason', 'model_artifact_missing')->assertJsonPath('model_id', $report['model_id'])
        ->assertJsonMissingPath('signal');
    $this->assertDatabaseHas('intelligence_heads', ['model_id' => $report['model_id']]);
    $this->assertDatabaseHas('intelligence_models', ['model_id' => $report['model_id'], 'status' => 'ready']);
});

it('returns 409 for a corrupt production artifact without trusting its stored ready status', function () {
    [$token, $path] = m4AvailabilityAccount();
    $report = m4AvailabilityModel();
    file_put_contents(app(ModelStore::class)->path($report['model_id']), 'corrupted artifact');
    $this->travelTo('2024-01-01 04:12:00 UTC');

    $this->withToken($token)->getJson($path.'/models?model_exchange=kraken&period=1m')
        ->assertOk()->assertJsonPath('models.0.current', false)
        ->assertJsonPath('models.0.status', 'ready')->assertJsonPath('models.0.unavailable_reason', 'model_checksum_mismatch');
    $this->postJson($path.'/infer', m4AvailabilityInput($report['model_id']))
        ->assertConflict()->assertJsonPath('code', 'model_unavailable')
        ->assertJsonPath('reason', 'model_checksum_mismatch')->assertJsonMissingPath('signal');
    $this->assertDatabaseCount('market_signals', 0);
});

it('keeps a usable retained head current when a newer completed build abstains', function () {
    [$token, $path] = m4AvailabilityAccount();
    $report = m4AvailabilityModel();
    $candidate = (string) Str::uuid7();
    DB::table('intelligence_models')->insert(['model_id' => $candidate, 'dataset_id' => $report['dataset_id'],
        'market_key' => ModelStore::marketKey('kraken', 'BTC/USD', '1m'), 'status' => 'abstaining',
        'sha256' => str_repeat('0', 64), 'report' => json_encode([...$report, 'model_id' => $candidate, 'status' => 'abstaining']),
        'created_at' => now()->addMinute()]);
    $this->travelTo('2024-01-01 04:12:00 UTC');

    $this->withToken($token)->getJson($path.'/models?model_exchange=kraken&period=1m')
        ->assertOk()->assertJsonCount(2, 'models')->assertJsonPath('models.0.model_id', $candidate)
        ->assertJsonPath('models.0.current', false)->assertJsonPath('models.0.status', 'abstaining')
        ->assertJsonPath('models.1.model_id', $report['model_id'])
        ->assertJsonPath('models.1.current', true)->assertJsonPath('models.1.status', 'ready');
    $this->assertDatabaseHas('intelligence_heads', ['model_id' => $report['model_id']]);
});

it('permits a historical replay that was eligible at its decision even after the model expires today', function () {
    [$token, $path] = m4AvailabilityAccount();
    $report = m4AvailabilityModel();
    $this->travelTo('2024-01-20 00:00:00 UTC');

    $this->withToken($token)->postJson($path.'/infer', m4AvailabilityInput($report['model_id'], intelligence: 'historical'))
        ->assertOk()->assertJsonPath('provenance.out_of_sample', true)
        ->assertJsonPath('provenance.model_id', $report['model_id']);
    $this->assertDatabaseCount('market_signals', 0);
});

it('returns 409 when the production model was already expired at the historical decision', function () {
    [$token, $path] = m4AvailabilityAccount();
    $report = m4AvailabilityModel();
    $this->travelTo('2024-01-20 00:00:00 UTC');

    $this->withToken($token)->postJson($path.'/infer', m4AvailabilityInput($report['model_id'], '2024-01-15 04:03:00 UTC', 'historical'))
        ->assertConflict()->assertJsonPath('code', 'model_unavailable')
        ->assertJsonPath('reason', 'stale_model')->assertJsonMissingPath('signal');
    $this->assertDatabaseCount('market_signals', 0);
});

it('excludes an obsolete ready report from current discovery without changing its publication status', function () {
    [$token, $path] = m4AvailabilityAccount();
    $report = m4AvailabilityModel();
    DB::table('intelligence_models')->where('model_id', $report['model_id'])
        ->update(['report' => json_encode([...$report, 'validation_version' => 'obsolete-evaluator'])]);

    $this->withToken($token)->getJson($path.'/models?model_exchange=kraken&period=1m')
        ->assertOk()->assertJsonPath('models.0.current', false)
        ->assertJsonPath('models.0.status', 'ready')->assertJsonPath('models.0.unavailable_reason', 'model_not_ready');
    $this->assertDatabaseHas('intelligence_models', ['model_id' => $report['model_id'], 'status' => 'ready']);
    $this->assertDatabaseHas('intelligence_heads', ['model_id' => $report['model_id']]);
});

it('includes the retained head beyond the recent catalog page with its current eligibility', function (string $reviewAt, bool $current, ?string $reason) {
    [$token, $path] = m4AvailabilityAccount();
    $report = m4AvailabilityModel();
    $ids = [];
    $candidates = [];
    for ($i = 1; $i <= 101; $i++) {
        $id = (string) Str::uuid();
        $ids[] = $id;
        $candidates[] = ['model_id' => $id, 'dataset_id' => $report['dataset_id'],
            'market_key' => ModelStore::marketKey('kraken', 'BTC/USD', '1m'), 'status' => 'abstaining',
            'sha256' => str_repeat('0', 64), 'report' => json_encode(['status' => 'abstaining']),
            'created_at' => now()->addMinutes($i)];
    }
    DB::table('intelligence_models')->insert($candidates);
    $this->travelTo($reviewAt);

    $response = $this->withToken($token)->getJson($path.'/models?model_exchange=kraken&period=1m')
        ->assertOk()->assertJsonCount(101, 'models')
        ->assertJsonPath('models.100.model_id', $report['model_id'])
        ->assertJsonPath('models.100.current', $current)
        ->assertJsonPath('models.100.status', 'ready')->assertJsonPath('models.100.unavailable_reason', $reason);

    expect(array_column($response->json('models'), 'model_id'))->toBe([...array_reverse(array_slice($ids, 1)), $report['model_id']]);
    $this->assertDatabaseHas('intelligence_heads', ['model_id' => $report['model_id']]);
    $this->assertDatabaseCount('intelligence_models', 102);
})->with([
    'usable retained head' => ['2024-01-01 06:00:00 UTC', true, null],
    'expired retained head' => ['2024-01-16 06:00:00 UTC', false, 'stale_model'],
]);
