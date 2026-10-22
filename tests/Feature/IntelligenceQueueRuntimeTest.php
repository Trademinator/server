<?php

use App\Domain\Intelligence\TrainingRuntime;
use App\Jobs\RebuildBackfilledIntelligence;
use App\Jobs\TrainMarketIntelligence;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

it('loads compatible training and signal reservations even when legacy environment values are too short', function (
    int $algorithm, int $configuredTimeout, int $configuredRetry, int $expectedTimeout, int $expectedRetry
) {
    $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M', '-r', <<<'PHP'
require 'vendor/autoload.php';
new Illuminate\Foundation\Application(getcwd());
// Queue configuration must not depend on intelligence having been loaded first.
$queues = require 'config/queue.php';
$intelligence = require 'config/intelligence.php';
$leadLag = require 'config/lead_lag.php';
$humanTraining = require 'config/human_training.php';
$research = require 'config/research.php';
echo json_encode([
    'configuration' => ['intelligence' => $intelligence, 'lead_lag' => $leadLag,
        'human_training' => $humanTraining, 'research' => $research],
    'signals' => $intelligence['signals'],
    'reservations' => array_map(fn ($name) => $queues['connections'][$name]['retry_after'], ['database', 'redis', 'beanstalkd']),
    'signal_retry' => $queues['connections']['redis-signals']['retry_after'],
], JSON_THROW_ON_ERROR);
PHP,
    ], base_path(), [
        'QUEUE_CONNECTION' => 'redis',
        'INTELLIGENCE_MAX_SECONDS' => (string) $algorithm,
        'INTELLIGENCE_JOB_TIMEOUT' => (string) $configuredTimeout,
        'REDIS_QUEUE_RETRY_AFTER' => (string) $configuredRetry,
        'DB_QUEUE_RETRY_AFTER' => (string) $configuredRetry,
        'BEANSTALKD_QUEUE_RETRY_AFTER' => (string) $configuredRetry,
        'INTELLIGENCE_SIGNALS_CONNECTION' => false,
        'INTELLIGENCE_SIGNALS_QUEUE' => false,
        'REDIS_SIGNALS_RETRY_AFTER' => '90',
        'RESEARCH_DATASET_MAX_SECONDS' => '540',
        'INTELLIGENCE_KNN_MIN_EFFECTIVE_NEIGHBORS' => '3',
        'LEAD_LAG_ENABLED' => 'true',
        'HUMAN_TRAINING_ENABLED' => 'true',
        'HUMAN_TREND_TRAINING_ENABLED' => 'false',
        'HUMAN_CANDLE_TRAINING_ENABLED' => 'true',
        'INTELLIGENCE_COINGECKO_INSIGHT_ENABLED' => 'false',
    ]);

    $process->mustRun();
    $settings = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    config($settings['configuration']);

    expect(config('intelligence.job_timeout'))->toBe($expectedTimeout);

    foreach ([new TrainMarketIntelligence('kraken', 'BTC/USD', '1m', 'week'),
        new RebuildBackfilledIntelligence('history', 'lease')] as $job) {
        expect($job->timeout)->toBe($expectedTimeout)->toBeGreaterThan($algorithm);
        foreach ($settings['reservations'] as $reservation) {
            expect($reservation)->toBe($expectedRetry)->toBeGreaterThan(TrainingRuntime::lockSeconds());
        }
    }
    expect($settings['signals'])->toBe(['queue' => 'signals', 'connection' => 'redis-signals'])
        ->and($settings['signal_retry'])->toBe(1200);
})->with([
    'default algorithm budget' => [1800, 0, 90, 836340, 836640],
    'four hours with stale environment values' => [14400, 2200, 2400, 6682740, 6683040],
    'explicit four-hour deployment' => [14400, 6682740, 6683040, 6682740, 6683040],
    'larger explicit limits' => [14400, 8000000, 8100000, 8000000, 8100000],
]);

