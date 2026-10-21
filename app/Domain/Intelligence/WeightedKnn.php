<?php

namespace App\Domain\Intelligence;

use InvalidArgumentException;
use Rubix\ML\Classifiers\KNearestNeighbors;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Datasets\Unlabeled;

/** Bounded top-K search with normalized RMS distance and Rubix weighted voting. */
final class WeightedKnn
{
    private const SEARCH_BLOCK_ROWS = 1024;

    public function __construct(
        public readonly float $maxDistance = 0.25,
        public readonly float $minEffective = 3,
        public readonly float $minConfidence = 0.6,
    ) {
        if (! is_finite($maxDistance) || $maxDistance <= 0 || $maxDistance > 1
            || ! is_finite($minEffective) || $minEffective < 1
            || ! is_finite($minConfidence) || $minConfidence < 0.5 || $minConfidence > 1) {
            throw new InvalidArgumentException('Invalid KNN evidence thresholds.');
        }
    }

    /** Validate and flatten knowledge vectors once before repeated KNN scans. */
    public function prepareRows(array $rows): array
    {
        $this->prepareRowsInPlace($rows);

        return $rows;
    }

    public function validatePreparedRows(iterable $rows): void
    {
        $dimensions = null;
        foreach ($rows as $row) {
            if (! isset($row['vector']) || ! is_array($row['vector']) || ! array_is_list($row['vector']) || $row['vector'] === []) {
                throw new InvalidArgumentException('Knowledge vectors must be nonempty lists.');
            }
            $dimensions ??= count($row['vector']);
            if (count($row['vector']) !== $dimensions) {
                throw new InvalidArgumentException('Knowledge feature dimensions do not match.');
            }
            foreach ($row['vector'] as $value) {
                if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < 0 || $value > 1) {
                    throw new InvalidArgumentException('KNN expects prepared finite unit-interval vectors.');
                }
            }
            $rowWeights = $row['feature_weights'] ?? [];
            if (! is_array($rowWeights) || ($rowWeights !== [] && (! array_is_list($rowWeights) || count($rowWeights) !== $dimensions))) {
                throw new InvalidArgumentException('Knowledge feature weights must match the vector.');
            }
            foreach ($rowWeights as $weight) {
                if ((! is_int($weight) && ! is_float($weight)) || ! is_finite((float) $weight) || $weight < 0 || $weight > 1) {
                    throw new InvalidArgumentException('Feature weights must be prepared finite unit-interval values.');
                }
            }
        }
    }

    public function prepareRowsInPlace(array &$rows): void
    {
        $dimensions = null;
        foreach ($rows as &$row) {
            if (! isset($row['vector']) || ! is_array($row['vector']) || ! array_is_list($row['vector']) || $row['vector'] === []) {
                throw new InvalidArgumentException('Knowledge vectors must be nonempty lists.');
            }
            $dimensions ??= count($row['vector']);
            if (count($row['vector']) !== $dimensions) {
                throw new InvalidArgumentException('Knowledge feature dimensions do not match.');
            }
            $this->prepareVectorInPlace($row['vector'], $dimensions);
            $rowWeights = $row['feature_weights'] ?? [];
            if (! is_array($rowWeights)) {
                throw new InvalidArgumentException('Knowledge feature weights must be a list.');
            }
            $this->prepareWeightsInPlace($rowWeights, $dimensions, 'Knowledge');
            if ($rowWeights === []) {
                unset($row['feature_weights']);
            } else {
                $row['feature_weights'] = $rowWeights;
            }
        }
        unset($row);
    }

    public function neighbors(array $rows, array $vector, int $k, int $asOfMs, array $weights = []): array
    {
        return $this->neighborsIterable($rows, $vector, $k, $asOfMs, $weights);
    }

    /** Prepare each row once while Rubix searches bounded blocks of the snapshot. */
    public function neighborsIterable(iterable $rows, array $vector, int $k, int $asOfMs, array $weights = []): array
    {
        if ($k < 1 || $vector === []) {
            throw new InvalidArgumentException('K and feature count must be positive.');
        }
        $vector = $this->prepareVector($vector);
        $weights = $this->prepareWeights($weights, count($vector), 'Query');
        $prepared = (function () use ($rows): \Generator {
            foreach ($rows as $row) {
                yield $this->prepareRows([$row])[0];
            }
        })();

        return $this->neighborsPrepared($prepared, $vector, $k, $asOfMs, $weights);
    }

    public function neighborsPrepared(iterable $rows, array $vector, int $k, int $asOfMs, array $weights = []): array
    {
        if ($k < 1 || $vector === []) {
            throw new InvalidArgumentException('K and feature count must be positive.');
        }
        $dimensions = count($vector);
        if ($weights !== [] && count($weights) !== $dimensions) {
            throw new InvalidArgumentException('Query feature weights do not match the vector.');
        }
        $eligible = (function () use ($rows, $dimensions, $asOfMs): \Generator {
            foreach ($rows as $row) {
                if (count($row['vector']) !== $dimensions) {
                    throw new InvalidArgumentException('Knowledge feature dimensions do not match.');
                }
                if ($row['decision_at_ms'] < $asOfMs && $row['label_available_at_ms'] < $asOfMs) {
                    yield $row;
                }
            }
        })();

        return $this->rubixNeighbors($eligible, $vector, $k, $weights);
    }

    private function rubixNeighbors(iterable $rows, array $vector, int $k, array $weights): array
    {
        $n = count($vector);
        $query = [...$vector, ...($weights ?: array_fill(0, $n, 1.0))];
        $rubix = new RubixNearestNeighbors($k, true, new RubixWeightedRms);
        $samples = $metadata = $nearest = [];
        $offset = 0;
        foreach ($rows as $row) {
            $samples[] = [...$row['vector'], ...($row['feature_weights'] ?? array_fill(0, $n, 1.0))];
            $metadata[] = ['label' => $row['label'], 'decision_at_ms' => $row['decision_at_ms']];
            if (count($samples) === self::SEARCH_BLOCK_ROWS) {
                $this->mergeBlockNeighbors($rubix, $samples, $metadata, $query, $k, $offset, $nearest);
                $offset += count($samples);
                $samples = $metadata = [];
            }
        }
        if ($samples !== []) {
            $this->mergeBlockNeighbors($rubix, $samples, $metadata, $query, $k, $offset, $nearest);
        }
        foreach ($nearest as &$neighbor) {
            unset($neighbor['ordinal']);
        }
        unset($neighbor);

        return $nearest;
    }

    /**
     * The global top K is contained in the union of each block's top K.
     * Original row ordinals preserve Rubix's first-seen ordering for equal distances.
     *
     * @param  list<list<float>>  $samples
     * @param  list<array{label: string, decision_at_ms: int}>  $metadata
     * @param  list<float>  $query
     * @param  list<array{distance: float, label: string, decision_at_ms: int, ordinal: int}>  $nearest
     */
    private function mergeBlockNeighbors(RubixNearestNeighbors $rubix, array $samples, array $metadata,
        array $query, int $k, int $offset, array &$nearest): void
    {
        $rubix->attach($samples);
        foreach ($rubix->locate($query) as $entry) {
            if ($entry['distance'] <= $this->maxDistance) {
                $record = $metadata[$entry['index']];
                $nearest[] = ['distance' => $entry['distance'], 'label' => $record['label'],
                    'decision_at_ms' => $record['decision_at_ms'], 'ordinal' => $offset + $entry['index']];
            }
        }
        $rubix->attach([]);
        usort($nearest, static fn (array $a, array $b): int => [$a['distance'], $a['ordinal']] <=> [$b['distance'], $b['ordinal']]);
        $nearest = array_slice($nearest, 0, $k);
    }

    /** Rubix 3.1 owns the weighted classification distribution. */
    public function vote(array $neighbors, int $k, array $classWeights = []): array
    {
        foreach ($classWeights as $label => $weight) {
            if (! in_array($label, ['buy', 'hodl', 'sell'], true) || ! is_numeric($weight)
                || ! is_finite((float) $weight) || $weight < 0) {
                throw new InvalidArgumentException('Invalid KNN class weight.');
            }
        }
        $neighbors = array_slice($neighbors, 0, $k);
        if ($neighbors === []) {
            return self::abstain('no_similar_history');
        }
        if ($neighbors[0]['distance'] <= 1e-12) {
            $neighbors = array_values(array_filter($neighbors,
                fn (array $row): bool => $row['distance'] <= 1e-12));
        }
        foreach ($neighbors as $neighbor) {
            if (! in_array($neighbor['label'], ['buy', 'hodl', 'sell'], true)) {
                throw new InvalidArgumentException('Unknown knowledge label.');
            }
        }
        $classifier = new KNearestNeighbors(count($neighbors), true);
        $classifier->train(new Labeled(
            array_map(static fn (array $row): array => [(float) $row['distance']], $neighbors),
            array_column($neighbors, 'label')
        ));
        $rubix = $classifier->proba(new Unlabeled([[0.0]]))[0];
        $raw = ['buy' => 0.0, 'hodl' => 0.0, 'sell' => 0.0];
        $sum = $squared = $similarity = 0.0;
        foreach ($neighbors as $row) {
            // Rubix ML 3.1 weighted KNN uses 1 / (1 + distance).
            $weight = ($classWeights[$row['label']] ?? 1.0) / (1.0 + $row['distance']);
            $sum += $weight;
            $squared += $weight * $weight;
            $similarity += $weight * (1 - $row['distance']);
        }
        if ($sum <= 0.0) {
            return self::abstain('no_weighted_evidence');
        }
        foreach ($raw as $label => &$share) {
            $share = ($rubix[$label] ?? 0.0) * ($classWeights[$label] ?? 1.0);
        }
        unset($share);
        $total = array_sum($raw);
        if ($total <= 0.0) {
            return self::abstain('no_weighted_evidence');
        }
        $votes = array_map(static fn (float $share): float => $share / $total, $raw);
        arsort($votes);
        $values = array_values($votes);
        $effective = $sum * $sum / $squared;
        $evidence = ['neighbors' => count($neighbors), 'effective_neighbors' => $effective,
            'similarity' => $similarity / $sum, 'votes' => $votes];
        if ($effective + 1e-9 < $this->minEffective) {
            return array_replace(self::abstain('insufficient_effective_neighbors'), $evidence);
        }
        if (abs($values[0] - $values[1]) < 1e-12) {
            return array_replace(self::abstain('tied_votes'), $evidence);
        }
        $confidence = $values[0] * $evidence['similarity'];
        if ($confidence < $this->minConfidence) {
            return array_replace(self::abstain('weak_consensus'), $evidence);
        }

        return ['action' => array_key_first($votes), 'confidence' => $confidence,
            'reason' => 'supported', ...$evidence];
    }

    public function predict(array $rows, array $vector, int $k, int $asOfMs, array $weights = []): array
    {
        return $this->vote($this->neighbors($rows, $vector, $k, $asOfMs, $weights), $k);
    }

    public function predictIterable(iterable $rows, array $vector, int $k, int $asOfMs, array $weights = []): array
    {
        return $this->vote($this->neighborsIterable($rows, $vector, $k, $asOfMs, $weights), $k);
    }

    public static function abstain(string $reason): array
    {
        return ['action' => 'hodl', 'confidence' => 0.0, 'reason' => $reason,
            'neighbors' => 0, 'effective_neighbors' => 0.0, 'similarity' => 0.0,
            'votes' => ['buy' => 0.0, 'hodl' => 0.0, 'sell' => 0.0]];
    }

    private function prepareVector(array $vector, ?int $dimensions = null): array
    {
        $this->prepareVectorInPlace($vector, $dimensions);

        return $vector;
    }

    private function prepareVectorInPlace(array &$vector, ?int $dimensions = null): void
    {
        if (! array_is_list($vector) || $vector === [] || ($dimensions !== null && count($vector) !== $dimensions)) {
            throw new InvalidArgumentException('KNN feature dimensions do not match.');
        }
        foreach ($vector as &$value) {
            if (! is_numeric($value) || ! is_finite((float) $value) || $value < 0 || $value > 1) {
                throw new InvalidArgumentException('KNN expects finite unit-interval vectors.');
            }
            $value = (float) $value;
        }
        unset($value);
    }

    private function prepareWeights(array $weights, int $dimensions, string $scope): array
    {
        $this->prepareWeightsInPlace($weights, $dimensions, $scope);

        return $weights;
    }

    private function prepareWeightsInPlace(array &$weights, int $dimensions, string $scope): void
    {
        if ($weights === []) {
            return;
        }
        if (! array_is_list($weights) || count($weights) !== $dimensions) {
            throw new InvalidArgumentException($scope.' feature weights do not match the vector.');
        }
        foreach ($weights as &$weight) {
            if (! is_numeric($weight) || ! is_finite((float) $weight) || $weight < 0 || $weight > 1) {
                throw new InvalidArgumentException('Feature weights must be finite unit-interval values.');
            }
            $weight = (float) $weight;
        }
        unset($weight);
    }
}
