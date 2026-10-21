<?php

use App\Domain\Operations\QueueBacklog;
use App\Models\User;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\BeanstalkdQueue;
use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\SqsQueue;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisClusterConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

function queueBacklogOwner(): User
{
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id]);

    return $owner;
}

function backlogJob(string $queue, ?int $reserved = null, int $available = 1_700_000_000, int $created = 1_699_999_900): array
{
    return ['queue' => $queue, 'payload' => 'PRIVATE_JOB_PAYLOAD', 'attempts' => 0,
        'reserved_at' => $reserved, 'available_at' => $available, 'created_at' => $created];
}

it('reports waiting delayed and reserved database jobs separately without exposing payloads', function () {
    $this->travelTo(now()->setTimestamp(1_700_000_000));
    $owner = queueBacklogOwner();
    config(['operations.queue_backlog_connections' => ['database']]);
    DB::table('jobs')->insert([
        backlogJob('features'),
        backlogJob('features', available: 1_700_000_060, created: 1_699_999_800),
        backlogJob('features', reserved: 1_699_999_990, created: 1_699_999_700),
        backlogJob('custom:<script>alert(1)</script>'),
    ]);

    $response = $this->actingAs($owner)->get('/owner/queue-backlog');

    $response->assertSee('Waiting')->assertSee('Delayed')->assertSee('Reserved')
        ->assertSee('custom:&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('PRIVATE_JOB_PAYLOAD')->assertDontSee('database queue only');
    expect($response->viewData('queueBacklog')['rows'][1])->toBe([
        'connection' => 'database', 'driver' => 'database', 'queue' => 'features',
        'waiting' => 1, 'delayed' => 1, 'reserved' => 1, 'total' => 3, 'oldest' => 1_699_999_900,
    ]);
    $this->assertDatabaseCount('jobs', 4);
});

it('uses the database connection and table configured for the queue', function () {
    config([
        'operations.queue_backlog_connections' => ['separate'],
        'database.connections.queue_store' => config('database.connections.sqlite'),
        'queue.connections.separate' => ['driver' => 'database', 'connection' => 'queue_store', 'table' => 'custom_jobs'],
    ]);
    Schema::connection('queue_store')->create('custom_jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue');
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    DB::connection('queue_store')->table('custom_jobs')->insert(backlogJob('history'));
    DB::table('jobs')->insert(backlogJob('not-selected'));

    $snapshot = app(QueueBacklog::class)->snapshot();

    expect($snapshot['rows'])->toHaveCount(1);
    expect($snapshot['rows'][0])->toMatchArray(['connection' => 'separate', 'queue' => 'history', 'total' => 1]);
    expect($snapshot['errors'])->toBe([]);
});

it('discovers Redis queues and their states alongside database jobs without blocking key enumeration', function (bool $cluster, bool $scanPrefix) {
    $owner = queueBacklogOwner();
    $database = config('queue.connections.database');
    config([
        'queue.default' => 'redis', 'operations.queue_backlog_connections' => [],
        'queue.connections' => ['database' => $database, 'redis' => ['driver' => 'redis', 'queue' => 'default']],
    ]);
    DB::table('jobs')->insert(backlogJob('database-only'));
    $client = Mockery::mock(Redis::class);
    $client->shouldReceive('getOption')->with(Redis::OPT_PREFIX)->andReturn('tm:');
    $client->shouldReceive('getOption')->with(Redis::OPT_SCAN)->andReturn($scanPrefix ? Redis::SCAN_PREFIX : Redis::SCAN_NORETRY);
    $redis = Mockery::mock($cluster ? PhpRedisClusterConnection::class : PhpRedisConnection::class);
    $redis->shouldReceive('client')->andReturn($client);
    $redis->shouldReceive('isCluster')->andReturn($cluster);
    $key = fn (string $name): string => 'queues:'.($cluster ? '{'.$name.'}' : $name);
    $options = ['match' => $scanPrefix ? 'queues:*' : 'tm:queues:*', 'count' => 1000];
    $redis->shouldReceive('scan')->with(null, $options)->once()->andReturn(['17', []]);
    $redis->shouldReceive('scan')->with('17', $options)->once()->andReturn(['0', [
        'tm:'.$key('features'), 'tm:'.$key('features').':reserved',
        'tm:'.$key('features').':notify', 'tm:'.$key('features'),
        'tm:'.$key('archive:urgent').':delayed', 'tm:'.$key('running-only').':reserved',
        'other:'.$key('unrelated'),
    ]]);
    foreach (['features' => [2, 3, 4], 'archive:urgent' => [0, 5, 0], 'running-only' => [0, 0, 2]] as $name => [$waiting, $delayed, $reserved]) {
        $redis->shouldReceive('llen')->with($key($name))->once()->andReturn($waiting);
        $redis->shouldReceive('zcard')->with($key($name).':delayed')->once()->andReturn($delayed);
        $redis->shouldReceive('zcard')->with($key($name).':reserved')->once()->andReturn($reserved);
    }
    $redis->shouldReceive('lindex')->with($key('features'), 0)->once()->andReturn('{"createdAt":1700000000}');
    $factory = Mockery::mock(Factory::class);
    $factory->shouldReceive('connection')->with('queue-store')->andReturn($redis);
    $queue = new RedisQueue($factory, 'default', 'queue-store');
    $queue->setContainer(app());
    $queue->setConnectionName('redis');
    Queue::shouldReceive('connection')->with('redis')->once()->andReturn($queue);

    $response = $this->actingAs($owner)->get('/owner/queue-backlog');

    $response->assertSee('redis / redis')->assertSee('database-only')->assertSee('archive:urgent')->assertSee('running-only');
    $snapshot = $response->viewData('queueBacklog');
    expect($snapshot['errors'])->toBe([]);
    expect($snapshot['rows'])->toHaveCount(4);
    expect($snapshot['rows'][2])->toBe([
        'connection' => 'redis', 'driver' => 'redis', 'queue' => 'features',
        'waiting' => 2, 'delayed' => 3, 'reserved' => 4, 'total' => 9, 'oldest' => 1_700_000_000,
    ]);
    expect($snapshot['rows'][1])->toMatchArray(['waiting' => 0, 'delayed' => 5, 'oldest' => null]);
    expect($snapshot['rows'][3])->toMatchArray(['waiting' => 0, 'reserved' => 2, 'oldest' => null]);
    $this->assertDatabaseCount('jobs', 1);
})->with(['standalone' => [false, false], 'cluster' => [true, false], 'scan prefix' => [false, true]])
    ->skip(! extension_loaded('redis'), 'The PhpRedis adapter requires the optional Redis extension.');

it('discovers prefixed queues with Predis on every cluster node', function (bool $cluster) {
    config(['operations.queue_backlog_connections' => ['redis']]);
    $key = $cluster ? 'queues:{custom}' : 'queues:custom';
    $nodes = [];
    for ($i = 0; $i < ($cluster ? 2 : 1); $i++) {
        $node = Mockery::mock();
        $node->shouldReceive('executeRaw')->with(['SCAN', 0, 'MATCH', 'tm:queues:*', 'COUNT', 1000])
            ->once()->andReturn(['0', ['tm:'.$key.':delayed']]);
        $nodes[] = $node;
    }
    $client = $cluster ? Mockery::mock(ArrayIterator::class, [$nodes])->makePartial() : $nodes[0];
    $client->shouldReceive('getOptions')->andReturn((object) ['prefix' => 'tm:']);
    $connection = $cluster ? new PredisClusterConnection($client) : new PredisConnection($client);
    $client->shouldReceive('llen')->with($key)->once()->andReturn(0);
    $client->shouldReceive('zcard')->with($key.':delayed')->once()->andReturn(8);
    $client->shouldReceive('zcard')->with($key.':reserved')->once()->andReturn(0);
    $factory = Mockery::mock(Factory::class);
    $factory->shouldReceive('connection')->with('queue-store')->andReturn($connection);
    $queue = new RedisQueue($factory, 'default', 'queue-store');
    $queue->setContainer(app());
    $queue->setConnectionName('redis');
    Queue::shouldReceive('connection')->with('redis')->once()->andReturn($queue);

    $snapshot = app(QueueBacklog::class)->snapshot();

    expect($snapshot['errors'])->toBe([]);
    expect($snapshot['rows'])->toHaveCount(1);
    expect($snapshot['rows'][0])->toMatchArray(['queue' => 'custom', 'waiting' => 0, 'delayed' => 8, 'reserved' => 0, 'total' => 8]);
})->with(['standalone' => false, 'cluster' => true]);

it('reports unavailable connections without claiming the backlog is empty or leaking the error', function () {
    $owner = queueBacklogOwner();
    config(['operations.queue_backlog_connections' => ['redis']]);
    Queue::shouldReceive('connection')->with('redis')->once()->andThrow(new RuntimeException('PRIVATE_PASSWORD'));

    $response = $this->actingAs($owner)->get('/owner/queue-backlog');

    $response->assertSee('Unable to read queue connection redis')->assertSee('Queue backlog could not be fully read.')
        ->assertDontSee('No queued jobs')->assertDontSee('PRIVATE_PASSWORD');
});

it('shows a shared Redis signal queue once while preserving custom discovery and selected connections', function (array $selected, string $signalsStore, array $expected) {
    config([
        'queue.default' => 'training', 'operations.queue_backlog_connections' => $selected,
        'intelligence.signals.connection' => 'realtime', 'intelligence.signals.queue' => 'market:signals',
        'queue.connections' => [
            'training' => ['driver' => 'redis', 'connection' => 'queue-store', 'queue' => 'intelligence'],
            'realtime' => ['driver' => 'redis', 'connection' => $signalsStore, 'queue' => 'market:signals'],
        ],
    ]);
    foreach ($selected as $name) {
        $client = Mockery::mock();
        $client->shouldReceive('getOptions')->andReturn((object) ['prefix' => 'tm:']);
        $client->shouldReceive('executeRaw')->with(['SCAN', 0, 'MATCH', 'tm:queues:*', 'COUNT', 1000])
            ->once()->andReturn(['0', ['tm:queues:intelligence', 'tm:queues:market:signals', 'tm:queues:custom:urgent:delayed']]);
        foreach (['intelligence', 'market:signals', 'custom:urgent'] as $queueName) {
            $client->shouldReceive('llen')->with('queues:'.$queueName)->once()->andReturn(0);
            $client->shouldReceive('zcard')->with('queues:'.$queueName.':delayed')->once()->andReturn(2);
            $client->shouldReceive('zcard')->with('queues:'.$queueName.':reserved')->once()->andReturn(0);
        }
        $factory = Mockery::mock(Factory::class);
        $store = config('queue.connections.'.$name.'.connection');
        $factory->shouldReceive('connection')->with($store)->andReturn(new PredisConnection($client));
        $queue = new RedisQueue($factory, 'default', $store);
        $queue->setContainer(app());
        $queue->setConnectionName($name);
        Queue::shouldReceive('connection')->with($name)->once()->andReturn($queue);
    }

    $snapshot = app(QueueBacklog::class)->snapshot();

    expect($snapshot['errors'])->toBe([]);
    expect(array_map(fn (array $row): string => $row['connection'].'/'.$row['queue'], $snapshot['rows']))->toBe($expected);
})->with([
    'shared store' => [['training', 'realtime'], 'queue-store', ['realtime/market:signals', 'training/custom:urgent', 'training/intelligence']],
    'distinct stores' => [['training', 'realtime'], 'signals-store', [
        'realtime/custom:urgent', 'realtime/intelligence', 'realtime/market:signals',
        'training/custom:urgent', 'training/intelligence', 'training/market:signals',
    ]],
    'training connection only' => [['training'], 'queue-store', ['training/custom:urgent', 'training/intelligence', 'training/market:signals']],
    'signal connection only' => [['realtime'], 'queue-store', ['realtime/custom:urgent', 'realtime/intelligence', 'realtime/market:signals']],
]);

it('inspects failover children once and explains connections without persistent jobs', function () {
    $database = config('queue.connections.database');
    config([
        'queue.default' => 'main', 'operations.queue_backlog_connections' => [],
        'queue.connections' => [
            'main' => ['driver' => 'failover', 'connections' => ['stored', 'sync', 'nested']],
            'nested' => ['driver' => 'failover', 'connections' => ['stored', 'main']],
            'stored' => $database, 'sync' => ['driver' => 'sync'], 'null' => ['driver' => 'null'],
        ],
    ]);
    DB::table('jobs')->insert(backlogJob('features'));

    $snapshot = app(QueueBacklog::class)->snapshot();

    expect($snapshot['connections'])->toBe(['stored']);
    expect($snapshot['rows'])->toHaveCount(1);
    expect($snapshot['notices'])->toBe([
        'sync (sync) has no persistent queue backlog.', 'null (null) has no persistent queue backlog.',
    ]);
    expect($snapshot['errors'])->toBe([]);
});

it('discovers SQS queues across every page without receiving their messages', function () {
    config(['operations.queue_backlog_connections' => ['sqs']]);
    $first = 'https://sqs.us-east-1.amazonaws.com/123456789012/features';
    $second = 'https://sqs.us-east-1.amazonaws.com/123456789012/custom';
    $client = Mockery::mock();
    $client->shouldReceive('listQueues')->with(['MaxResults' => 1000])->once()->andReturn(['QueueUrls' => [$first], 'NextToken' => 'next']);
    $client->shouldReceive('listQueues')->with(['MaxResults' => 1000, 'NextToken' => 'next'])->once()->andReturn(['QueueUrls' => [$second]]);
    $queue = Mockery::mock(SqsQueue::class);
    $queue->shouldReceive('getSqs')->andReturn($client);
    foreach ([$first, $second] as $url) {
        $queue->shouldReceive('pendingSize')->with($url)->once()->andReturn(3);
        $queue->shouldReceive('delayedSize')->with($url)->once()->andReturn(4);
        $queue->shouldReceive('reservedSize')->with($url)->once()->andReturn(5);
        $queue->shouldReceive('creationTimeOfOldestPendingJob')->with($url)->once()->andReturn(null);
    }
    Queue::shouldReceive('connection')->with('sqs')->once()->andReturn($queue);

    $snapshot = app(QueueBacklog::class)->snapshot();

    expect($snapshot['rows'])->toHaveCount(2);
    expect($snapshot['rows'][0])->toMatchArray(['queue' => $second, 'total' => 12, 'oldest' => null]);
    expect($snapshot['errors'])->toBe([]);
});

it('discovers Beanstalkd tubes using queue metrics without reserving jobs', function () {
    config(['operations.queue_backlog_connections' => ['beanstalkd']]);
    $client = Mockery::mock();
    $client->shouldReceive('listTubes')->once()->andReturn(['custom-tube']);
    $queue = Mockery::mock(BeanstalkdQueue::class);
    $queue->shouldReceive('getPheanstalk')->once()->andReturn($client);
    $queue->shouldReceive('pendingSize')->with('custom-tube')->once()->andReturn(1);
    $queue->shouldReceive('delayedSize')->with('custom-tube')->once()->andReturn(2);
    $queue->shouldReceive('reservedSize')->with('custom-tube')->once()->andReturn(3);
    $queue->shouldReceive('creationTimeOfOldestPendingJob')->with('custom-tube')->once()->andReturn(null);
    Queue::shouldReceive('connection')->with('beanstalkd')->once()->andReturn($queue);

    $snapshot = app(QueueBacklog::class)->snapshot();

    expect($snapshot['rows'])->toHaveCount(1);
    expect($snapshot['rows'][0])->toMatchArray(['queue' => 'custom-tube', 'waiting' => 1, 'delayed' => 2, 'reserved' => 3, 'total' => 6]);
    expect($snapshot['errors'])->toBe([]);
});
