<?php

use App\Domain\Intelligence\AlgorithmicLabelBuilder;
use App\Domain\Intelligence\AlgorithmicLabelSets;
use App\Domain\Intelligence\KnowledgeRequestJournal;
use App\Domain\Research\DatasetSnapshotBuilder;
use App\Jobs\AnalyzeMarketAutoLabels;
use App\Jobs\BuildOwnerDatasetSnapshot;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\Support\IntelligenceFixtures;

it('keeps a replacement auto-label lock and its status when an older job executes or fails', function () {
    $key = 'test:auto-label';
    $old = Cache::lock($key.':lock', 3600);
    $old->get();
    $job = new AnalyzeMarketAutoLabels('kraken', 'BTC/USD', '1m', $key);
    $job->dispatchLockOwner = $old->owner();
    $old->release();
    $replacement = Cache::lock($key.':lock', 3600);
    $replacement->get();
    Cache::put($key.':status', 'queued', 3600);

    $job->handle(app(AlgorithmicLabelBuilder::class));
    $job->failed(new RuntimeException('An older attempt failed.'));

    expect($replacement->isOwnedByCurrentProcess())->toBeTrue()
        ->and(Cache::get($key.':status'))->toBe('queued')
        ->and(Cache::get($key.':error'))->toBeNull();
    $this->assertDatabaseEmpty('algorithmic_label_sets');
});

it('releases only its own auto-label lock after completing the analysis', function (bool $expired) {
    $key = 'test:auto-label';
    $asOfMs = now()->getTimestampMs();
    $counts = ['buy' => 1, 'hold' => 2, 'sell' => 1];
    app(AlgorithmicLabelSets::class)->publish('kraken', 'BTC/USD', '1m', $asOfMs,
        ['as_of_ms' => $asOfMs, 'action' => ['action_counts' => $counts]]);
    $lock = Cache::lock($key.':lock', 60);
    $lock->get();
    $job = new AnalyzeMarketAutoLabels('kraken', 'BTC/USD', '1m', $key, $asOfMs);
    $job->dispatchLockOwner = $lock->owner();
    if ($expired) {
        $this->travel(2)->minutes();
    }

    $job->handle(app(AlgorithmicLabelBuilder::class));

    expect(Cache::lock($key.':lock')->isLocked())->toBeFalse()
        ->and(Cache::get($key.':status'))->toBe('completed')
        ->and(Cache::get($key.':summary')['action_counts'])->toBe($counts);
})->with([false, true]);

it('leaves legacy boolean dispatch locks to expire when their jobs fail', function () {
    $key = 'test:legacy-label';
    Cache::put($key.':lock', true, 3600);

    (new AnalyzeMarketAutoLabels('kraken', 'BTC/USD', '1m', $key))
        ->failed(new RuntimeException('Legacy attempt failed.'));

    expect(Cache::get($key.':lock'))->toBeTrue();
});

it('passes snapshot label dispatch ownership to the job and coalesces the next attempt', function () {
    IntelligenceFixtures::feature(0, 0.5);
    Bus::fake([AnalyzeMarketAutoLabels::class]);
    $job = new BuildOwnerDatasetSnapshot('kraken', 'BTC/USD', '1m');

    $job->handle(app(DatasetSnapshotBuilder::class), app(TickerRepository::class), app(KnowledgeRequestJournal::class));
    $job->handle(app(DatasetSnapshotBuilder::class), app(TickerRepository::class), app(KnowledgeRequestJournal::class));

    Bus::assertDispatchedTimes(AnalyzeMarketAutoLabels::class, 1);
    Bus::assertDispatched(AnalyzeMarketAutoLabels::class, fn (AnalyzeMarketAutoLabels $labels): bool => $labels->dispatchLockOwner !== null
        && Cache::restoreLock($labels->cacheKey.':lock', $labels->dispatchLockOwner)->isOwnedByCurrentProcess());
});
