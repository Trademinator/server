<?php

use App\Domain\Intelligence\AlgorithmicLabelBuilder;
use App\Domain\Intelligence\AlgorithmicLabelSets;
use App\Domain\Intelligence\KnowledgeLabelSources;
use App\Domain\Intelligence\LabelChunkWriter;
use App\Domain\MarketData\HistoryChanges;
use App\Domain\Research\DatasetSnapshotBuilder;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\SemanticLabels;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\Ticker;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    config(['exchange_fees.taker_overrides.kraken.rate' => 0.001,
        'intelligence.lookback' => 3, 'intelligence.min_horizon_distance_observations' => 1,
        'archive.enabled' => false, 'research.path' => sys_get_temp_dir().'/label-revision-'.Str::uuid7()]);
});

afterEach(function () {
    File::deleteDirectory(config('research.path'));
});

function labelRevisionMarket(): Market
{
    $exchange = Exchange::query()->create(['class' => 'kraken', 'name' => 'Kraken', 'config' => '{}']);

    return Market::query()->create(['exchange_id' => $exchange->exchange_id,
        'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
}

function labelRevisionDuringRead(Closure $change): void
{
    app()->instance(TickerRepository::class, new class($change) extends TickerRepository
    {
        private bool $changed = false;

        public function __construct(private Closure $change)
        {
            parent::__construct();
        }

        public function streamHistory(string $exchange, string $symbol, string $period,
            ?int $fromMs = null, ?int $toMs = null): Generator
        {
            foreach (parent::streamHistory($exchange, $symbol, $period, $fromMs, $toMs) as $timestamp => $candle) {
                yield $timestamp => $candle;
                if (! $this->changed) {
                    $this->changed = true;
                    ($this->change)();
                }
            }
        }
    });
}

it('finishes frozen labels and builds a snapshot while newer live candles are corrected', function () {
    $market = labelRevisionMarket();
    IntelligenceFixtures::candles();
    $asOf = now()->getTimestampMs();
    $changes = app(HistoryChanges::class);
    $changes->record($market->market_id, '1m', IntelligenceFixtures::START, IntelligenceFixtures::START);
    $reference = app(AlgorithmicLabelBuilder::class)->build('kraken', 'BTC/USD', '1m', $asOf - 1);
    labelRevisionDuringRead(function () use ($changes, $market, $asOf): void {
        Ticker::query()->create(['exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
            'microtimestamp' => $asOf + 60000, 'payload' => json_encode([
                'open' => '200', 'high' => '205', 'low' => '190', 'close' => '195', 'volume' => '10',
            ], JSON_THROW_ON_ERROR)]);
        $changes->record($market->market_id, '1m', $asOf + 60000, $asOf + 60000, 'live_candle_correction');
    });

    $set = app(AlgorithmicLabelBuilder::class)->build('kraken', 'BTC/USD', '1m', $asOf);

    foreach (LabelChunkWriter::KINDS as $kind) {
        expect(iterator_to_array($set[$kind]))->toBe(iterator_to_array($reference[$kind]));
    }
    $this->assertDatabaseHas('algorithmic_label_sets', ['label_set_id' => $set['label_set_id'], 'history_revision' => 2]);

    $changes->record($market->market_id, '1m', $asOf + 120000, $asOf + 120000, 'live_candle_correction');
    $reloaded = app(AlgorithmicLabelBuilder::class)->build('kraken', 'BTC/USD', '1m', $asOf);
    expect($reloaded['label_set_id'])->toBe($set['label_set_id']);
    $availability = app(KnowledgeLabelSources::class)->describe('kraken', 'BTC/USD', '1m');
    expect($availability['algorithm_action']['state'])->toBe('green');
    expect($availability['algorithm_outcome']['state'])->toBe('green');

    $snapshot = app(DatasetSnapshotBuilder::class)->build('kraken', 'BTC/USD', '1m', new SemanticLabels(2, 3), asOfMs: $asOf);
    [, $rows] = app(DatasetStore::class)->load($snapshot['dataset_id']);
    expect($snapshot['rows'])->toBeGreaterThan(0);
    foreach ($rows as $row) {
        expect($row['label'])->toBe($reference['outcome_labels'][$row['microtimestamp']]['label']);
    }
    $this->assertDatabaseCount('algorithmic_label_sets', 2);
});

it('rejects a build when a correction can affect its frozen input', function (?int $fromOffset, ?int $toOffset) {
    $market = labelRevisionMarket();
    IntelligenceFixtures::candles();
    $asOf = now()->getTimestampMs();
    labelRevisionDuringRead(fn () => app(HistoryChanges::class)->record($market->market_id, '1m',
        $fromOffset === null ? null : $asOf + $fromOffset, $toOffset === null ? null : $asOf + $toOffset));

    expect(fn () => app(AlgorithmicLabelBuilder::class)->build('kraken', 'BTC/USD', '1m', $asOf))
        ->toThrow(RuntimeException::class, 'Candle history changed');

    $this->assertDatabaseEmpty('algorithmic_label_sets');
    $this->assertDatabaseEmpty('algorithmic_label_chunks');
})->with([
    'earlier candle' => [-60000, -60000],
    'exact cutoff' => [0, 0],
    'range crossing cutoff' => [-60000, 60000],
    'unknown legacy bounds' => [null, null],
]);

it('invalidates a saved set after a later revision corrects an earlier candle', function () {
    $market = labelRevisionMarket();
    $asOf = now()->getTimestampMs();
    $sets = app(AlgorithmicLabelSets::class);
    $set = $sets->publish('kraken', 'BTC/USD', '1m', $asOf, ['as_of_ms' => $asOf,
        'action_labels' => [IntelligenceFixtures::START => 'buy']]);
    $changes = app(HistoryChanges::class);
    $changes->record($market->market_id, '1m', $asOf + 60000, $asOf + 60000, 'live_candle_correction');
    $changes->record($market->market_id, '1m', IntelligenceFixtures::START, IntelligenceFixtures::START);
    $changes->record($market->market_id, '1m', $asOf + 120000, $asOf + 120000, 'live_candle_correction');

    expect($sets->find('kraken', 'BTC/USD', '1m', $asOf))->toBeNull();

    $this->assertDatabaseHas('algorithmic_label_sets', ['label_set_id' => $set['label_set_id']]);
    $this->assertDatabaseHas('algorithmic_label_chunks', ['label_set_id' => $set['label_set_id']]);
});

it('requires a complete revision ledger even when every recorded correction is newer', function () {
    $market = labelRevisionMarket();
    $asOf = now()->getTimestampMs();
    $changes = app(HistoryChanges::class);
    $changes->record($market->market_id, '1m', IntelligenceFixtures::START, IntelligenceFixtures::START);
    $sets = app(AlgorithmicLabelSets::class);
    $sets->publish('kraken', 'BTC/USD', '1m', $asOf, ['action_labels' => [IntelligenceFixtures::START => 'buy']]);
    DB::table('market_history_backfills')->where('market_id', $market->market_id)->update(['history_revision' => 2]);
    $changes->record($market->market_id, '1m', $asOf + 60000, $asOf + 60000, 'live_candle_correction');

    expect(fn () => $sets->publishChunks('kraken', 'BTC/USD', '1m', $asOf,
        [], new LabelChunkWriter, expectedRevision: 1))->toThrow(RuntimeException::class, 'Candle history changed');
    expect($sets->find('kraken', 'BTC/USD', '1m', $asOf))->toBeNull();
    $this->assertDatabaseCount('algorithmic_label_sets', 1);
});

it('checks all newer revisions across database pages without invalidating unchanged old inputs', function () {
    $market = labelRevisionMarket();
    $asOf = now()->getTimestampMs();
    $sets = app(AlgorithmicLabelSets::class);
    $set = $sets->publish('kraken', 'BTC/USD', '1m', $asOf, ['action_labels' => [IntelligenceFixtures::START => 'buy']]);
    for ($revision = 1; $revision <= 260; $revision++) {
        app(HistoryChanges::class)->record($market->market_id, '1m', $asOf + $revision * 60000, $asOf + $revision * 60000);
    }

    expect($sets->find('kraken', 'BTC/USD', '1m', $asOf)['label_set_id'])->toBe($set['label_set_id']);

    DB::table('market_history_changes')->where('revision', 258)->delete();
    expect($sets->find('kraken', 'BTC/USD', '1m', $asOf))->toBeNull();
});
