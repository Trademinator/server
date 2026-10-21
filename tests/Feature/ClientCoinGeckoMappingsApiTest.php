<?php

use App\Models\ClientApiKey;
use App\Models\CoinGeckoMarketMapping;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function mappingApiToken(User $user, array $attributes = []): string
{
    $token = 'tmk_'.Str::random(64);
    ClientApiKey::query()->create(array_replace([
        'user_id' => $user->getKey(), 'label' => 'mapping-test',
        'prefix' => substr($token, 0, 12), 'secret_hash' => hash('sha256', $token),
    ], $attributes));

    return $token;
}

function mappingApiMarket(string $exchangeClass, string $symbol, array $attributes = []): CoinGeckoMarketMapping
{
    $exchange = Exchange::query()->firstOrCreate(['class' => $exchangeClass], [
        'name' => ucfirst($exchangeClass), 'config' => '{"private":"DO_NOT_EXPOSE"}',
    ]);
    $market = Market::query()->create(['exchange_id' => $exchange->getKey(), 'symbol' => $symbol, 'tick_size' => '0.01']);
    [$base, $quote] = explode('/', $symbol);

    return CoinGeckoMarketMapping::query()->updateOrCreate(['market_id' => $market->getKey()], array_replace([
        'base_symbol' => $base, 'vs_currency' => strtolower($quote), 'status' => 'resolved',
        'coin_id' => 'cosmos', 'manually_mapped' => true, 'last_error' => 'DO_NOT_EXPOSE',
    ], $attributes));
}

beforeEach(function (): void {
    config(['client.enabled' => true]);
    $this->freezeSecond();
    Http::preventStrayRequests();
});

it('requires a valid active Client key and verified account', function (): void {
    $url = '/api/v1/client/coingecko-mappings';
    $this->getJson($url)->assertUnauthorized();
    $user = User::factory()->create();
    $this->actingAs($user)->getJson($url)->assertUnauthorized();
    $this->withToken(mappingApiToken($user, ['revoked_at' => now()]))->getJson($url)->assertUnauthorized();
    $this->withToken(mappingApiToken(User::factory()->unverified()->create()))->getJson($url)->assertForbidden();
});

it('returns shared exchange-specific mappings without subscriptions or private fields', function (): void {
    $bitso = mappingApiMarket('bitso', 'ATOM/USD');
    $kraken = mappingApiMarket('kraken', 'ATOM/USD', ['coin_id' => 'other-asset', 'manually_mapped' => false]);
    $before = CoinGeckoMarketMapping::all()->toArray();
    $response = $this->withToken(mappingApiToken(User::factory()->create()))
        ->getJson('/api/v1/client/coingecko-mappings')->assertOk()->assertExactJson([
            'api_version' => 1,
            'mappings' => collect([$bitso, $kraken])->sortBy('coin_gecko_market_mapping_id')->map(fn ($mapping): array => [
                'mapping_id' => $mapping->getKey(), 'exchange' => $mapping->market->exchange->class,
                'symbol' => 'ATOM/USD', 'base_symbol' => 'ATOM', 'vs_currency' => 'usd',
                'status' => 'resolved', 'coin_id' => $mapping->coin_id,
                'manually_mapped' => $mapping->manually_mapped, 'updated_at' => now()->toISOString(),
            ])->values()->all(),
            'next_cursor' => null,
        ])->assertHeader('X-Trademinator-API-Version', '1');
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    expect(CoinGeckoMarketMapping::all()->toArray())->toBe($before);
    $this->assertDatabaseCount('market_subscriptions', 0);
    Http::assertNothingSent();
});

it('reflects corrections unresolved states and deletion on the next pull', function (): void {
    $mapping = mappingApiMarket('bitso', 'ATOM/USD');
    $this->withToken(mappingApiToken(User::factory()->create()));
    $url = '/api/v1/client/coingecko-mappings';
    $this->getJson($url)->assertOk()->assertJsonPath('mappings.0.coin_id', 'cosmos');
    $mapping->update(['coin_id' => 'corrected-asset']);
    $this->getJson($url)->assertOk()->assertJsonPath('mappings.0.coin_id', 'corrected-asset');
    foreach (['pending', 'ambiguous', 'unmapped', 'unsupported'] as $status) {
        $mapping->update(['status' => $status]);
        $this->getJson($url)->assertOk()->assertJsonPath('mappings.0.status', $status)->assertJsonPath('mappings.0.coin_id', null);
    }
    $mapping->delete();
    $this->getJson($url)->assertOk()->assertExactJson(['api_version' => 1, 'mappings' => [], 'next_cursor' => null]);
});

it('pages the complete catalogue in stable ID order and validates cursors', function (): void {
    for ($i = 0; $i < 251; $i++) {
        mappingApiMarket('bitso', 'ASSET'.$i.'/USD');
    }
    $this->withToken(mappingApiToken(User::factory()->create()));
    $url = '/api/v1/client/coingecko-mappings';
    $first = $this->getJson($url)->assertOk()->assertJsonCount(250, 'mappings');
    $ids = array_column($first->json('mappings'), 'mapping_id');
    expect($first->json('next_cursor'))->toBe($ids[249]);
    $second = $this->getJson($url.'?after='.$first->json('next_cursor'))
        ->assertOk()->assertJsonCount(1, 'mappings')->assertJsonPath('next_cursor', null);
    $ids[] = $second->json('mappings.0.mapping_id');
    expect($ids)->toBe(CoinGeckoMarketMapping::query()->orderBy('coin_gecko_market_mapping_id')->pluck('coin_gecko_market_mapping_id')->all());
    $this->getJson($url.'?after='.$ids[250])->assertOk()->assertJsonCount(0, 'mappings')->assertJsonPath('next_cursor', null);
    foreach (['not-a-uuid', '', 'a%2Fb'] as $cursor) {
        $this->getJson($url.'?after='.$cursor)->assertUnprocessable()->assertJsonValidationErrors('after');
    }
});
