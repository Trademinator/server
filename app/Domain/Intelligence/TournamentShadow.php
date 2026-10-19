<?php

namespace App\Domain\Intelligence;

use App\Domain\Research\DatasetStore;
use App\Domain\Research\FeatureSchema;
use App\Domain\Features\FeatureEngine;
use App\Models\MarketFeature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Rubix\ML\Classifiers\ClassificationTree;
use Rubix\ML\Classifiers\RandomForest;
use Rubix\ML\Classifiers\MultilayerPerceptron;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Datasets\Unlabeled;
use Rubix\ML\NeuralNet\Layers\Dense;
use Rubix\ML\NeuralNet\Layers\Activation;
use Rubix\ML\NeuralNet\ActivationFunctions\ReLU;
use RuntimeException;

/** Research only. Does not write intelligence_heads, market_signals, or client decisions. */
final class TournamentShadow
{
    public function __construct(private DatasetStore $datasets, private FinalizedShadowLabels $finalized) {}

    private function path(string $id): string
    {
        if (! Str::isUuid($id)) throw new RuntimeException('Invalid shadow ID.');
        return rtrim(config('intelligence.path'), '/').'/shadow-'.$id.'.model';
    }

    public function publish(string $datasetId): array
    {
        $record = DB::table('intelligence_tournaments')->where('dataset_id', $datasetId)->first();
        if (!$record) throw new RuntimeException('Tournament results missing.');
        $report = json_decode($record->report, true, flags: JSON_THROW_ON_ERROR);
        if (($report['version'] ?? null) !== ResearchTournament::VERSION) {
            throw new RuntimeException('Rebuild the tournament with the current evaluator first.');
        }
        [$manifest, $dataset] = $this->datasets->open($datasetId);
        $now = now()->getTimestampMs();
        $created = [];
        foreach (['outcome', 'action'] as $task) {
            $name = $report['tasks'][$task]['research_leader'] ?? null;
            if (!in_array($name, ResearchTournament::CANDIDATES, true)) continue;
            if (!(bool)($report['tasks'][$task]['candidates'][$name]['eligible'] ?? false)) continue;
            if (DB::table('tournament_shadow_models')->where('dataset_id', $datasetId)
                ->where('task', $task)->where('algorithm', $name)->exists()) continue;
            $cut = (int)floor(count($dataset)*0.8);
            $cutoff = $dataset->at($cut)['decision_at_ms'];
            $training = [];
            foreach ($dataset->slice(0, $cut) as $row) {
                $labelTime = (int)($row[$task === 'action' ? 'action_label_available_at_ms' : 'label_available_at_ms']
                    ?? $row['label_available_at_ms']);
                $label = $row[$task === 'action' ? 'action_label' : 'label'] ?? null;
                if ($labelTime >= $cutoff || $labelTime > $now || !is_string($label)) continue;
                $training[] = ['vector' => NormalizedVector::from($row['vector'], $manifest['keys']),
                    'label' => $label, 'decision_at_ms' => (int)$row['decision_at_ms'],
                    'label_available_at_ms' => $labelTime, 'feature_weights' => $row['feature_weights'] ?? []];
            }
            if (count($training) < 20 || count(array_unique(array_column($training, 'label'))) < 2) continue;
            // Rubix warns when optional native Tensor acceleration is absent.
            // Laravel promotes this advisory warning to an exception. Scope the
            // handler over *construction and training*, and always restore it.
            $fallback = $name === 'neural_network' && !extension_loaded('tensor');
            if ($fallback) {
                set_error_handler(static function (int $level, string $message): bool {
                    if ($level === E_USER_WARNING
                        && str_contains($message, 'Tensor C extension is not loaded')) return true;
                    return false;
                });
            }
            try {
                $estimator = match ($name) {
                    'decision_tree' => new ClassificationTree(8, 6),
                    'random_forest' => new RandomForest(new ClassificationTree(8, 6), 12, 0.7, false),
                    'neural_network' => new MultilayerPerceptron(
                        [new Dense(12), new Activation(new ReLU()), new Dense(8), new Activation(new ReLU())],
                        batchSize:32, epochs:12, window:3),
                    default => null,
                };
                if ($estimator) {
                    $estimator->train(new Labeled(array_column($training, 'vector'),
                        array_column($training, 'label')));
                }
            } finally {
                if ($fallback) restore_error_handler();
            }
            $id = (string)Str::uuid7();
            $blob = serialize(['algorithm'=>$name, 'task'=>$task, 'keys'=>$manifest['keys'],
                'training'=>$name === 'weighted_knn' ? $training : [],
                'estimator'=>$estimator, 'k'=>min(9,count($training)), 'trained_cutoff'=>$cutoff]);
            $directory = dirname($this->path($id));
            if (!is_dir($directory)) mkdir($directory,0700,true);
            $temporary = $this->path($id).'.tmp';
            if (file_put_contents($temporary,$blob,LOCK_EX) !== strlen($blob)) throw new RuntimeException('Shadow artifact write failed.');
            chmod($temporary,0600);
            rename($temporary,$this->path($id));
            DB::table('tournament_shadow_models')->insert([
                'shadow_model_id'=>$id,'dataset_id'=>$datasetId,'market_key'=>$record->market_key,
                'task'=>$task,'algorithm'=>$name,'version'=>ResearchTournament::VERSION,
                'sha256'=>hash('sha256',$blob),'trained_until_ms'=>$cutoff,
                'published_at_ms'=>$now,'metadata'=>json_encode([
                    'exchange'=>$manifest['exchange'],'symbol'=>$manifest['symbol'],'period'=>$manifest['period'],
                    'schema'=>$manifest['schema'], 'training_rows'=>count($training)],JSON_THROW_ON_ERROR),
                'created_at'=>now(),'updated_at'=>now()]);
            $created[] = $id;
        }
        return $created;
    }

