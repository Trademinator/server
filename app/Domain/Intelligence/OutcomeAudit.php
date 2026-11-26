<?php

namespace App\Domain\Intelligence;

use App\Domain\MarketData\CandleProvenance;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\Research\DatasetRows;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\FeatureSchema;
use App\Domain\Research\SemanticLabels;
use App\Repositories\TickerRepository;
use InvalidArgumentException;
use RuntimeException;

final class OutcomeAudit
{
    public const VERSION = 'outcome-audit-v1';

    public function __construct(
        private DatasetStore $datasets,
        private TickerRepository $tickers,
        private PatternTrainer $patterns,
        private CandleTimeframe $timeframe,
    ) {}

    public function run(string $dataset, int $k, float $deadline): array
    {
        [$manifest, $rows] = $this->datasets->open($dataset);
        $this->validateManifest($manifest);
        if ($k < 1 || $k > (int) config('intelligence.knn.k_cap')) {
            throw new InvalidArgumentException('Audit K must be between 1 and INTELLIGENCE_KNN_K_CAP.');
        }

        $count = count($rows);
        if ($count < 10) {
            throw new RuntimeException('Outcome audit requires at least 10 frozen rows.');
        }

        $holdoutStart = (int) floor($count * 0.8);
        $holdoutStart = max(1, min($count - 1, $holdoutStart));
        $cutoff = $rows->at($holdoutStart)['decision_at_ms'];
        $currentH = (int) $manifest['label_definition']['horizon'];
        $lookback = (int) $manifest['label_definition']['lookback'];
        $horizons = $this->horizons($currentH);
        $maxH = max($horizons);

        [$candles, $candleIndex] = $this->candles($manifest, $rows, $lookback, $maxH, $deadline);
        $settings = config('intelligence.knn');
        $settings['outcome'] = config('intelligence.outcome');
        $knn = new WeightedKnn(
            $settings['max_distance'],
            $settings['min_effective_neighbors'],
            $settings['min_confidence'],
        );
        $tuner = new OutcomeKnnTuner($knn, new OutcomeKnn($settings));

        $technicalKeys = FeatureSchema::keys('technical');
        $horizonKeys = array_diff($technicalKeys, $manifest['keys']) === []
            ? $technicalKeys
            : $manifest['keys'];
        $horizonFeatureGroup = $horizonKeys === $technicalKeys ? 'technical' : 'source_schema';

        $horizonReports = [];
        foreach ($horizons as $horizon) {
            $this->checkDeadline($deadline);
            $variant = $this->rowsFor(
                $rows,
                $manifest,
                $candles,
                $candleIndex,
                $holdoutStart,
                $cutoff,
                $horizonKeys,
                $horizon,
                $lookback,
                false,
            );
            $horizonReports[(string) $horizon] = $this->evaluateVariant(
                $variant,
                $tuner,
                $settings,
                $k,
                $deadline,
                ['horizon' => $horizon, 'feature_group' => $horizonFeatureGroup],
            );
            unset($variant);
        }

        $featureReports = [];
        foreach (['core', 'technical'] as $schema) {
            $this->checkDeadline($deadline);
            if ($schema === 'technical' && $horizonFeatureGroup === 'technical') {
                $featureReports[$schema] = $horizonReports[(string) $currentH];

                continue;
            }
            $keys = FeatureSchema::keys($schema);
            if (array_diff($keys, $manifest['keys']) !== []) {
                $featureReports[$schema] = [
                    'status' => 'unavailable',
                    'reason' => 'dataset_missing_required_features',
                    'missing_keys' => array_values(array_diff($keys, $manifest['keys'])),
                ];

                continue;
            }

            $variant = $this->rowsFor(
                $rows,
                $manifest,
                $candles,
                $candleIndex,
                $holdoutStart,
                $cutoff,
                $keys,
                $currentH,
                $lookback,
                false,
            );
            $featureReports[$schema] = $this->evaluateVariant(
                $variant,
                $tuner,
                $settings,
                $k,
                $deadline,
                ['horizon' => $currentH, 'feature_group' => $schema],
            );
            unset($variant);
        }

        $featureReports['patterns'] = [
            'status' => 'informational_only',
            'influence' => false,
            'reason' => 'patterns_are_not_knn_features',
        ];

        return [
            'version' => self::VERSION,
            'read_only' => true,
            'selection_policy' => 'fixed_k_research_only',
            'k' => $k,
            'dataset_id' => $dataset,
            'exchange' => $manifest['exchange'],
            'symbol' => $manifest['symbol'],
            'period' => $manifest['period'],
            'source_schema' => $manifest['schema'],
            'source_rows' => $count,
            'current_horizon' => $currentH,
            'horizon_candidates' => $horizons,
            'research' => [
                'rows_before_reserved_holdout' => $holdoutStart,
                'cutoff_ms' => $cutoff,
                'horizons' => $horizonReports,
                'feature_groups_at_current_horizon' => $featureReports,
            ],
            'final_holdout' => [
                'rows' => $count - $holdoutStart,
                'from_ms' => $cutoff,
                'evaluated' => false,
                'reason' => 'reserved_for_post_research_validation',
            ],
        ];
    }

