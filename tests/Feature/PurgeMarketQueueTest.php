<?php

use App\Domain\Operations\ManagedRedisConnector;
use App\Domain\Operations\MarketQueuePurge;
use App\Domain\Operations\QueuePayload;
use App\Jobs\AnalyzeMarketAutoLabels;
use App\Jobs\BuildMarketFeatures;
use App\Jobs\SyncMarketCandles;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    if (! extension_loaded('redis') || ! getenv('QUEUE_TEST_REDIS_PORT')) {
        $this->markTestSkipped('Set QUEUE_TEST_REDIS_PORT to an isolated localhost Redis server to run queue integration tests.');
    }
    $configuration = [
        'options' => ['prefix' => 'market-purge-tests:'.Str::uuid().':'],
        'isolated' => ['host' => '127.0.0.1', 'port' => (int) getenv('QUEUE_TEST_REDIS_PORT'), 'database' => 15],
    ];
    $manager = new RedisManager(app(), 'phpredis', $configuration);
    $this->originalRedis = app('redis');
    $this->originalStoragePath = storage_path();
    app()->instance('redis', $manager);
    $this->purgeRoot = sys_get_temp_dir().'/trademinator-market-command-'.Str::uuid7();
    app()->useStoragePath($this->purgeRoot.'/storage');
    File::ensureDirectoryExists(storage_path('framework/cache'), 0700, true);
    File::ensureDirectoryExists(storage_path('logs'), 0700, true);
    config([
        'queue.default' => 'market-purge-test',
        'queue.connections.market-purge-test' => [
            'driver' => 'market-purge-test', 'connection' => 'isolated', 'queue' => 'testing', 'retry_after' => 120,
        ],
        'features.queue' => 'testing',
        'history_backfill.queue' => 'testing',
        'intelligence.queue' => 'testing',
        'intelligence.signals.connection' => 'market-purge-test',
        'intelligence.signals.queue' => 'testing',
        'intelligence.tournament.queue' => 'testing',
        'queue_management.workers.connection' => 'isolated',
        'queue_management.workers.hostname' => 'purge-test',
        'queue_management.workers.ip' => '192.0.2.55',
        'research.path' => $this->purgeRoot.'/research',
        'intelligence.path' => $this->purgeRoot.'/intelligence',
        'archive.root' => $this->purgeRoot.'/archive',
    ]);
    Queue::addConnector('market-purge-test', fn () => new ManagedRedisConnector($manager));
    $this->purgeQueue = Queue::connection('market-purge-test');
});

afterEach(function () {
    if (! isset($this->purgeQueue)) {
        return;
    }
    $this->purgeQueue->clear('testing');
    foreach (['version', 'manual', 'claimed', 'cancelled', 'cancel_cursor'] as $suffix) {
        $this->purgeQueue->getConnection()->del($this->purgeQueue->controlKey('testing', $suffix));
    }
    $this->purgeQueue->getConnection()->disconnect();
    if (isset($this->originalRedis)) {
        app()->instance('redis', $this->originalRedis);
    }
    if (isset($this->originalStoragePath)) {
        app()->useStoragePath($this->originalStoragePath);
    }
    if (isset($this->purgeRoot)) {
        File::deleteDirectory($this->purgeRoot);
    }
});

it('removes matching ready delayed and reserved jobs while preserving another market', function () {
    $this->purgeQueue->push(new SyncMarketCandles('kraken', 'BTC/USD', '1m', 1, 100), '', 'testing');
    $reserved = $this->purgeQueue->pop('testing');
    expect($reserved)->not->toBeNull();

    $this->purgeQueue->push(new BuildMarketFeatures('kraken', 'BTC/USD', '1m'), '', 'testing');
    $this->purgeQueue->later(600,
        new AnalyzeMarketAutoLabels('kraken', 'BTC/USD', '1m', 'purge-labels', now()->getTimestampMs()),
        '', 'testing');
    $this->purgeQueue->push(new BuildMarketFeatures('kraken', 'ETH/USD', '1m'), '', 'testing');

    $scope = [
        'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'market_ids' => [], 'dataset_ids' => [],
        'model_ids' => [], 'shadow_model_ids' => [], 'history_ids' => [], 'gap_ids' => [], 'request_ids' => [],
    ];
    $purge = app(MarketQueuePurge::class);
    expect($purge->plan($scope)['total'])->toBe(3);

    $removed = $purge->purge($scope);

    expect(array_sum($removed))->toBe(3)
        ->and($this->purgeQueue->pendingSize('testing'))->toBe(1)
        ->and($this->purgeQueue->delayedSize('testing'))->toBe(0)
        ->and($this->purgeQueue->reservedSize('testing'))->toBe(0)
        ->and($purge->plan($scope)['total'])->toBe(0);
    $remaining = app(QueuePayload::class)->read(
        $this->purgeQueue->getConnection()->lindex($this->purgeQueue->key('testing'), 0)
    );
    expect($remaining['symbol'])->toBe('ETH/USD');
});

