<?php

use App\Domain\Features\FeatureBuilder;
use App\Domain\MarketData\CandlePeriodReevaluation;
use App\Domain\MarketData\ExchangeMetadata;
use App\Domain\MarketData\MarketCatalog;
use App\Domain\MarketData\MarketDataSynchronizer;
use App\Domain\MarketSuggestions\Questionnaire;
use App\Jobs\CollectMarketFeed;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketPreferenceProfile;
use App\Models\MarketSubscription;
use App\Models\User;
use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;
use Database\Seeders\ExchangeSeeder;
use Illuminate\Support\Facades\Cache;
use PHPUnit\TextUI\Configuration\Builder;
use PHPUnit\TextUI\Configuration\PhpHandler;
use Tests\Support\FixtureBinance;
use Tests\TestCase;

require dirname(__DIR__, 2).'/vendor/autoload.php';

// This standalone probe creates Tests\TestCase outside the PHPUnit runner.
// PHPUnit 12 requires its configuration registry to exist before Laravel's
// testing lifecycle is initialized. Load the same phpunit.xml environment
// used by the normal test suite, while preserving this probe's 128M limit.
$memoryLimit = ini_get('memory_limit');

$phpunitConfiguration = (new Builder)->build([
    'phpunit',
    '--configuration',
    dirname(__DIR__, 2).'/phpunit.xml',
]);

(new PhpHandler)
    ->handle($phpunitConfiguration->php());

ini_set('memory_limit', $memoryLimit);

$probe = new class('testProbe') extends TestCase
{
    public function test_probe(): void {}

    public function runProbe(bool $collector): void
    {
        $this->setUp(); // Mandatory in-memory guard; never use a deployment database.
        $path = tempnam(sys_get_temp_dir(), 'binance-catalogue-');
        try {
            $this->artisan('migrate', ['--force' => true])->assertSuccessful();
            $this->seed(ExchangeSeeder::class);

            Cache::forget(
                MarketCatalog::EXCHANGES_CACHE
            );

            $user = User::factory()->create();
            if (! $collector) {
                $this->actingAs($user)->get(route('markets.index'))->assertOk()
                    ->assertSee('<option value="binance"', false)
                    ->assertDontSee('<option value="apex"', false);
            }
            $listPeak = memory_get_peak_usage(true);
            FixtureBinance::writeResponse($path, 5000);
            FixtureBinance::$responsePath = $path;
            $exchange = Exchange::query()->where('class', 'binance')->firstOrFail();
            $exchange->update(['config' => json_encode([
                'apiKey' => 'fixture-key', 'secret' => 'fixture-secret',
                'options' => ['fetchMarkets' => ['types' => ['spot', 'linear', 'inverse']], 'loadAllOptions' => true],
            ])]);
            $originalConfig = $exchange->config;
            $repository = new class extends ExchangeRepository
            {
                public function setExchange(Exchange $exchange, array $extraSettings = [], ?User $user = null, ?string $symbol = null): void
                {
                    if ($this->exchange?->getKey() === $exchange->getKey() && $this->ccxtExchange instanceof FixtureBinance) {
                        return;
                    }
                    $this->exchange = $exchange;
                    $this->ccxtExchange = new FixtureBinance(json_decode($exchange->config, true));
                    $this->tickerRepository->setExchange($this->ccxtExchange);
                }
            };
            app()->instance(ExchangeRepository::class, $repository);
            if (! $collector) {
                $this->getJson(route('markets.options', 'binance'))->assertOk()
                    ->assertJsonCount(5000, 'symbols')->assertJsonPath('symbols.0.value', 'BTC/USDT')
                    ->assertJsonPath('symbols.0.tick_size', '0.01');
                $this->post(route('markets.store'), ['exchange' => 'binance', 'symbol' => 'BTC/USDT'])
                    ->assertRedirect(route('markets.index'));
                $this->assertSame(1, MarketSubscription::query()->count());
                $this->assertSame(1, FixtureBinance::$requests);
                $this->assertSame($originalConfig, $exchange->fresh()->config);
                $client = (new ReflectionProperty(ExchangeRepository::class, 'ccxtExchange'))->getValue($repository);
                $this->assertEmpty($client->markets);
                $this->assertNull($client->last_json_response);
                $this->assertNull($client->last_http_response);
                MarketPreferenceProfile::query()->create(['user_id' => $user->user_id, 'answers' => array_replace(Questionnaire::defaults(), [
                    'country' => 'CA', 'region' => 'ON', 'exchange' => 'binance', 'holdings' => [['asset' => 'USDT', 'band' => '100_500']],
                ])]);
                // Missing regional/history evidence stays exploratory; no claim of Ontario eligibility.
                $this->get(route('markets.suggestions', ['show' => 1]))->assertOk()->assertSee('Explore only');
                $this->assertSame(1, MarketSubscription::query()->count());
                $this->assertSame(1, FixtureBinance::$requests);
            } else {
                $market = Market::query()->create(['exchange_id' => $exchange->getKey(), 'symbol' => 'BTC/USDT', 'tick_size' => '0.01']);
                // This probe measures collector/feature memory, not automatic period
                // reevaluation. Give the feed the already-selected period that the
                // collector would have after evaluation completes.
                MarketFeed::query()->create(['market_id' => $market->getKey(), 'selected_period' => '1m', 'status' => 'pending']);
                MarketSubscription::query()->create(['user_id' => $user->getKey(), 'market_id' => $market->getKey(), 'active' => true]);
                // Run real collector and feature services in a fresh worker-sized process.

                // The legacy inline selector used to leave a few hundred candles in
                // storage. Seed the equivalent history through the real bounded
                // synchronizer so the feature-memory assertion still exercises >200 rows.
                $to = time() - 60;
                app(MarketDataSynchronizer::class)->sync('binance', 'BTC/USDT', '1m', $to - 240 * 60, $to, false, false, 90);

                for ($i = 0; $i < 3; $i++) {
                    $market->feed->update(['lease_token' => 'memory-pass-'.$i, 'lease_until' => now()->addMinutes(15)]);
                    (new CollectMarketFeed($market->getKey(), 'memory-pass-'.$i))->handle(app(CandlePeriodReevaluation::class),
                        app(MarketDataSynchronizer::class), app(TickerRepository::class), app(ExchangeMetadata::class));
                    $this->assertSame('ready', $market->feed->fresh()->status);
                    gc_collect_cycles();
                    gc_mem_caches();
                }
                $client = (new ReflectionProperty(ExchangeRepository::class, 'ccxtExchange'))->getValue($repository);
                $this->assertSame(['BTC/USDT'], array_keys($client->markets));
                $this->assertCount(1, $client->markets_by_id);
                $this->assertNull($client->last_json_response);
                $this->assertNull($client->last_http_response);
                $features = app(FeatureBuilder::class)->build('binance', 'BTC/USDT', '1m');
                $this->assertGreaterThan(200, $features);
            }
            echo json_encode(['exchanges' => Exchange::query()->count(), 'pairs' => 5000,
                'list_peak_bytes' => $listPeak, 'peak_bytes' => memory_get_peak_usage(true),
                'memory_limit' => ini_get('memory_limit'), 'requests' => FixtureBinance::$requests,
                'ohlcv_requests' => FixtureBinance::$ohlcvRequests, 'features' => $features ?? 0,
                'largest_candle_request' => FixtureBinance::$largestCandleRequest], JSON_THROW_ON_ERROR);
        } finally {
            unlink($path);
            // This standalone probe exits here; PHP releases its in-memory DB.
        }
    }
};
$probe->runProbe(in_array('--collector', $argv, true));
