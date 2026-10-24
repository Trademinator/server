<?php

use App\Domain\Intelligence\KnowledgeRequestJournal;
use App\Domain\Operations\ManagedRedisConnector;
use App\Domain\Operations\ManagedRedisQueue;
use App\Domain\Operations\QueueAdministration;
use App\Domain\Operations\QueueCancellation;
use App\Domain\Operations\QueuePayload;
use App\Domain\Operations\QueuePriority;
use App\Jobs\AnalyzeMarketAutoLabels;
use App\Jobs\BuildMarketFeatures;
use App\Jobs\CollectMarketFeed;
use App\Jobs\RunTournamentShadow;
use App\Jobs\SyncMarketCandles;
use App\Jobs\TrainIntelligenceTournament;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Bus\UniqueLock;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    if (! extension_loaded('redis') || ! getenv('QUEUE_TEST_REDIS_PORT')) {
        $this->markTestSkipped('Set QUEUE_TEST_REDIS_PORT to an isolated localhost Redis server to run queue integration tests.');
    }
    $this->freezeTime();
    $this->queueRedisConfiguration = [
        'options' => ['prefix' => 'managed-queue-tests:'.Str::uuid().':'],
        'isolated' => ['host' => '127.0.0.1', 'port' => (int) getenv('QUEUE_TEST_REDIS_PORT'), 'database' => 15],
    ];
    $manager = new RedisManager(app(), 'phpredis', $this->queueRedisConfiguration);
    config(['queue.connections.managed-test' => ['driver' => 'managed-test', 'connection' => 'isolated',
        'queue' => 'testing', 'retry_after' => 120, 'migration_batch_size' => 2],
        'queue_management.read_batch_size' => 2, 'queue_management.priority_enabled' => true,
        'queue_management.aging_seconds.testing' => 300]);
    Queue::addConnector('managed-test', fn () => new ManagedRedisConnector($manager));
    $this->managedQueue = Queue::connection('managed-test');
    $this->queueAdmin = app(QueueAdministration::class);
    $this->exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
});

afterEach(function () {
    if (! isset($this->managedQueue)) {
        return;
    }
    $this->managedQueue->clear('testing');
    foreach (['version', 'manual', 'claimed', 'cancelled', 'cancel_cursor', 'test-start', 'test-ready', 'test-results'] as $suffix) {
        $this->managedQueue->getConnection()->del($this->managedQueue->controlKey('testing', $suffix));
    }
    $this->managedQueue->getConnection()->disconnect();
});

function queueMarket(Exchange $exchange, string $symbol, int $subscribers): Market
{
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => $symbol, 'tick_size' => '0.01']);
    MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1m', 'status' => 'ready']);
    foreach (User::factory()->count($subscribers)->create() as $user) {
        MarketSubscription::query()->create(['market_id' => $market->market_id, 'user_id' => $user->user_id, 'active' => true]);
    }

    return $market;
}

function queueCandleJob(string $symbol, int $from = 1): SyncMarketCandles
{
    return new SyncMarketCandles('kraken', $symbol, '1m', $from, 1000);
}

function queuedUuid(ManagedRedisQueue $queue, object $job, ?int $delay = null): string
{
    if ($delay === null) {
        $queue->push($job, '', 'testing');
        $raw = $queue->getConnection()->lindex($queue->key('testing'), -1);
    } else {
        $before = $queue->getConnection()->zrange($queue->key('testing').':delayed', 0, -1);
        $queue->later($delay, $job, '', 'testing');
        $after = $queue->getConnection()->zrange($queue->key('testing').':delayed', 0, -1);
        $raw = array_values(array_diff($after, $before))[0];
    }

    return app(QueuePayload::class)->read($raw)['uuid'];
}

it('uses current distinct active subscriber demand for already queued work and excludes suspended users', function () {
    $low = queueMarket($this->exchange, 'ETH/USD', 1);
    $high = queueMarket($this->exchange, 'BTC/USD', 10);
    $lowId = queuedUuid($this->managedQueue, queueCandleJob($low->symbol));
    $highId = queuedUuid($this->managedQueue, queueCandleJob($high->symbol));
    $rows = $this->queueAdmin->snapshot('managed-test', 'testing')['jobs'];
    expect(array_column($rows, 'uuid'))->toBe([$highId, $lowId])
        ->and(array_column($rows, 'subscriber_count'))->toBe([10, 1]);
    User::query()->whereIn('user_id', MarketSubscription::query()->where('market_id', $high->market_id)->select('user_id'))
        ->update(['suspended_at' => now()]);
    expect($this->managedQueue->pop('testing')->uuid())->toBe($lowId)
        ->and($this->queueAdmin->inspect('managed-test', 'testing', $highId)['subscriber_count'])->toBe(0);
});