    public function predict(string $modelId): ?array
    {
        $model = DB::table('tournament_shadow_models')->where('shadow_model_id',$modelId)->first();
        if (!$model) throw new RuntimeException('Shadow model not found.');
        if ($model->version !== ResearchTournament::VERSION) return null;
        $meta=json_decode($model->metadata,true,flags:JSON_THROW_ON_ERROR);
        $feature=MarketFeature::query()->where('exchange',$meta['exchange'])
            ->where('symbol',$meta['symbol'])->where('period',$meta['period'])
            ->where('version', FeatureEngine::VERSION)
            ->where('available_at_ms','>',(int)$model->published_at_ms)
            ->where('available_at_ms','<=',now()->getTimestampMs())
            ->orderByDesc('microtimestamp')->first();
        if (!$feature) return null;
        $now=now()->getTimestampMs();
        $decision=(int)$feature->available_at_ms;
        if ($decision > $now || $decision <= (int)$model->published_at_ms
            || $decision <= (int)$model->trained_until_ms) return null;
        if (DB::table('tournament_shadow_predictions')->where('shadow_model_id',$modelId)
            ->where('decision_at_ms',$decision)->exists()) return null;
        $blob=file_get_contents($this->path($modelId));
        if ($blob===false || !hash_equals($model->sha256,hash('sha256',$blob)))
            throw new RuntimeException('Shadow artifact integrity failure.');
        $artifact=unserialize($blob,['allowed_classes'=>true]);
        $payload=$feature->payload;
        if (($payload['version'] ?? null) !== FeatureEngine::VERSION
            || (int)($payload['microtimestamp'] ?? -1) !== (int)$feature->microtimestamp
            || (int)($payload['available_at_ms'] ?? -1) !== $decision
            || $feature->version !== FeatureEngine::VERSION) return null;
        $vector=FeatureSchema::vector($payload, $artifact['keys']);
        if ($vector === null) return null;
        $vector=NormalizedVector::from($vector,$artifact['keys']);
        $probs=[];
        if ($model->algorithm === 'weighted_knn') {
            $knn=new WeightedKnn();
            $training=$artifact['training'];
            $neighbors=$knn->neighbors($training,$vector,(int)$artifact['k'],$decision);
            $result=$model->task==='action' ? $knn->vote($neighbors,(int)$artifact['k'])
                : (new OutcomeKnn(config('intelligence.knn')))->vote($neighbors,(int)$artifact['k']);
            $probs=$result['votes'] ?? [];
            $supported=($result['reason']??'')==='supported';
            $reason=$result['reason']??'insufficient_evidence';
        } else {
            $probs=$artifact['estimator']->proba(new Unlabeled([$vector]))[0] ?? [];
            arsort($probs);
            $supported=isset($probs[array_key_first($probs)]) && (float)reset($probs)>=0.6;
            $reason=$supported?'supported':'weak_consensus';
        }
        arsort($probs);
        $prediction=$supported ? array_key_first($probs) : null;
        $confidence=$probs ? (float)reset($probs) : 0.0;
        $id=(string)Str::uuid7();
        $inserted=DB::table('tournament_shadow_predictions')->insertOrIgnore([
            'shadow_prediction_id'=>$id,'shadow_model_id'=>$modelId,'market_key'=>$model->market_key,
            'decision_at_ms'=>$decision,'predicted_at_ms'=>$now,'prediction'=>$prediction,
            'supported'=>$supported,'confidence'=>$confidence,'probabilities'=>json_encode($probs,JSON_THROW_ON_ERROR),
            'reason'=>$reason,'feature_sha256'=>hash('sha256',json_encode($vector,JSON_THROW_ON_ERROR)),
            'created_at'=>now(),'updated_at'=>now()]);
        return $inserted ? ['id'=>$id,'prediction'=>$prediction,'reason'=>$reason] : null;
    }

