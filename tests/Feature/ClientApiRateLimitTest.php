<?php

use App\Models\ClientApiKey;
use App\Models\User;
use Illuminate\Support\Str;

function clientRateLimitBearer(User $user): string
{
    $secret = 'tmk_'.Str::random(64);
    ClientApiKey::query()->create([
        'user_id' => $user->getKey(),
        'label' => 'rate-limit-test',
        'prefix' => substr($secret, 0, 12),
        'secret_hash' => hash('sha256', $secret),
    ]);

    return $secret;
}

/** Missing subscriptions exercise the real route middleware without loading model artifacts or making writes. */
function clientRateLimitMarketPath(string $resource, string $subscription = '00000000-0000-4000-8000-000000000001'): string
{
    return '/api/v1/client/markets/'.$subscription.'/'.$resource;
}

beforeEach(function () {
    config(['client.enabled' => true]);
});

it('allows thirty inferences after catalogue reads and returns 429 until the inference window expires', function () {
    $this->freezeTime();
    $token = clientRateLimitBearer(User::factory()->create());
    $this->withToken($token);

    for ($request = 0; $request < 40; $request++) {
        $this->getJson('/api/v1/client/markets')->assertOk();
    }
    for ($request = 0; $request < 30; $request++) {
        $this->postJson(clientRateLimitMarketPath('replay/infer'))->assertNotFound();
    }

    $this->postJson(clientRateLimitMarketPath('replay/infer'))
        ->assertTooManyRequests()->assertHeader('Retry-After', '60');
    $this->getJson('/api/v1/client/markets')->assertOk();

    $this->travel(61)->seconds();

    $this->postJson(clientRateLimitMarketPath('replay/infer'))->assertNotFound();
});

it('counts reads and inference once each toward the global limit and returns 429 on request 121', function () {
    $this->freezeTime();
    $this->withToken(clientRateLimitBearer(User::factory()->create()));

    for ($request = 0; $request < 90; $request++) {
        $this->getJson('/api/v1/client/markets')->assertOk();
    }
    for ($request = 0; $request < 30; $request++) {
        $this->postJson(clientRateLimitMarketPath('replay/infer'))->assertNotFound();
    }

    $this->getJson('/api/v1/client/markets')
        ->assertTooManyRequests()->assertHeader('Retry-After', '60');
});

it('returns 429 at the settings and paper write limits without spending the inference allowance', function () {
    $this->freezeTime();
    $this->withToken(clientRateLimitBearer(User::factory()->create()));

    for ($request = 0; $request < 30; $request++) {
        $this->putJson(clientRateLimitMarketPath('settings'))->assertNotFound();
    }
    $this->putJson(clientRateLimitMarketPath('settings'))
        ->assertTooManyRequests()->assertHeader('Retry-After', '60');

    for ($request = 0; $request < 60; $request++) {
        $this->postJson(clientRateLimitMarketPath('paper'))->assertNotFound();
    }
    $this->postJson(clientRateLimitMarketPath('paper'))
        ->assertTooManyRequests()->assertHeader('Retry-After', '60');

    $this->postJson(clientRateLimitMarketPath('replay/infer'))->assertNotFound();
    $this->getJson('/api/v1/client/markets')->assertOk();
});

it('counts report writes once toward the shared global limit and returns 429 after 120 mixed requests', function () {
    $this->freezeTime();
    $this->withToken(clientRateLimitBearer(User::factory()->create()));

    for ($request = 0; $request < 100; $request++) {
        $this->postJson(clientRateLimitMarketPath('reports'))->assertNotFound();
    }
    for ($request = 0; $request < 20; $request++) {
        $this->getJson('/api/v1/client/markets')->assertOk();
    }

    $this->postJson(clientRateLimitMarketPath('reports'))
        ->assertTooManyRequests()->assertHeader('Retry-After', '60');
});

it('shares the inference limit across one users keys and subscriptions while allowing another user at the same IP', function () {
    $this->freezeTime();
    $firstUser = User::factory()->create();
    $firstToken = clientRateLimitBearer($firstUser);
    $secondToken = clientRateLimitBearer($firstUser);
    $otherToken = clientRateLimitBearer(User::factory()->create());
    $otherSubscription = '00000000-0000-4000-8000-000000000002';
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10']);

    for ($request = 0; $request < 15; $request++) {
        $this->withToken($firstToken)->postJson(clientRateLimitMarketPath('replay/infer'))->assertNotFound();
    }
    for ($request = 0; $request < 15; $request++) {
        $this->withToken($secondToken)->postJson(clientRateLimitMarketPath('replay/infer', $otherSubscription))->assertNotFound();
    }

    $this->withToken($secondToken)->postJson(clientRateLimitMarketPath('replay/infer'))
        ->assertTooManyRequests()->assertHeader('Retry-After', '60');
    $this->withToken($otherToken)->postJson(clientRateLimitMarketPath('replay/infer'))->assertNotFound();
});

it('shares the global limit across one users keys while allowing another user at the same IP', function () {
    $this->freezeTime();
    $firstUser = User::factory()->create();
    $firstToken = clientRateLimitBearer($firstUser);
    $secondToken = clientRateLimitBearer($firstUser);
    $otherToken = clientRateLimitBearer(User::factory()->create());
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10']);

    for ($request = 0; $request < 60; $request++) {
        $this->withToken($firstToken)->getJson('/api/v1/client/markets')->assertOk();
    }
    for ($request = 0; $request < 60; $request++) {
        $this->withToken($secondToken)->getJson('/api/v1/client/markets')->assertOk();
    }

    $this->withToken($secondToken)->getJson('/api/v1/client/markets')
        ->assertTooManyRequests()->assertHeader('Retry-After', '60');
    $this->withToken($otherToken)->getJson('/api/v1/client/markets')->assertOk();
});
