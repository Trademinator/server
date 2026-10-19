<?php

use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Intelligence\ResearchTournament;
use App\Models\ClientApiKey;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketSubscription;
use App\Models\Ticker;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Rubix\ML\Classifiers\ClassificationTree;
use Rubix\ML\Classifiers\MultilayerPerceptron;
use Rubix\ML\Classifiers\RandomForest;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\NeuralNet\ActivationFunctions\ReLU;
use Rubix\ML\NeuralNet\Layers\Activation;
use Rubix\ML\NeuralNet\Layers\Dense;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    $this->travelTo('2026-10-19 06:00:00 UTC');
    config(['client.enabled' => true, 'intelligence.path' => sys_get_temp_dir().'/m4-api-'.Str::uuid()]);
});
afterEach(function () {
    File::deleteDirectory(config('intelligence.path'));
});

function m4ReplayAccount(): array
{
    $user = User::factory()->create();
    $secret = 'tmk_'.Str::random(43);
    ClientApiKey::query()->create(['user_id' => $user->getKey(), 'label' => 'm4', 'prefix' => substr($secret, 0, 12), 'secret_hash' => hash('sha256', $secret)]);
    $exchange = Exchange::query()->firstOrCreate(['class' => 'bitso'], ['name' => 'Bitso', 'config' => '{}']);
    $market = Market::query()->firstOrCreate(['exchange_id' => $exchange->getKey(), 'symbol' => 'BTC/USD'], ['tick_size' => '1']);
    $sub = MarketSubscription::query()->create(['user_id' => $user->getKey(), 'market_id' => $market->getKey(), 'active' => true]);

    return [$secret, $sub];
}
function m4ReplayModel(string $algorithm = 'decision_tree', string $symbol = 'BTC/USD'): string
{
    $samples = [];
    $labels = [];
    $training = [];
    for ($i = 0; $i < 20; $i++) {
        $value = $i < 10 ? 0.0 : 1.0;
        $label = $i < 10 ? 'sell' : 'buy';
        $samples[] = [$value];
        $labels[] = $label;
        $training[] = ['vector' => [$value], 'label' => $label, 'decision_at_ms' => 1000 + $i, 'label_available_at_ms' => 2000 + $i];
    }
    $estimator = match ($algorithm) {
        'decision_tree' => new ClassificationTree(3, 2),'random_forest' => new RandomForest(new ClassificationTree(3, 2), 3, 0.8, false),default => null
    };
    $estimator?->train(new Labeled($samples, $labels));
    $id = (string) Str::uuid();
    $dataset = (string) Str::uuid();
    $blob = serialize(['algorithm' => $algorithm, 'task' => 'action', 'keys' => ['candle.direction'], 'training' => $training, 'estimator' => $estimator, 'k' => 5]);
    File::ensureDirectoryExists(config('intelligence.path'));
    file_put_contents(config('intelligence.path').'/shadow-'.$id.'.model', $blob);
    DB::table('research_datasets')->insert(['dataset_id' => $dataset, 'manifest' => '{}', 'created_at' => now()]);
    DB::table('tournament_shadow_models')->insert(['shadow_model_id' => $id, 'dataset_id' => $dataset, 'market_key' => ModelStore::marketKey('bitso', $symbol, '1m'),
        'task' => 'action', 'algorithm' => $algorithm, 'version' => ResearchTournament::VERSION, 'sha256' => hash('sha256', $blob), 'trained_until_ms' => 60000,
        'published_at_ms' => 120000, 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}
function m4ReplayInput(string $id): array
{
    return ['source_exchange' => 'binance', 'model_exchange' => 'bitso', 'period' => '1m', 'model_id' => $id, 'family' => 'shadow', 'intelligence' => 'historical', 'decision_at_ms' => 240000,
        'candles' => [[120000, '100', '102', '99', '101', '10'], [180000, '101', '103', '100', '102', '10']]];
}
it('requires authentication and hides other subscribers replay data', function () {
    [$token,$sub] = m4ReplayAccount();
    [$otherToken] = m4ReplayAccount();
    $path = '/api/v1/client/markets/'.$sub->getKey().'/replay/models?model_exchange=bitso&period=1m';
    $this->getJson($path)->assertUnauthorized();
    $this->withToken($otherToken)->getJson($path)->assertNotFound();
    $this->withToken($token)->getJson($path)->assertOk()->assertJsonPath('models', []);
});
it('runs supplied OHLCV inference across exchanges without production writes', function (string $algorithm) {
    [$token,$sub] = m4ReplayAccount();
    $id = m4ReplayModel($algorithm);
    $path = '/api/v1/client/markets/'.$sub->getKey().'/replay/infer';
    $input = m4ReplayInput($id);
    $this->withToken($token)->postJson($path, $input)->assertOk()->assertJsonPath('signal.action', 'buy')->assertJsonPath('provenance.out_of_sample', true)->assertJsonPath('provenance.source_exchange', 'binance');
    $input['candles'][1] = [180000, '101', '102', '98', '99', '10'];
    $this->postJson($path, $input)->assertOk()->assertJsonPath('signal.action', 'sell');
    foreach (['market_features', 'market_signals', 'intelligence_heads', 'tournament_shadow_predictions'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
})->with(['decision_tree', 'random_forest', 'weighted_knn']);
it('rejects future models historically and labels current replay', function () {
    [$token,$sub] = m4ReplayAccount();
    $id = m4ReplayModel();
    DB::table('tournament_shadow_models')->where('shadow_model_id', $id)->update(['published_at_ms' => 300000, 'trained_until_ms' => 270000]);
    $path = '/api/v1/client/markets/'.$sub->getKey().'/replay/infer';
    $input = m4ReplayInput($id);
    $this->withToken($token)->postJson($path, $input)->assertUnprocessable()->assertJsonValidationErrors('model_id');
    $input['intelligence'] = 'current';
    $this->postJson($path, $input)->assertOk()->assertJsonPath('provenance.out_of_sample', false)->assertJsonPath('provenance.current_model_on_historical_data', true)->assertJsonPath('signal.action', 'buy');
});
it('rejects incomplete unordered gapped and invalid candles', function (string $case) {
    [$token,$sub] = m4ReplayAccount();
    $input = m4ReplayInput(m4ReplayModel());
    if ($case === 'future') {
        $input['candles'][1][0] = 240000;
    }if ($case === 'duplicate') {
        $input['candles'][1][0] = 120000;
    }if ($case === 'gap') {
        $input['candles'][0][0] = 60000;
    }
    if ($case === 'bounds') {
        $input['candles'][1][2] = '1';
    }if ($case === 'partial') {
        $input['decision_at_ms'] = 239999;
    }
    $this->withToken($token)->postJson('/api/v1/client/markets/'.$sub->getKey().'/replay/infer', $input)->assertUnprocessable();
    $this->assertDatabaseCount('market_signals', 0);
})->with(['future', 'duplicate', 'gap', 'bounds', 'partial']);
it('rejects a model for another symbol', function () {
    [$token,$sub] = m4ReplayAccount();
    $id = m4ReplayModel(symbol: 'ETH/USD');
    $this->withToken($token)->postJson('/api/v1/client/markets/'.$sub->getKey().'/replay/infer', m4ReplayInput($id))->assertNotFound();
});
it('exports closed OHLCV with pagination and gap provenance', function () {
    [$token,$sub] = m4ReplayAccount();
    foreach ([60000, 120000, 240000] as $t) {
        Ticker::query()->create(['exchange' => 'binance', 'symbol' => 'BTC/USD', 'period' => '1m', 'microtimestamp' => $t, 'payload' => json_encode(['microtimestamp' => $t, 'open' => '100', 'high' => '102', 'low' => '99', 'close' => '101', 'volume' => '10'])]);
    }
    $path = '/api/v1/client/markets/'.$sub->getKey().'/replay/history?source_exchange=binance&period=1m&from_ms=60000&to_ms=300000&limit=1';
    $this->withToken($token)->getJson($path)->assertOk()->assertJsonCount(1, 'candles')->assertJsonPath('next_from_ms', 120000);
    $this->getJson(str_replace('from_ms=60000', 'from_ms=180000', $path))->assertOk()->assertJsonPath('gaps.0.from_ms', 180000)->assertJsonPath('gaps.0.to_ms', 240000)->assertJsonPath('execution_detail', 'ohlcv_approximate');
});

it('uses the frozen neural network estimator for supplied candles', function () {
    [$token,$sub] = m4ReplayAccount();
    $id = m4ReplayModel();
    set_error_handler(static fn (int $level, string $message): bool => $level === E_USER_WARNING && str_contains($message, 'Tensor C extension is not loaded'));
    try {
        $network = new MultilayerPerceptron([new Dense(4), new Activation(new ReLU)], batchSize: 4, epochs: 2, window: 1);
        $network->train(new Labeled([[0.0], [0.0], [1.0], [1.0]], ['sell', 'sell', 'buy', 'buy']));
    } finally {
        restore_error_handler();
    }
    $blob = serialize(['algorithm' => 'neural_network', 'task' => 'action', 'keys' => ['candle.direction'], 'training' => [], 'estimator' => $network, 'k' => 5]);
    file_put_contents(config('intelligence.path').'/shadow-'.$id.'.model', $blob);
    DB::table('tournament_shadow_models')->where('shadow_model_id', $id)->update(['algorithm' => 'neural_network', 'sha256' => hash('sha256', $blob)]);
    $result = $this->withToken($token)->postJson('/api/v1/client/markets/'.$sub->getKey().'/replay/infer', m4ReplayInput($id))->assertOk()->assertJsonPath('signal.algorithm', 'neural_network')->json();
    expect(array_sum($result['signal']['votes']))->toBeGreaterThan(0.99)->toBeLessThan(1.01);
    expect($result['signal']['action'])->toBeIn(['buy', 'sell', 'hodl']);
});

it('loads pinned production models with streamed knowledge and reuses production inference', function () {
    [$token,$sub] = m4ReplayAccount();
    $this->travelTo('2024-01-01 04:10:00 UTC');
    config(['research.path' => config('intelligence.path').'/research']);
    $manifest = IntelligenceFixtures::snapshot();
    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    $model = app(ModelStore::class)->forPrediction($report['model_id']);
    expect($model)->not->toHaveKey('knowledge');
    $input = m4ReplayInput($report['model_id']);
    $input['family'] = 'production';
    $input['model_exchange'] = 'kraken';
    $input['intelligence'] = 'current';
    $this->withToken($token)->postJson('/api/v1/client/markets/'.$sub->getKey().'/replay/infer',$input)->assertOk()->assertJsonPath('signal.model_id',$report['model_id'])->assertJsonPath('provenance.out_of_sample',false);
    $this->assertDatabaseCount('market_signals',0);
});