it('applies the guarded command and preserves unrelated market work', function () {
    $exchange = Exchange::query()->create(['class' => 'kraken', 'name' => 'Kraken', 'config' => '{}']);
    $target = Market::query()->create([
        'exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01',
    ]);
    $other = Market::query()->create([
        'exchange_id' => $exchange->exchange_id, 'symbol' => 'ETH/USD', 'tick_size' => '0.01',
    ]);
    foreach ([$target, $other] as $market) {
        MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1m', 'status' => 'ready']);
        MarketSubscription::query()->create([
            'user_id' => User::factory()->create()->user_id, 'market_id' => $market->market_id, 'active' => true,
        ]);
    }
    DB::table('tickers')->insert([
        ['ticker_id' => (string) Str::uuid7(), 'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
            'microtimestamp' => 60000, 'payload' => '{}', 'created_at' => now(), 'updated_at' => now()],
        ['ticker_id' => (string) Str::uuid7(), 'exchange' => 'kraken', 'symbol' => 'ETH/USD', 'period' => '1m',
            'microtimestamp' => 60000, 'payload' => '{}', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $this->purgeQueue->push(new BuildMarketFeatures('kraken', 'BTC/USD', '1m'), '', 'testing');
    $this->purgeQueue->push(new BuildMarketFeatures('kraken', 'ETH/USD', '1m'), '', 'testing');

    $this->artisan('trademinator:purge-market', [
        'exchange' => 'kraken', 'symbol' => 'BTC/USD', '--apply' => true,
    ])->expectsOutputToContain('was erased from canonical server state')->assertSuccessful();

    $this->assertDatabaseMissing('markets', ['market_id' => $target->market_id]);
    $this->assertDatabaseMissing('tickers', ['exchange' => 'kraken', 'symbol' => 'BTC/USD']);
    $this->assertDatabaseHas('markets', ['market_id' => $other->market_id]);
    $this->assertDatabaseHas('tickers', ['exchange' => 'kraken', 'symbol' => 'ETH/USD']);
    expect($this->purgeQueue->pendingSize('testing'))->toBe(1);
    $remaining = app(QueuePayload::class)->read(
        $this->purgeQueue->getConnection()->lindex($this->purgeQueue->key('testing'), 0)
    );
    expect($remaining['symbol'])->toBe('ETH/USD');
});

it('recovers checkpoint identity from a matching failed job payload', function () {
    $job = new AnalyzeMarketAutoLabels('kraken', 'BTC/USD', '1m', 'purge-failed-labels', 120000);
    $this->purgeQueue->push($job, '', 'testing');
    $payload = $this->purgeQueue->getConnection()->lindex($this->purgeQueue->key('testing'), 0);
    expect($payload)->toBeString();
    $this->purgeQueue->clear('testing');
    $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    $failedId = DB::table('failed_jobs')->insertGetId([
        'uuid' => $decoded['uuid'], 'connection' => 'market-purge-test', 'queue' => 'testing',
        'payload' => $payload, 'exception' => 'fixture', 'failed_at' => now(),
    ]);
    $scope = [
        'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'market_ids' => [], 'dataset_ids' => [],
        'model_ids' => [], 'shadow_model_ids' => [], 'history_ids' => [], 'gap_ids' => [],
        'request_ids' => [], 'failed_job_ids' => [$failedId],
    ];

    $plan = app(MarketQueuePurge::class)->plan($scope);

    expect($plan['total'])->toBe(0)
        ->and($plan['checkpoint_keys'])->toHaveCount(1);
});
