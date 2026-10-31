<?php

use App\Domain\Intelligence\ActionAutoLabeler;
use App\Domain\Intelligence\ActionLabelAnalysis;
use App\Domain\Intelligence\AlgorithmicLabelSets;
use App\Domain\Intelligence\LabelChunkWriter;
use App\Domain\Intelligence\PublishedTakerFee;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\Research\SemanticLabels;
use App\Repositories\TickerRepository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function () {
    config(['exchange_fees.taker_overrides.kraken.rate' => 0.001,
        'intelligence.min_horizon_distance_observations' => 1]);
});

function labelMemoryWriter(int $count = 600): LabelChunkWriter
{
    $writer = new LabelChunkWriter;
    for ($i = 0; $i < $count; $i++) {
        $writer->append('action_labels', 1704067200000 + $i * 60000, $i % 2 ? 'buy' : 'sell');
    }

    return $writer;
}

it('preserves Action labels and opposite-pivot maturity across disk pages and real candle gaps', function (string $pattern) {
    $history = [];
    for ($i = 0; $i < 2600; $i++) {
        if ($i === 1400) {
            continue;
        }
        $flat = $pattern === 'long holds' ? ! in_array($i % 1300, [10, 1200], true) : $i % 11 === 0;
        $direction = $pattern === 'chains' ? (intdiv($i, 25) % 2 ? -1 : 1) : ($i % 2 ? -1 : 1);
        $close = $flat ? '100' : ($direction > 0 ? '103.000000000000000001' : '97.000000000000000001');
        $history[1704067200000 + $i * 60000] = ['open' => '100', 'close' => $close,
            'high' => $flat ? '100' : '104', 'low' => $flat ? '100' : '96', 'volume' => '1'];
    }
    $repository = new class($history) extends TickerRepository
    {
        public function __construct(private array $history) {}

        public function streamHistory(string $exchange, string $symbol, string $period, ?int $fromMs = null, ?int $toMs = null): Generator
        {
            yield from $this->history;
        }
    };
    $analysis = new ActionLabelAnalysis($repository, new ActionAutoLabeler, app(PublishedTakerFee::class), new CandleTimeframe);
    $reference = $analysis->analyze('kraken', 'BTC/USD', '1m', 1800000000000);
    $labels = $availability = [];

    $streamed = $analysis->analyze('kraken', 'BTC/USD', '1m', 1800000000000,
        function (int $timestamp, string $label, ?int $available) use (&$labels, &$availability): void {
            $labels[$timestamp] = $label;
            if ($available !== null) {
                $availability[$timestamp] = $available;
            }
        });

    expect($labels)->toBe($reference['_action_labels']);
    expect($availability)->toBe($reference['_action_available_at_ms']);
    expect(ActionLabelAnalysis::publicDiagnostics($streamed))->toBe(ActionLabelAnalysis::publicDiagnostics($reference));
})->with(['alternating', 'chains', 'long holds']);

it('reads labels across chunk boundaries and gaps with bounded query counts', function () {
    $sets = app(AlgorithmicLabelSets::class);
    $set = $sets->publishChunks('kraken', 'BTC/USD', '1m', 1800000000000, [], labelMemoryWriter());
    $queries = 0;
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'algorithmic_label_chunks')) {
            $queries++;
        }
    });

    for ($i = 0; $i < 600; $i++) {
        $timestamp = 1704067200000 + $i * 60000;
        expect($set['action_labels'][$timestamp])->toBe($i % 2 ? 'buy' : 'sell');
        expect($set['action_labels'][$timestamp + 1] ?? null)->toBeNull();
    }
    expect($queries)->toBeLessThanOrEqual(4);
    expect(count($set['action_labels']))->toBe(600);
    expect(iterator_count($set['action_labels']->getIterator()))->toBe(600);
    expect($set['action_labels'][1704067200000])->toBe('sell');
    expect(fn () => $set['action_labels'][1704067200000] = 'buy')->toThrow(LogicException::class);
    $again = $sets->publishChunks('kraken', 'BTC/USD', '1m', 1800000000000, [], labelMemoryWriter());
    expect($again['label_set_id'])->toBe($set['label_set_id']);
    $this->assertDatabaseCount('algorithmic_label_sets', 1);
    $this->assertDatabaseCount('algorithmic_label_chunks', 3);
});

