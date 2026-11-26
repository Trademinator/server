<?php

use App\Domain\Features\FeatureEngine;
use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\KnowledgeWindow;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Intelligence\WeightedKnn;
use App\Domain\Research\FeatureSchema;
use App\Models\MarketFeed;
use App\Models\MarketSignal;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function latestReadinessSubscription(User $user, string $symbol = 'BTC/MXN'): MarketSubscription
{
    $market = MarketSignal::factory()->create([
        'period' => '4h', 'recorded_at_ms' => now()->subMinute()->getTimestampMs(),
    ])->market;
    $market->update(['symbol' => $symbol]);
    $market->exchange->update(['name' => 'Bitso', 'class' => 'bitso']);
    MarketFeed::query()->create(['market_id' => $market->getKey(), 'selected_period' => '4h', 'status' => 'ready']);

    return MarketSubscription::query()->create([
        'user_id' => $user->getKey(), 'market_id' => $market->getKey(), 'active' => true,
    ]);
}

function latestReadinessModel(MarketSubscription $subscription, array $overrides = [], bool $published = false,
    ?string $createdAt = null): array
{
    $market = $subscription->market;
    $dataset = (string) Str::uuid7();
    $model = (string) Str::uuid7();
    $key = ModelStore::marketKey($market->exchange->class, $market->symbol, '4h');
    $createdAt ??= now()->toDateTimeString();
    $report = array_replace_recursive([
        'model_id' => $model, 'dataset_id' => $dataset,
        'exchange' => $market->exchange->class, 'symbol' => $market->symbol, 'period' => '4h',
        'status' => 'abstaining', 'reason' => 'outcome_knn_unavailable',
        'feature_version' => FeatureEngine::VERSION, 'validation_version' => IntelligenceTrainer::VERSION,
        'rubix_ml_major' => \Rubix\ML\VERSION, 'keys' => FeatureSchema::keys('core'),
        'trained_as_of_ms' => now()->subHour()->getTimestampMs(),
        'settings' => config('intelligence.knn'), 'label_definition' => ['horizon' => 12],
        'k' => null, 'action_k' => null, 'knowledge_rows' => 7551,
        'selection' => ['candidates' => []], 'holdout' => ['eligible' => false, 'reason' => 'no_eligible_k'], 'patterns' => [],
        'training_data' => [
            'schema' => 'core', 'source_rows' => 7551, 'usable_rows' => 7551,
            'window' => KnowledgeWindow::trainingMetadata(now()->getTimestampMs()), 'skipped' => [],
        ],
        'outcome' => [
            'status' => 'abstaining', 'reason' => 'no_eligible_k', 'schema' => 'core',
            'algorithmic' => ['status' => 'abstaining', 'reason' => 'no_eligible_k',
                'holdout' => ['eligible' => false, 'reason' => 'no_eligible_k']],
        ],
        'action' => [
            'status' => 'ready', 'reason' => 'validated',
            'algorithmic' => ['status' => 'abstaining', 'reason' => 'no_eligible_k'],
            'human' => ['status' => 'validated', 'influence' => true],
        ],
    ], $overrides);
    DB::table('research_datasets')->insert(['dataset_id' => $dataset, 'manifest' => '{}', 'created_at' => $createdAt]);
    DB::table('intelligence_models')->insert([
        'model_id' => $model, 'dataset_id' => $dataset, 'market_key' => $key,
        'status' => $report['status'], 'sha256' => str_repeat('0', 64),
        'report' => json_encode($report, JSON_THROW_ON_ERROR), 'created_at' => $createdAt,
    ]);
    if ($published) {
        DB::table('intelligence_heads')->insert(['market_key' => $key, 'model_id' => $model, 'updated_at' => $createdAt]);
    }

    return $report;
}

it('shows the latest completed Action validation despite an expired published model', function (string $surface) {
    $this->travelTo('2026-11-26 04:00:00 UTC');
    $user = User::factory()->create();
    $subscription = latestReadinessSubscription($user);
    $published = latestReadinessModel($subscription, [
        'trained_as_of_ms' => now()->subDays(30)->getTimestampMs(),
        'outcome' => ['status' => 'ready', 'reason' => 'validated'],
    ], published: true, createdAt: now()->subDays(30)->toDateTimeString());
    latestReadinessModel($subscription);
    $url = match ($surface) {
        'intelligence' => route('markets.intelligence', $subscription->getKey()),
        'dashboard' => route('dashboard'),
        'subscriptions' => route('markets.index'),
    };

    $this->actingAs($user)->get($url)->assertOk()
        ->assertSee('Action KNN: Ready via human-trained fallback')
        ->assertSee('Outcome KNN: Not ready — Tuning validation has not passed')
        ->assertSee('Production: Unavailable');

    $this->assertDatabaseHas('intelligence_heads', ['model_id' => $published['model_id']]);
})->with(['intelligence', 'dashboard', 'subscriptions']);

