<?php

return [
    'enabled' => env('LEAD_LAG_ENABLED', true),
    'daily_refresh' => env('LEAD_LAG_DAILY_REFRESH', true),
    'max_peers' => 8,
    'max_lag' => 6,
    'min_samples' => 160,
    'min_block_rows' => 30,
    'min_correlation' => 0.2,
    'min_skill' => 0.05,
    'min_direction_advantage' => 0.05,
    'confidence_z' => 3.5,
    'max_age_days' => 14,
    'max_influence' => 0.25,
];
