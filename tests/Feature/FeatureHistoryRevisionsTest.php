<?php

use App\Domain\Archive\FeatureCheckpointStore;
use App\Domain\Features\FeatureBuilder;
use App\Domain\Features\FeatureHistoryRevisions;
use App\Domain\MarketData\CandleGaps;
use App\Domain\MarketData\HistoryChanges;
use App\Domain\MarketData\MarketDataSynchronizer;
use App\Domain\Operations\ActionLog;
use App\Jobs\BuildMarketFeatures;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function revisionMarket(): Market
{
    $exchange = Exchange::query()->create(['class' => 'kraken', 'name' => 'Kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1m', 'status' => 'ready']);
    MarketSubscription::query()->create(['user_id' => User::factory()->create()->user_id, 'market_id' => $market->market_id, 'active' => true]);

    return $market;
}

function revisionCandles(int $count): array
{
    $candles = [];
    for ($i = 0; $i < $count; $i++) {
        $price = 100 + $i % 7;
        $candles[] = ['microtimestamp' => 1_700_000_000_000 + $i * 60_000,
            'open' => (string) $price, 'high' => (string) ($price + 3), 'low' => (string) ($price - 3),
            'close' => (string) $price, 'volume' => '10'];
    }

    return $candles;
}

it('acknowledges only the captured feature revision and retains corrections arriving during a build', function () {
    $market = revisionMarket();
    $changes = app(HistoryChanges::class);
    $changes->record($market->market_id, '1m', 1000, 2000, 'test');
    $revisions = app(FeatureHistoryRevisions::class);
    $captured = $revisions->pending('kraken', 'BTC/USD', '1m');
    $changes->record($market->market_id, '1m', 3000, 4000, 'test');

    $revisions->complete($captured['history_id'], $captured['revision']);

    expect($revisions->pending('kraken', 'BTC/USD', '1m'))->toBe([
        'history_id' => $captured['history_id'], 'revision' => 2, 'from_ms' => 3000,
    ]);
    $revisions->complete($captured['history_id'], 2);
    expect($revisions->pending('kraken', 'BTC/USD', '1m'))->toBeNull();
    $this->assertDatabaseHas('market_history_backfills', ['market_id' => $market->market_id,
        'feature_revision' => 2, 'trained_revision' => 0]);
});

it('initializes deployed feature progress from completed training and retains newer pending corrections', function () {
    $market = revisionMarket();
    $changes = app(HistoryChanges::class);
    $changes->record($market->market_id, '1m', 1000, 2000, 'test');
    $changes->record($market->market_id, '1m', 3000, 4000, 'test');
    DB::table('market_history_backfills')->where('market_id', $market->market_id)->update(['trained_revision' => 2]);
    $changes->record($market->market_id, '1m', 5000, 6000, 'test');
    $migration = require database_path('migrations/2026_10_23_020000_track_feature_history_revision.php');

    $migration->down();
    $migration->up();

    $this->assertDatabaseHas('market_history_backfills', ['market_id' => $market->market_id,
        'feature_revision' => 2, 'trained_revision' => 2, 'history_revision' => 3]);
    expect(app(FeatureHistoryRevisions::class)->pending('kraken', 'BTC/USD', '1m'))
        ->toMatchArray(['revision' => 3, 'from_ms' => 5000]);
});

