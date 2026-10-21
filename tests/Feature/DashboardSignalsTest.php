<?php

use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\SignalJournal;
use App\Domain\Operations\ActionContext;
use App\Domain\Operations\ActionLog;
use App\Jobs\RecordMarketSignal;
use App\Models\MarketFeed;
use App\Models\MarketSignal;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\Support\IntelligenceFixtures;

it('records waiting once for shared subscribers and stops recording after the last unsubscribe', function () {
    $this->freezeTime();
    $fixture = MarketSignal::factory()->create();
    $market = $fixture->market;
    $fixture->delete();
    MarketFeed::query()->create(['market_id' => $market->getKey(), 'selected_period' => '1m', 'status' => 'active']);
    foreach (User::factory()->count(2)->create() as $user) {
        MarketSubscription::query()->create(['user_id' => $user->getKey(), 'market_id' => $market->getKey(), 'active' => true]);
    }
    $journal = app(SignalJournal::class);
    $signal = $journal->record($market);
    expect($signal->reason)->toBe('no_model')->and($signal->payload['execution_status'])->toBe('unknown');
    $this->travel(1)->minutes();
    expect($journal->record($market)->recorded_at_ms)->toBe($signal->recorded_at_ms);
    $this->assertDatabaseCount('market_signals', 1);
    $market->feed->update(['selected_period' => '1h']);
    $journal->record($market);
    $this->travel(1)->minutes();
    $market->feed->update(['selected_period' => '1m']);
    $recovery = $journal->record($market);
    expect($recovery->recorded_at_ms)->toBeGreaterThan($signal->recorded_at_ms)->and($recovery->is_change)->toBeTrue();
    $this->assertDatabaseCount('market_signals', 3);
    expect(fn () => $signal->update(['action' => 'buy']))->toThrow(LogicException::class);
    $market->subscriptions()->update(['active' => false]);
    expect($journal->record($market))->toBeNull();
});

it('records the original model evidence, preserves its time and distinguishes supported HOLD from waiting', function () {
    $this->travelTo('2024-01-01 04:05:00 UTC');
    $path = sys_get_temp_dir().'/trademinator-dashboard-'.Str::uuid7();
    config(['research.path' => $path.'/research', 'intelligence.path' => $path.'/models',
        'intelligence.knn.min_train_size' => 36, 'intelligence.knn.test_size' => 12,
        'intelligence.knn.min_validation_rows' => 5, 'intelligence.knn.min_directional_predictions' => 1,
        'intelligence.patterns.enabled' => false, 'intelligence.horizon' => 2, 'intelligence.lookback' => 3]);
    $fixture = MarketSignal::factory()->create();
    $market = $fixture->market;
    $fixture->delete();
    MarketFeed::query()->create(['market_id' => $market->getKey(), 'selected_period' => '1m', 'status' => 'active']);
    $owner = User::factory()->create();
    MarketSubscription::query()->create(['user_id' => $owner->getKey(), 'market_id' => $market->getKey(), 'active' => true]);
    try {
        $manifest = IntelligenceFixtures::snapshot();
        $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
        $handler = new TestHandler;
        app()->instance(ActionLog::class, new ActionLog(new Logger('test-actions', [$handler]), app(ActionContext::class)));
        IntelligenceFixtures::feature(243, 0.5);
        IntelligenceFixtures::feature(244, 0.5);
        $first = app(SignalJournal::class)->record($market);
        expect($first->reason)->toBe('supported')->and($first->action)->toBe('hodl')
            ->and($first->model_id)->toBe($report['model_id'])->and($first->payload['horizon_candles'])->toBe(2);
        expect($first->payload['scoring']['version'])->toBe('outcome-action-matrix-v3')
            ->and($first->payload['scoring']['action']['sources']['components']['algorithmic']['action'])->toBe('hodl')
            ->and((float) $first->payload['scoring']['action']['sources']['effective_weights']['algorithmic'])->toBe(1.0);
        expect(app(SignalJournal::class)->record($market)->getKey())->toBe($first->getKey());
        $this->actingAs($owner)->get('/dashboard')->assertOk()->assertSee('HOLD')
            ->assertSee('aria-label="Outcome KNN: Ready"', false);
        $this->travelTo('2024-01-01 04:08:00 UTC');
        $second = app(SignalJournal::class)->record($market);
        expect($second->reason)->toBe('stale_features')->and($second->is_change)->toBeTrue();
        expect($first->fresh()->payload)->toBe($first->payload);
        $this->assertDatabaseCount('market_signals', 2);
        $predictions = collect($handler->getRecords())->map(fn ($record) => json_decode($record->message, true))
            ->where('event', 'intelligence.predicted')->values();
        expect($predictions)->toHaveCount(3);
        expect($predictions[0])->toMatchArray(['model_id' => $first->model_id, 'action' => 'hodl', 'reason' => 'supported']);
        expect($predictions[2])->toMatchArray(['model_id' => $second->model_id, 'reason' => 'stale_features']);
    } finally {
        File::deleteDirectory($path);
    }
});

it('queues one recording per shared active market, rechecks the subscription in the job, and rejects sync dispatch', function () {
    config(['queue.default' => 'database', 'intelligence.signals.connection' => null]);
    Queue::fake([RecordMarketSignal::class]);
    $fixture = MarketSignal::factory()->create();
    $market = $fixture->market;
    $fixture->delete();
    MarketFeed::query()->create(['market_id' => $market->getKey(), 'selected_period' => '1m', 'status' => 'active']);
    foreach (User::factory()->count(2)->create() as $user) {
        MarketSubscription::query()->create(['user_id' => $user->getKey(), 'market_id' => $market->getKey(), 'active' => true]);
    }
    $this->artisan('trademinator:dispatch-market-signals')->assertSuccessful();
    Queue::assertPushed(RecordMarketSignal::class, 1);
    Queue::assertPushed(RecordMarketSignal::class, fn ($job) => $job->marketId === $market->getKey()
        && $job->queue === config('intelligence.signals.queue'));
    $market->subscriptions()->update(['active' => false]);
    (new RecordMarketSignal($market->getKey()))->handle(app(SignalJournal::class));
    $this->assertDatabaseCount('market_signals', 0);
    config(['queue.default' => 'sync']);
    $this->artisan('trademinator:dispatch-market-signals')->assertFailed();
});

