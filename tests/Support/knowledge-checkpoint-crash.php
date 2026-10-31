<?php

/** Separate-process crash fixture: the killed worker never executes finally or destructors. */

use App\Domain\Intelligence\AlgorithmicLabelBuilder;
use App\Domain\Intelligence\LabelChunkWriter;
use App\Domain\Research\DatasetSnapshotBuilder;
use App\Domain\Research\SemanticLabels;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\IntelligenceFixtures;
use Tests\Support\TestEnvironment;

require dirname(__DIR__, 2).'/vendor/autoload.php';
TestEnvironment::prepare();
[$script, $directory, $kind, $mode] = $argv;
if (! is_dir($directory) || ! in_array($kind, ['labels', 'snapshot'], true)
    || ! in_array($mode, ['kill', 'resume', 'reference'], true)) {
    throw new InvalidArgumentException('Invalid crash fixture arguments.');
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->afterBootstrapping(LoadConfiguration::class, static function ($app): void {
    TestEnvironment::guard($app['config']);
});
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $directory.'/db.sqlite',
    'database.connections.sqlite.foreign_key_constraints' => true,
    'cache.default' => 'database', 'cache.stores.database.connection' => 'sqlite', 'cache.stores.database.lock_connection' => 'sqlite',
    'queue.default' => 'sync', 'logging.default' => 'null', 'archive.enabled' => false,
    'intelligence.lookback' => 3, 'intelligence.min_horizon_distance_observations' => 1,
    'research.path' => $directory.'/research', 'research.checkpoint_rows' => 128,
    'exchange_fees.taker_overrides.kraken.rate' => 0.001]);
DB::purge();
if ($mode === 'kill') {
    touch($directory.'/db.sqlite');
    Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
    IntelligenceFixtures::candles(1300);
    Event::listen('knowledge.checkpoint.saved', static function (string $key, array $state) use ($kind): void {
        if (($kind === 'labels' && $state['stage'] === 'action_passes' && ($state['action_pass'] ?? 0) === 3
                && ($state['action_pass_state']['position'] ?? 0) >= 600)
            || ($kind === 'snapshot' && $state['stage'] === 'snapshot_rows' && ($state['snapshot']['count'] ?? 0) >= 600)) {
            posix_kill(getmypid(), SIGKILL);
        }
    });
} elseif ($mode === 'resume') {
    // Simulate elapsed lease time after a dead worker, without a blocking sleep.
    DB::table('cache_locks')->update(['expiration' => time() - 1]);
}
$asOf = IntelligenceFixtures::START + 1400 * 60000 - ($mode === 'reference' ? 1 : 0);
if ($kind === 'labels') {
    $set = app(AlgorithmicLabelBuilder::class)->build('kraken', 'BTC/USD', '1m', $asOf);
    $hashes = [];
    foreach (LabelChunkWriter::KINDS as $stream) {
        $hash = hash_init('sha256');
        foreach ($set[$stream] as $at => $row) {
            hash_update($hash, $at.':'.json_encode($row, JSON_THROW_ON_ERROR)."\n");
        }
        $hashes[$stream] = hash_final($hash);
    }
    echo json_encode($hashes, JSON_THROW_ON_ERROR);
} else {
    $manifest = app(DatasetSnapshotBuilder::class)->build('kraken', 'BTC/USD', '1m', new SemanticLabels(2, 3), asOfMs: $asOf);
    echo json_encode(['sha256' => $manifest['rows_sha256'], 'rows' => $manifest['rows']], JSON_THROW_ON_ERROR);
}
