<?php

use App\Domain\Features\FeatureBuilder;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\Research\DatasetSnapshotBuilder;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\LabelDefinition;
use App\Models\MarketFeature;
use App\Models\Ticker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['research.path' => sys_get_temp_dir().'/trademinator-research-'.Str::uuid7()]);
});

afterEach(function () {
    File::deleteDirectory(config('research.path'));
});

function seedResearchHistory(int $count = 90, string $period = '1m'): void
{
    $timeframe = new CandleTimeframe;
    $timestamp = 1704067200000;
    for ($i = 0; $i < $count; $i++) {
        // Deliberate open/previous-close difference catches same-close look-ahead.
        $open = 100 + $i;
        Ticker::query()->create(['exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => $period,
            'microtimestamp' => $timestamp, 'payload' => json_encode(['open' => (string) $open, 'high' => (string) ($open + 2),
                'low' => (string) ($open - 1), 'close' => (string) ($open + 0.5), 'volume' => '10'])]);
        $timestamp = $timeframe->next($timestamp, $period);
    }
    app(FeatureBuilder::class)->build('kraken', 'BTC/USD', $period);
}

function buildResearchSnapshot(int $horizon = 2): array
{
    return app(DatasetSnapshotBuilder::class)->build('kraken', 'BTC/USD', '1m', new LabelDefinition($horizon));
}

