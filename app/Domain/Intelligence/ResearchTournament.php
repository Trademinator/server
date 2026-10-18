<?php

namespace App\Domain\Intelligence;

use App\Domain\Research\DatasetStore;
use App\Domain\Research\FeatureSchema;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Rubix\ML\Classifiers\ClassificationTree;
use Rubix\ML\Classifiers\KNearestNeighbors;
use Rubix\ML\Classifiers\MultilayerPerceptron;
use Rubix\ML\Classifiers\RandomForest;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Datasets\Unlabeled;
use Rubix\ML\NeuralNet\Layers\Activation;
use Rubix\ML\NeuralNet\Layers\Dense;
use Rubix\ML\NeuralNet\ActivationFunctions\ReLU;
use Throwable;

/**
 * Shadow-only Rubix tournament. Never writes intelligence_heads or publishes
 * model artifacts. All candidates share the same time-safe, bounded sample.
 */
final class ResearchTournament
{
    public const VERSION = 'rubix-research-tournament-v2-walk-forward';
    public const CANDIDATES = ['weighted_knn', 'decision_tree', 'random_forest', 'neural_network'];

    public function __construct(private DatasetStore $datasets) {}

    public function run(string $datasetId, int $maxRows = 1200): array
    {
        if ($maxRows < 100 || $maxRows > 4000) {
            throw new InvalidArgumentException('Tournament sample cap must be between 100 and 4000.');
        }
        [$manifest, $dataset] = $this->datasets->open($datasetId);
        if (! FeatureSchema::modelCompatible(['keys' => $manifest['keys'],
            'outcome' => ['schema' => $manifest['schema']]])) {
            throw new InvalidArgumentException('Tournament requires a current feature schema.');
        }
        $rows = [];
        // All candidates see the exact same chronological tail of the frozen dataset.
        foreach ($dataset->slice(max(0, count($dataset) - $maxRows), $maxRows) as $row) {
            $vector = NormalizedVector::from($row['vector'], $manifest['keys']);
            $rows[] = ['decision_at_ms' => $row['decision_at_ms'],
                'label_available_at_ms' => $row['label_available_at_ms'],
                'action_label_available_at_ms' => $row['action_label_available_at_ms'] ?? $row['label_available_at_ms'],
                'vector' => $vector,
                'outcome' => $row['label'] ?? null,
                'action' => $row['action_label'] ?? null];
        }
        $tasks = [];
        foreach (['outcome' => ['super_bear', 'bear', 'neutral', 'bull', 'super_bull'],
            'action' => ['buy', 'hodl', 'sell']] as $task => $classes) {
            $tasks[$task] = $this->evaluateTask($rows, $task, $classes);
        }
        $report = [
            'version' => self::VERSION, 'rubix_version' => \Composer\InstalledVersions::getPrettyVersion('rubix/ml'),
            'status' => 'research_only', 'dataset_id' => $datasetId,
            'exchange' => $manifest['exchange'], 'symbol' => $manifest['symbol'], 'period' => $manifest['period'],
            'schema' => $manifest['schema'], 'source_rows' => count($dataset),
            'sampled_rows' => count($rows), 'sample_limit' => $maxRows,
            'sample_policy' => 'chronological_tail_same_for_all_candidates',
            'selection' => 'research_ranking_only_no_production_promotion',
            'tasks' => $tasks, 'generated_at' => now()->toIso8601String(),
        ];
        DB::table('intelligence_tournaments')->updateOrInsert(
            ['dataset_id' => $datasetId],
            ['market_key' => ModelStore::marketKey($manifest['exchange'], $manifest['symbol'], $manifest['period']),
                'report' => json_encode($report, JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now()]
        );

        return $report;
    }

    private function evaluateTask(array $rows, string $task, array $classes): array
    {
        $timeKey = $task === 'action' ? 'action_label_available_at_ms' : 'label_available_at_ms';
        $rows = array_values(array_filter($rows, static fn (array $row): bool =>
            in_array($row[$task], $classes, true) &&
            $row[$timeKey] > $row['decision_at_ms']));
        $count = count($rows);
        $cut = (int) floor($count * 0.8);
        $test = array_slice($rows, $cut);
        $cutoff = $test[0]['decision_at_ms'] ?? PHP_INT_MAX;
        $train = array_values(array_filter(array_slice($rows, 0, $cut),
            static fn (array $row): bool => $row[$timeKey] < $cutoff));
        $output = ['train_rows' => count($train), 'holdout_rows' => count($test),
            'holdout_from_ms' => $test[0]['decision_at_ms'] ?? null, 'candidates' => []];
        foreach (self::CANDIDATES as $name) {
            $folds = [];
            // Expanding training windows; all candidates evaluated on identical chronological segments.
            // Final 20% holdout is never used in fold selection or candidate qualification.
            $warmup = (int) floor(count($train) * 0.4);
            $step = max(1, (int) floor((count($train) - $warmup) / 3));
            for ($i = 0; $i < 3; $i++) {
                $foldEnd = $warmup + $step * $i;
                $foldTest = array_slice($train, $foldEnd, $step);
                if ($foldTest === []) continue;
                $foldCutoff = $foldTest[0]['decision_at_ms'];
                $foldTraining = array_values(array_filter(array_slice($train, 0, $foldEnd),
                    static fn (array $row): bool => $row[$timeKey] < $foldCutoff));
                $result = $this->candidate($foldTraining, $foldTest, $task, $classes, $name);
                unset($result['trace'], $result['confusion']);
                $folds[] = $result;
            }
            $holdout = $this->candidate($train, $test, $task, $classes, $name);
            $evaluated = array_values(array_filter($folds,
                static fn (array $r): bool => $r['status'] === 'evaluated'));
            $scores = array_column($evaluated, 'score');
            $coverage = count($evaluated) ? array_sum(array_column($evaluated, 'coverage')) / count($evaluated) : 0;
            $mean = $scores ? array_sum($scores) / count($scores) : 0;
            $minimum = $scores ? min($scores) : 0;
            $directional = array_sum(array_column($evaluated, 'directional_predictions'));
            $reasons = [];
            if (count($evaluated) !== 3) $reasons[] = 'incomplete_walk_forward';
            if ($mean < ($task === 'outcome' ? 0.25 : 0.55)) $reasons[] = 'low_validation_quality';
            if ($minimum < ($task === 'outcome' ? 0.15 : 0.45)) $reasons[] = 'unstable_fold_quality';
            if ($coverage < 0.10) $reasons[] = 'low_validation_coverage';
            if ($task === 'action' && $directional < 5) $reasons[] = 'insufficient_directional_predictions';
            // Conservative interval discourages high precision based on a handful of BUY/SELL calls.
            $directionalCorrect = array_sum(array_column($evaluated, 'directional_correct'));
            $wilsonLower = $this->wilsonLower($directionalCorrect, $directional);
            if ($task === 'action' && $wilsonLower < 0.45) $reasons[] = 'directional_wilson_lower_bound';
            if ($holdout['status'] !== 'evaluated') $reasons[] = 'holdout_unavailable';
            if (($holdout['coverage'] ?? 0) < 0.10) $reasons[] = 'low_holdout_coverage';
            if (($holdout['score'] ?? 0) < ($task === 'outcome' ? 0.15 : 0.45)) $reasons[] = 'low_holdout_quality';
            $output['candidates'][$name] = [...$holdout, 'folds' => $folds,
                'walk_forward_mean' => $mean, 'walk_forward_min' => $minimum,
                'walk_forward_coverage' => $coverage,
                'directional_wilson_lower_95' => $task === 'action' ? $wilsonLower : null,
                'failed_gates' => $reasons,
                'eligible' => $reasons === [], 'research_rank_score' => $mean];
        }
        $ranked = array_filter($output['candidates'], static fn (array $r): bool => $r['eligible']);
        uasort($ranked, static fn (array $a, array $b): int =>
            ($b['research_rank_score'] <=> $a['research_rank_score'])
            ?: ($b['walk_forward_min'] <=> $a['walk_forward_min']));
        $output['research_leader'] = array_key_first($ranked);
        $output['selection_policy'] = 'walk_forward_eligibility_then_mean_rank_final_holdout_gate';
        return $output;
    }

    private function wilsonLower(int $successes, int $count): float
    {
        if ($count === 0) return 0.0;
        $p = $successes / $count;
        $z = 1.959963984540054;
        $d = 1 + $z * $z / $count;
        return max(0.0, ($p + $z * $z / (2 * $count)
            - $z * sqrt(($p * (1 - $p) + $z * $z / (4 * $count)) / $count)) / $d);
    }

    private function candidate(array $train, array $test, string $task, array $classes, string $name): array
    {
        $start = hrtime(true);
        $peakBefore = memory_get_peak_usage(true);
        try {
            if (count($train) < 20 || count($test) < 5 ||
                count(array_unique(array_column($train, $task))) < 2) {
                return ['status' => 'insufficient_history', 'score' => null, 'coverage' => 0, 'trace' => []];
            }
            $labels = array_column($train, $task);
            $samples = array_column($train, 'vector');
            // Rubix MLP supports pure-PHP Tensor; Laravel promotes its optional-extension notice to an exception.
            if ($name === 'neural_network' && ! extension_loaded('tensor')) {
                $previousErrorHandler = set_error_handler(static function (int $severity, string $message): bool {
                    if ($severity === E_USER_WARNING && str_contains($message, 'Tensor C extension is not loaded')) {
                        return true;
                    }
                    return false;
                });
            }
            try {
            $model = match ($name) {
                'weighted_knn' => new KNearestNeighbors(min(9, count($train)), true),
                'decision_tree' => new ClassificationTree(8, 6),
                'random_forest' => new RandomForest(new ClassificationTree(8, 6), 12, 0.7, false),
                'neural_network' => new MultilayerPerceptron(
                    [new Dense(12), new Activation(new ReLU()), new Dense(8), new Activation(new ReLU())],
                    batchSize: 32, epochs: 12, window: 3),
            };
            $model->train(new Labeled($samples, $labels));
            } finally {
                if ($name === 'neural_network' && ! extension_loaded('tensor')) restore_error_handler();
            }
            unset($samples, $labels);
            $confusion = array_fill_keys($classes, array_fill_keys($classes, 0));
            $trace = [];
            $supported = 0;
            $correct = 0;
            $directional = 0;
            $directionalCorrect = 0;
            $validPredictions = 0;
            foreach (array_chunk($test, 50) as $batch) {
                $probs = $model->proba(new Unlabeled(array_column($batch, 'vector')));
                foreach ($batch as $i => $row) {
                    $scores = $probs[$i] ?? [];
                    arsort($scores);
                    $prediction = array_key_first($scores);
                    $confidence = (float) (reset($scores) ?: 0);
                    $accepted = in_array($prediction, $classes, true) && $confidence >= 0.60;
                    $validPredictions++;
                    if ($accepted) {
                        $supported++;
                        $confusion[$row[$task]][$prediction]++;
                        $correct += (int) ($prediction === $row[$task]);
                        if ($task === 'action' && $prediction !== 'hodl') {
                            $directional++;
                            $directionalCorrect += (int) ($prediction === $row[$task]);
                        }
                    }
                    $trace[] = ['decision_at_ms' => $row['decision_at_ms'],
                        'actual' => $row[$task], 'predicted' => $prediction,
                        'confidence' => $confidence, 'supported' => $accepted,
                        'reason' => $accepted ? 'supported' : 'weak_consensus'];
                    if (count($trace) > 180) array_shift($trace);
                }
            }
            $f1 = [];
            foreach ($classes as $class) {
                $tp = $confusion[$class][$class];
                $actual = array_sum($confusion[$class]);
                $predicted = array_sum(array_column($confusion, $class));
                $precision = $predicted ? $tp / $predicted : 0.0;
                $recall = $actual ? $tp / $actual : 0.0;
                $f1[] = $precision + $recall > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;
            }
            $macroF1 = array_sum($f1) / count($f1);
            $directionalPrecision = $directional ? $directionalCorrect / $directional : 0.0;
            $coverage = $validPredictions ? $supported / $validPredictions : 0.0;
            $score = $task === 'outcome' ? $macroF1 : $directionalPrecision;
            return ['status' => 'evaluated', 'score' => $score, 'macro_f1' => $macroF1,
                'directional_precision' => $task === 'action' ? $directionalPrecision : null,
                'directional_predictions' => $directional, 'directional_correct' => $directionalCorrect,
                'coverage' => $coverage,
                'correct' => $correct, 'supported' => $supported, 'holdout_rows' => count($test),
                'confusion' => $confusion, 'trace' => $trace,
                'elapsed_ms' => round((hrtime(true) - $start) / 1_000_000, 1),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
                'memory_peak_before_mb' => round($peakBefore / 1048576, 1)];
        } catch (Throwable $error) {
            return ['status' => 'failed', 'reason' => get_class($error).': '.$error->getMessage(),
                'score' => null, 'coverage' => 0, 'trace' => [],
                'elapsed_ms' => round((hrtime(true) - $start) / 1_000_000, 1)];
        }
    }
}
