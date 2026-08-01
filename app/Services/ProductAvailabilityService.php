<?php

namespace App\Services;

use App\Models\Alamat;
use App\Models\Product;

class ProductAvailabilityService
{
    public const PRODUCT_DELETED = 'PRODUCT_DELETED';

    public const OUT_OF_STOCK = 'OUT_OF_STOCK';

    public const SELLER_LOCATION_UNVERIFIED = 'SELLER_LOCATION_UNVERIFIED';

    /**
     * Menyiapkan dependency yang diperlukan oleh class.
     *
     * @param  AlamatService  $alamatService  Service alamat yang digunakan oleh class ini.
     *
     * @return void  Tidak mengembalikan nilai; dependency disimpan pada instance.
     */
    public function __construct(private AlamatService $alamatService) {}

    /**
     * Menentukan apakah seller memiliki satu lokasi toko aktif yang sudah diverifikasi.
     *
     * @param  string  $sellerId  ID seller yang menjadi scope pemeriksaan.
     *
     * @return bool  True ketika kondisi seller has verified address terpenuhi; false jika tidak.
     */
    public function sellerHasVerifiedAddress(string $sellerId): bool
    {
        return in_array($sellerId, $this->verifiedSellerIds([$sellerId]), true);
    }

    /**
     * Mengembalikan seller yang mempunyai lokasi toko aktif dan terverifikasi tanpa query per produk.
     *
     * Query memilih seller yang memiliki setidaknya satu alamat aktif bertipe map dengan seluruh
     * metadata verifikasi. Hasil berbentuk ID unik agar pengecekan banyak produk tidak menimbulkan N+1
     * query.
     *
     * @param  array<int, string>  $sellerIds  Daftar ID seller yang akan diperiksa secara sekaligus.
     *
     * @return array<int, string>  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function verifiedSellerIds(array $sellerIds): array
    {
        $sellerIds = array_values(array_unique(array_filter($sellerIds)));

        if ($sellerIds === []) {
            return [];
        }

        return Alamat::whereIn('user_id', $sellerIds)
            ->where('type', 'seller')
            ->where('enable', 1)
            ->get()
            ->filter(fn (Alamat $alamat) => $this->alamatService->isVerifiedPinpoint($alamat))
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Menentukan alasan utama produk tidak dapat dibeli sesuai prioritas buyer.
     *
     * Prioritas alasan dibuat deterministik: produk hilang atau dihapus, stok habis, lalu lokasi
     * seller belum terverifikasi. Urutan yang sama dipakai UI cart dan checkout agar pesan tidak
     * berubah antar-endpoint.
     *
     * @param  bool  $productExists  Penanda bahwa produk masih tersedia pada query aktif.
     * @param  mixed  $deletedAt  Waktu soft delete produk jika produk telah dinonaktifkan.
     * @param  int  $stock  Jumlah stok produk untuk skenario atau perubahan terkait.
     * @param  bool  $sellerLocationVerified  Penanda bahwa lokasi seller memenuhi invariant pinpoint.
     *
     * @return string|null  Nilai teks yang telah dinormalisasi, atau null ketika sumber datanya tidak tersedia.
     */
    public function unavailableReason(
        bool $productExists,
        mixed $deletedAt,
        int $stock,
        bool $sellerLocationVerified,
    ): ?string {
        return match (true) {
            ! $productExists || $deletedAt !== null => self::PRODUCT_DELETED,
            $stock < 1 => self::OUT_OF_STOCK,
            ! $sellerLocationVerified => self::SELLER_LOCATION_UNVERIFIED,
            default => null,
        };
    }

    /**
     * Menentukan apakah seluruh item unavailable mempunyai satu alasan yang sama.
     *
     * @param  array<string, string>  $reasons  Pemetaan alasan ketersediaan berdasarkan ID produk.
     * @param  string  $expectedReason  Kode alasan ketersediaan yang diharapkan oleh test.
     *
     * @return bool  True ketika kondisi has only unavailable reason terpenuhi; false jika tidak.
     */
    public function hasOnlyUnavailableReason(array $reasons, string $expectedReason): bool
    {
        return $reasons !== []
            && collect($reasons)->every(
                fn (string $reason): bool => $reason === $expectedReason,
            );
    }

    /**
     * Mengambil produk termasuk soft-deleted untuk menjelaskan status item keranjang lama.
     *
     * @param  string  $productId  ID produk yang menjadi target operasi.
     *
     * @return Product|null  Model produk yang dibuat atau digunakan sebagai fixture.
     */
    public function findProductForAvailability(string $productId): ?Product
    {
        return Product::withTrashed()->whereKey($productId)->first();
    }
}
