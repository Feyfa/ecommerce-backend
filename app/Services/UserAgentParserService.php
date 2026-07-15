<?php

namespace App\Services;

/**
 * Membentuk metadata perangkat sederhana dari user-agent request.
 */
class UserAgentParserService
{
    /**
     * Tujuan method ini untuk mengambil ringkasan perangkat tanpa
     * menyimpan atau menampilkan detail user-agent mentah ke frontend.
     *
     * @return array{browser: string|null, operating_system: string|null, device_type: string|null}
     */
    public function parse(?string $userAgent): array
    {
        // --- step 1 - start - normalisasi input dan kembalikan bentuk data stabil saat header kosong
        $userAgent = trim((string) $userAgent);

        if ($userAgent === '') {
            return [
                'browser' => null,
                'operating_system' => null,
                'device_type' => null,
            ];
        }
        // --- step 1 - end - normalisasi input dan kembalikan bentuk data stabil saat header kosong

        // --- step 2 - start - resolve setiap atribut secara independen agar data parsial tetap bisa dipakai
        $device = [
            'browser' => $this->resolveBrowser($userAgent),
            'operating_system' => $this->resolveOperatingSystem($userAgent),
            'device_type' => $this->resolveDeviceType($userAgent),
        ];
        // --- step 2 - end - resolve setiap atribut secara independen agar data parsial tetap bisa dipakai

        return $device;
    }

    /**
     * Mendeteksi browser utama yang dipakai request.
     *
     * @param  string  $userAgent  User-agent yang sudah dinormalisasi.
     */
    private function resolveBrowser(string $userAgent): ?string
    {
        return match (true) {
            str_contains($userAgent, 'Edg/') => 'Microsoft Edge',
            str_contains($userAgent, 'OPR/'), str_contains($userAgent, 'Opera/') => 'Opera',
            str_contains($userAgent, 'CriOS'), str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'FxiOS'), str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };
    }

    /**
     * Mendeteksi sistem operasi utama yang dipakai request.
     *
     * @param  string  $userAgent  User-agent yang sudah dinormalisasi.
     */
    private function resolveOperatingSystem(string $userAgent): ?string
    {
        return match (true) {
            str_contains($userAgent, 'Windows NT') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            preg_match('/iPhone|iPad|iPod/', $userAgent) === 1 => 'iOS',
            str_contains($userAgent, 'Mac OS X'), str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };
    }

    /**
     * Mengelompokkan perangkat menjadi Desktop, Tablet, atau Mobile.
     *
     * @param  string  $userAgent  User-agent yang sudah dinormalisasi.
     */
    private function resolveDeviceType(string $userAgent): ?string
    {
        if (preg_match('/iPad|Tablet|PlayBook|Silk/i', $userAgent) === 1
            || (str_contains($userAgent, 'Android') && ! str_contains($userAgent, 'Mobile'))) {
            return 'Tablet';
        }

        if (preg_match('/Mobile|iPhone|iPod/i', $userAgent) === 1) {
            return 'Mobile';
        }

        if (preg_match('/Windows NT|Macintosh|Mac OS X|X11|Linux/i', $userAgent) === 1) {
            return 'Desktop';
        }

        return null;
    }
}
