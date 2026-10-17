<?php

namespace App\Domain\Intelligence;

use Rubix\ML\Classifiers\KNearestNeighbors;
use Rubix\ML\DataType;
use Rubix\ML\Kernels\Distance\Distance;

/**
 * Keep Rubix's top-K selection algorithm, while providing Trademinator's
 * normalized pairwise feature reliability as a Rubix distance kernel.
 */
final class RubixWeightedRms implements Distance
{
    public function compatibility(): array
    {
        return [DataType::continuous()];
    }

    public function compute(array $a, array $b): float
    {
        $n = intdiv(count($a), 2);
        $sum = $effective = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $weight = min($a[$n + $i], $b[$n + $i]);
            $effective += $weight;
            $delta = $a[$i] - $b[$i];
            $sum += $weight * $delta * $delta;
        }

        return $effective > 0.0 ? sqrt($sum / $effective) : NAN;
    }

    public function __toString(): string
    {
        return 'Trademinator reliability-weighted normalized RMS';
    }
}