    private function validateManifest(array $manifest): void
    {
        if (($manifest['label_definition']['version'] ?? null) !== SemanticLabels::VERSION) {
            throw new InvalidArgumentException('Outcome audit requires a current semantic Outcome dataset.');
        }
        if (($manifest['outcome_available'] ?? true) !== true) {
            throw new InvalidArgumentException('Outcome audit requires a dataset with an available Outcome horizon.');
        }
        if (! isset($manifest['label_definition']['horizon'], $manifest['label_definition']['lookback'])) {
            throw new InvalidArgumentException('Outcome dataset is missing horizon metadata.');
        }
    }

    /** @return list<int> */
    private function horizons(int $horizon): array
    {
        $values = [
            max(1, (int) round($horizon * 0.5, 0, PHP_ROUND_HALF_UP)),
            $horizon,
            max(1, (int) round($horizon * 1.5, 0, PHP_ROUND_HALF_UP)),
            max(1, $horizon * 2),
        ];
        $values = array_values(array_unique($values));
        sort($values, SORT_NUMERIC);

        return $values;
    }

    /** @return array{0:list<array<string,mixed>>,1:array<int,int>} */
    private function candles(array $manifest, DatasetRows $rows, int $lookback, int $maxH, float $deadline): array
    {
        $first = $rows->at(0);
        $last = $rows->at(count($rows) - 1);
        $from = (int) $first['microtimestamp'];
        for ($i = 1; $i < $lookback; $i++) {
            $from = max(0, $this->timeframe->previous($from, $manifest['period']));
        }
        $to = (int) $last['microtimestamp'];
        for ($i = 0; $i <= $maxH; $i++) {
            $to = $this->timeframe->next($to, $manifest['period']);
        }
        $to = min($to, (int) $manifest['as_of_ms']);

        $candles = [];
        $index = [];
        $previous = null;
        foreach ($this->tickers->streamHistory(
            $manifest['exchange'],
            $manifest['symbol'],
            $manifest['period'],
            $from,
            $to,
        ) as $timestamp => $candle) {
            $this->checkDeadline($deadline);
            $timestamp = (int) $timestamp;
            if ($previous !== null && $this->timeframe->next($previous, $manifest['period']) !== $timestamp) {
                // Keep the index discontinuity explicit. rowsFor() rejects windows crossing it.
            }
            $candle['microtimestamp'] = $timestamp;
            $index[$timestamp] = count($candles);
            $candles[] = $candle;
            $previous = $timestamp;
        }

        return [$candles, $index];
    }

    /** @return list<array<string,mixed>> */
    private function rowsFor(
        DatasetRows $rows,
        array $manifest,
        array $candles,
        array $candleIndex,
        int $limit,
        int $cutoff,
        array $keys,
        int $horizon,
        int $lookback,
        bool $patterns,
    ): array {
        $positions = [];
        foreach ($keys as $key) {
            $position = array_search($key, $manifest['keys'], true);
            if ($position === false) {
                throw new InvalidArgumentException('Dataset does not contain required feature: '.$key);
            }
            $positions[] = $position;
        }

        $definition = new SemanticLabels($horizon, $lookback);
        $variant = [];
        for ($position = 0; $position < $limit; $position++) {
            $row = $rows->at($position);
            $candlePosition = $candleIndex[(int) $row['microtimestamp']] ?? null;
            if ($candlePosition === null || $candlePosition - ($lookback - 1) < 0
                || $candlePosition + $horizon >= count($candles)) {
                continue;
            }

            $past = array_slice($candles, $candlePosition - ($lookback - 1), $lookback);
            $future = array_slice($candles, $candlePosition, $horizon + 1);
            if (! $this->continuous($past, $manifest['period']) || ! $this->continuous($future, $manifest['period'])) {
                continue;
            }

            $available = $this->timeframe->next($future[$horizon]['microtimestamp'], $manifest['period']);
            foreach ($future as $bar) {
                $available = max($available, CandleProvenance::availableAt($bar, $manifest['period']));
            }
            if ($available >= $cutoff) {
                continue;
            }

            $label = $definition->label($past, $future)['action'];
            $prepared = [
                'decision_at_ms' => $row['decision_at_ms'],
                'label_available_at_ms' => $available,
                'vector' => NormalizedVector::from(
                    array_map(fn (int $i): float|int => $row['vector'][$i], $positions), $keys
                ),
                'label' => $label,
            ];
            if ($patterns) {
                $prepared['patterns'] = $row['patterns'] ?? [];
            }
            $variant[] = $prepared;
        }

        return $variant;
    }

