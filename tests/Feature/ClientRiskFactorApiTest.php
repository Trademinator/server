<?php

use App\Domain\Client\RiskFactorCalculator;
use App\Models\ClientApiKey;
use App\Models\MarketPreferenceProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function riskFactorBearer(User $user, array $attributes = []): string
{
    $secret = 'tmk_'.Str::random(64);
    ClientApiKey::query()->create(array_replace([
        'user_id' => $user->getKey(), 'label' => 'risk-factor-test',
        'prefix' => substr($secret, 0, 12), 'secret_hash' => hash('sha256', $secret),
    ], $attributes));

    return $secret;
}

function riskFactorAnswers(bool $restrictive = false): array
{
    return $restrictive
        ? ['loss_impact' => 'yes', 'risk' => 'low', 'money_needed' => 'soon', 'experience' => 'new']
        : ['loss_impact' => 'no', 'risk' => 'medium', 'money_needed' => 'months', 'experience' => 'some'];
}

beforeEach(function () {
    config(['client.enabled' => true]);
});

it('requires a Client API bearer token even for a browser-authenticated user', function () {
    $this->getJson('/api/v1/client/risk-factor')->assertUnauthorized();
    $this->actingAs(User::factory()->create())->getJson('/api/v1/client/risk-factor')->assertUnauthorized();
});

it('rejects unknown expired and revoked tokens', function () {
    $this->withToken('tmk_'.Str::random(64))->getJson('/api/v1/client/risk-factor')->assertUnauthorized();
    $user = User::factory()->create();
    foreach ([['expires_at' => now()->subMinute()], ['revoked_at' => now()]] as $attributes) {
        $this->withToken(riskFactorBearer($user, $attributes))->getJson('/api/v1/client/risk-factor')->assertUnauthorized();
    }
});

it('requires a verified active account', function () {
    foreach ([['email_verified_at' => null], ['suspended_at' => now()]] as $attributes) {
        $user = User::factory()->create($attributes);
        $this->withToken(riskFactorBearer($user))->getJson('/api/v1/client/risk-factor')
            ->assertForbidden()->assertJsonPath('error.code', 'account_unavailable');
    }
});

it('honors the existing Client API enabled setting', function () {
    config(['client.enabled' => false]);
    $this->getJson('/api/v1/client/risk-factor')->assertStatus(503)
        ->assertJsonPath('error.code', 'client_api_disabled');
});

it('returns the exact default without requiring a market subscription or creating a profile', function () {
    $user = User::factory()->create();
    $response = $this->withToken(riskFactorBearer($user))->getJson('/api/v1/client/risk-factor')
        ->assertOk()->assertJsonPath('api_version', 1)
        ->assertJsonPath('risk_factor', '0.2500000000000000')
        ->assertJsonPath('source', 'default')
        ->assertJsonPath('algorithm_version', 'questionnaire-risk-v1')
        ->assertJsonPath('questionnaire_updated_at', null)
        ->assertJsonPath('restraint_score', null)
        ->assertJsonPath('components', [])
        ->assertJsonStructure(['calculated_at'])
        ->assertHeader('X-Trademinator-API-Version', '1');
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    $this->assertDatabaseMissing('market_preference_profiles', ['user_id' => $user->getKey()]);
    $this->assertDatabaseCount('market_subscriptions', 0);
});

it('reads the encrypted questionnaire and returns a decimal breakdown without unrelated answers', function () {
    $user = User::factory()->create();
    $profile = MarketPreferenceProfile::query()->create([
        'user_id' => $user->getKey(),
        'answers' => riskFactorAnswers() + ['country' => 'CA', 'holdings' => [['asset' => 'BTC']]],
    ]);
    $response = $this->withToken(riskFactorBearer($user))->getJson('/api/v1/client/risk-factor')
        ->assertOk()->assertJsonPath('risk_factor', '0.2511886431509580')
        ->assertJsonPath('source', 'questionnaire')
        ->assertJsonPath('restraint_score', '0.3000000000000000')
        ->assertJsonPath('questionnaire_updated_at', $profile->fresh()->updated_at->toISOString())
        ->assertJsonPath('components.risk.weight', '0.30')
        ->assertJsonPath('components.risk.score', '0.5000000000000000')
        ->assertJsonPath('unknown_fields', []);

    expect($response->json())->not->toHaveKey('answers')
        ->and(array_keys($response->json('components')))->toBe(['loss_impact', 'risk', 'money_needed', 'experience']);
    $this->assertDatabaseCount('client_market_settings', 0);
});