it('reserves independent candidate and auxiliary runs according to the configured model search', function () {
    $intelligence = [
        'max_seconds' => 60, 'job_timeout' => 0,
        'knn' => ['k_cap' => 6, 'min_train_size' => 5, 'min_effective_neighbors' => 3],
        'patterns' => ['enabled' => true], 'coingecko_insight' => ['enabled' => true],
    ];
    $leadLag = ['enabled' => true, 'max_peers' => 2, 'max_lag' => 2];
    $humanTraining = ['enabled' => true, 'trend_enabled' => true, 'candle_enabled' => true];

    $limits = TrainingRuntime::limits($intelligence, $leadLag, $humanTraining, ['dataset_max_seconds' => 20]);

    expect($limits['runs'])->toBe([
        'preparation' => 6, 'patterns' => 13, 'lead_lag' => 30,
        'outcome_candidates' => 2, 'outcome_holdout' => 1, 'outcome_ablation' => 2,
        'action_candidates' => 2, 'action_holdout' => 1,
        'human_outcome' => 1, 'human_action' => 5, 'coingecko_insight' => 1,
    ]);
    expect($limits['job_timeout'])->toBe(4460);
    expect($limits['lock_seconds'])->toBe(1140);
    expect($limits['retry_after'])->toBe(4760);
});

it('excludes disabled optional algorithms without shortening the remaining candidates', function () {
    $intelligence = [
        'max_seconds' => 60, 'job_timeout' => 0,
        'knn' => ['k_cap' => 6, 'min_train_size' => 5, 'min_effective_neighbors' => 3],
        'patterns' => ['enabled' => false], 'coingecko_insight' => ['enabled' => false],
    ];
    $leadLag = ['enabled' => false, 'max_peers' => 2, 'max_lag' => 2];
    $humanTraining = ['enabled' => false, 'trend_enabled' => true, 'candle_enabled' => true];

    $limits = TrainingRuntime::limits($intelligence, $leadLag, $humanTraining, ['dataset_max_seconds' => 20]);

    expect($limits['runs'])->toMatchArray([
        'patterns' => 0, 'lead_lag' => 0, 'human_outcome' => 0, 'human_action' => 0,
        'coingecko_insight' => 0, 'outcome_candidates' => 2, 'action_candidates' => 2,
    ]);
    expect($limits['job_timeout'])->toBe(1460);
});

it('reserves an extended publication window and preserves a larger explicit job timeout', function () {
    $intelligence = [
        'max_seconds' => 60, 'job_timeout' => 0,
        'knn' => ['k_cap' => 1, 'min_train_size' => 1, 'min_effective_neighbors' => 3],
        'patterns' => ['enabled' => false], 'coingecko_insight' => ['enabled' => false],
    ];
    $leadLag = ['enabled' => false];
    $humanTraining = ['enabled' => false, 'publication_reserve_seconds' => 900];
    $research = ['dataset_max_seconds' => 20];

    $limits = TrainingRuntime::limits($intelligence, $leadLag, $humanTraining, $research);
    $raised = TrainingRuntime::limits([...$intelligence, 'job_timeout' => 2000], $leadLag, $humanTraining, $research);

    expect($limits['runs']['outcome_candidates'])->toBe(0);
    expect($limits['runs']['action_candidates'])->toBe(0);
    expect($limits['job_timeout'])->toBe(1640);
    expect($raised['job_timeout'])->toBe(2000);
    expect($raised['retry_after'])->toBe(2300);
});

it('keeps fractional algorithm allowances and never extends an explicit outer deadline', function () {
    config(['intelligence.max_seconds' => 0.125]);
    $expired = microtime(true) - 1;

    expect(TrainingRuntime::algorithmSeconds())->toBe(0.125);
    expect(TrainingRuntime::algorithmDeadline($expired))->toBe($expired);
});

it('reserves lead-lag discovery when enabled even with no permitted peers', function () {
    $runs = TrainingRuntime::runCounts([], ['enabled' => true, 'max_peers' => 0], []);

    expect($runs['lead_lag'])->toBe(1);
});

it('keeps a weekly training job unique while it waits more than a day for its worker', function () {
    config(['queue.default' => 'database']);
    $this->travelTo('2024-01-01 00:00:00 UTC');

    TrainMarketIntelligence::dispatch('kraken', 'BTC/USD', '1m', '2024-01-01')->onQueue('intelligence');
    $this->travel(26)->hours();
    TrainMarketIntelligence::dispatch('kraken', 'BTC/USD', '1m', '2024-01-01')->onQueue('intelligence');

    $this->assertDatabaseCount('jobs', 1);
    expect(DB::table('jobs')->value('queue'))->toBe('intelligence');
});
