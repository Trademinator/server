<?php

/** Isolated synthetic history: no database, network, saved labels or model writes. */

use App\Domain\Intelligence\ActionAutoLabeler;
use App\Domain\Intelligence\ActionLabelAnalysis;
use App\Domain\Intelligence\PublishedTakerFee;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\MarketData\MarketCatalog;
use App\Domain\Operations\ActionContext;
use App\Domain\Operations\ActionLog;
use App\Repositories\TickerRepository;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Psr\Log\NullLogger;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$count = filter_var($argv[1] ?? '527041', FILTER_VALIDATE_INT);
$pattern = $argv[2] ?? 'alternating';
if ($count === false || $count < 3 || $count > 600000 || ! in_array($pattern, ['alternating', 'mixed', 'flat'], true)) {
    throw new InvalidArgumentException('Expected 3..600000 rows and alternating, mixed or flat.');
}
$container = new Container;
Container::setInstance($container);
$container->instance('config', new Repository([
    'intelligence' => ['max_model_age_days' => 366, 'min_horizon_distance_observations' => 30],
    'exchange_fees' => ['taker_overrides' => ['kraken' => ['rate' => 0.0]]],
]));
$container->instance(ActionLog::class, new ActionLog(new NullLogger, new ActionContext));
$repository = new class($count, $pattern) extends TickerRepository
{
    public function __construct(private int $count, private string $pattern) {}

    public function streamHistory(string $exchange, string $symbol, string $period, ?int $fromMs = null, ?int $toMs = null): Generator
    {
        for ($i = 0; $i < $this->count; $i++) {
            $price = $this->pattern === 'mixed' ? 10000 + (($i * 31) % 601) : 10000;
            $direction = $this->pattern === 'mixed'
                ? [0, -1, -1, -1, -1, -1, 1, 1, 1, 1, 1, 0][$i % 12]
                : ($i % 2 === 0 ? 1 : -1);
            $flat = $this->pattern === 'flat' || ($this->pattern === 'mixed' && $i % 12 === 0);
            yield 1704067200000 + $i * 60000 => [
                'open' => (string) $price,
                'high' => (string) ($flat ? $price : $price + 100),
                'low' => (string) ($flat ? $price : $price - 100),
                'close' => (string) ($flat ? $price : $price + $direction * 70),
                'volume' => '1.00000000',
            ];
        }
    }
};
$catalog = (new ReflectionClass(MarketCatalog::class))->newInstanceWithoutConstructor();
$analysis = new ActionLabelAnalysis($repository, new ActionAutoLabeler, new PublishedTakerFee($catalog), new CandleTimeframe);
$started = hrtime(true);
$result = $analysis->analyze('kraken', 'BTC/USD', '1m', 1704067200000 + ($count - 1) * 60000);
$digest = hash_init('sha256');
foreach ($result['_action_labels'] as $timestamp => $action) {
    hash_update($digest, $timestamp.':'.$action.':'.($result['_action_available_at_ms'][$timestamp] ?? '-')."\n");
}
echo json_encode([
    'rows' => $count, 'pattern' => $pattern, 'memory_limit' => ini_get('memory_limit'),
    'peak_bytes' => memory_get_peak_usage(true),
    'elapsed_seconds' => round((hrtime(true) - $started) / 1e9, 3),
    'actions_sha256' => hash_final($digest),
    'diagnostics' => ActionLabelAnalysis::publicDiagnostics($result),
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