    public function score(string $modelId): int
    {
        $model=DB::table('tournament_shadow_models')->where('shadow_model_id',$modelId)->first();
        if (!$model) throw new RuntimeException('Shadow model not found.');
        $meta=json_decode($model->metadata,true,flags:JSON_THROW_ON_ERROR);
        $asOfMs = now()->getTimestampMs();
        $pending = DB::table('tournament_shadow_predictions as p')
            ->leftJoin('tournament_shadow_scores as s', 's.shadow_prediction_id', '=', 'p.shadow_prediction_id')
            ->where('p.shadow_model_id', $modelId)->whereNull('s.shadow_prediction_id')
            ->where('p.decision_at_ms', '<', $asOfMs)
            ->select('p.*')->orderBy('p.decision_at_ms')->limit(500)->get();
        if ($pending->isEmpty()) return 0;

        $actionLabels = [];
        if ($model->task === 'action') {
            $actionLabels = $this->finalized->actions($meta['exchange'], $meta['symbol'], $meta['period'], $asOfMs);
        } elseif ($model->task !== 'outcome') {
            throw new RuntimeException('Unknown shadow model task.');
        }
        $manifest = $this->datasets->manifest($model->dataset_id);
        $horizon = (int)($manifest['label_definition']['horizon'] ?? 0);
        $lookback = (int)($manifest['label_definition']['lookback'] ?? 0);
        $count = 0;
        foreach ($pending as $prediction) {
            $label = $model->task === 'action'
                ? ($actionLabels[(int)$prediction->decision_at_ms] ?? null)
                : $this->finalized->outcome($meta['exchange'], $meta['symbol'], $meta['period'],
                    (int)$prediction->decision_at_ms, $horizon, $lookback, $asOfMs);
            if ($label === null || $label['available_at_ms'] > $asOfMs) continue;
            // A label may have been finalized before a late-running inference job.
            // It is still a valid score because the prediction is immutable and
            // refers to a candle after the model was published.
            $actual = $label['actual'];
            $count += DB::table('tournament_shadow_scores')->insertOrIgnore([
                'shadow_prediction_id' => $prediction->shadow_prediction_id,
                'actual' => $actual,
                'label_available_at_ms' => $label['available_at_ms'],
                'scored_at_ms' => $asOfMs,
                'correct' => $prediction->supported ? (bool)($actual === $prediction->prediction) : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $count;
    }
}
