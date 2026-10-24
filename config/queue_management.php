<?php

return [
    'priority_enabled' => (bool) env('QUEUE_PRIORITY_ENABLED', true),
    'read_batch_size' => max(10, min(1000, (int) env('QUEUE_READ_BATCH_SIZE', 100))),
    'claim_retries' => max(1, min(20, (int) env('QUEUE_CLAIM_RETRIES', 5))),
    'aging_seconds' => [
        'default' => max(60, (int) env('QUEUE_DEFAULT_MAX_WAIT', 3600)),
        'features' => max(60, (int) env('QUEUE_FEATURES_MAX_WAIT', 120)),
        'signals' => max(60, (int) env('QUEUE_SIGNALS_MAX_WAIT', 120)),
        'intelligence' => max(60, (int) env('QUEUE_INTELLIGENCE_MAX_WAIT', 3600)),
        'history' => max(60, (int) env('QUEUE_HISTORY_MAX_WAIT', 1800)),
        'tournament' => max(60, (int) env('QUEUE_TOURNAMENT_MAX_WAIT', 7200)),
    ],
    'feed_publication_grace_seconds' => max(0, (int) env('OHLCV_PUBLICATION_GRACE_SECONDS', 5)),
    'feed_publication_retry_seconds' => max(15, (int) env('OHLCV_PUBLICATION_RETRY_SECONDS', 60)),
    'feed_max_wait_seconds' => max(60, (int) env('OHLCV_MAX_WAIT_SECONDS', 300)),
    'workers' => [
        'connection' => env('WORKER_REDIS_CONNECTION', 'default'),
        'heartbeat_seconds' => max(1, (int) env('WORKER_HEARTBEAT_SECONDS', 10)),
        'overdue_seconds' => max(1, (int) env('WORKER_OVERDUE_SECONDS', 30)),
        'lost_seconds' => max(1, (int) env('WORKER_LOST_SECONDS', 60)),
        'lease_seconds' => max(30, (int) env('WORKER_LEASE_SECONDS', 90)),
        'retention_seconds' => max(3600, (int) env('WORKER_RETENTION_SECONDS', 604800)),
        'hostname' => env('WORKER_HOSTNAME'),
        'ip' => env('WORKER_IP'),
    ],
];
