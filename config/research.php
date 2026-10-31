<?php

return [
    // Private, local/shared filesystem; never expose this directory under public/.
    'path' => storage_path('app/private/research'),
    // Backtests load the selected vectors into memory. Fail, never silently truncate.
    'max_rows' => 50000,
    // Durable cursor cadence and cooperative queued-job work slice; neither caps training history.
    'checkpoint_rows' => max(1, (int) env('RESEARCH_CHECKPOINT_ROWS', 512)),
    'checkpoint_work_seconds' => max(10, (int) env('RESEARCH_CHECKPOINT_WORK_SECONDS', 240)),
    // Maximum dataset build duration per attempt in seconds.
    'dataset_max_seconds' => max(60, (int) env('RESEARCH_DATASET_MAX_SECONDS', 540)),
];
