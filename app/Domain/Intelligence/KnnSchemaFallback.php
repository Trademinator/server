<?php

namespace App\Domain\Intelligence;

use App\Domain\Features\FeatureEngine;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\Research\FeatureSchema;
use App\Domain\Research\WalkForward;
use App\Models\MarketFeature;
use RuntimeException;

/** Choose a fixed KNN schema before freezing training data, never from validation scores. */
final class KnnSchemaFallback
{
    public const VERSION = 'knn-technical-history-fallback-v1';

    public function __construct(private CandleTimeframe $timeframe) {}

    public function inspect(string $exchange, string $symbol, string $period, int $from, int $to, int $asOf, int $horizon, float $deadline): array
    {
        $candidate = ['technical' => FeatureSchema::keys('technical'), 'core' => FeatureSchema::keys('core')];
        $counts = ['technical' => 0, 'core' => 0];
        $mature = ['technical' => [], 'core' => []];
        $total = 0;
        $missing = array_fill_keys($candidate['technical'], 0);
        foreach (MarketFeature::query()->where('exchange', $exchange)->where('symbol', $symbol)
            ->where('period', $period)->where('version', FeatureEngine::VERSION)
            ->whereBetween('available_at_ms', [$from, $to])
            ->lazyById(500, column: 'microtimestamp', alias: 'microtimestamp') as $feature) {
            $this->checkDeadline($deadline);
            $payload = $feature->payload;
            $decision = $this->timeframe->next($feature->microtimestamp, $period);
            if (($payload['version'] ?? null) !== FeatureEngine::VERSION
                || ($payload['microtimestamp'] ?? null) !== $feature->microtimestamp
                || ($payload['available_at_ms'] ?? null) !== $decision || $decision !== $feature->available_at_ms) {
                throw new RuntimeException('Invalid feature timestamp/version; rebuild features.');
            }
            $total++;
            foreach ($missing as $key => $_) {
                if (($payload['features'][$key] ?? null) === null) {
                    $missing[$key]++;
                }
            }
            $labelAt = $decision;
            for ($i = 0; $i < $horizon && $labelAt <= $asOf; $i++) {
                $labelAt = $this->timeframe->next($labelAt, $period);
            }
            foreach ($candidate as $name => $keys) {
                if (FeatureSchema::vector($payload, $keys) === null) {
                    continue;
                }
                $counts[$name]++;
                if ($labelAt <= $asOf) {
                    $mature[$name][] = ['decision_at_ms' => $decision, 'label_available_at_ms' => $labelAt];
                }
            }
        }
        $requirements = config('intelligence.knn');
        $potential = [];
        foreach ($mature as $name => $rows) {
            $split = (int) floor(count($rows) * 0.8);
            $holdout = array_slice($rows, $split);
            $cutoff = $holdout[0]['decision_at_ms'] ?? 0;
            $training = array_values(array_filter(array_slice($rows, 0, $split),
                fn (array $row): bool => $row['label_available_at_ms'] < $cutoff));
            $evaluated = 0;
            foreach ((new WalkForward)->folds(
                $training, $requirements['min_train_size'], $requirements['test_size'], $requirements['gap'], true, null
            ) as $fold) {
                $this->checkDeadline($deadline);
                $evaluated += count($fold['test']);
                if ($evaluated >= $requirements['min_validation_rows']) {
                    break;
                }
            }
            $sufficient = count($holdout) >= $requirements['min_validation_rows']
                && $evaluated >= $requirements['min_validation_rows'];
            $potential[$name] = ['potential_mature_rows' => count($rows),
                'tuning_rows_after_purge' => count($training), 'holdout_rows' => count($holdout),
                'validation_rows' => $evaluated, 'sufficient' => $sufficient];
        }
        $selected = $potential['technical']['sufficient'] ? 'technical'
            : ($potential['core']['sufficient'] ? 'core' : null);

        $this->checkDeadline($deadline);

        return ['version' => self::VERSION, 'requested_schema' => 'technical',
            'effective_schema' => $selected, 'fallback_policy' => 'core',
            'reason' => $selected === 'technical' ? 'technical_history_sufficient'
                : ($selected === 'core' ? 'insufficient_technical_history'
                    : 'insufficient_both_feature_histories'),
            'selection_basis' => 'feature_availability_and_chronology_only',
            'validation_performed' => false, 'feature_rows' => $total,
            'complete_feature_rows' => $counts, 'missing_by_key' => $missing,
            'potential_history' => $potential, 'window' => ['from_ms' => $from, 'to_ms' => $to, 'as_of_ms' => $asOf]];
    }

    private function checkDeadline(float $deadline): void
    {
        TrainingRuntime::heartbeat();
        if (microtime(true) > $deadline) {
            throw new RuntimeException('KNN schema inspection exceeded training deadline.');
        }
    }
}
