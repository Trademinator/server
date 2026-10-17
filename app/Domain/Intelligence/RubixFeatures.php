<?php

namespace App\Domain\Intelligence;

use InvalidArgumentException;

/** Rubix 3 treats integers as categories, including endpoints decoded from JSON. */
final class RubixFeatures
{
    /** @return list<float> */
    public static function vector(array $values): array
    {
        foreach ($values as &$value) {
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                throw new InvalidArgumentException('Rubix features must be finite numbers.');
            }
            $value = (float) $value;
        }
        unset($value);

        return array_values($values);
    }
}
