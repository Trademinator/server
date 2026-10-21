<?php

namespace App\Domain\Intelligence;

final class TrainingRuntime
{
    public static function jobTimeout(): int
    {
        $automatic = max(60, (int) config('intelligence.max_seconds', 1800));
        $auxiliary = (int) ceil(max(1, (float) config('human_training.auxiliary_max_seconds', 300))
            + max(1, (float) config('human_training.publication_reserve_seconds', 10)));

        return max((int) config('intelligence.job_timeout', 0), $automatic + max(600, $auxiliary + 120));
    }

    public static function lockSeconds(): int
    {
        return self::jobTimeout() + 60;
    }

    public static function retryAfter(): int
    {
        return self::jobTimeout() + 300;
    }
}
