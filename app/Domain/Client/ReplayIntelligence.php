<?php

namespace App\Domain\Client;

use App\Domain\Features\FeatureEngine;
use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\MarketIntelligence;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Intelligence\NormalizedVector;
use App\Domain\Intelligence\OutcomeKnn;
use App\Domain\Intelligence\PatternCatalog;
use App\Domain\Intelligence\ResearchTournament;
use App\Domain\Intelligence\WeightedKnn;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\Research\FeatureSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Rubix\ML\Datasets\Unlabeled;
use RuntimeException;

final class ReplayIntelligence
{
    public function __construct(private FeatureEngine $features, private ModelStore $models, private MarketIntelligence $intelligence) {}

    public function infer(array $data, string $symbol): array
    {
        $period = $data['period'];
        $at = (int) $data['decision_at_ms'];
        $timeframe = new CandleTimeframe;
        $rows = [];
        $previous = null;
        foreach ($data['candles'] as $candle) {
            $timestamp = $candle[0];
            if ($timeframe->next($timestamp, $period) > $at
                || ($previous !== null && $timestamp !== $timeframe->next($previous, $period))) {
                throw ValidationException::withMessages(['candles' => 'Use ascending, contiguous, completed candles at the declared interval.']);
            }
            $rows[] = ['microtimestamp' => $timestamp, 'open' => $candle[1], 'high' => $candle[2], 'low' => $candle[3], 'close' => $candle[4], 'volume' => $candle[5]];
            $previous = $timestamp;
        }
        if ($timeframe->next($previous, $period) !== $at) {
            throw ValidationException::withMessages(['decision_at_ms' => 'The decision must be the last supplied candle close.']);
        }
        $key = ModelStore::marketKey($data['model_exchange'], $symbol, $period);
        $family = $data['family'];
        $table = $family === 'production' ? 'intelligence_models' : 'tournament_shadow_models';
        $column = $family === 'production' ? 'model_id' : 'shadow_model_id';
        $record = DB::table($table)->where($column, $data['model_id'])->where('market_key', $key)->first();
        abort_if($record === null, 404, 'Unknown model for this pair, period and exchange.');
        $published = $family === 'production' ? (int) (strtotime($record->created_at) * 1000) : (int) $record->published_at_ms;
        $historical = $data['intelligence'] === 'historical';
        if ($historical && $published > $at) {
            throw ValidationException::withMessages(['model_id' => 'This model was not published at the replay time.']);
        }
        $payloads = [];
        foreach ($this->features->rows($rows, $period, $at) as $row) {
            $payloads[] = ['version' => FeatureEngine::VERSION, ...$row];
            if (count($payloads) > 2) {
                array_shift($payloads);
            }
        }
        $payload = end($payloads);
        $context = ['exchange' => $data['source_exchange'], 'symbol' => $symbol, 'period' => $period,
            'model_id' => $data['model_id'], 'decision_at_ms' => $at, 'reference_price' => (string) end($rows)['close'],
            'reference_price_source' => 'supplied_closed_candle', 'prediction_input_basis' => 'supplied_ohlcv',
            'regime' => 'neutral', 'lead_lag' => [], 'patterns' => [], 'patterns_evaluated' => false];
        $inferenceAt = $historical ? $at : now()->getTimestampMs();
        if ($family === 'production') {
            $model = $this->models->forPrediction($data['model_id']);
            if (($model['validation_version'] ?? null) !== IntelligenceTrainer::VERSION
                || ($model['feature_version'] ?? null) !== FeatureEngine::VERSION
                || ($model['normalization'] ?? null) !== NormalizedVector::VERSION
                || ($model['patterns']['version'] ?? null) !== PatternCatalog::VERSION
                || ! FeatureSchema::modelCompatible($model)) {
                throw ValidationException::withMessages(['model_id' => 'Rebuild this model with the current schema and evaluator.']);
            }
            if ($historical && max((int) $model['trained_as_of_ms'], (int) $model['available_at_ms']) >= $at) {
                throw ValidationException::withMessages(['model_id' => 'The historical model must precede the supplied decision.']);
            }
            if (($model['lead_lag_keys'] ?? []) !== []) {
                $result = [...WeightedKnn::abstain('replay_lead_lag_context_unavailable'), ...$context];
            } else {
                $history = [];
                foreach ($payloads as $i => $item) {
                    $history[] = ['features' => $item['features'], 'candle' => $rows[count($rows) - count($payloads) + $i]];
                }
                $context['horizon_candles'] = $model['label_definition']['horizon'] ?? null;
                $result = $this->intelligence->inferPayload($model, $payload, $history, $inferenceAt, $context);
            }
            $trained = (int) $model['trained_as_of_ms'];
        } else {
            if ($record->version !== ResearchTournament::VERSION) {
                throw ValidationException::withMessages(['model_id' => 'Shadow evaluator version mismatch.']);
            }
            $path = rtrim(config('intelligence.path'), '/').'/shadow-'.$data['model_id'].'.model';
            $bytes = is_file($path) ? file_get_contents($path) : false;
            if ($bytes === false || ! hash_equals($record->sha256, hash('sha256', $bytes))) {
                throw new RuntimeException('Replay artifact integrity failure.');
            }
            $model = unserialize($bytes, ['allowed_classes' => true]);
            $trained = (int) $record->trained_until_ms;
            if ($historical && $trained >= $at) {
                throw ValidationException::withMessages(['model_id' => 'The historical model training must precede the decision.']);
            }
            $vector = FeatureSchema::vector($payload, $model['keys']);
            if ($vector === null) {
                $result = WeightedKnn::abstain('missing_selected_features');
            } else {
                $vector = NormalizedVector::from($vector, $model['keys']);
                if ($record->algorithm === 'weighted_knn') {
                    $knn = new WeightedKnn;
                    $neighbors = $knn->neighbors($model['training'], $vector, $model['k'], $inferenceAt);
                    $result = $record->task === 'action' ? $knn->vote($neighbors, $model['k']) : (new OutcomeKnn(config('intelligence.knn')))->vote($neighbors, $model['k']);
                } else {
                    $probabilities = $model['estimator']->proba(new Unlabeled([$vector]))[0] ?? [];
                    arsort($probabilities);
                    $confidence = (float) (reset($probabilities) ?: 0);
                    $supported = $confidence >= 0.6;
                    $result = ['action' => $record->task === 'action' && $supported ? array_key_first($probabilities) : 'hodl',
                        'outcome' => $record->task === 'outcome' && $supported ? array_key_first($probabilities) : 'neutral',
                        'confidence' => $confidence, 'reason' => $supported ? 'supported' : 'weak_consensus', 'votes' => $probabilities];
                }
                // An Outcome classifier alone is not an Action decision.
                if ($record->task === 'outcome') {
                    $result['action'] = 'hodl';
                    $result['reason'] = 'outcome_only_requires_action';
                }
            }
            $result = [...$result, ...$context, 'task' => $record->task, 'algorithm' => $record->algorithm];
        }

        return ['api_version' => 1, 'signal' => $result, 'provenance' => [
            'engine' => 'm4-replay-v1', 'source_exchange' => $data['source_exchange'], 'model_exchange' => $data['model_exchange'],
            'intelligence' => $data['intelligence'], 'family' => $family, 'model_id' => $data['model_id'], 'model_sha256' => $record->sha256,
            'feature_version' => FeatureEngine::VERSION, 'input_sha256' => hash('sha256', json_encode($data['candles'], JSON_THROW_ON_ERROR)),
            'published_at_ms' => $published, 'trained_until_ms' => $trained,
            'out_of_sample' => $historical && $trained < $at, 'current_model_on_historical_data' => ! $historical && $trained >= $at,
        ]];
    }
}
