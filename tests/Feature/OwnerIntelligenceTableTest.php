<?php

use App\Domain\Intelligence\ModelStore;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withSession(['auth.password_confirmed_at' => time()]);
});

function intelligenceTableOwner(): User
{
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id]);

    return $owner;
}

function intelligenceTableModel(array $report = [], bool $current = true, string $builtAt = '2026-10-05 10:00:00'): string
{
    $report = array_replace(['exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
        'status' => 'abstaining', 'reason' => 'no_eligible_k', 'knowledge_rows' => 20], $report);
    $dataset = (string) Str::uuid();
    $model = (string) Str::uuid();
    $key = ModelStore::marketKey($report['exchange'], $report['symbol'], $report['period']);
    DB::table('research_datasets')->insert(['dataset_id' => $dataset, 'manifest' => '{}', 'created_at' => $builtAt]);
    DB::table('intelligence_models')->insert(['model_id' => $model, 'dataset_id' => $dataset, 'market_key' => $key,
        'status' => $report['status'], 'sha256' => str_repeat('0', 64), 'created_at' => $builtAt,
        'report' => json_encode($report, JSON_THROW_ON_ERROR)]);
    if ($current) {
        DB::table('intelligence_heads')->insert(['market_key' => $key, 'model_id' => $model, 'updated_at' => $builtAt]);
        $exchange = Exchange::query()->firstOrCreate(['class' => $report['exchange']],
            ['name' => ucfirst($report['exchange']), 'config' => '{}']);
        $market = Market::query()->firstOrCreate(['exchange_id' => $exchange->exchange_id, 'symbol' => $report['symbol']],
            ['tick_size' => '0.01']);
        MarketFeed::query()->updateOrCreate(['market_id' => $market->market_id],
            ['selected_period' => $report['period'], 'status' => 'ready']);
    }

    return $model;
}

it('searches displayed model fields without changing version and build filters', function (string $term) {
    $owner = intelligenceTableOwner();
    $matching = intelligenceTableModel(['status' => 'ready']);
    intelligenceTableModel(['status' => 'ready'], current: false);
    intelligenceTableModel(['exchange' => 'bitso', 'symbol' => 'ETH/USD', 'period' => '15m', 'reason' => 'trained', 'status' => 'ready']);
    intelligenceTableModel(['symbol' => 'BTC/CAD']);

    $response = $this->actingAs($owner)->get(route('owner.intelligence', ['q' => $term, 'status' => 'ready']));

    $response->assertOk()->assertViewHas('models', fn ($models) => $models->pluck('model_id')->all() === [$matching]);
})->with(['mixed case and multiple fields' => ['  KRAKEN btc/usd 1M  '], 'reason' => ['no_eligible_k'], 'exchange' => ['KrAkEn']]);

it('treats search punctuation literally and escapes the search field', function (string $term, string $reason) {
    $owner = intelligenceTableOwner();
    $matching = intelligenceTableModel(['reason' => $reason]);
    intelligenceTableModel(['symbol' => 'ETH/USD', 'reason' => 'feeXunavailable 100 percent alert']);

    $response = $this->actingAs($owner)->get(route('owner.intelligence', ['q' => $term]));

    $response->assertOk()->assertViewHas('models', fn ($models) => $models->pluck('model_id')->all() === [$matching])
        ->assertSee('value="'.e($term).'"', false);
})->with([
    'underscore' => ['fee_unavailable', 'fee_unavailable'],
    'percent' => ['100%', '100%'],
    'escape character' => ['!', '!'],
    'SQL punctuation' => ["x' OR 1=1 --", "x' OR 1=1 --"],
    'html' => ['<script>alert(1)</script>', '<script>alert(1)</script>'],
]);

