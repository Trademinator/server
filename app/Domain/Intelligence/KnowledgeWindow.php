<?php

namespace App\Domain\Intelligence;

use InvalidArgumentException;

final class KnowledgeWindow
{
    public const TRAINING_POLICY = 'all_available_eligible_history';

    /** Model lifetime only; never a limit on the age of training examples. */
    public static function days(): int
    {
        $days = (int) config('intelligence.max_model_age_days');
        if ($days < 1) {
            throw new InvalidArgumentException('INTELLIGENCE_MAX_MODEL_AGE_DAYS must be a positive number of days.');
        }

        return $days;
    }

    public static function fromMs(int $asOfMs): int
    {
        return max(0, $asOfMs - self::days() * 86_400_000);
    }

    /** @return array{days: int, from_ms: int, as_of_ms: int} */
    public static function metadata(int $asOfMs): array
    {
        return ['days' => self::days(), 'from_ms' => self::fromMs($asOfMs), 'as_of_ms' => $asOfMs];
    }

    /** Production learning includes all stored history, subject to source and label eligibility. */
    public static function trainingFromMs(): int
    {
        return 0;
    }

    /** @return array{policy: string, days: null, from_ms: int, as_of_ms: int} */
    public static function trainingMetadata(int $asOfMs): array
    {
        return ['policy' => self::TRAINING_POLICY, 'days' => null,
            'from_ms' => self::trainingFromMs(), 'as_of_ms' => $asOfMs];
    }
}