it('keeps one waiting signal across a long backlog and permits another recording after completion', function () {
    $this->travelTo('2026-10-18 12:00:00 UTC');
    config(['queue.default' => 'sync', 'intelligence.signals.connection' => 'database',
        'intelligence.signals.queue' => 'market-signals', 'intelligence.queue' => 'intelligence']);
    $fixture = MarketSignal::factory()->create();
    $market = $fixture->market;
    $fixture->delete();
    MarketFeed::query()->create(['market_id' => $market->getKey(), 'selected_period' => '1m', 'status' => 'active']);
    MarketSubscription::query()->create([
        'user_id' => User::factory()->create()->getKey(), 'market_id' => $market->getKey(), 'active' => true,
    ]);

    $this->artisan('trademinator:dispatch-market-signals')->assertSuccessful();
    $this->travel(2)->days();
    $this->artisan('trademinator:dispatch-market-signals')->assertSuccessful();

    $this->assertDatabaseCount('jobs', 1);
    $this->assertDatabaseHas('jobs', ['queue' => 'market-signals']);
    $this->assertDatabaseMissing('jobs', ['queue' => 'intelligence']);
    $queued = Queue::connection('database')->pop('market-signals');
    expect($queued)->not->toBeNull();
    $queued->fire();
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseHas('market_signals', ['market_id' => $market->getKey(), 'reason' => 'no_model']);

    $this->artisan('trademinator:dispatch-market-signals')->assertSuccessful();

    $this->assertDatabaseCount('jobs', 1);
    $this->assertDatabaseHas('jobs', ['queue' => 'market-signals']);
});

it('releases signal uniqueness after terminal failure so a new recording can be queued', function () {
    config(['intelligence.signals.connection' => 'database', 'intelligence.signals.queue' => 'signals']);
    $marketId = (string) Str::uuid7();
    RecordMarketSignal::dispatch($marketId);
    $queued = Queue::connection('database')->pop('signals');
    expect($queued)->not->toBeNull();

    $queued->fail(new RuntimeException('Signal inference failed.'));
    RecordMarketSignal::dispatch($marketId);

    $this->assertDatabaseCount('jobs', 1);
    $payload = json_decode(DB::table('jobs')->sole()->payload, true, flags: JSON_THROW_ON_ERROR);
    expect($payload['failOnTimeout'])->toBeTrue();
    expect($payload['timeout'])->toBe(900);
});

it('rejects a synchronous signal connection even when the default queue is persistent', function () {
    config(['queue.default' => 'database', 'intelligence.signals.connection' => 'inline-signals',
        'queue.connections.inline-signals' => ['driver' => 'sync']]);
    Queue::fake([RecordMarketSignal::class]);

    $this->artisan('trademinator:dispatch-market-signals')
        ->expectsOutput('Signal recording requires a persistent queue connection.')
        ->assertFailed();

    Queue::assertNothingPushed();
});

it('skips signal dispatch while recovery owns the guard and resumes after it releases', function () {
    config(['intelligence.signals.connection' => 'database']);
    Queue::fake([RecordMarketSignal::class]);
    $market = MarketSignal::factory()->create()->market;
    MarketFeed::query()->create(['market_id' => $market->getKey(), 'selected_period' => '1m', 'status' => 'active']);
    MarketSubscription::query()->create([
        'user_id' => User::factory()->create()->getKey(), 'market_id' => $market->getKey(), 'active' => true,
    ]);
    $recovery = Cache::lock('trademinator:signal-dispatch', 600);
    expect($recovery->get())->toBeTrue();
    try {
        $this->artisan('trademinator:dispatch-market-signals')
            ->expectsOutput('Signal dispatch is already running or undergoing recovery; skipping.')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        expect($recovery->isOwnedByCurrentProcess())->toBeTrue();
    } finally {
        $recovery->release();
    }

    $this->artisan('trademinator:dispatch-market-signals')->assertSuccessful();

    Queue::assertPushed(RecordMarketSignal::class, 1);
    $next = Cache::lock('trademinator:signal-dispatch', 600);
    expect($next->get())->toBeTrue();
    $next->release();
});

it('releases the signal dispatch guard when a queue push fails', function () {
    config(['intelligence.signals.connection' => 'database']);
    $market = MarketSignal::factory()->create()->market;
    MarketFeed::query()->create(['market_id' => $market->getKey(), 'selected_period' => '1m', 'status' => 'active']);
    MarketSubscription::query()->create([
        'user_id' => User::factory()->create()->getKey(), 'market_id' => $market->getKey(), 'active' => true,
    ]);
    Bus::shouldReceive('dispatch')->once()->with(Mockery::type(RecordMarketSignal::class))
        ->andThrow(new RuntimeException('Queue unavailable.'));

    expect(fn () => $this->artisan('trademinator:dispatch-market-signals')->run())
        ->toThrow(RuntimeException::class, 'Queue unavailable.');

    $this->assertDatabaseCount('jobs', 0);
    $recovery = Cache::lock('trademinator:signal-dispatch', 600);
    expect($recovery->get())->toBeTrue();
    $recovery->release();
});
