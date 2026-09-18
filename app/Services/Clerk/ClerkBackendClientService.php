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
     *
     * @return ClerkBackend Hasil proses yang telah dinormalisasi sesuai kontrak function ini.
     */
    public function makeSdk(): ClerkBackend
    {
        $secretKey = $this->requiredSecretKey();

        return ClerkBackend::builder()
            ->setSecurity($secretKey)
            ->build();
    }

    /**
     * Tujuan method ini untuk menyiapkan opsi authenticateRequest
     * agar middleware tidak perlu merakit konfigurasi Clerk berulang kali.
     *
     * @return AuthenticateRequestOptions Hasil proses yang telah dinormalisasi sesuai kontrak function ini.
     */
    public function makeAuthenticateRequestOptions(): AuthenticateRequestOptions
    {
        return new AuthenticateRequestOptions(
            secretKey: $this->requiredSecretKey(),
            authorizedParties: $this->authorizedParties(),
            acceptsToken: ['session_token']
        );
    }

    /**
     * Mengambil secret key Clerk yang telah dinormalisasi untuk autentikasi backend.
     *
     * @return string Secret key non-empty yang aman diteruskan ke SDK Clerk.
     *
     * @throws RuntimeException Ketika secret key tidak tersedia sebagai string non-empty.
     */
    private function requiredSecretKey(): string
    {
        $secretKey = $this->nullableConfig('clerk.secret_key');

        if ($secretKey !== null) {
            return $secretKey;
        }

        throw new RuntimeException('Clerk secret key is not configured yet.');
    }

    /**
     * Mengambil allowlist origin Clerk sebagai daftar string yang tervalidasi.
     *
     * Array kosong tetap didukung, sedangkan bentuk config atau anggota non-string ditolak agar
     * pemeriksaan authorized party tidak dinonaktifkan secara diam-diam oleh konfigurasi malformed.
     *
     * @return list<string> Daftar authorized party yang aman diteruskan ke SDK Clerk.
     *
     * @throws RuntimeException Ketika konfigurasi bukan array atau memiliki anggota non-string.
     */
    private function authorizedParties(): array
    {
        $configuredParties = config('clerk.authorized_parties', []);

        if (! is_array($configuredParties)) {
            throw new RuntimeException('Clerk authorized parties must be configured as an array of strings.');
        }

        $authorizedParties = [];

        foreach ($configuredParties as $configuredParty) {
            if (! is_string($configuredParty)) {
                throw new RuntimeException('Clerk authorized parties must be configured as an array of strings.');
            }

            $authorizedParties[] = $configuredParty;
        }

        return $authorizedParties;
    }

    /**
     * Tujuan helper ini untuk mengubah value config kosong menjadi null
     * supaya lebih aman saat diteruskan ke SDK Clerk.
     *
     * @param  string  $key  Nama configuration key yang akan dinormalisasi.
     *
     * @return string|null Nilai teks yang telah dinormalisasi, atau null ketika sumber datanya tidak tersedia.
     */
    private function nullableConfig(string $key): ?string
    {
        $configuredValue = config($key);

        if (! is_string($configuredValue)) {
            return null;
        }

        $value = trim($configuredValue);

        return $value !== '' ? $value : null;
    }
}
