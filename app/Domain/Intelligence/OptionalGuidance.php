<?php

namespace App\Domain\Intelligence;

use Closure;
use RuntimeException;
use Throwable;

/** Optional evidence cannot consume the publication reserve of the base model. */
final class OptionalGuidance
{
    public static function enabled(string $mode): bool
    {
        return (bool) config('human_training.enabled') && (bool) config('human_training.'.$mode.'_enabled', true);
    }

    /** Add a separate human allowance after the automatic training deadline. */
    public static function publicationDeadline(float $automaticDeadline): float
    {
        return self::enabled('candle')
            ? $automaticDeadline + self::budgetSeconds() + self::reserveSeconds()
            : $automaticDeadline;
    }

    public static function compare(string $mode, string $version, float $deadline, Closure $compare,
        ?HumanTrainingProgress $progress = null, ?float $budgetSeconds = null): array
    {
        $skipped = fn (string $reason): array => ['bundle' => [
            'version' => $version, 'status' => $reason, 'optional' => true,
            'influence' => false, 'keys' => [], 'samples' => 0,
        ]];
        if (! self::enabled($mode)) {
            $progress?->finish('skipped', 'disabled');

            return $skipped('disabled');
        }
        $reserve = self::reserveSeconds();
        $budget = $budgetSeconds ?? self::budgetSeconds();
        $auxiliaryDeadline = min($deadline - $reserve, microtime(true) + $budget);
        $progress?->start($auxiliaryDeadline, $budget);
        if ($auxiliaryDeadline <= microtime(true)) {
            $progress?->finish('timeout', 'no_time_remaining');

            return $skipped('optional_budget_exhausted');
        }
        try {
            $result = $compare($auxiliaryDeadline);
            if (microtime(true) > $auxiliaryDeadline) {
                throw new RuntimeException('Candle guidance training time budget exceeded.');
            }
            $result['bundle']['optional'] = true;
            $progress?->finish('completed', $result['bundle']['status'] ?? 'completed');

            return $result;
        } catch (Throwable $error) {
            // Only known computation-budget errors are recoverable here. In particular,
            // corrupt snapshots, source/checksum errors and programming errors propagate.
            if (! $error instanceof RuntimeException || ! in_array($error->getMessage(), [
                'Human guidance training time budget exceeded.',
                'Candle guidance training time budget exceeded.',
                'K tuning time budget exceeded; reduce INTELLIGENCE_MAX_MODEL_AGE_DAYS or increase the build time budget.',
                'KNN evaluation time budget exceeded.',
            ], true)) {
                $progress?->finish('failed', 'training_error', $error);
                throw $error;
            }

            $progress?->finish('timeout', 'optional_budget_exhausted', $error);

            return $skipped('optional_budget_exhausted');
        }
    }

    private static function budgetSeconds(): float
    {
        return max(1.0, (float) config('human_training.auxiliary_max_seconds', 300));
    }

    private static function reserveSeconds(): float
    {
        return max(1.0, (float) config('human_training.publication_reserve_seconds', 10));
    }
}
