<?php

use App\Services\XenditService;

return [
    // Kredensial API
    'key' => env('XENDIT_KEY', ''),

    // Batas waktu request virtual account; alasan nilai default ada di XenditService.
    'connect_timeout_seconds' => env(
        'XENDIT_CONNECT_TIMEOUT_SECONDS',
        XenditService::DEFAULT_CONNECT_TIMEOUT_SECONDS,
    ),
    'request_timeout_seconds' => env(
        'XENDIT_REQUEST_TIMEOUT_SECONDS',
        XenditService::DEFAULT_REQUEST_TIMEOUT_SECONDS,
    ),
];
