<?php

return [
    // Bump this whenever automatic period-selection semantics change. Existing
    // shared feeds keep their current period until the new version succeeds.
    'selection_version' => max(5, (int) env('CANDLE_PERIOD_SELECTION_VERSION', 5)),
    'periods' => ['1m', '3m', '5m', '15m', '30m', '1h', '4h', '1d'],
    'quality_threshold' => env('CANDLE_PERIOD_QUALITY_THRESHOLD', 0.7),
    'minimum_coverage' => env('CANDLE_PERIOD_MINIMUM_COVERAGE', 0.8),
    'evaluation_days' => env('CANDLE_PERIOD_EVALUATION_DAYS', 60),
    // Additional days, so the default fallback evaluates 90 days in total.
    'fallback_days' => env('CANDLE_PERIOD_FALLBACK_DAYS', 30),
    'max_rejected_reversal_ratio' => env('CANDLE_PERIOD_MAX_REJECTED_REVERSAL_RATIO', 0.10),
    'minimum_action_ratio' => env('CANDLE_PERIOD_MINIMUM_ACTION_RATIO', 0.02),
    // Hard cap on O=H=L=C candles, regardless of the blended quality score.
    'max_true_flat_ratio' => env('CANDLE_PERIOD_MAX_TRUE_FLAT_RATIO', 0.10),
    // Stricter cap and non-overlapping historical windows when moving to a shorter period.
    'shorter_reentry_flat_ratio' => env('CANDLE_PERIOD_SHORTER_REENTRY_FLAT_RATIO', 0.05),
    'shorter_confirmation_windows' => env('CANDLE_PERIOD_SHORTER_CONFIRMATION_WINDOWS', 2),
    'backfill_retry_minutes' => env('CANDLE_PERIOD_BACKFILL_RETRY_MINUTES', 15),
    'no_candidate_retry_minutes' => env('CANDLE_PERIOD_NO_CANDIDATE_RETRY_MINUTES', 360),
    'scheduled_markets_per_run' => env('CANDLE_PERIOD_SCHEDULED_MARKETS_PER_RUN', 10),
];