it('replays a corrected candle even when the latest feature timestamp is current and preserves continuation age', function () {
    config(['features.queue_chunk_candles' => 40, 'archive.enabled' => false]);
    $market = revisionMarket();
    $tickers = app(TickerRepository::class);
    $candles = revisionCandles(130);
    $tickers->saveTickers('kraken', 'BTC/USD', '1m', $candles);
    app(FeatureBuilder::class)->build('kraken', 'BTC/USD', '1m');
    $original = DB::table('market_features')->orderBy('microtimestamp')->pluck('payload')->all();
    $candles[30]['close'] = '106';
    $candles[30]['high'] = '107';
    $tickers->saveTickers('kraken', 'BTC/USD', '1m', [$candles[30]]);
    app(HistoryChanges::class)->record($market->market_id, '1m', $candles[30]['microtimestamp'], $candles[30]['microtimestamp'], 'live_candle_correction');
    $requestedAt = now()->subMinutes(10)->getTimestamp();
    $job = (new BuildMarketFeatures('kraken', 'BTC/USD', '1m'))->onConnection('database');
    $job->queueRequestedAt = $requestedAt;
    dispatch($job);
    $runs = 0;
    while (($queued = Queue::connection('database')->pop('features')) !== null && ++$runs < 10) {
        $command = unserialize($queued->payload()['data']['command']);
        expect($command->queueRequestedAt)->toBe($requestedAt);
        $queued->fire();
    }
    $actual = DB::table('market_features')->orderBy('microtimestamp')->pluck('payload')->all();

    expect($runs)->toBe(4);
    expect($actual)->not->toBe($original);
    $this->assertDatabaseHas('market_history_backfills', ['market_id' => $market->market_id,
        'feature_revision' => 1, 'trained_revision' => 0]);
    DB::table('market_features')->delete();
    app(FeatureCheckpointStore::class)->clear('kraken', 'BTC/USD', '1m');
    app(FeatureBuilder::class)->build('kraken', 'BTC/USD', '1m');
    expect($actual)->toBe(DB::table('market_features')->orderBy('microtimestamp')->pluck('payload')->all());
});

it('records an actual live candle correction and coalesces a feature update even when timestamps have not advanced', function () {
    config(['queue.default' => 'database', 'features.enabled' => true, 'archive.enabled' => false]);
    $market = revisionMarket();
    $tickers = app(TickerRepository::class);
    $candles = revisionCandles(5);
    $tickers->saveTickers('kraken', 'BTC/USD', '1m', $candles);
    app(FeatureBuilder::class)->build('kraken', 'BTC/USD', '1m');
    Queue::fake([BuildMarketFeatures::class]);
    $corrected = $candles[3];
    $corrected['close'] = '105';
    $repository = Mockery::mock(ExchangeRepository::class);
    $repository->shouldReceive('findByClass')->twice()->andReturn(new Collection([$market->exchange]));
    $repository->shouldReceive('setExchange')->twice();
    $repository->shouldReceive('periods')->twice()->andReturn(['1m' => '1m']);
    $repository->shouldReceive('prepareCandleMarket')->twice();
    $repository->shouldReceive('fetch')->twice()->andReturnUsing(function () use ($tickers, $corrected): array {
        $tickers->saveTickers('kraken', 'BTC/USD', '1m', [$corrected]);

        return [$corrected];
    });
    $sync = new MarketDataSynchronizer($repository, $tickers, new CandleGaps, app(ActionLog::class));
    $sync->sync('kraken', 'BTC/USD', '1m', intdiv($candles[0]['microtimestamp'], 1000), intdiv($candles[4]['microtimestamp'], 1000));
    $sync->sync('kraken', 'BTC/USD', '1m', intdiv($candles[0]['microtimestamp'], 1000), intdiv($candles[4]['microtimestamp'], 1000));

    $this->assertDatabaseCount('market_history_changes', 1);
    $this->assertDatabaseHas('market_history_changes', ['source' => 'live_candle_correction', 'from_ms' => $corrected['microtimestamp']]);
    Queue::assertPushed(BuildMarketFeatures::class, 1);
});

it('builds the complete available history when a new feature version also has a pending correction', function () {
    config(['features.queue_chunk_candles' => 1000, 'archive.enabled' => false]);
    $market = revisionMarket();
    $candles = revisionCandles(130);
    $tickers = app(TickerRepository::class);
    $tickers->saveTickers('kraken', 'BTC/USD', '1m', $candles);
    app(HistoryChanges::class)->record($market->market_id, '1m',
        $candles[120]['microtimestamp'], $candles[120]['microtimestamp'], 'live_candle_correction');

    (new BuildMarketFeatures('kraken', 'BTC/USD', '1m'))->handle(app(FeatureBuilder::class), $tickers);

    $this->assertDatabaseCount('market_features', 130);
    expect((int) DB::table('market_features')->min('microtimestamp'))->toBe($candles[0]['microtimestamp']);
    $this->assertDatabaseHas('market_history_backfills', ['market_id' => $market->market_id, 'feature_revision' => 1]);
});
