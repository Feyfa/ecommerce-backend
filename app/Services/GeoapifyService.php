<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class GeoapifyService
{
    /**
     * Memverifikasi koordinat pilihan user melalui Geoapify dan hanya
     * mengembalikan data alamat Indonesia yang sudah dipercaya aplikasi.
     *
     * Koordinat dikirim ke reverse-geocoding provider dan response diperiksa untuk negara Indonesia,
     * place ID, serta alamat terformat. Hanya subset data provider yang telah dinormalisasi yang
     * diteruskan untuk penyimpanan.
     *
     * @param  float  $latitude  Koordinat lintang yang akan diverifikasi oleh provider.
     * @param  float  $longitude  Koordinat bujur yang akan diverifikasi oleh provider.
     *
     * @return array<string, mixed>  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function verifyIndonesiaLocation(float $latitude, float $longitude): array
    {
        // --- step 1 - start - validasi konfigurasi server
        $apiKey = trim((string) config('services.geoapify.key'));
        if ($apiKey == '') {
            $this->throwUnavailable();
        }
        // --- step 1 - end - validasi konfigurasi server

        // --- step 2 - start - verifikasi koordinat melalui provider tepercaya
        try {
            $response = Http::acceptJson()
                ->timeout((int) config('services.geoapify.timeout', 8))
                ->get(rtrim((string) config('services.geoapify.url'), '/').'/reverse', [
                    'lat' => $latitude,
                    'lon' => $longitude,
                    'lang' => 'id',
                    'limit' => 1,
                    'format' => 'json',
                    'apiKey' => $apiKey,
                ]);
        } catch (ConnectionException) {
            $this->throwUnavailable();
        }

        if (! $response->successful()) {
            $this->throwUnavailable();
        }
        // --- step 2 - end - verifikasi koordinat melalui provider tepercaya

        // --- step 3 - start - validasi wilayah Indonesia dan normalisasi data alamat
        $result = $response->json('results.0');
        if (! is_array($result) || empty($result['formatted'])) {
            throw ValidationException::withMessages([
                'latitude' => ['Alamat pada pinpoint tidak ditemukan.'],
            ]);
        }

        if (strtolower((string) ($result['country_code'] ?? '')) !== 'id') {
            throw ValidationException::withMessages([
                'latitude' => ['Lokasi harus berada di wilayah Indonesia.'],
            ]);
        }

        $location = [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'geoapify_place_id' => ! empty($result['place_id']) ? (string) $result['place_id'] : null,
            'formatted_address' => trim((string) $result['formatted']),
        ];
        // --- step 3 - end - validasi wilayah Indonesia dan normalisasi data alamat

        return $location;
    }

    /**
     * Menghentikan proses ketika verifikasi lokasi dari server tidak dapat diselesaikan.
     *
     * @return never  Hasil proses yang telah dinormalisasi sesuai kontrak function ini.
     */
    private function throwUnavailable(): never
    {
        throw new HttpResponseException(response()->json([
            'result' => 'error',
            'code' => 'LOCATION_VERIFICATION_UNAVAILABLE',
            'message' => 'Lokasi belum dapat diverifikasi. Silakan coba lagi.',
        ], 503));
    }
}
