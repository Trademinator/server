<?php

/** Full Action + Outcome build and persisted reads with generated, uncapped OHLCV. */

use App\Domain\Intelligence\AlgorithmicLabelBuilder;
use App\Domain\Intelligence\AlgorithmicLabelSets;
use App\Domain\Intelligence\LabelChunkWriter;
use App\Repositories\TickerRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\TestEnvironment;

require dirname(__DIR__, 2).'/vendor/autoload.php';

TestEnvironment::prepare();

$count = (int) ($argv[1] ?? 527041);
$retainedMiB = (int) ($argv[2] ?? 192);
if ($count < 10 || $count > 2000000 || $retainedMiB < 0 || $retainedMiB > 256) {
    throw new InvalidArgumentException('Invalid synthetic label history size.');
}
$directory = sys_get_temp_dir().'/label-memory-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
touch($directory.'/database.sqlite');
register_shutdown_function(static fn () => File::deleteDirectory($directory));
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->afterBootstrapping(LoadConfiguration::class, static function ($app): void {
    TestEnvironment::guard($app['config']);
});
$app->make(Kernel::class)->bootstrap();
config([
    'database.default' => 'sqlite', 'database.connections.sqlite.database' => $directory.'/database.sqlite',
    'database.connections.sqlite.foreign_key_constraints' => true,
    'cache.default' => 'array', 'queue.default' => 'sync', 'logging.default' => 'null',
    'intelligence.lookback' => 3, 'intelligence.min_horizon_distance_observations' => 30,
    'research.path' => $directory.'/research', 'exchange_fees.taker_overrides.kraken.rate' => 0.0,
]);
DB::purge();
Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
$app->instance(TickerRepository::class, new class($count) extends TickerRepository
{
    public function __construct(private int $count) {}

    public function streamHistory(string $exchange, string $symbol, string $period, ?int $fromMs = null, ?int $toMs = null): Generator
    {
        for ($i = 0; $i < $this->count; $i++) {
            $timestamp = 1704067200000 + $i * 60000;
            if ($timestamp < ($fromMs ?? 0) || $timestamp > ($toMs ?? PHP_INT_MAX)) {
                continue;
            }
            yield $timestamp => ['open' => '10000', 'high' => '10100', 'low' => '9900',
                'close' => $i % 2 === 0 ? '10070' : '9930', 'volume' => '1.00000000'];
        }
    }
});
$retained = str_repeat('x', $retainedMiB * 1024 * 1024);
$started = hrtime(true);
$asOf = 1704067200000 + $count * 60000;
$set = app(AlgorithmicLabelBuilder::class)->build('kraken', 'BTC/USD', '1m', $asOf);
$id = $set['label_set_id'];
unset($set);
$set = app(AlgorithmicLabelSets::class)->find('kraken', 'BTC/USD', '1m', $asOf);
$digests = $counts = [];
foreach (LabelChunkWriter::KINDS as $kind) {
    $hash = hash_init('sha256');
    $counts[$kind] = 0;
    foreach ($set[$kind] as $timestamp => $value) {
        hash_update($hash, $timestamp.':'.json_encode($value, JSON_THROW_ON_ERROR)."\n");
        $counts[$kind]++;
    }
    $digests[$kind] = hash_final($hash);
}
echo json_encode(['counts' => $counts, 'digests' => $digests, 'horizon' => $set['outcome_horizon'],
    'same_set' => $set['label_set_id'] === $id, 'peak_bytes' => memory_get_peak_usage(true),
    'retained_bytes' => strlen($retained), 'memory_limit' => ini_get('memory_limit'),
    'seconds' => round((hrtime(true) - $started) / 1e9, 3)], JSON_THROW_ON_ERROR)."\n";