it('shows completed training without a published head and never uses its signal as production', function () {
    $this->travelTo('2026-11-26 04:00:00 UTC');
    $user = User::factory()->create();
    $subscription = latestReadinessSubscription($user);
    $latest = latestReadinessModel($subscription);
    $decisionAt = now()->subHour()->getTimestampMs();
    MarketSignal::factory()->create([
        'market_id' => $subscription->market_id, 'period' => '4h',
        'model_id' => $latest['model_id'], 'decision_at_ms' => $decisionAt,
        'action' => 'buy', 'reason' => 'supported',
        'recorded_at_ms' => now()->getTimestampMs(),
        'payload' => [...WeightedKnn::abstain('supported'), 'action' => 'buy',
            'confidence' => 0.99, 'decision_at_ms' => $decisionAt, 'patterns' => []],
    ]);

    $this->actingAs($user)->get(route('markets.intelligence', $subscription->getKey()))->assertOk()
        ->assertViewHas('report', null)
        ->assertViewHas('trainingReport', fn (array $report): bool => $report['model_id'] === $latest['model_id']
            && $report['created_at'] === '2026-11-26 04:00:00')
        ->assertViewHas('progress', fn (array $progress): bool => $progress['holdout'] === null)
        ->assertSee($latest['model_id'])
        ->assertSee('no_eligible_k')->assertSee('7,551 retained examples')
        ->assertSee('Action KNN: Ready via human-trained fallback')
        ->assertSee('No model has been published.')
        ->assertSee('No model is published for production.')
        ->assertSee('Action<strong>WAITING</strong>', false)
        ->assertDontSee('Action<strong>BUY</strong>', false)
        ->assertDontSee('Training has not completed')
        ->assertDontSee('No model build recorded yet.')
        ->assertSee('Not evaluated: K selection must pass first.')
        ->assertDontSee('<dt>Outcome macro F1</dt>', false);

    $this->assertDatabaseEmpty('intelligence_heads');
});

it('preserves the healthy published signal and its thresholds when a newer training build abstains', function () {
    $this->travelTo('2026-11-26 04:00:00 UTC');
    $user = User::factory()->create();
    $subscription = latestReadinessSubscription($user);
    $published = latestReadinessModel($subscription, [
        'status' => 'ready', 'reason' => 'validated',
        'outcome' => ['status' => 'ready', 'reason' => 'validated'],
        'settings' => ['min_confidence' => 0.61],
    ], published: true, createdAt: now()->subDay()->toDateTimeString());
    $latest = latestReadinessModel($subscription, ['settings' => ['min_confidence' => 0.93]]);
    $decisionAt = now()->subHour()->getTimestampMs();
    MarketSignal::factory()->create([
        'market_id' => $subscription->market_id, 'period' => '4h',
        'model_id' => $published['model_id'], 'decision_at_ms' => $decisionAt,
        'action' => 'buy', 'reason' => 'supported',
        'recorded_at_ms' => now()->getTimestampMs(),
        'payload' => [...WeightedKnn::abstain('supported'), 'action' => 'buy',
            'confidence' => 0.71, 'effective_neighbors' => 15, 'decision_at_ms' => $decisionAt, 'patterns' => []],
    ]);

    $this->actingAs($user)->get(route('markets.intelligence', $subscription->getKey()))->assertOk()
        ->assertViewHas('report', fn (array $report): bool => $report['model_id'] === $published['model_id'])
        ->assertViewHas('trainingReport', fn (array $report): bool => $report['model_id'] === $latest['model_id'])
        ->assertViewHas('signalSettings', fn (array $settings): bool => $settings['min_confidence'] === 0.61)
        ->assertSee('Production: Available')
        ->assertSee('Action<strong>BUY</strong>', false)->assertSee('71.0%')
        ->assertSee('Outcome KNN: Not ready — Tuning validation has not passed')
        ->assertSee('no_eligible_k');

    $this->assertDatabaseHas('intelligence_heads', ['model_id' => $published['model_id']]);
});

it('refreshes latest training counts within the dashboard cache lifetime without moving the published head', function () {
    $this->travelTo('2026-11-26 04:00:00 UTC');
    $user = User::factory()->create();
    $subscription = latestReadinessSubscription($user);
    $published = latestReadinessModel($subscription, [
        'trained_as_of_ms' => now()->subDays(30)->getTimestampMs(),
    ], published: true, createdAt: now()->subDays(30)->toDateTimeString());
    $first = latestReadinessModel($subscription, ['training_data' => ['usable_rows' => 7000]]);
    $url = route('dashboard', ['subscription' => $subscription->getKey()]);
    $this->actingAs($user)->get($url)->assertOk()
        ->assertViewHas('details', fn (array $details): bool => $details['training_report']['model_id'] === $first['model_id']
            && $details['progress']['source']['usable_rows'] === 7000);
    $this->travel(1)->seconds();
    $second = latestReadinessModel($subscription, ['training_data' => ['usable_rows' => 7551]]);

    $this->get($url)->assertOk()
        ->assertViewHas('details', fn (array $details): bool => $details['training_report']['model_id'] === $second['model_id']
            && $details['progress']['source']['usable_rows'] === 7551
            && $details['report']['model_id'] === $published['model_id'])
        ->assertViewHas('totals', ['followed' => 1, 'outcome' => 0, 'action' => 1, 'coingecko' => 0])
        ->assertSee($second['model_id'])->assertDontSee($first['model_id']);

    $this->assertDatabaseHas('intelligence_heads', ['model_id' => $published['model_id']]);
});

it('keeps latest training diagnostics within the active subscription access boundary', function () {
    $this->travelTo('2026-11-26 04:00:00 UTC');
    $user = User::factory()->create();
    $mine = latestReadinessSubscription($user);
    latestReadinessModel($mine);
    $private = latestReadinessSubscription(User::factory()->create(), 'PRIVATE/MXN');
    $privateReport = latestReadinessModel($private);

    $this->actingAs($user)->get(route('markets.intelligence', $private->getKey()))->assertNotFound();
    $this->get(route('dashboard'))->assertOk()
        ->assertViewHas('totals', ['followed' => 1, 'outcome' => 0, 'action' => 1, 'coingecko' => 0])
        ->assertDontSee($privateReport['model_id'])->assertDontSee('PRIVATE/MXN');
    $this->get(route('markets.index'))->assertOk()->assertDontSee('PRIVATE/MXN');
});
