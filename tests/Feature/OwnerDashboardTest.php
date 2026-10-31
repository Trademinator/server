<?php

use App\Domain\Features\FeatureEngine;
use App\Domain\Intelligence\ModelStore;
use App\Domain\MarketData\MarketSubscriptions;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withSession(['auth.password_confirmed_at' => time()]);
});

function serverOwner(): User
{
    $user = User::factory()->create();
    config(['operations.owner_uuid' => $user->user_id]);

    return $user;
}

function ownerReportMarket(User $user): Market
{
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1m', 'status' => 'ready', 'next_pull_at' => now()]);
    MarketSubscription::query()->create(['user_id' => $user->user_id, 'market_id' => $market->market_id, 'active' => true]);

    return $market;
}

it('requires a signed-in verified owner for administration', function () {
    $this->get('/owner')->assertRedirect('/login');
    $owner = serverOwner();
    $owner->forceFill(['email_verified_at' => null])->save();
    $this->actingAs($owner)->get('/owner')->assertRedirect('/verify-email');
});

it('grants the owner gate only for a valid configured matching UUID', function (string $configuration, bool $allowed) {
    $user = User::factory()->create();
    config(['operations.owner_uuid' => match ($configuration) {
        'same' => $user->user_id, 'uppercase' => strtoupper($user->user_id), 'other' => (string) Str::uuid(), default => $configuration,
    }]);

    expect(Gate::forUser($user)->allows('manage-server'))->toBe($allowed);
})->with([['same', true], ['uppercase', true], ['other', false], ['', false], ['invalid', false]]);

it('refuses every owner report to ordinary users before resolving private records', function (string $path) {
    serverOwner();
    $this->actingAs(User::factory()->create())->get($path)->assertForbidden();
})->with(['/owner', '/owner/users', '/owner/subscriptions', '/owner/intelligence', '/owner/status', '/owner/access',
    '/owner/users/00000000-0000-4000-8000-000000000001', '/owner/markets/00000000-0000-4000-8000-000000000001',
    '/owner/intelligence/00000000-0000-4000-8000-000000000001']);

it('renders global reports with escaped identities and without secrets', function () {
    $owner = serverOwner();
    $target = User::factory()->create(['name' => '<script>alert(1)</script>', 'api_key' => 'PRIVATE_API_KEY']);
    $market = ownerReportMarket($target);
    $this->actingAs($owner);

    $this->get('/owner')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('Server overview');
    $this->get('/owner/users')->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('PRIVATE_API_KEY');
    $this->get('/owner/users/'.$target->user_id)->assertOk()->assertSee('BTC/USD')->assertDontSee('PRIVATE_API_KEY');
    $this->get('/owner/subscriptions')->assertOk()->assertSee($target->email)->assertSee('BTC/USD');
    $this->get('/owner/markets/'.$market->market_id)->assertOk()->assertSee('Not trained');
    $this->get('/owner/intelligence')->assertOk()->assertSee('No matching models');
    $this->get('/owner/access')->assertOk()->assertSee('Requests by city');
});

it('shows validation reports without loading a serialized model artifact', function () {
    $owner = serverOwner();
    $market = ownerReportMarket($owner);
    $key = ModelStore::marketKey($market->exchange->class, $market->symbol, $market->feed->selected_period);
    $dataset = (string) Str::uuid();
    $model = (string) Str::uuid();
    DB::table('research_datasets')->insert(['dataset_id' => $dataset, 'manifest' => '{}', 'created_at' => now()]);
    DB::table('intelligence_models')->insert(['model_id' => $model, 'dataset_id' => $dataset, 'market_key' => $key,
        'status' => 'abstaining', 'sha256' => str_repeat('0', 64), 'created_at' => now(),
        'report' => json_encode(['exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m', 'knowledge_rows' => 227,
            'status' => 'abstaining', 'reason' => 'no_eligible_k', 'selection' => ['k' => null]])]);
    DB::table('intelligence_heads')->insert(['market_key' => $key, 'model_id' => $model, 'updated_at' => now()]);

    $this->actingAs($owner)->get('/owner/intelligence')->assertOk()->assertSee('227')->assertSee('no_eligible_k');
    $this->get('/owner/intelligence/'.$model)->assertOk()->assertSee('K selection and chronological validation');
});

