<?php

use App\Domain\Operations\WorkerRegistry;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\WorkerHeartbeatProbeJob;

it('keeps heartbeating independently while a child blocks and passes through the PHP memory limit', function (): void {
    $port = getenv('QUEUE_WORKER_TEST_REDIS_PORT');
    if (! extension_loaded('redis') || ! is_string($port) || ! ctype_digit($port)
        || (int) $port < 1 || (int) $port > 65535) {
        $this->markTestSkipped('Set QUEUE_WORKER_TEST_REDIS_PORT to an isolated localhost Redis server.');
    }
    $prefix = 'worker-process-test:'.Str::uuid().':';
    config([
        'database.redis' => ['client' => 'phpredis', 'options' => ['prefix' => $prefix],
            'default' => ['host' => '127.0.0.1', 'port' => (int) $port, 'database' => 14,
                'timeout' => 2, 'read_timeout' => 2]],
        'queue.connections.redis' => ['driver' => 'redis', 'connection' => 'default', 'queue' => 'probe',
            'retry_after' => 60, 'block_for' => null, 'after_commit' => false],
        'queue_management.workers.connection' => 'default',
        'queue_management.workers.overdue_seconds' => 3, 'queue_management.workers.lost_seconds' => 6,
    ]);
    $redis = app('redis')->connection('default');
    $registry = new WorkerRegistry;
    $arguments = [PHP_BINARY];
    if (php_ini_loaded_file() !== false) {
        array_push($arguments, '-c', php_ini_loaded_file());
    }
    array_push($arguments, '-d', 'memory_limit=96M', base_path('artisan'), 'trademinator:queue-work',
        'redis', '--queue=probe', '--slot=1', '--stop-when-empty', '--max-time=10', '--timeout=15',
        '--sleep=0', '--memory=80', '--tries=1', '--no-interaction');
    $process = new Process($arguments, base_path(), [
        'APP_ENV' => 'testing', 'ACTION_SYSLOG_ENABLED' => 'false', 'CACHE_STORE' => 'array',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
        'QUEUE_CONNECTION' => 'redis', 'QUEUE_FAILED_DRIVER' => 'null',
        'REDIS_CLIENT' => 'phpredis', 'REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => $port,
        'REDIS_DB' => '14', 'REDIS_CACHE_DB' => '14', 'REDIS_PREFIX' => $prefix,
        'REDIS_URL' => '', 'REDIS_USERNAME' => '', 'REDIS_PASSWORD' => '',
        'REDIS_QUEUE_CONNECTION' => 'default', 'WORKER_REDIS_CONNECTION' => 'default',
        'WORKER_HOSTNAME' => 'probe-worker', 'WORKER_IP' => '192.0.2.42',
        'WORKER_HEARTBEAT_SECONDS' => '1', 'WORKER_OVERDUE_SECONDS' => '3',
        'WORKER_LOST_SECONDS' => '6', 'WORKER_LEASE_SECONDS' => '30',
    ], timeout: 20);
    try {
        Queue::connection('redis')->push(new WorkerHeartbeatProbeJob('probe'), '', 'probe');
        $process->start();
        $observed = null;
        $deadline = microtime(true) + 10;
        while ($process->isRunning() && microtime(true) < $deadline) {
            $records = $registry->snapshot();
            $record = $records[0] ?? null;
            if ($redis->get('probe:entered') === '1' && $record !== null && $record['job'] !== null
                && $record['heartbeat_at'] > $record['job']['started_at']) {
                $observed = $record;
                break;
            }
            usleep(50_000);
        }
        expect($observed, $process->getErrorOutput().$process->getOutput())->not->toBeNull();
        expect($observed)->toMatchArray(['state' => 'running', 'status' => 'healthy',
            'hostname' => 'probe-worker', 'ip' => '192.0.2.42', 'last_stage' => 'job_started']);
        expect($observed['pid'])->toBeInt()->toBeGreaterThan(0)->not->toBe($observed['supervisor_pid']);
        expect($observed['job'])->toMatchArray(['name' => WorkerHeartbeatProbeJob::class, 'queue' => 'probe', 'attempt' => 1]);
        expect($observed['last_progress_at'])->toBe($observed['job']['started_at']);
        expect($redis->get('probe:memory'))->toBe('96M');
        expect($redis->get('probe:finished'))->toBeNull();
        $redis->rpush('probe:release', 'finish');
        $process->wait();
        expect($process->getExitCode(), $process->getErrorOutput().$process->getOutput())->toBe(0);
        expect($redis->get('probe:finished'))->toBe('1');
        $stopped = $registry->snapshot()[0];
        expect($stopped)->toMatchArray(['state' => 'stopped', 'status' => 'stopped', 'job' => null, 'exit_code' => 0]);
        expect($stopped['last_job'])->toMatchArray(['uuid' => $observed['job']['uuid'], 'outcome' => 'completed']);
    } finally {
        if ($process->isRunning()) {
            $redis->rpush('probe:release', 'finish');
            $process->stop(3);
        }
        $keys = $redis->keys('*');
        if ($keys !== []) {
            $redis->client()->rawCommand('DEL', ...$keys);
        }
    }
});
