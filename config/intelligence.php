<?php

return [
    'enabled' => env('INTELLIGENCE_ENABLED', true),
    'path' => storage_path('app/private/intelligence'),
    'queue' => env('INTELLIGENCE_QUEUE', 'intelligence'),
    'tournament' => ['queue' => env('INTELLIGENCE_TOURNAMENT_QUEUE', 'tournament')],
    'schema' => env('INTELLIGENCE_SCHEMA', 'core'),
    // Previous full-context fallback is retired: CoinGecko never enters Core/Technical KNN.
    'context_fallback' => 'none',
    'technical_fallback' => (bool) env('INTELLIGENCE_TECHNICAL_FALLBACK', true),
    // Bootstrap/fallback depth only. Each dataset replaces this with H derived from Action pivot frequencies.
    'horizon' => 12,
    'lookback' => 20,
    // Statistical minimum for H; intentionally not environment-configurable.
    'min_horizon_distance_observations' => 30,
    // Automatic dataset/model deadline; human training adds its own allowance.
    'max_seconds' => max(60, (int) env('INTELLIGENCE_MAX_SECONDS', 1800)),
    // Both the training history window and the published model's maximum age.
    'max_model_age_days' => (int) env('INTELLIGENCE_MAX_MODEL_AGE_DAYS', 14),
    'max_signal_age_periods' => (int) env('INTELLIGENCE_MAX_SIGNAL_AGE_PERIODS', 2),
    'max_signal_age_seconds' => max(60, (int) env('INTELLIGENCE_MAX_SIGNAL_AGE_SECONDS', 86400)),
    'knn' => [
        'min_train_size' => 250, // Validation warmup only; all eligible history is retained.
        'test_size' => 100,
        'gap' => 0,
        'k_cap' => 65,
        'max_distance' => (float) env('INTELLIGENCE_KNN_MAX_DISTANCE', 0.25),
        'min_effective_neighbors' => (float) env('INTELLIGENCE_KNN_MIN_EFFECTIVE_NEIGHBORS', 3.0),
        'min_confidence' => (float) env('INTELLIGENCE_KNN_MIN_CONFIDENCE', 0.6),
        'min_validation_rows' => 50,
        // An absence of opportunities/evidence is UNKNOWN, never a quality failure.
        'min_directional_opportunities' => 5,
        'min_directional_predictions' => 5,
        'min_semantic_precision' => 0.55,
        'min_directional_wilson_lower' => 0.55,
        'min_directional_baseline_lift' => 0.02,
        'max_contradiction_rate' => 0.05,
        // Legacy diagnostic setting: Action directional coverage is no longer a hard gate.
        'min_coverage' => 0.01,
    ],
    'outcome' => [
        // Five-class Outcome quality gates. These are intentionally separate from Action KNN.
        'min_macro_f1' => min(1.0, max(0.0, (float) env('INTELLIGENCE_OUTCOME_MIN_MACRO_F1', 0.20))),
        'min_baseline_improvement' => min(1.0, max(0.0, (float) env('INTELLIGENCE_OUTCOME_MIN_BASELINE_IMPROVEMENT', 0.02))),
        'min_supported_predictions' => max(5, (int) env('INTELLIGENCE_OUTCOME_MIN_SUPPORTED_PREDICTIONS', 25)),
        'min_coverage' => min(1.0, max(0.0, (float) env('INTELLIGENCE_OUTCOME_MIN_COVERAGE', 0.01))),
    ],
    // Separate optional Random Forest advisor. Never appended to KNN vectors.
    'coingecko_insight' => [
        'enabled' => env('INTELLIGENCE_COINGECKO_INSIGHT_ENABLED', false),
        // Shadow-mode by default until paired Core + advisor validation.
        'influence_enabled' => env('INTELLIGENCE_COINGECKO_INFLUENCE_ENABLED', false),
        'max_weight' => (float) env('INTELLIGENCE_COINGECKO_MAX_WEIGHT', 0.15),
        'min_snapshots' => (int) env('INTELLIGENCE_COINGECKO_MIN_SNAPSHOTS', 192),
        'max_snapshots' => (int) env('INTELLIGENCE_COINGECKO_MAX_SNAPSHOTS', 2400),
        'min_holdout' => (int) env('INTELLIGENCE_COINGECKO_MIN_HOLDOUT', 32),
        'min_f1_improvement' => (float) env('INTELLIGENCE_COINGECKO_MIN_F1_IMPROVEMENT', 0.02),
        'trees' => (int) env('INTELLIGENCE_COINGECKO_TREES', 24),
        'depth' => (int) env('INTELLIGENCE_COINGECKO_DEPTH', 6),
    ],
    'patterns' => [
        'enabled' => true,
        'as_knn_features' => false, // Informational only; never appended to KNN vectors.
        'min_samples' => 100,
        'min_block_rows' => 15,
        'trees' => 50,
        'k' => 9,
    ],
];