it('sorts model headers in both directions using numeric rows and K', function (string $sort, string $direction, array $order) {
    $owner = intelligenceTableOwner();
    $ids = [
        'a' => intelligenceTableModel(['exchange' => 'zeta', 'symbol' => 'ADA/USD', 'reason' => 'bravo', 'knowledge_rows' => '100', 'k' => '11']),
        'b' => intelligenceTableModel(['exchange' => 'alpha', 'symbol' => 'ETH/USD', 'reason' => 'charlie', 'knowledge_rows' => '2', 'k' => '3'], builtAt: '2026-10-05 12:00:00'),
        'c' => intelligenceTableModel(['exchange' => 'alpha', 'symbol' => 'BTC/USD', 'reason' => 'alpha', 'knowledge_rows' => '20', 'k' => '5'], builtAt: '2026-10-05 11:00:00'),
    ];

    $response = $this->actingAs($owner)->get(route('owner.intelligence', compact('sort', 'direction')));

    $response->assertOk()->assertViewHas('models', fn ($models) => $models->pluck('model_id')->all() === array_map(fn ($key) => $ids[$key], $order))
        ->assertSee('aria-sort="'.($direction === 'asc' ? 'ascending' : 'descending').'"', false);
})->with([
    'market ascending' => ['market', 'asc', ['c', 'b', 'a']],
    'market descending' => ['market', 'desc', ['a', 'b', 'c']],
    'reason ascending' => ['reason', 'asc', ['c', 'a', 'b']],
    'reason descending' => ['reason', 'desc', ['b', 'a', 'c']],
    'rows ascending' => ['knowledge_rows', 'asc', ['b', 'c', 'a']],
    'rows descending' => ['knowledge_rows', 'desc', ['a', 'c', 'b']],
    'K ascending' => ['k', 'asc', ['b', 'c', 'a']],
    'K descending' => ['k', 'desc', ['a', 'c', 'b']],
    'date ascending' => ['created_at', 'asc', ['a', 'c', 'b']],
    'date descending' => ['created_at', 'desc', ['b', 'c', 'a']],
]);

it('keeps missing K values last in either direction', function (string $direction) {
    $owner = intelligenceTableOwner();
    intelligenceTableModel(['k' => null]);
    intelligenceTableModel(['symbol' => 'ETH/USD']);
    $selected = intelligenceTableModel(['symbol' => 'ATOM/USD', 'k' => 7]);

    $this->actingAs($owner)->get(route('owner.intelligence', ['sort' => 'k', 'direction' => $direction]))
        ->assertOk()->assertViewHas('models', fn ($models) => $models->first()->model_id === $selected && $models->total() === 3);
})->with(['asc', 'desc']);

it('defaults to newest builds with a stable model ID tie breaker', function () {
    $owner = intelligenceTableOwner();
    $older = intelligenceTableModel();
    $newer = intelligenceTableModel(['symbol' => 'ETH/USD'], builtAt: '2026-10-05 12:00:00');
    $sameTime = intelligenceTableModel(['symbol' => 'ATOM/USD'], builtAt: '2026-10-05 12:00:00');
    $expected = [min($newer, $sameTime), max($newer, $sameTime), $older];

    $this->actingAs($owner)->get(route('owner.intelligence'))
        ->assertOk()->assertViewHas('models', fn ($models) => $models->pluck('model_id')->all() === $expected);
});

it('filters and sorts before pagination while preserving controls and resetting header links to page one', function () {
    $owner = intelligenceTableOwner();
    $ids = [];
    for ($i = 0; $i < 26; $i++) {
        $ids[] = intelligenceTableModel(['symbol' => 'BTC'.$i.'/USD', 'knowledge_rows' => $i, 'status' => 'ready']);
    }
    $historical = intelligenceTableModel(['symbol' => 'BTC0/USD', 'knowledge_rows' => 999, 'status' => 'ready'], current: false);
    intelligenceTableModel(['exchange' => 'bitso', 'knowledge_rows' => 1000, 'status' => 'ready']);
    $filters = ['q' => 'kraken', 'history' => '1', 'status' => 'ready', 'sort' => 'knowledge_rows', 'direction' => 'desc'];
    $this->actingAs($owner);

    $firstPage = $this->get(route('owner.intelligence', $filters));
    $secondPage = $this->get(route('owner.intelligence', $filters + ['page' => 2]));

    $firstPage->assertOk()->assertViewHas('models', fn ($models) => $models->total() === 27 && $models->first()->model_id === $historical && $models->count() === 25)
        ->assertSee(route('owner.intelligence', $filters + ['page' => 2]));
    $secondPage->assertOk()->assertViewHas('models', fn ($models) => $models->pluck('model_id')->all() === [$ids[1], $ids[0]])
        ->assertSee(route('owner.intelligence', array_replace($filters, ['direction' => 'asc'])))
        ->assertSee(route('owner.intelligence', array_replace($filters, ['sort' => 'k', 'direction' => 'asc'])))
        ->assertSee(route('owner.intelligence', array_diff_key($filters, ['q' => true])))
        ->assertSee('name="sort" value="knowledge_rows"', false)
        ->assertSee('name="direction" value="desc"', false);
});

