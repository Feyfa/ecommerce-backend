<?php

$trustedProxies = trim((string) env('TRUSTED_PROXIES', ''));

return [
    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Staging and production receive requests through Nginx. Keep this empty
    | for native development, and use REMOTE_ADDR in Docker so Laravel trusts
    | only the backend Nginx instance that directly calls PHP-FPM.
    |
    */
    'proxies' => $trustedProxies !== '' ? $trustedProxies : null,
];