it('rejects damaged or missing chunks before returning usable labels', function (string $damage) {
    $sets = app(AlgorithmicLabelSets::class);
    $set = $sets->publishChunks('kraken', 'BTC/USD', '1m', 1800000000000, [], labelMemoryWriter());
    $chunk = DB::table('algorithmic_label_chunks')->where('label_set_id', $set['label_set_id'])->where('chunk', 0);
    if ($damage === 'missing') {
        $chunk->delete();
    } else {
        $chunk->update(['payload' => '{}']);
    }

    expect(function () use ($sets): void {
        $loaded = $sets->find('kraken', 'BTC/USD', '1m', 1800000000000);
        $loaded['action_labels'][1704067200000];
    })->toThrow(RuntimeException::class, $damage === 'missing' ? 'chunk index' : 'checksum mismatch');
})->with(['missing', 'corrupt']);

it('rolls back the header and partial chunks when publication fails', function () {
    DB::statement("CREATE TRIGGER fail_label_chunk BEFORE INSERT ON algorithmic_label_chunks WHEN NEW.chunk = 1 BEGIN SELECT RAISE(ABORT, 'fixture chunk failure'); END");
    try {
        expect(fn () => app(AlgorithmicLabelSets::class)->publishChunks('kraken', 'BTC/USD', '1m',
            1800000000000, [], labelMemoryWriter()))->toThrow(QueryException::class);
        $this->assertDatabaseEmpty('algorithmic_label_sets');
        $this->assertDatabaseEmpty('algorithmic_label_chunks');
    } finally {
        DB::statement('DROP TRIGGER fail_label_chunk');
    }
});

it('rejects a history revision change without publishing stale labels', function () {
    expect(fn () => app(AlgorithmicLabelSets::class)->publishChunks('kraken', 'BTC/USD', '1m',
        1800000000000, [], labelMemoryWriter(), expectedRevision: 1))
        ->toThrow(RuntimeException::class, 'Candle history changed');
    $this->assertDatabaseEmpty('algorithmic_label_sets');
    $this->assertDatabaseEmpty('algorithmic_label_chunks');
});

it('keeps pre-upgrade immutable JSON label sets readable', function () {
    $sets = app(AlgorithmicLabelSets::class);
    $payload = json_encode(['action_labels' => [1704067200000 => 'buy'],
        'action_available_at_ms' => [1704067200000 => 1704067320000], 'outcome_labels' => []], JSON_THROW_ON_ERROR);
    DB::table('algorithmic_label_sets')->insert([
        'label_set_id' => (string) Str::uuid7(), 'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
        'as_of_ms' => 1800000000000, 'history_revision' => 0,
        'action_version' => AlgorithmicLabelSets::ACTION_VERSION, 'outcome_version' => SemanticLabels::VERSION,
        'profile_sha256' => $sets->profile('kraken', 'BTC/USD'), 'sha256' => hash('sha256', $payload),
        'payload' => $payload, 'created_at' => now(),
    ]);

    $set = $sets->find('kraken', 'BTC/USD', '1m', 1800000000000);

    expect($set['action_labels'][1704067200000])->toBe('buy');
    expect($set['action_available_at_ms'][1704067200000])->toBe(1704067320000);
    $this->assertDatabaseEmpty('algorithmic_label_chunks');
});

it('builds and reloads a full year of Action and Outcome labels within a busy 512 MiB worker', function () {
    $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M',
        base_path('tests/Support/algorithmic-label-memory-check.php'), '527041', '192'], base_path());
    $process->setTimeout(240);

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($result)->toMatchArray(['counts' => ['action_labels' => 527038, 'action_available_at_ms' => 527037,
        'outcome_labels' => 527038], 'horizon' => 1, 'same_set' => true, 'memory_limit' => '512M',
        'retained_bytes' => 192 * 1024 * 1024]);
    // Captured from the original full-history builder with a 2 GiB limit.
    expect($result['digests'])->toBe([
        'action_labels' => 'fa8eaf9b571e5ed8405b49a818d83888b595fef7578c2e1401ff4f07cdb1349e',
        'action_available_at_ms' => 'cdc91a71a6ef5fcf54ca94c8303f31282c9bdb86a55d21aecd72664bade90b03',
        'outcome_labels' => '24e70f03e58468483f3884a5784d5447d0137b0a03d74c6ce80313a3e06850da',
    ]);
    expect($result['peak_bytes'])->toBeLessThan(320 * 1024 * 1024);
});