it('promotes old low-demand work and keeps FIFO ties across metadata read pages', function () {
    queueMarket($this->exchange, 'ETH/USD', 1);
    queueMarket($this->exchange, 'BTC/USD', 10);
    $old = queuedUuid($this->managedQueue, queueCandleJob('ETH/USD'));
    $this->travel(301)->seconds();
    $first = queuedUuid($this->managedQueue, queueCandleJob('BTC/USD', 1));
    $second = queuedUuid($this->managedQueue, queueCandleJob('BTC/USD', 2));
    $third = queuedUuid($this->managedQueue, queueCandleJob('BTC/USD', 3));
    expect($this->managedQueue->pop('testing')->uuid())->toBe($old)
        ->and($this->managedQueue->pop('testing')->uuid())->toBe($first)
        ->and($this->managedQueue->pop('testing')->uuid())->toBe($second)
        ->and($this->managedQueue->pop('testing')->uuid())->toBe($third);
});

it('does not run a popular delayed job early and drains all due pages before ordering', function () {
    queueMarket($this->exchange, 'ETH/USD', 1);
    queueMarket($this->exchange, 'BTC/USD', 10);
    $ready = queuedUuid($this->managedQueue, queueCandleJob('ETH/USD'));
    for ($index = 0; $index < 5; $index++) {
        queuedUuid($this->managedQueue, queueCandleJob('ETH/USD', $index), 10);
    }
    $popular = queuedUuid($this->managedQueue, queueCandleJob('BTC/USD'), 11);
    expect($this->managedQueue->pop('testing')->uuid())->toBe($ready)
        ->and($this->managedQueue->pop('testing'))->toBeNull();
    $this->travel(12)->seconds();
    expect($this->managedQueue->pop('testing')->uuid())->toBe($popular)
        ->and($this->managedQueue->delayedSize('testing'))->toBe(0)
        ->and($this->managedQueue->pendingSize('testing'))->toBe(5);
});

it('retains manual movement across subscriber refresh and supports automatic priority reset', function () {
    queueMarket($this->exchange, 'ETH/USD', 1);
    queueMarket($this->exchange, 'BTC/USD', 10);
    queueMarket($this->exchange, 'SOL/USD', 5);
    $low = queuedUuid($this->managedQueue, queueCandleJob('ETH/USD'));
    $high = queuedUuid($this->managedQueue, queueCandleJob('BTC/USD'));
    $middle = queuedUuid($this->managedQueue, queueCandleJob('SOL/USD'));
    $ids = fn () => array_column($this->queueAdmin->snapshot('managed-test', 'testing')['jobs'], 'uuid');
    expect($this->queueAdmin->move('managed-test', 'testing', $low, 'top'))->toBeTrue()
        ->and($ids())->toBe([$low, $high, $middle]);
    MarketSubscription::query()->update(['active' => false]);
    expect($ids())->toBe([$low, $high, $middle])
        ->and($this->queueAdmin->move('managed-test', 'testing', $low, 'bottom'))->toBeTrue()
        ->and($ids())->toBe([$high, $middle, $low])
        ->and($this->queueAdmin->move('managed-test', 'testing', $low, 'up'))->toBeTrue()
        ->and($ids())->toBe([$high, $low, $middle])
        ->and($this->queueAdmin->move('managed-test', 'testing', $high, 'down'))->toBeTrue()
        ->and($ids())->toBe([$low, $high, $middle])
        ->and($this->queueAdmin->move('managed-test', 'testing', $low, 'automatic'))->toBeTrue()
        ->and($this->queueAdmin->move('managed-test', 'testing', $high, 'automatic'))->toBeTrue()
        ->and($ids())->toBe([$low, $high, $middle]);
});

