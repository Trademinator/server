<?php

use App\Domain\Intelligence\TrainingRuntime;
use App\Jobs\RebuildBackfilledIntelligence;
use App\Jobs\TrainMarketIntelligence;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

it('loads compatible training and signal reservations even when legacy environment values are too short', function (
    int $automatic, int $configuredTimeout, int $configuredRetry, int $expectedTimeout, int $expectedRetry
) {
    $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M', '-r', <<<'PHP'
require 'vendor/autoload.php';
new Illuminate\Foundation\Application(getcwd());
$intelligence = require 'config/intelligence.php';
$queues = require 'config/queue.php';
echo json_encode([
    'automatic' => $intelligence['max_seconds'],
    'timeout' => $intelligence['job_timeout'],
    'signals' => $intelligence['signals'],
    'reservations' => array_map(fn ($name) => $queues['connections'][$name]['retry_after'], ['database', 'redis', 'beanstalkd']),
    'signal_retry' => $queues['connections']['redis-signals']['retry_after'],
], JSON_THROW_ON_ERROR);
PHP,
    ], base_path(), [
        'QUEUE_CONNECTION' => 'redis',
        'INTELLIGENCE_MAX_SECONDS' => (string) $automatic,
        'INTELLIGENCE_JOB_TIMEOUT' => (string) $configuredTimeout,
        'REDIS_QUEUE_RETRY_AFTER' => (string) $configuredRetry,
        'DB_QUEUE_RETRY_AFTER' => (string) $configuredRetry,
        'BEANSTALKD_QUEUE_RETRY_AFTER' => (string) $configuredRetry,
        'INTELLIGENCE_SIGNALS_CONNECTION' => false,
        'INTELLIGENCE_SIGNALS_QUEUE' => false,
        'REDIS_SIGNALS_RETRY_AFTER' => '90',
    ]);

    $process->mustRun();
    $settings = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    config(['intelligence.max_seconds' => $settings['automatic'], 'intelligence.job_timeout' => $settings['timeout']]);

    foreach ([new TrainMarketIntelligence('kraken', 'BTC/USD', '1m', 'week'),
        new RebuildBackfilledIntelligence('history', 'lease')] as $job) {
        expect($job->timeout)->toBe($expectedTimeout)->toBeGreaterThan($automatic + 310);
        foreach ($settings['reservations'] as $reservation) {
            expect($reservation)->toBe($expectedRetry)->toBeGreaterThan(TrainingRuntime::lockSeconds());
        }
    }
    expect($settings['signals'])->toBe(['queue' => 'signals', 'connection' => 'redis-signals'])
        ->and($settings['signal_retry'])->toBe(1200);
})->with([
    'default automatic budget' => [1800, 0, 90, 2400, 2700],
    'four hours with stale environment values' => [14400, 2200, 2400, 15000, 15300],
    'explicit four-hour deployment' => [14400, 15000, 15300, 15000, 15300],
    'larger explicit limits' => [14400, 16000, 18000, 16000, 18000],
]);

it('keeps a weekly training job unique while it waits more than a day for its worker', function () {
    config(['queue.default' => 'database']);
    $this->travelTo('2024-01-01 00:00:00 UTC');

    TrainMarketIntelligence::dispatch('kraken', 'BTC/USD', '1m', '2024-01-01')->onQueue('intelligence');
    $this->travel(26)->hours();
    TrainMarketIntelligence::dispatch('kraken', 'BTC/USD', '1m', '2024-01-01')->onQueue('intelligence');

    $this->assertDatabaseCount('jobs', 1);
    expect(DB::table('jobs')->value('queue'))->toBe('intelligence');
});