    private function continuous(array $candles, string $period): bool
    {
        for ($i = 1, $count = count($candles); $i < $count; $i++) {
            if ($this->timeframe->next($candles[$i - 1]['microtimestamp'], $period) !== $candles[$i]['microtimestamp']) {
                return false;
            }
        }

        return true;
    }

    private function evaluateVariant(
        array $rows,
        OutcomeKnnTuner $tuner,
        array $settings,
        int $k,
        float $deadline,
        array $metadata,
    ): array {
        if (count($rows) < $settings['min_train_size'] + $settings['test_size']) {
            return [
                'status' => 'unavailable',
                'reason' => 'insufficient_research_rows',
                'rows' => count($rows),
                ...$metadata,
            ];
        }

        $evaluation = $tuner->evaluateWalkForwardPrepared($rows, $settings, $k, $deadline);
        $report = $evaluation['report'];

        return [
            'status' => 'evaluated',
            'rows' => count($rows),
            'folds' => count($evaluation['folds']),
            ...$metadata,
            'five_class' => $this->fiveClass($report),
            'three_class' => $this->threeClass($report['confusion']),
            'ordinal' => $report['ordinal'],
            'baseline' => $report['baseline'],
            'coverage' => $report['coverage'],
            'mean_confidence' => $report['mean_confidence'],
            'gates' => $report['gates'],
            'failed_gates' => $report['failed_gates'],
            'eligible_under_current_gates' => $report['eligible'],
        ];
    }

    private function fiveClass(array $report): array
    {
        return [
            'supported' => $report['supported'],
            'abstained' => $report['abstained'],
            'accuracy' => $report['supported_accuracy'],
            'macro_f1' => $report['supported_macro_f1'],
            'per_class' => $report['per_class'],
        ];
    }

    private function threeClass(array $confusion): array
    {
        $map = [
            'super_bear' => 'bear',
            'bear' => 'bear',
            'neutral' => 'neutral',
            'bull' => 'bull',
            'super_bull' => 'bull',
        ];
        $classes = ['bear', 'neutral', 'bull'];
        $collapsed = array_fill_keys($classes, array_fill_keys($classes, 0));
        foreach ($confusion as $actual => $predictions) {
            foreach ($predictions as $predicted => $count) {
                $collapsed[$map[$actual]][$map[$predicted]] += (int) $count;
            }
        }

        $total = array_sum(array_map('array_sum', $collapsed));
        $correct = array_sum(array_map(fn (string $class): int => $collapsed[$class][$class], $classes));
        $perClass = [];
        foreach ($classes as $class) {
            $tp = $collapsed[$class][$class];
            $predicted = array_sum(array_column($collapsed, $class));
            $actual = array_sum($collapsed[$class]);
            $precision = $predicted ? $tp / $predicted : 0.0;
            $recall = $actual ? $tp / $actual : 0.0;
            $f1 = ($precision + $recall) > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;
            $perClass[$class] = compact('precision', 'recall', 'f1') + ['support' => $actual, 'predicted' => $predicted];
        }

        return [
            'confusion' => $collapsed,
            'accuracy' => $total ? $correct / $total : 0.0,
            'macro_f1' => array_sum(array_column($perClass, 'f1')) / count($classes),
            'per_class' => $perClass,
        ];
    }

    private function checkDeadline(float $deadline): void
    {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Outcome audit time budget exceeded; increase --timeout.');
        }
    }
}