it('rejects cancellation and movement after reservation but can cancel delayed unstarted work', function () {
    $running = queuedUuid($this->managedQueue, queueCandleJob('ETH/USD'));
    $delayed = queuedUuid($this->managedQueue, queueCandleJob('BTC/USD'), 600);
    $reserved = $this->managedQueue->pop('testing');
    expect($reserved->uuid())->toBe($running)
        ->and($this->queueAdmin->cancel('managed-test', 'testing', $running))->toBeFalse()
        ->and($this->queueAdmin->move('managed-test', 'testing', $running, 'top'))->toBeFalse()
        ->and($this->queueAdmin->inspect('managed-test', 'testing', $running))
        ->toMatchArray(['state' => 'reserved', 'can_cancel' => false, 'can_move' => false, 'attempts' => 1])
        ->and($this->queueAdmin->cancel('managed-test', 'testing', $delayed))->toBeTrue()
        ->and($this->managedQueue->reservedSize('testing'))->toBe(1)
        ->and($this->managedQueue->delayedSize('testing'))->toBe(0);
});

it('preserves original waiting age and backoff when a reservation is released', function () {
    $id = queuedUuid($this->managedQueue, queueCandleJob('ETH/USD'));
    $created = now()->timestamp;
    $first = $this->managedQueue->pop('testing');
    $this->travel(30)->seconds();
    $first->release(60);
    expect($this->managedQueue->pop('testing'))->toBeNull()
        ->and($this->queueAdmin->inspect('managed-test', 'testing', $id))
        ->toMatchArray(['state' => 'delayed', 'requested_at' => $created, 'attempts' => 1]);
    $this->travel(61)->seconds();
    $second = $this->managedQueue->pop('testing');
    $first->release(0);
    expect($second->uuid())->toBe($id)->and($second->attempts())->toBe(2)
        ->and($this->managedQueue->delayedSize('testing'))->toBe(0)
        ->and($this->managedQueue->reservedSize('testing'))->toBe(1);
});

it('cleans the cancelled unique owner and only its unchanged unstarted knowledge request', function () {
    $request = app(KnowledgeRequestJournal::class)->request('kraken', 'ETH/USD', '1m', 'features', 1000);
    $job = new BuildMarketFeatures('kraken', 'ETH/USD', '1m');
    expect((new UniqueLock(Cache::store()))->acquire($job))->toBeTrue();
    $id = queuedUuid($this->managedQueue, $job);
    expect($this->queueAdmin->cancel('managed-test', 'testing', $id))->toBeTrue()
        ->and(Cache::restoreLock(UniqueLock::getKey($job), $job->uniqueLockOwner)->isOwnedByCurrentProcess())->toBeFalse()
        ->and(DB::table('knowledge_stage_requests')->where('request_id', $request)->value('status'))->toBe('cancelled')
        ->and($this->managedQueue->getConnection()->hlen($this->managedQueue->controlKey('testing', 'cancelled')))->toBe(0);
    $request = app(KnowledgeRequestJournal::class)->request('kraken', 'ETH/USD', '1m', 'features', 1000);
    $next = new BuildMarketFeatures('kraken', 'ETH/USD', '1m');
    expect((new UniqueLock(Cache::store()))->acquire($next))->toBeTrue();
    $nextId = queuedUuid($this->managedQueue, $next);
    app(KnowledgeRequestJournal::class)->request('kraken', 'ETH/USD', '1m', 'features', 2000);
    expect($this->queueAdmin->cancel('managed-test', 'testing', $nextId))->toBeTrue()
        ->and(DB::table('knowledge_stage_requests')->where('request_id', $request)->value('status'))->toBe('queued');
});

it('never releases a newer unique owner or changes its shared request on stale cancellation', function () {
    $request = app(KnowledgeRequestJournal::class)->request('kraken', 'ETH/USD', '1m', 'features', 1000);
    $old = new BuildMarketFeatures('kraken', 'ETH/USD', '1m');
    (new UniqueLock(Cache::store()))->acquire($old);
    $id = queuedUuid($this->managedQueue, $old);
    Cache::restoreLock(UniqueLock::getKey($old), $old->uniqueLockOwner)->release();
    $successor = Cache::lock(UniqueLock::getKey($old), 3600);
    expect($successor->get())->toBeTrue()
        ->and($this->queueAdmin->cancel('managed-test', 'testing', $id))->toBeTrue()
        ->and($successor->isOwnedByCurrentProcess())->toBeTrue()
        ->and(DB::table('knowledge_stage_requests')->where('request_id', $request)->value('status'))->toBe('queued');
});