it('freezes chronological features with next-open labels and excludes warmup and unfinished horizons', function () {
    seedResearchHistory();
    $manifest = buildResearchSnapshot();
    [$saved, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    expect($saved)->toEqual($manifest)->and($manifest['rows'])->toBe(61)
        ->and($manifest['skipped']['missing_features'])->toBe(27)->and($manifest['skipped']['immature'])->toBe(2);
    expect($rows[0]['entry_price'])->toBe('128')->and($rows[0]['exit_price'])->toBe('129.5')
        ->and($rows[0]['decision_at_ms'])->toBe(1704067200000 + 28 * 60000)
        ->and($rows[0]['entry_at_ms'])->toBe($rows[0]['decision_at_ms'])
        ->and($rows[0]['label_available_at_ms'])->toBe(1704067200000 + 30 * 60000)
        ->and($rows[0]['label'])->toBe('buy')->and(count($rows[0]['vector']))->toBe(15);
    $this->artisan('trademinator:dataset-info', ['dataset' => $manifest['dataset_id']])->assertSuccessful();
});

it('preserves snapshots when sources change and produces a new identity for each build', function () {
    seedResearchHistory();
    $first = buildResearchSnapshot();
    $second = buildResearchSnapshot();
    expect($second['dataset_id'])->not->toBe($first['dataset_id'])
        ->and($second['rows_sha256'])->toBe($first['rows_sha256']);
    $before = app(DatasetStore::class)->load($first['dataset_id']);
    DB::table('tickers')->delete();
    DB::table('market_features')->delete();
    expect(app(DatasetStore::class)->load($first['dataset_id']))->toBe($before);
});

it('fails verification after row or manifest tampering', function () {
    seedResearchHistory();
    $manifest = buildResearchSnapshot();
    $directory = app(DatasetStore::class)->directory($manifest['dataset_id']);
    $original = file_get_contents($directory.'/rows.jsonl');
    file_put_contents($directory.'/rows.jsonl', str_replace('129.5', '130.5', $original));
    expect(fn () => app(DatasetStore::class)->load($manifest['dataset_id']))->toThrow(RuntimeException::class, 'checksum');
    $this->artisan('trademinator:backtest', ['dataset' => $manifest['dataset_id'], '--train' => 10])->assertFailed();
    expect(DB::table('research_backtests')->count())->toBe(0);
    file_put_contents($directory.'/rows.jsonl', $original);
    file_put_contents($directory.'/manifest.json', '{}');
    expect(fn () => app(DatasetStore::class)->manifest($manifest['dataset_id']))->toThrow(RuntimeException::class, 'manifest');
});

it('skips gaps and caps labels at the explicit as-of cutoff', function () {
    seedResearchHistory();
    Ticker::query()->where('microtimestamp', 1704067200000 + 50 * 60000)->delete();
    $manifest = app(DatasetSnapshotBuilder::class)->build('kraken', 'BTC/USD', '1m', new LabelDefinition(2),
        asOfMs: 1704067200000 + 70 * 60000);
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    expect($manifest['skipped']['gaps'])->toBe(2)->and($manifest['skipped']['missing_source'])->toBe(1);
    foreach ($rows as $row) {
        expect($row['label_available_at_ms'])->toBeLessThanOrEqual(1704067200000 + 70 * 60000);
        expect($row['microtimestamp'])->not->toBeIn([1704067200000 + 48 * 60000, 1704067200000 + 49 * 60000]);
    }
});

it('supports calendar periods without converting months to fixed minutes', function () {
    // Custom schema avoids technical warm-up after some monthly gaps in short test histories.
    seedResearchHistory(12, '1M');
    $manifest = app(DatasetSnapshotBuilder::class)->build('kraken', 'BTC/USD', '1M', new LabelDefinition(2), 'custom', ['candle.direction']);
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    expect($rows[0]['decision_at_ms'])->toBe(strtotime('2024-02-01 UTC') * 1000)
        ->and($rows[0]['label_available_at_ms'])->toBe(strtotime('2024-04-01 UTC') * 1000);
});

it('filters by selected features and keeps explicit context keys in the requested order', function () {
    seedResearchHistory();
    expect(fn () => app(DatasetSnapshotBuilder::class)->build('kraken', 'BTC/USD', '1m', new LabelDefinition, 'full'))
        ->toThrow(RuntimeException::class, 'No eligible');
    $feature = MarketFeature::query()->orderBy('microtimestamp')->skip(30)->first();
    $payload = $feature->payload;
    $payload['features']['context.btc_dominance'] = 0.6;
    DB::table('market_features')->where('feature_id', $feature->getKey())->update(['payload' => json_encode($payload)]);
    $manifest = app(DatasetSnapshotBuilder::class)->build('kraken', 'BTC/USD', '1m', new LabelDefinition(2),
        'custom', ['context.btc_dominance', 'trend.direction']);
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    expect($manifest['rows'])->toBe(1)->and($rows[0]['vector'])->toBe([0.6, 1]);
});

it('cleans up empty or oversized builds and rejects stale feature prices', function () {
    seedResearchHistory();
    config(['research.max_rows' => 5]);
    expect(fn () => buildResearchSnapshot())->toThrow(RuntimeException::class, 'max_rows');
    expect(DB::table('research_datasets')->count())->toBe(0)->and(File::directories(config('research.path')))->toBe([]);
    config(['research.max_rows' => 50000]);
    $feature = MarketFeature::query()->orderBy('microtimestamp')->skip(30)->first();
    $payload = $feature->payload;
    $payload['close'] = 1;
    DB::table('market_features')->where('feature_id', $feature->getKey())->update(['payload' => json_encode($payload)]);
    expect(fn () => buildResearchSnapshot())->toThrow(RuntimeException::class, 'rebuild features');
    expect(DB::table('research_datasets')->count())->toBe(0)->and(File::directories(config('research.path')))->toBe([]);
});

it('runs the full CLI workflow and saves an auditable backtest report', function () {
    seedResearchHistory();
    $this->artisan('trademinator:build-dataset', ['exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m', '--horizon' => 2])->assertSuccessful();
    $id = DB::table('research_datasets')->value('dataset_id');
    $this->artisan('trademinator:backtest', ['dataset' => $id, '--train' => 10, '--test' => 7, '--rolling' => true])->assertSuccessful();
    $report = json_decode(DB::table('research_backtests')->value('report'), true);
    expect($report['parameters']['window'])->toBe('rolling')->and($report['folds'])->not->toBeEmpty();
    foreach ($report['folds'] as $fold) {
        expect($fold['train_rows'])->toBe(10)
            ->and($fold['train_labels_available_by_ms'])->toBeLessThan($fold['test_from_ms']);
    }
    $path = app(DatasetStore::class)->directory($id).'/backtest-'.$report['backtest_id'].'.json';
    expect(json_decode(file_get_contents($path), true))->toBe($report);
});

it('fails malformed CLI inputs without publishing artifacts', function () {
    $base = ['exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m'];
    foreach ([['--horizon' => '1.2'], ['--fee-bps' => 'oops'], ['--schema' => 'unknown'], ['--from' => 'tomorrow']] as $bad) {
        $this->artisan('trademinator:build-dataset', array_merge($base, $bad))->assertFailed();
    }
    $this->artisan('trademinator:dataset-info', ['dataset' => '../../test'])->assertFailed();
    expect(DB::table('research_datasets')->count())->toBe(0);
});

it('does not snapshot a feature build in progress and releases the lock after failure', function () {
    $lock = Cache::lock('trademinator:features:'.hash('sha256', 'kraken|BTC/USD|1m'), 720);
    expect($lock->get())->toBeTrue();
    try {
        expect(fn () => buildResearchSnapshot())->toThrow(RuntimeException::class, 'already being built');
    } finally {
        $lock->release();
    }
    expect(fn () => buildResearchSnapshot())->toThrow(RuntimeException::class, 'No M2 features');
    seedResearchHistory();
    expect(buildResearchSnapshot()['rows'])->toBe(61);
});

it('keeps earlier matured samples unchanged when later candles are appended', function () {
    seedResearchHistory(60);
    $first = buildResearchSnapshot();
    [, $before] = app(DatasetStore::class)->load($first['dataset_id']);
    for ($i = 60; $i < 70; $i++) {
        Ticker::query()->create(['exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
            'microtimestamp' => 1704067200000 + $i * 60000,
            'payload' => json_encode(['open' => '1000', 'high' => '1001', 'low' => '999', 'close' => '1000', 'volume' => '100'])]);
    }
    app(FeatureBuilder::class)->build('kraken', 'BTC/USD', '1m');
    $second = buildResearchSnapshot();
    [, $after] = app(DatasetStore::class)->load($second['dataset_id']);
    expect(array_slice($after, 0, count($before)))->toBe($before);
});

it('preserves the candle sequence and label boundaries across feature pages without rescanning offsets', function () {
    $this->travelTo('2024-01-02 00:00:00 UTC');
    seedResearchHistory(540);
    foreach (MarketFeature::query()->orderByDesc('microtimestamp')->get() as $feature) {
        $feature->forceFill(['feature_id' => (string) Str::uuid7()])->save();
    }
    $pages = [];
    DB::listen(function ($query) use (&$pages): void {
        $sql = strtolower($query->sql);
        if (str_starts_with($sql, 'select') && str_contains($sql, 'market_features') && str_contains($sql, 'limit 500')) {
            $pages[] = $sql;
        }
    });

    $manifest = buildResearchSnapshot();
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);

    expect($manifest['rows'])->toBe(511);
    expect($manifest['skipped']['missing_features'])->toBe(27);
    expect($manifest['skipped']['immature'])->toBe(2);
    expect(array_column($rows, 'microtimestamp'))->toBe(range(1704068820000, 1704099420000, 60000));
    expect($rows[472]['decision_at_ms'])->toBe(1704097200000);
    expect($rows[473]['decision_at_ms'])->toBe(1704097260000);
    expect($rows[473]['entry_price'])->toBe('601');
    expect($rows[473]['exit_price'])->toBe('602.5');
    expect($pages)->toHaveCount(2);
    foreach ($pages as $query) {
        expect($query)->not->toContain(' offset ');
    }
});

it('keeps its owned feature lock through the configured training timeout and releases it after publication', function () {
    $this->travelTo('2024-01-02 00:00:00 UTC');
    config(['intelligence.max_seconds' => 14400, 'intelligence.job_timeout' => 15000]);
    seedResearchHistory();
    $name = 'trademinator:features:'.hash('sha256', 'kraken|BTC/USD|1m');
    $protectedDuringPublication = null;
    DB::listen(function ($query) use ($name, &$protectedDuringPublication): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'insert') && str_contains($query->sql, 'research_datasets')) {
            $this->travel(15000)->seconds();
            $contender = Cache::lock($name, 60);
            $protectedDuringPublication = ! $contender->get();
            if (! $protectedDuringPublication) {
                $contender->release();
            }
        }
    });

    $manifest = buildResearchSnapshot();

    expect($manifest['rows'])->toBe(61);
    expect($protectedDuringPublication)->toBeTrue();
    $next = Cache::lock($name, 60);
    try {
        expect($next->get())->toBeTrue();
    } finally {
        $next->release();
    }
});