it('never reads another users questionnaire or accepts score overrides', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    MarketPreferenceProfile::query()->create(['user_id' => $first->getKey(), 'answers' => riskFactorAnswers(true)]);
    MarketPreferenceProfile::query()->create(['user_id' => $second->getKey(), 'answers' => riskFactorAnswers()]);
    $firstToken = riskFactorBearer($first);
    $secondToken = riskFactorBearer($second);
    $query = http_build_query(['user_id' => $second->getKey(), 'risk_factor' => '1', 'risk' => 'high']);

    $this->withToken($firstToken)->getJson('/api/v1/client/risk-factor?'.$query)
        ->assertOk()->assertJsonPath('risk_factor', '0.0100000000000000');
    $this->withToken($secondToken)->getJson('/api/v1/client/risk-factor')
        ->assertOk()->assertJsonPath('risk_factor', '0.2511886431509580');
    // A different owner's existing profile must not defeat this user's default.
    MarketPreferenceProfile::query()->whereKey($first->getKey())->delete();
    $this->withToken($firstToken)->getJson('/api/v1/client/risk-factor')
        ->assertOk()->assertJsonPath('risk_factor', '0.2500000000000000')->assertJsonPath('source', 'default');
});

it('reflects saved answer edits and removal on the next request', function () {
    $user = User::factory()->create();
    $profile = MarketPreferenceProfile::query()->create(['user_id' => $user->getKey(), 'answers' => riskFactorAnswers()]);
    $secret = riskFactorBearer($user);
    $this->withToken($secret)->getJson('/api/v1/client/risk-factor')->assertOk()
        ->assertJsonPath('risk_factor', '0.2511886431509580');
    $profile->update(['answers' => riskFactorAnswers(true)]);
    $this->withToken($secret)->getJson('/api/v1/client/risk-factor')->assertOk()
        ->assertJsonPath('risk_factor', '0.0100000000000000');
    $profile->delete();
    $this->withToken($secret)->getJson('/api/v1/client/risk-factor')->assertOk()
        ->assertJsonPath('risk_factor', '0.2500000000000000')->assertJsonPath('source', 'default');
});

it('distinguishes a saved empty profile from no profile and handles partial answers', function () {
    $user = User::factory()->create();
    $profile = MarketPreferenceProfile::query()->create(['user_id' => $user->getKey(), 'answers' => []]);
    $secret = riskFactorBearer($user);
    $this->withToken($secret)->getJson('/api/v1/client/risk-factor')->assertOk()
        ->assertJsonPath('risk_factor', '0.2500000000000000')->assertJsonPath('source', 'questionnaire')
        ->assertJsonPath('unknown_fields', ['loss_impact', 'risk', 'money_needed', 'experience']);
    $profile->update(['answers' => ['loss_impact' => 'yes']]);
    $this->withToken($secret)->getJson('/api/v1/client/risk-factor')->assertOk()
        ->assertJsonPath('risk_factor', (new RiskFactorCalculator)->calculate(['loss_impact' => 'yes'])['risk_factor'])
        ->assertJsonPath('components.experience.status', 'missing');
});

it('returns an error rather than a default for undecryptable or malformed saved profiles', function () {
    $user = User::factory()->create();
    MarketPreferenceProfile::query()->create(['user_id' => $user->getKey(), 'answers' => riskFactorAnswers(true)]);
    $secret = riskFactorBearer($user);
    foreach (['not-encrypted', Crypt::encryptString('not-json'), Crypt::encryptString('null'), Crypt::encryptString('"not-an-array"'), Crypt::encryptString('["not-a-profile"]')] as $stored) {
        DB::table('market_preference_profiles')->where('user_id', $user->getKey())->update(['answers' => $stored]);
        $response = $this->withToken($secret)->getJson('/api/v1/client/risk-factor')->assertStatus(503)
            ->assertJsonPath('error.code', 'risk_profile_unavailable');
        expect($response->json())->not->toHaveKey('risk_factor');
    }
});

it('uses a read-only GET route with the existing authentication and throttle middleware', function () {
    $route = app('router')->getRoutes()->match(Request::create('/api/v1/client/risk-factor', 'GET'));
    expect($route->methods())->toBe(['GET', 'HEAD'])
        ->and($route->gatherMiddleware())->toContain('client-auth', 'throttle:120,1,client-api');
    $this->postJson('/api/v1/client/risk-factor')->assertStatus(405);
});