it('releases only matching collector dispatch leases while preserving subscriptions', function () {
    $market = queueMarket($this->exchange, 'ETH/USD', 1);
    $token = (string) Str::uuid();
    DB::table('market_feeds')->where('market_id', $market->market_id)
        ->update(['status' => 'queued', 'lease_token' => $token, 'lease_until' => now()->addMinute()]);
    $id = queuedUuid($this->managedQueue, new CollectMarketFeed($market->market_id, $token));
    expect($this->queueAdmin->cancel('managed-test', 'testing', $id))->toBeTrue()
        ->and($market->feed()->first()->lease_token)->toBeNull()
        ->and($market->subscriptions()->where('active', true)->count())->toBe(1);
    $id = queuedUuid($this->managedQueue, new CollectMarketFeed($market->market_id, $token));
    $newToken = (string) Str::uuid();
    DB::table('market_feeds')->where('market_id', $market->market_id)->update(['lease_token' => $newToken]);
    expect($this->queueAdmin->cancel('managed-test', 'testing', $id))->toBeTrue()
        ->and($market->feed()->first()->lease_token)->toBe($newToken);
});

it('releases only its auto-label dispatch lock without exposing the token', function () {
    $lock = Cache::lock('labels-test:lock', 1200);
    $lock->get();
    $job = new AnalyzeMarketAutoLabels('kraken', 'ETH/USD', '1m', 'labels-test');
    $job->dispatchLockOwner = $lock->owner();
    $id = queuedUuid($this->managedQueue, $job);
    $details = $this->queueAdmin->inspect('managed-test', 'testing', $id);
    expect(json_encode($details))->not->toContain($lock->owner())
        ->and($this->queueAdmin->cancel('managed-test', 'testing', $id))->toBeTrue()
        ->and($lock->isOwnedByCurrentProcess())->toBeFalse()
        ->and(Cache::get('labels-test:status'))->toBe('cancelled');
});

it('supports native Redis backlog and safely inspects unknown payloads without decoding commands', function () {
    $job = new BuildMarketFeatures('kraken', 'ETH/USD', '1m');
    (new UniqueLock(Cache::store()))->acquire($job);
    $id = queuedUuid($this->managedQueue, $job);
    $raw = json_decode($this->managedQueue->getConnection()->lpop($this->managedQueue->key('testing')), true);
    unset($raw['queue_context']);
    $this->managedQueue->getConnection()->rpush($this->managedQueue->key('testing'), json_encode($raw));
    expect($this->queueAdmin->inspect('managed-test', 'testing', $id))
        ->toMatchArray(['name' => 'BuildMarketFeatures', 'symbol' => 'ETH/USD', 'can_cancel' => true])
        ->and($this->queueAdmin->cancel('managed-test', 'testing', $id))->toBeTrue();
    $unknown = json_encode(['id' => 'old-native-id', 'attempts' => 0,
        'data' => ['commandName' => 'ThirdParty\\Job', 'command' => 'malformed serialized data and secret-token']]);
    $this->managedQueue->pushRaw($unknown, 'testing');
    $details = $this->queueAdmin->inspect('managed-test', 'testing', hash('sha256', $unknown));
    expect($details['can_cancel'])->toBeFalse()
        ->and(json_encode($details))->not->toContain('secret-token')
        ->and($this->queueAdmin->move('managed-test', 'testing', $details['uuid'], 'top'))->toBeTrue()
        ->and($this->queueAdmin->cancel('managed-test', 'testing', $details['uuid']))->toBeFalse();
});

it('propagates waiting age to the same stream and clears parent context after the attempt', function () {
    queueMarket($this->exchange, 'ETH/USD', 1);
    $created = now()->timestamp;
    queuedUuid($this->managedQueue, queueCandleJob('ETH/USD'));
    $parent = $this->managedQueue->pop('testing');
    $this->travel(100)->seconds();
    $metadata = app(QueuePayload::class);
    $metadata->processing(new JobProcessing('managed-test', $parent));
    expect($metadata->metadata(queueCandleJob('ETH/USD'), now()->timestamp)['requested_at'])->toBe($created)
        ->and($metadata->metadata(queueCandleJob('BTC/USD'), now()->timestamp)['requested_at'])->toBe(now()->timestamp);
    $metadata->attempted(new JobAttempted('managed-test', $parent, null));
    expect($metadata->metadata(queueCandleJob('ETH/USD'), now()->timestamp)['requested_at'])->toBe(now()->timestamp);
});

