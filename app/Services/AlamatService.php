<?php

namespace App\Services;

use App\Models\Alamat;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AlamatService
{
    /**
     * Menyiapkan dependency yang diperlukan oleh class.
     *
     * @param  GeoapifyService  $geoapifyService  Service geoapify yang digunakan oleh class ini.
     *
     * @return void Tidak mengembalikan nilai; dependency disimpan pada instance.
     */
    public function __construct(private GeoapifyService $geoapifyService) {}

    /**
     * Menyediakan aturan validasi lokasi yang digunakan bersama oleh mutasi alamat buyer dan seller.
     *
     * Aturan mewajibkan koordinat, formatted address, detail alamat, place ID, dan sumber map dalam
     * bentuk yang konsisten. Rule yang sama dipakai buyer dan seller agar kedua flow tidak memiliki
     * definisi pinpoint berbeda.
     *
     * @return array<string, array<int, mixed>> Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function locationRules(): array
    {
        return [
            'location_source' => ['required', Rule::in(['map'])],
            'alamat' => ['nullable', 'string', 'max:2000'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'geoapify_place_id' => ['nullable', 'string', 'max:255'],
            'formatted_address' => ['nullable', 'string', 'max:2000'],
            'address_detail' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * Determine whether a stored address is eligible for new checkout flows.
     *
     * @param  Alamat|null  $alamat  Model alamat yang diperiksa atau digunakan sebagai snapshot.
     *
     * @return bool True ketika kondisi is verified pinpoint terpenuhi; false jika tidak.
     */
    public function isVerifiedPinpoint(?Alamat $alamat): bool
    {
        return $alamat !== null
            && $alamat->location_source === 'map'
            && is_numeric($alamat->latitude)
            && is_numeric($alamat->longitude)
            && trim((string) $alamat->formatted_address) !== ''
            && trim((string) $alamat->address_detail) !== '';
    }

    /**
     * Membentuk attribute alamat yang siap disimpan dari hasil verifikasi Geoapify di server.
     *
     * Koordinat request diverifikasi ulang melalui Geoapify dan metadata client diganti dengan hasil
     * server. Attribute yang dikembalikan siap disimpan tanpa mempercayai formatted address atau place
     * ID dari browser. Caller wajib menerapkan locationRules() sebelum memanggil method ini sehingga
     * koordinat berupa angka dan detail alamat berupa string.
     *
     * @param  Request  $request  Request terautentikasi dengan field lokasi yang sudah lolos validasi.
     *
     * @return array{
     *     latitude: float,
     *     longitude: float,
     *     geoapify_place_id: string|null,
     *     formatted_address: string,
     *     alamat: string,
     *     address_detail: string,
     *     location_source: 'map'
     * } Attribute lokasi terverifikasi yang siap disimpan.
     */
    public function locationAttributes(Request $request): array
    {
        /** @var int|float|numeric-string $latitude */
        $latitude = $request->latitude;
        /** @var int|float|numeric-string $longitude */
        $longitude = $request->longitude;
        /** @var string $addressDetail */
        $addressDetail = $request->address_detail;

        $verifiedLocation = $this->geoapifyService->verifyIndonesiaLocation(
            (float) $latitude,
            (float) $longitude,
        );
        $addressDetail = trim($addressDetail);

        return array_merge($verifiedLocation, [
            'alamat' => "{$addressDetail}, {$verifiedLocation['formatted_address']}",
            'address_detail' => $addressDetail,
            'location_source' => 'map',
        ]);
    }
}
