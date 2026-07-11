<?php

namespace App\Services\Clerk;

use Clerk\Backend\ClerkBackend;
use Clerk\Backend\Helpers\Jwks\AuthenticateRequestOptions;
use RuntimeException;

class ClerkBackendClientService
{
    /**
     * Tujuan service ini untuk menyiapkan instance SDK Clerk backend
     * dan opsi verifikasi request secara terpusat.
     */
    public function makeSdk(): ClerkBackend
    {
        $this->ensureSecretKeyConfiguration();

        return ClerkBackend::builder()
            ->setSecurity((string) config('clerk.secret_key'))
            ->build();
    }

    /**
     * Tujuan method ini untuk menyiapkan opsi authenticateRequest
     * agar middleware tidak perlu merakit konfigurasi Clerk berulang kali.
     */
    public function makeAuthenticateRequestOptions(): AuthenticateRequestOptions
    {
        $this->ensureSecretKeyConfiguration();

        return new AuthenticateRequestOptions(
            secretKey: $this->nullableConfig('clerk.secret_key'),
            authorizedParties: config('clerk.authorized_parties', []),
            acceptsToken: ['session_token']
        );
    }

    /**
     * Tujuan method ini untuk memastikan backend Clerk sudah memiliki
     * secret key sebelum dipakai autentikasi.
     */
    private function ensureSecretKeyConfiguration(): void
    {
        if ($this->nullableConfig('clerk.secret_key')) {
            return;
        }

        throw new RuntimeException('Clerk secret key is not configured yet.');
    }

    /**
     * Tujuan helper ini untuk mengubah value config kosong menjadi null
     * supaya lebih aman saat diteruskan ke SDK Clerk.
     */
    private function nullableConfig(string $key): ?string
    {
        $value = trim((string) config($key));

        return $value !== '' ? $value : null;
    }
}
