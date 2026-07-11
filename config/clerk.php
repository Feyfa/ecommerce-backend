<?php

/**
 * Tujuan helper ini untuk membentuk daftar authorized parties Clerk
 * langsung dari FRONTEND_URL agar konfigurasi backend tidak redundant.
 */
$resolveAuthorizedParties = static function (): array {
    /* step 1: ambil frontend url utama aplikasi */
    $frontendUrl = trim((string) env('FRONTEND_URL', ''));

    /* step 2: tetap pecah dengan koma jika suatu saat format env diperluas */
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
    | Clerk Secret Key
    |--------------------------------------------------------------------------
    |
    | Secret key utama untuk memanggil Backend API Clerk dan memverifikasi
    | session token dari frontend.
    |
    */
    'secret_key' => env('CLERK_SECRET_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Clerk Authorized Parties
    |--------------------------------------------------------------------------
    |
    | Daftar origin frontend yang diizinkan saat memverifikasi token Clerk.
    | Pada project ini nilai tersebut langsung mengikuti FRONTEND_URL agar
    | konfigurasi auth backend tetap sederhana dan tidak redundant.
    |
    */
    'authorized_parties' => $resolveAuthorizedParties(),
];
