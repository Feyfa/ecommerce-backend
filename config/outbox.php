<?php

return [
    'max_attempts' => (int) env('OUTBOX_MAX_ATTEMPTS', 20),
    'batch_size' => (int) env('OUTBOX_BATCH_SIZE', 100),
    'max_batches' => (int) env('OUTBOX_MAX_BATCHES', 10),
    'lock_timeout_seconds' => (int) env('OUTBOX_LOCK_TIMEOUT_SECONDS', 300),
    'retry_base_seconds' => (int) env('OUTBOX_RETRY_BASE_SECONDS', 60),
    'retry_max_seconds' => (int) env('OUTBOX_RETRY_MAX_SECONDS', 21600),
    'published_retention_days' => (int) env('OUTBOX_PUBLISHED_RETENTION_DAYS', 7),
];