it('applies stage aging budgets to renamed and hash-tagged queues', function () {
    config(['features.queue' => '{custom-features}', 'queue_management.aging_seconds.features' => 120]);
    expect(app(QueuePriority::class)->agingSeconds('{custom-features}'))->toBe(120)
        ->and(app(QueuePriority::class)->agingSeconds('custom-features'))->toBe(120);
    config(['features.queue' => '{shared}:features', 'intelligence.queue' => '{shared}:intelligence',
        'queue_management.aging_seconds.intelligence' => 3600]);
    expect(app(QueuePriority::class)->agingSeconds('{shared}:features'))->toBe(120)
        ->and(app(QueuePriority::class)->agingSeconds('{shared}:intelligence'))->toBe(3600);
    config(['features.queue' => '{default}:features']);
    expect(app(QueuePriority::class)->agingSeconds('{default}:features'))->toBe(120);
});

it('retains cancellation cleanup durably after an interrupted cleanup and retries it before the next claim', function () {
    $job = new BuildMarketFeatures('kraken', 'ETH/USD', '1m');
    (new UniqueLock(Cache::store()))->acquire($job);
    $id = queuedUuid($this->managedQueue, $job);
    $cleanup = new class(app(QueuePayload::class)) extends QueueCancellation
    {
        public bool $interrupt = true;

        protected function cleanup(array $row): void
        {
            if ($this->interrupt) {
                $this->interrupt = false;
                throw new RuntimeException('Simulated cleanup interruption.');
            }
            parent::cleanup($row);
        }
    };
    app()->instance(QueueCancellation::class, $cleanup);
    $admin = app(QueueAdministration::class);
    expect($admin->cancel('managed-test', 'testing', $id))->toBeTrue()
        ->and($this->managedQueue->pendingSize('testing'))->toBe(0)
        ->and($this->managedQueue->getConnection()->hlen($this->managedQueue->controlKey('testing', 'cancelled')))->toBe(1)
        ->and(Cache::restoreLock(UniqueLock::getKey($job), $job->uniqueLockOwner)->isOwnedByCurrentProcess())->toBeTrue()
        ->and($this->managedQueue->pop('testing'))->toBeNull()
        ->and($this->managedQueue->getConnection()->hlen($this->managedQueue->controlKey('testing', 'cancelled')))->toBe(0)
        ->and(Cache::restoreLock(UniqueLock::getKey($job), $job->uniqueLockOwner)->isOwnedByCurrentProcess())->toBeFalse();
});

it('stops a cancelled unstarted chain and refuses removal from a coordinated batch', function () {
    $job = queueCandleJob('ETH/USD');
    $job->chain([queueCandleJob('BTC/USD')]);
    $id = queuedUuid($this->managedQueue, $job);
    expect($this->queueAdmin->inspect('managed-test', 'testing', $id)['chain_remaining'])->toBe(1)
        ->and($this->queueAdmin->cancel('managed-test', 'testing', $id))->toBeTrue()
        ->and($this->managedQueue->size('testing'))->toBe(0);

    $id = queuedUuid($this->managedQueue, queueCandleJob('ETH/USD'));
    $raw = json_decode($this->managedQueue->getConnection()->lpop($this->managedQueue->key('testing')), true);
    $raw['queue_context']['batched'] = true;
    $this->managedQueue->pushRaw(json_encode($raw), 'testing');
    expect($this->queueAdmin->inspect('managed-test', 'testing', $id))
        ->toMatchArray(['can_cancel' => false, 'cancel_reason' => 'Batch cancellation requires its batch coordinator.'])
        ->and($this->queueAdmin->cancel('managed-test', 'testing', $id))->toBeFalse()
        ->and($this->managedQueue->pendingSize('testing'))->toBe(1);
});

