<?php

namespace App\Services;

use App\Models\Alamat;
use App\Models\Keranjang;
use App\Models\Product;

class KeranjangService
{
    /**
     * Menyiapkan dependency yang diperlukan oleh class.
     *
     * @param  ProductAvailabilityService  $productAvailabilityService  Service product availability yang digunakan oleh class ini.
     *
     * @return void  Tidak mengembalikan nilai; dependency disimpan pada instance.
     */
    public function __construct(private ProductAvailabilityService $productAvailabilityService) {}

    /**
     * Mengambil keranjang, menghitung availability terbaru, dan menetralkan pilihan item yang tidak valid.
     *
     * Cart buyer dimuat bersama produk dan seller, lalu setiap item direkonsiliasi terhadap stok serta
     * ketersediaan terkini. Item yang tidak valid tetap dipertahankan untuk penjelasan UI, tetapi
     * status checkout-nya diperbaiki agar tidak ikut diproses.
     *
     * @param  string  $user_id_buyer  ID buyer pemilik cart, alamat, atau transaksi.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function getKeranjangs(string $user_id_buyer = ''): array
    {
        // --- step 1 - start - ambil item termasuk produk yang sudah di-soft-delete
        $keranjangs = Keranjang::selectRaw('
                keranjangs.id as k_id,
                keranjangs.user_id_seller as k_user_id_seller,
                keranjangs.checked as k_checked,
                keranjangs.checkout as k_checkout,
                keranjangs.total as k_total,
                (keranjangs.total * COALESCE(products.price, 0)) as k_total_price,
                COALESCE(NULLIF(companies.name, \'\'), users.name) as u_seller_name,
                keranjangs.product_id as p_id,
                products.id as p_exists_id,
                products.name as p_name,
                products.price as p_price,
                products.stock as p_stock,
                products.img as p_img,
                products.deleted_at as p_deleted_at
            ')
            ->join('users', 'keranjangs.user_id_seller', '=', 'users.id')
            ->leftJoin('companies', 'companies.user_id', '=', 'users.id')
            ->leftJoin('products', 'keranjangs.product_id', '=', 'products.id')
            ->where('keranjangs.user_id_buyer', $user_id_buyer)
            ->orderBy('keranjangs.created_at', 'DESC')
            ->get();
        // --- step 1 - end - ambil item termasuk produk yang sudah di-soft-delete

        // --- step 2 - start - hitung status availability tanpa query alamat per item
        $verifiedSellerIds = $this->productAvailabilityService->verifiedSellerIds(
            $keranjangs->pluck('k_user_id_seller')->filter()->all()
        );
        $verifiedSellerLookup = array_fill_keys($verifiedSellerIds, true);
        $unavailableCartIds = [];
        $unavailableSelectedItemIds = [];
        $unavailableSelectedReasons = [];
        $unavailableCheckoutItemIds = [];
        $unavailableCheckoutReasons = [];
        $stockIssueCartIds = [];
        $stockIssues = [];
        $selectedStockIssues = [];
        $totalPrice = 0;

        foreach ($keranjangs as $keranjang) {
            // Samakan boolean PostgreSQL dan tiny integer MySQL dengan kontrak API berbentuk angka.
            $keranjang->k_checked = (int) (bool) $keranjang->k_checked;
            $keranjang->k_checkout = (int) (bool) $keranjang->k_checkout;

            $unavailableReason = $this->productAvailabilityService->unavailableReason(
                productExists: $keranjang->p_exists_id !== null,
                deletedAt: $keranjang->p_deleted_at,
                stock: intval($keranjang->p_stock ?? 0),
                sellerLocationVerified: isset($verifiedSellerLookup[$keranjang->k_user_id_seller]),
            );

            $keranjang->is_purchasable = $unavailableReason === null;
            $keranjang->unavailable_reason = $unavailableReason;
            $keranjang->stock_issue = null;

            $availableStock = intval($keranjang->p_stock ?? 0);
            $cartQuantity = intval($keranjang->k_total ?? 0);

            if ($unavailableReason === null && $cartQuantity > $availableStock) {
                $stockIssue = $this->makeStockIssue(
                    $keranjang,
                    'QUANTITY_EXCEEDS_STOCK',
                    $cartQuantity,
                    $availableStock,
                );

                $keranjang->stock_issue = $stockIssue;
                $stockIssueCartIds[] = $keranjang->k_id;
                $stockIssues[] = $stockIssue;

                if ((bool) $keranjang->k_checked) {
                    $selectedStockIssues[] = $stockIssue;
                }

                // Quantity tetap disimpan agar buyer dapat melihat dan menyetujui
                // penyesuaian sebelum item dipilih kembali.
                $keranjang->k_checked = 0;
                $keranjang->k_checkout = 0;
            }

            if ($unavailableReason !== null) {
                $unavailableCartIds[] = $keranjang->k_id;
                $outOfStockIssue = null;

                if ($unavailableReason === ProductAvailabilityService::OUT_OF_STOCK) {
                    $outOfStockIssue = $this->makeStockIssue(
                        $keranjang,
                        ProductAvailabilityService::OUT_OF_STOCK,
                        $cartQuantity,
                        $availableStock,
                    );
                    $stockIssues[] = $outOfStockIssue;
                }

                if ((bool) $keranjang->k_checked) {
                    $unavailableSelectedItemIds[] = $keranjang->k_id;
                    $unavailableSelectedReasons[$keranjang->k_id] = $unavailableReason;

                    if ($outOfStockIssue !== null) {
                        $selectedStockIssues[] = $outOfStockIssue;
                    }
                }

                if ((bool) $keranjang->k_checkout) {
                    $unavailableCheckoutItemIds[] = $keranjang->k_id;
                    $unavailableCheckoutReasons[$keranjang->k_id] = $unavailableReason;
                }

                // Response harus langsung konsisten dengan read-repair yang disimpan setelah loop.
                $keranjang->k_checked = 0;
                $keranjang->k_checkout = 0;
            }

            $keranjang->is_selectable = $keranjang->is_purchasable
                && $keranjang->stock_issue === null;

            if ($keranjang->is_selectable && (bool) $keranjang->k_checked) {
                $totalPrice += $keranjang->k_total_price;
            }
        }
        // --- step 2 - end - hitung status availability tanpa query alamat per item

        // --- step 3 - start - simpan status tidak terpilih tanpa menghilangkan quantity buyer
        $invalidCartIds = array_values(array_unique([
            ...$unavailableCartIds,
            ...$stockIssueCartIds,
        ]));

        if ($invalidCartIds !== []) {
            Keranjang::where('user_id_buyer', $user_id_buyer)
                ->whereIn('id', $invalidCartIds)
                ->update(['checked' => 0, 'checkout' => 0]);
        }
        // --- step 3 - end - simpan status tidak terpilih tanpa menghilangkan quantity buyer

        // --- step 4 - start - kelompokkan response berdasarkan seller
        $groupKeranjangs = $keranjangs->groupBy('k_user_id_seller')
            ->toArray();
        // --- step 4 - end - kelompokkan response berdasarkan seller

        return [
            'totalPrice' => $totalPrice,
            'keranjangs' => $groupKeranjangs,
            'unavailableSelectedItemIds' => array_values(array_unique($unavailableSelectedItemIds)),
            'unavailableSelectedReasons' => $unavailableSelectedReasons,
            'unavailableCheckoutItemIds' => array_values(array_unique($unavailableCheckoutItemIds)),
            'unavailableCheckoutReasons' => $unavailableCheckoutReasons,
            'stockIssues' => $stockIssues,
            'selectedStockIssues' => $selectedStockIssues,
        ];
    }

    /**
     * Membentuk detail masalah stok yang stabil untuk UI keranjang dan checkout.
     *
     * Issue memuat cart ID, product ID, quantity, stok tersedia, dan kode alasan yang dapat dipakai
     * semua endpoint. Struktur stabil ini memungkinkan controller memperbaiki selection tanpa
     * kehilangan penjelasan untuk UI.
     *
     * @param  object  $keranjang  Model item keranjang yang menjadi target pemeriksaan.
     * @param  string  $code  Kode alasan stabil yang dikembalikan kepada client.
     * @param  int  $cartQuantity  Quantity yang tersimpan pada item cart.
     * @param  int  $availableStock  Stok terbaru yang tersedia untuk produk.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    private function makeStockIssue(
        object $keranjang,
        string $code,
        int $cartQuantity,
        int $availableStock,
    ): array
    {
        return [
            'code' => $code,
            'cart_id' => $keranjang->k_id,
            'product_id' => $keranjang->p_id,
            'product_name' => $keranjang->p_name,
            'seller_id' => $keranjang->k_user_id_seller,
            'seller_name' => $keranjang->u_seller_name,
            'cart_quantity' => $cartQuantity,
            'available_stock' => $availableStock,
        ];
    }

    /**
     * Menetralkan pilihan item tertentu setelah transaksi checkout di-rollback.
     * Quantity sengaja tidak disentuh agar pilihan buyer tidak hilang.
     *
     * Update dibatasi ke cart milik buyer dan daftar ID yang diberikan. Hanya flag checked serta
     * checkout yang dinetralkan; quantity dipertahankan agar buyer dapat memperbaiki pilihan setelah
     * kegagalan.
     *
     * @param  string  $buyerId  ID buyer yang menjadi scope operasi.
     * @param  array<int, string>  $cartIds  Daftar ID cart yang akan direkonsiliasi atau diperbarui.
     *
     * @return void  Tidak mengembalikan nilai; proses dinyatakan berhasil ketika selesai tanpa exception.
     */
    public function uncheckCartItems(string $buyerId, array $cartIds): void
    {
        $cartIds = array_values(array_unique(array_filter($cartIds)));

        if ($cartIds === []) {
            return;
        }

        Keranjang::where('user_id_buyer', $buyerId)
            ->whereIn('id', $cartIds)
            ->update(['checked' => 0, 'checkout' => 0]);
    }

