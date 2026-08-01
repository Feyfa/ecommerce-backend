<?php

namespace App\Services;

use App\Models\Alamat;
use App\Models\Company;

class CompanyService
{
    /**
     * Menyiapkan dependency yang diperlukan oleh class.
     *
     * @param  AlamatService  $alamatService  Service alamat yang digunakan oleh class ini.
     *
     * @return void  Tidak mengembalikan nilai; dependency disimpan pada instance.
     */
    public function __construct(private AlamatService $alamatService) {}

    /**
     * Mengambil profil perusahaan milik user.
     *
     * Profil perusahaan dan alamat seller aktif dimuat dalam scope user yang diberikan. Service
     * mengembalikan struktur konsisten meskipun salah satu bagian profil belum tersedia.
     *
     * @param  string  $user_id  ID user yang menjadi scope data atau mutasi.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function getCompany(string $user_id = ''): array
    {
        // --- step 1 - start - ambil profil toko
        $company = Company::where('user_id', $user_id)->first();
        $companyFillables = (new Company())->getFillable();

        $companyFormat = [];
        foreach ($companyFillables as $field) {
            $companyFormat[$field] = $company->$field ?? '';
        }
        // --- step 1 - end - ambil profil toko

        // --- step 2 - start - ambil alamat
        $alamat = Alamat::where('user_id', $user_id)
            ->where('type', 'seller')
            ->orderBy('created_at', 'DESC')
            ->first();
        $companyFormat['alamat'] = $alamat->alamat ?? '';
        $companyFormat['latitude'] = $alamat->latitude ?? null;
        $companyFormat['longitude'] = $alamat->longitude ?? null;
        $companyFormat['geoapify_place_id'] = $alamat->geoapify_place_id ?? null;
        $companyFormat['formatted_address'] = $alamat->formatted_address ?? null;
        $companyFormat['address_detail'] = $alamat->address_detail ?? null;
        $companyFormat['location_source'] = $alamat->location_source ?? 'manual';
        $companyFormat['seller_location_verified'] = $this->alamatService->isVerifiedPinpoint($alamat);
        // --- step 2 - end - ambil alamat

        return ['status' => 'success', 'company' => $companyFormat];
    }
}
