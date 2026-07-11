<?php

/**
 * Tujuan helper ini untuk membatasi CORS hanya ke origin frontend yang memang
 * dikonfigurasi untuk environment aktif.
 */
$resolveFrontendOrigins = static function (): array {
    $frontendUrl = trim((string) env('FRONTEND_URL', ''));

    return array_values(
        array_filter(
            array_map(
                static fn (string $value): string => trim($value),
                explode(',', $frontendUrl)
            )
        )
    );
};

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $resolveFrontendOrigins(),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