it('rejects invalid table query parameters', function (string $field, mixed $value) {
    $this->actingAs(intelligenceTableOwner())->from('/owner/intelligence')
        ->get(route('owner.intelligence', [$field => $value]))
        ->assertRedirect('/owner/intelligence')->assertSessionHasErrors($field);
})->with([
    'sort expression' => ['sort', 'created_at desc; DROP TABLE intelligence_models'],
    'direction expression' => ['direction', 'desc, model_id'],
    'array search' => ['q', ['kraken']],
    'long search' => ['q', str_repeat('a', 121)],
]);

it('shows persisted auto-label d H and independent K diagnostics', function () {
    $owner = intelligenceTableOwner();
    intelligenceTableModel([
        'status' => 'ready',
        'k' => 9,
        'action_k' => 17,
        'outcome' => ['algorithmic' => ['selection' => ['k_min' => 4, 'k_max' => 33]]],
        'action' => ['algorithmic' => ['selection' => ['k_min' => 4, 'k_max' => 65]]],
        'action_label_analysis' => [
            'status' => 'validated',
            'minimum_distance_observations' => 30,
            'distance_observations' => 87,
            'distance_frequencies' => [4 => 10, 5 => 25, 6 => 40, 7 => 12],
            'distance_min' => 4,
            'distance_max' => 7,
            'distance_mean' => 5.62,
            'distance_median' => 6.0,
            'horizon' => 6,
            'candles_examined' => 12345,
            'contiguous_runs' => 2,
            'gaps' => 1,
            'action_counts' => ['buy' => 44, 'hold' => 600, 'sell' => 44],
            'from_ms' => 1_700_000_000_000,
            'as_of_ms' => 1_710_000_000_000,
            'max_history_days' => 366,
        ],
    ]);

    $this->actingAs($owner)->get(route('owner.intelligence'))
        ->assertOk()
        ->assertSee('Auto-label / d / H / K diagnostics')
        ->assertSee('87 / 30 minimum observations')
        ->assertSee('d=6')
        ->assertSee('Outcome 9')
        ->assertSee('Action 17')
        ->assertSee('H</strong> = round(Σ(d × frequency) / Σfrequency)', false);
});

it('shows the selected candle period and keeps older periods in model history', function () {
    $owner = intelligenceTableOwner();
    $older = intelligenceTableModel(['exchange' => 'bitso', 'symbol' => 'ATOM/USD', 'period' => '15m']);
    $current = intelligenceTableModel(['exchange' => 'bitso', 'symbol' => 'ATOM/USD', 'period' => '1h']);
    $otherOlder = intelligenceTableModel(['exchange' => 'bitso', 'symbol' => 'ATOM/USD', 'period' => '4h']);
    $market = Market::query()->where('symbol', 'ATOM/USD')->firstOrFail();
    $market->feed->update(['selected_period' => '1h']);

    $this->actingAs($owner)->get(route('owner.intelligence'))
        ->assertOk()
        ->assertViewHas('models', fn ($models) => $models->pluck('model_id')->all() === [$current])
        ->assertSee('<strong class="owner-intelligence-market-name">bitso · ATOM/USD</strong>', false);

    $this->get(route('owner.intelligence', ['history' => 1]))
        ->assertOk()->assertViewHas('models', fn ($models) => count(array_intersect($models->pluck('model_id')->all(), [$older, $current, $otherOlder])) === 3);
});
