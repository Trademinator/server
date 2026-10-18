<?php

use App\Domain\Operations\ActionContext;
use App\Domain\Operations\ActionLog;
use App\Jobs\TrainMarketIntelligence;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

it('shows the queued market and logs its identity before training fails', function () {
    $handler = new TestHandler;
    app()->instance(ActionLog::class, new ActionLog(new Logger('test-actions', [$handler]), app(ActionContext::class)));
    $job = new TrainMarketIntelligence('binance', 'XRP/USDC', '1m', 'manual:diagnostics');
    Queue::connection('database')->push($job, '', 'intelligence-diagnostics');
    $payload = json_decode(DB::table('jobs')->sole()->payload, true);

    // Missing features deliberately stop the build before it can write a model.
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'intelligence-diagnostics',
        '--once' => true, '--sleep' => 0]);

    expect($payload['displayName'])->toBe('App\\Jobs\\TrainMarketIntelligence:binance:XRP/USDC:1m');
    $records = array_map(fn ($record): array => json_decode($record->message, true), $handler->getRecords());
    $started = array_values(array_filter($records, fn (array $record): bool => $record['event'] === 'intelligence.training.started'));
    expect($started)->toHaveCount(1);
    expect($started[0])->toMatchArray(['exchange' => 'binance', 'symbol' => 'XRP/USDC', 'period' => '1m',
        'job_id' => $payload['uuid'], 'queue' => 'intelligence-diagnostics']);
    $jobStarted = array_values(array_filter($records, fn (array $record): bool => $record['event'] === 'job.started'));
    expect($jobStarted[0]['job'])->toBe($payload['displayName']);
    $this->assertDatabaseEmpty('intelligence_models');
});