it('never claims the same payload twice when two independent workers race', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('The real worker race requires pcntl.');
    }
    $ids = [];
    for ($index = 0; $index < 40; $index++) {
        $ids[] = queuedUuid($this->managedQueue, queueCandleJob('ETH/USD', $index));
    }
    $children = [];
    for ($worker = 0; $worker < 2; $worker++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Could not fork the queue test worker.');
        }
        if ($pid === 0) {
            $redis = new RedisManager(app(), 'phpredis', $this->queueRedisConfiguration);
            $queue = (new ManagedRedisConnector($redis))->connect(config('queue.connections.managed-test'));
            $queue->setContainer(app());
            $queue->setConnectionName('managed-test');
            $connection = $queue->getConnection();
            $connection->incr($queue->controlKey('testing', 'test-ready'));
            $deadline = microtime(true) + 10;
            while (! $connection->get($queue->controlKey('testing', 'test-start'))) {
                if (microtime(true) >= $deadline) {
                    exit(2);
                }
                usleep(1000);
            }
            try {
                while (microtime(true) < $deadline) {
                    try {
                        $job = $queue->pop('testing');
                    } catch (RuntimeException $error) {
                        if (str_starts_with($error->getMessage(), 'Queue order changed')) {
                            continue;
                        }
                        throw $error;
                    }
                    if ($job === null) {
                        exit(0);
                    }
                    $connection->rpush($queue->controlKey('testing', 'test-results'), $job->uuid());
                    $job->delete();
                }
                exit(3);
            } catch (Throwable) {
                exit(4);
            }
        }
        $children[] = $pid;
    }
    $redis = $this->managedQueue->getConnection();
    $deadline = microtime(true) + 10;
    while ((int) $redis->get($this->managedQueue->controlKey('testing', 'test-ready')) < 2 && microtime(true) < $deadline) {
        usleep(1000);
    }
    $redis->set($this->managedQueue->controlKey('testing', 'test-start'), 1);
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        expect(pcntl_wifexited($status))->toBeTrue()->and(pcntl_wexitstatus($status))->toBe(0);
    }
    $claimed = $redis->lrange($this->managedQueue->controlKey('testing', 'test-results'), 0, -1);
    sort($claimed);
    sort($ids);
    expect($claimed)->toBe($ids)->and(count(array_unique($claimed)))->toBe(40)
        ->and($this->managedQueue->size('testing'))->toBe(0);
});

it('does not mistake a chained child unique owner for the legacy parent owner', function () {
    $child = new BuildMarketFeatures('kraken', 'BTC/USD', '1m');
    (new UniqueLock(Cache::store()))->acquire($child);
    $parent = new BuildMarketFeatures('kraken', 'ETH/USD', '1m');
    $parent->chain([$child]);
    queuedUuid($this->managedQueue, $parent);
    $raw = json_decode($this->managedQueue->getConnection()->lindex($this->managedQueue->key('testing'), 0), true);
    unset($raw['queue_context']);
    expect(app(QueuePayload::class)->read(json_encode($raw))['can_cancel'])->toBeFalse();
});

it('shares subscriber demand across tournament dataset training and its shadow model jobs', function () {
    $low = queueMarket($this->exchange, 'ETH/USD', 1);
    $high = queueMarket($this->exchange, 'BTC/USD', 10);
    $ids = [];
    foreach ([$low, $high] as $market) {
        $datasetId = (string) Str::uuid();
        $shadowId = (string) Str::uuid();
        $stream = ['exchange' => 'kraken', 'symbol' => $market->symbol, 'period' => '4h'];
        DB::table('research_datasets')->insert(['dataset_id' => $datasetId,
            'manifest' => json_encode($stream), 'created_at' => now()]);
        DB::table('tournament_shadow_models')->insert([
            'shadow_model_id' => $shadowId, 'dataset_id' => $datasetId,
            'market_key' => hash('sha256', 'kraken|'.$market->symbol.'|4h'),
            'task' => 'action', 'algorithm' => 'weighted_knn', 'version' => 'test',
            'sha256' => str_repeat('a', 64), 'trained_until_ms' => 1000, 'published_at_ms' => 2000,
            'metadata' => json_encode($stream), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $training = new TrainIntelligenceTournament($datasetId);
        expect((new UniqueLock(Cache::store()))->acquire($training))->toBeTrue();
        $ids[$market->symbol] = [queuedUuid($this->managedQueue, $training),
            queuedUuid($this->managedQueue, new RunTournamentShadow($shadowId, 'predict'))];
    }
    $rows = $this->queueAdmin->snapshot('managed-test', 'testing')['jobs'];
    expect(array_column($rows, 'uuid'))->toBe([...$ids['BTC/USD'], ...$ids['ETH/USD']])
        ->and(array_column($rows, 'subscriber_count'))->toBe([10, 10, 1, 1])
        ->and(array_column($rows, 'period'))->toBe(['4h', '4h', '4h', '4h'])
        ->and($this->managedQueue->pop('testing')->uuid())->toBe($ids['BTC/USD'][0])
        ->and($this->managedQueue->pop('testing')->uuid())->toBe($ids['BTC/USD'][1]);
});
