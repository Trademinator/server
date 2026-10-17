<?php

namespace App\Domain\Intelligence;

use Rubix\ML\Classifiers\KNearestNeighbors;

final class RubixNearestNeighbors extends KNearestNeighbors
{
    /** Attach already validated samples without creating duplicate Rubix datasets. */
    public function attach(array $samples): void
    {
        $this->samples = $samples;
        $this->labels = array_keys($samples);
    }

    public function locate(array $query): array
    {
        [$indices, $distances] = $this->nearest($query);
        $result = [];
        foreach ($indices as $i => $index) {
            $result[] = ['index' => $index, 'distance' => $distances[$i]];
        }
        usort($result, static fn (array $a, array $b): int =>
            [$a['distance'], $a['index']] <=> [$b['distance'], $b['index']]);

        return $result;
    }
}