    /**
     * Mengambil produk yang tidak dapat dibeli, termasuk produk hilang atau soft-deleted.
     *
     * Function membandingkan daftar ID dengan produk aktif dan produk soft-deleted untuk membedakan
     * item hilang dari item yang tidak lagi dapat dibeli. Hasilnya mempertahankan kode alasan stabil
     * bagi keranjang dan checkout.
     *
     * @param  array  $product_ids  Daftar ID produk yang akan diperiksa.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function checkProductUnavailableByIds(array $product_ids = []): array
    {
        // --- step 1 - start - ambil produk dan seller terverifikasi
        $productIds = array_values(array_unique(array_filter($product_ids)));
        $products = Product::withTrashed()->whereIn('id', $productIds)->get();
        $verifiedSellerIds = $this->productAvailabilityService->verifiedSellerIds(
            $products->pluck('user_id_seller')->filter()->all()
        );
        $verifiedSellerLookup = array_fill_keys($verifiedSellerIds, true);
        $productLookup = $products->keyBy('id');
        // --- step 1 - end - ambil produk dan seller terverifikasi

        // --- step 2 - start - tentukan alasan unavailable setiap produk
        $unavailableIds = [];
        $reasons = [];

        foreach ($productIds as $productId) {
            $product = $productLookup->get($productId);
            $reason = $this->productAvailabilityService->unavailableReason(
                productExists: $product !== null,
                deletedAt: $product?->deleted_at,
                stock: intval($product?->stock ?? 0),
                sellerLocationVerified: $product !== null
                    && isset($verifiedSellerLookup[$product->user_id_seller]),
            );

            if ($reason !== null) {
                $unavailableIds[] = $productId;
                $reasons[$productId] = $reason;
            }
        }
        // --- step 2 - end - tentukan alasan unavailable setiap produk

        return [
            'ids' => $unavailableIds,
            'reasons' => $reasons,
        ];
    }

    /**
     * Alias sementara untuk caller lama selama seluruh alur beralih ke availability umum.
     *
     * @param  array  $product_ids  Daftar ID produk yang akan diperiksa.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function checkProductSoldOutByIds(array $product_ids = []): array
    {
        return $this->checkProductUnavailableByIds($product_ids);
    }

    /**
     * Memeriksa apakah buyer tidak memiliki item keranjang yang sedang dipilih.
     *
     * @param  string  $user_id_buyer  ID buyer pemilik cart, alamat, atau transaksi.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function checkKeranjangNotChecked(string $user_id_buyer = ''): array
    {
        $keranjangCheckedExists = Keranjang::where('user_id_buyer', $user_id_buyer)
            ->where('checked', 1)
            ->where('total', '>', 0)
            ->exists();

        return [
            'checked' => $keranjangCheckedExists,
        ];
    }

    /**
     * Menyelaraskan flag checkout agar hanya item yang masih dipilih buyer yang tetap aktif.
     *
     * Semua flag checkout buyer direset terlebih dahulu, kemudian hanya item yang masih checked yang
     * diaktifkan kembali. Dua tahap ini mencegah row lama tetap terpilih setelah pilihan buyer
     * berubah.
     *
     * @param  string  $user_id_buyer  ID buyer pemilik cart, alamat, atau transaksi.
     *
     * @return void  Tidak mengembalikan nilai; proses dinyatakan berhasil ketika selesai tanpa exception.
     */
    public function updateCheckoutKeranjang(string $user_id_buyer = ''): void
    {
        // --- step 1 - start - reset status checkout keranjang buyer
        Keranjang::where('user_id_buyer', $user_id_buyer)
            ->update([
                'checkout' => 0,
            ]);
        // --- step 1 - end - reset status checkout keranjang buyer

        // --- step 2 - start - tandai item terpilih sebagai checkout
        Keranjang::where('user_id_buyer', $user_id_buyer)
            ->where('checked', 1)
            ->update([
                'checkout' => 1,
            ]);
        // --- step 2 - end - tandai item terpilih sebagai checkout
    }

    /**
     * Memeriksa apakah buyer memiliki alamat pengiriman aktif.
     *
     * Query mencari alamat buyer aktif dalam scope user. Hasil boolean dipakai sebagai gate checkout
     * tanpa memuat seluruh detail alamat.
     *
     * @param  string  $user_id_buyer  ID buyer pemilik cart, alamat, atau transaksi.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function checkAlamatBuyerExist(string $user_id_buyer = ''): array
    {
        // --- step 1 - start - periksa keberadaan alamat buyer
        $alamatExists = Alamat::where('user_id', $user_id_buyer)
            ->where('type', 'buyer')
            ->where('enable', 1)
            ->exists();
        // --- step 1 - end - periksa keberadaan alamat buyer

        return [
            'exists' => $alamatExists,
        ];
    }
}