it('suspends accounts and subscriptions while preserving other users shared feeds', function () {
    $owner = serverOwner();
    $target = User::factory()->create(['api_key' => (string) Str::uuid()]);
    $market = ownerReportMarket($target);
    $other = User::factory()->create();
    MarketSubscription::query()->create(['market_id' => $market->market_id, 'user_id' => $other->user_id, 'active' => true]);

    $this->actingAs($owner)->put('/owner/users/'.$target->user_id, ['operation' => 'suspend'])->assertRedirect();

    expect($target->fresh()->suspended_at)->not->toBeNull();
    expect($target->fresh()->api_key)->toBeNull();
    expect($target->subscriptions()->where('active', true)->count())->toBe(0);
    expect($other->subscriptions()->where('active', true)->count())->toBe(1);
    expect($market->feed->status)->toBe('ready');
    $this->actingAs($target->fresh())->get('/markets')->assertForbidden();
    $this->assertGuest();
    $this->post('/login', ['email' => $target->email, 'password' => 'password'])->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('restores access without silently reactivating stopped subscriptions', function () {
    $owner = serverOwner();
    $target = User::factory()->create(['suspended_at' => now()]);
    ownerReportMarket($target);
    $target->subscriptions()->update(['active' => false]);

    $this->actingAs($owner)->put('/owner/users/'.$target->user_id, ['operation' => 'restore'])->assertRedirect();

    expect($target->fresh()->suspended_at)->toBeNull();
    expect($target->subscriptions()->where('active', true)->count())->toBe(0);
});

it('rejects forged privilege fields and protects the owner from self-suspension', function () {
    $owner = serverOwner();
    $target = User::factory()->create();
    $this->actingAs($target)->put('/owner/users/'.$owner->user_id, ['operation' => 'suspend'])->assertForbidden();
    $this->actingAs($owner)->put('/owner/users/'.$owner->user_id, ['operation' => 'suspend'])->assertUnprocessable();
    $this->put('/owner/users/'.$target->user_id, ['operation' => 'profile', 'name' => 'Updated', 'email' => 'new@example.com',
        'owner_uuid' => $target->user_id, 'user_id' => $owner->user_id, 'email_verified_at' => now()->toIso8601String()])->assertRedirect();

    expect($owner->fresh()->suspended_at)->toBeNull();
    expect($target->fresh()->email)->toBe('new@example.com');
    expect($target->fresh()->email_verified_at)->toBeNull();
    expect($target->fresh()->isOwner())->toBeFalse();
});

it('validates management operations and revokes API keys without revealing them', function () {
    $owner = serverOwner();
    $target = User::factory()->create(['api_key' => (string) Str::uuid()]);
    $this->actingAs($owner)->put('/owner/users/'.$target->user_id, ['operation' => 'delete'])->assertSessionHasErrors('operation');
    $this->put('/owner/users/'.$target->user_id, ['operation' => 'profile', 'name' => 'Name', 'email' => $owner->email])->assertSessionHasErrors('email');
    $this->put('/owner/users/'.$target->user_id, ['operation' => 'revoke-api'])->assertRedirect();

    expect($target->fresh()->api_key)->toBeNull();
    expect($target->fresh()->toArray())->not->toHaveKey('api_key');
});

it('blocks subscriptions through the service for suspended accounts', function () {
    $target = User::factory()->create(['suspended_at' => now()]);
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);

    expect(fn () => app(MarketSubscriptions::class)->subscribe($target, $exchange, 'BTC/USD', '0.01'))
        ->toThrow(InvalidArgumentException::class, 'The account is suspended.');
    expect(MarketSubscription::query()->count())->toBe(0);
});

it('shows version status and purges only obsolete feature versions', function () {
    $owner = serverOwner();
    $base = [
        'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
        'microtimestamp' => now()->getTimestampMs(), 'available_at_ms' => now()->getTimestampMs(),
        'payload' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ];
    DB::table('market_features')->insert([
        [...$base, 'feature_id' => (string) Str::uuid(), 'version' => FeatureEngine::VERSION],
        [...$base, 'feature_id' => (string) Str::uuid(), 'microtimestamp' => $base['microtimestamp'] - 60000, 'version' => 'obsolete-feature-v0'],
    ]);

    $this->actingAs($owner)->get('/owner/status')->assertOk()
        ->assertSee('Version status')->assertSee(FeatureEngine::VERSION)
        ->assertDontSee('obsolete-feature-v0')->assertSee('Purge obsolete');

    $this->delete('/owner/status/purge', ['scope' => 'features'])->assertRedirect();

    expect(DB::table('market_features')->where('version', 'obsolete-feature-v0')->count())->toBe(0)
        ->and(DB::table('market_features')->where('version', FeatureEngine::VERSION)->count())->toBe(1);
});
