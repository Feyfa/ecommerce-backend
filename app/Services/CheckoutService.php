<?php

namespace App\Services;

use App\Exceptions\CheckoutAvailabilityException;
use App\Exceptions\CheckoutChangedException;
use App\Models\Alamat;
use App\Models\Keranjang;
use App\Models\PaymentList;
use App\Models\Product;
use App\Models\TransactionInvoice;
use App\Models\TransactionProduct;
use App\Models\TransactionUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CheckoutService
{
    /**
     * Menyiapkan dependency yang diperlukan oleh class.
     *
     * @param  AlamatService  $alamatService  Service alamat yang digunakan oleh class ini.
     * @param  KeranjangService  $keranjangService  Service keranjang yang digunakan oleh class ini.
     * @param  ProductAvailabilityService  $productAvailabilityService  Service product availability yang digunakan oleh class ini.
     *
     * @return void  Tidak mengembalikan nilai; dependency disimpan pada instance.
     */
    public function __construct(
        private AlamatService $alamatService,
        private KeranjangService $keranjangService,
        private ProductAvailabilityService $productAvailabilityService,
    ) {}

    /**
     * Menyinkronkan item checkout dengan availability terbaru sebelum halaman atau proses dilanjutkan.
     *
     * Service menjalankan read-repair pada cart checkout dan mengembalikan state serta issue yang
     * ditemukan. Pemanggil dapat menampilkan penyebab perubahan tanpa kehilangan detail setelah item
     * bermasalah dilepas dari pilihan.
     *
     * @param  string  $buyerId  ID buyer yang menjadi scope operasi.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function reconcileCheckoutCart(string $buyerId): array
    {
        return $this->keranjangService->getKeranjangs($buyerId);
    }

    /**
     * Membentuk error availability checkout tanpa kehilangan penyebab setelah read-repair cart.
     *
     * Issue ketersediaan diprioritaskan menjadi kode dan pesan bisnis yang stabil. Cart terbaru tetap
     * disertakan agar frontend dapat memperbarui state setelah validasi backend menolak checkout.
     *
     * @param  array<string, mixed>  $cartState  State cart terbaru beserta issue ketersediaannya.
     *
     * @return array{code: string, message: string}|null  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function checkoutAvailabilityError(array $cartState): ?array
    {
        if (($cartState['unavailableCheckoutItemIds'] ?? []) === []) {
            return null;
        }

        // Seller yang kehilangan Pinpoint setelah checkout terbentuk membutuhkan
        // tindakan UI khusus. Alasan lain tetap memakai recovery checkout generik.
        if ($this->productAvailabilityService->hasOnlyUnavailableReason(
            $cartState['unavailableCheckoutReasons'] ?? [],
            ProductAvailabilityService::SELLER_LOCATION_UNVERIFIED,
        )) {
            return [
                'code' => 'SELLER_ADDRESS_REQUIRES_VERIFICATION',
                'message' => 'Lokasi toko penjual belum diverifikasi. Checkout belum dapat dilanjutkan.',
            ];
        }

        return [
            'code' => 'CHECKOUT_INVALID',
            'message' => 'Produk di checkout sudah tidak tersedia. Silakan periksa kembali keranjang.',
        ];
    }

    /**
     * Menyimpan checked=0 setelah transaksi checkout gagal dan sudah selesai di-rollback.
     *
     * @param  string  $buyerId  ID buyer yang menjadi scope operasi.
     * @param  array<int, string>  $cartIds  Daftar ID cart yang akan direkonsiliasi atau diperbarui.
     *
     * @return void  Tidak mengembalikan nilai; proses dinyatakan berhasil ketika selesai tanpa exception.
     */
    public function uncheckCheckoutItems(string $buyerId, array $cartIds): void
    {
        $this->keranjangService->uncheckCartItems($buyerId, $cartIds);
    }

    /**
     *
     * ID cart diekstrak dari issue ketersediaan, dinormalisasi menjadi string unik, dan dipakai untuk
     * read-repair selection. Nilai kosong dibuang agar update berikutnya tidak menyasar row yang tidak
     * valid.
     *
     * @param  array  $checkouts  Kumpulan item checkout yang telah dimuat dan divalidasi.
     *
     * @return array<int, string>  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function checkoutCartIds(array $checkouts): array
    {
        $cartIds = [];
        foreach ($checkouts as $checkout) {
            foreach (($checkout['keranjangs'] ?? []) as $keranjang) {
                if (($keranjang['k_id'] ?? '') !== '') {
                    $cartIds[] = $keranjang['k_id'];
                }
            }
        }

        return array_values(array_unique($cartIds));
    }

    /**
     * Menyediakan aturan kelayakan alamat untuk endpoint awal checkout.
     *
     * @param  Alamat|null  $alamat  Model alamat yang diperiksa atau digunakan sebagai snapshot.
     *
     * @return bool  True ketika kondisi is address verified terpenuhi; false jika tidak.
     */
    public function isAddressVerified(?Alamat $alamat): bool
    {
        return $this->alamatService->isVerifiedPinpoint($alamat);
    }

    /**
     * Memeriksa status verifikasi alamat setiap seller dalam checkout.
     *
     * Setiap grup checkout diperiksa terhadap alamat seller yang aktif, bertipe map, dan memiliki
     * metadata pinpoint lengkap. Satu seller yang gagal memenuhi invariant sudah cukup untuk
     * menghentikan checkout.
     *
     * @param  array  $checkouts  Kumpulan item checkout yang telah dimuat dan divalidasi.
     *
     * @return bool  True ketika kondisi has unverified seller address terpenuhi; false jika tidak.
     */
    public function hasUnverifiedSellerAddress(array $checkouts): bool
    {
        $sellerIds = array_values(array_unique(array_filter(array_column($checkouts, 'user_id_seller'))));
        if (count($sellerIds) === 0) {
            return false;
        }

        $verifiedSellerIds = Alamat::whereIn('user_id', $sellerIds)
            ->where('type', 'seller')
            ->where('enable', 1)
            ->get()
            ->filter(fn (Alamat $alamat) => $this->alamatService->isVerifiedPinpoint($alamat))
            ->pluck('user_id')
            ->all();

        return count(array_diff($sellerIds, $verifiedSellerIds)) > 0;
    }

    /**
     * Mengambil alamat buyer aktif yang memenuhi kontrak lokasi untuk checkout.
     *
     * Service mengambil satu alamat buyer aktif dengan metadata lokasi lengkap. Alamat manual legacy
     * atau pinpoint yang belum terverifikasi tidak dianggap layak untuk snapshot pengiriman.
     *
     * @param  string  $user_id_buyer  ID buyer pemilik cart, alamat, atau transaksi.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function getAlamatBuyer(string $user_id_buyer = ''): array
    {
        // --- step 1 - start - ambil alamat aktif buyer
        $alamat = Alamat::where('user_id', $user_id_buyer)
            ->where('type', 'buyer')
            ->where('enable', 1)
            ->first();
        // --- step 1 - end - ambil alamat aktif buyer

        return [
            'alamat' => $alamat,
        ];
    }

    /**
     * Mengambil item keranjang yang sedang dipilih buyer untuk diproses pada checkout.
     *
     * Query memuat item checkout milik buyer beserta produk, seller, gambar, dan data pendukung yang
     * dibutuhkan. Item kemudian dikelompokkan menggunakan kontrak yang sama dengan tampilan checkout.
     *
     * @param  string  $user_id_buyer  ID buyer pemilik cart, alamat, atau transaksi.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function getKeranjangCheckout(string $user_id_buyer = ''): array
    {
        // --- step 1 - start - ambil dan kelompokkan item checkout buyer
        $keranjangs = Keranjang::selectRaw('
                keranjangs.id as k_id,
                keranjangs.user_id_seller as k_user_id_seller,
                keranjangs.total as k_total,
                (keranjangs.total * products.price) as k_total_price,
                products.id as p_id,
                products.name as p_name,
                products.price as p_price,
                products.img as p_img,
                COALESCE(NULLIF(companies.name, \'\'), users.name) as u_name_seller
            ')
            ->join('users', 'keranjangs.user_id_seller', '=', 'users.id')
            ->leftJoin('companies', 'companies.user_id', '=', 'users.id')
            ->join('products', 'keranjangs.product_id', '=', 'products.id')
            ->where('keranjangs.user_id_buyer', $user_id_buyer)
            ->where('keranjangs.checkout', 1)
            ->where('keranjangs.total', '>', 0)
            ->whereNull('products.deleted_at')
            ->orderBy('k_user_id_seller', 'ASC')
            ->get();

        $groupKeranjangs = $keranjangs->groupBy('k_user_id_seller')
            ->toArray();
        // --- step 1 - end - ambil dan kelompokkan item checkout buyer

        // --- step 2 - start - hitung total harga produk
        $totalPrice = 0;
        foreach ($keranjangs as $keranjang) {
            $totalPrice += $keranjang->k_total_price;
        }
        // --- step 2 - end - hitung total harga produk

        // --- step 3 - start - bentuk paket checkout per seller
        $checkouts = [];
        foreach ($groupKeranjangs as $keranjangs) {
            $generateFormatKeranjangs = $this->generateFormatKeranjangs($keranjangs);
            $keranjangsFormat = $generateFormatKeranjangs['keranjangs'];

            $generateFormatKurirs = $this->generateFormatKurirs();
            $kurirs = $generateFormatKurirs['kurirs'];

            $checkouts[] = [
                'user_id_seller' => $keranjangs[0]['k_user_id_seller'],
                'user_name_seller' => $keranjangs[0]['u_name_seller'],
                'keranjangs' => $keranjangsFormat,
                'kurirs' => $kurirs,
            ];
        }
        // --- step 3 - end - bentuk paket checkout per seller

        return [
            'checkouts' => $checkouts,
            'totalPrice' => $totalPrice,
        ];
    }

    /**
     * Mengelompokkan item keranjang menjadi paket checkout per seller.
     *
     * Item cart dikelompokkan per seller sambil mempertahankan produk, quantity, harga, dan identitas
     * toko yang diperlukan checkout. Struktur hasil menjadi dasar ongkir, catatan, serta transaksi
     * seller.
     *
     * @param  array  $keranjangs  Kumpulan item keranjang yang akan dikelompokkan atau diproses.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    private function generateFormatKeranjangs($keranjangs = []): array
    {
        $keranjangsFormat = [];
        foreach ($keranjangs as $keranjang) {
            $keranjangsFormat[] = [
                'k_id' => $keranjang['k_id'],
                'k_total' => $keranjang['k_total'],
                'k_total_price' => $keranjang['k_total_price'],
                'p_id' => $keranjang['p_id'],
                'p_name' => $keranjang['p_name'],
                'p_price' => $keranjang['p_price'],
                'p_img' => $keranjang['p_img'],
            ];
        }

        return [
            'keranjangs' => $keranjangsFormat,
        ];
    }

    /**
     * Menormalisasi pilihan kurir untuk setiap paket seller.
     *
     * Pilihan kurir dari request dicocokkan ke setiap grup seller dan dinormalisasi ke bentuk yang
     * dapat dibandingkan. Seller tanpa pilihan valid membuat snapshot berbeda sehingga checkout harus
     * dikonfirmasi ulang.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    private function generateFormatKurirs(): array
    {
        // --- step 1 - start - siapkan waktu dan daftar kurir
        Carbon::setLocale('id');
        $now = Carbon::now('Asia/Jakarta');
        $startDate = $now->translatedFormat('d F Y');

        $kurirsFormat = [];
        $kurirslists = [
            ['name' => 'JNT', 'day' => 1],
            ['name' => 'Anter Aja', 'day' => 2],
            ['name' => 'Si Cepat Halu', 'day' => 3],
        ];
        // --- step 1 - end - siapkan waktu dan daftar kurir

        // --- step 2 - start - bentuk harga dan estimasi setiap kurir
        foreach ($kurirslists as $kurir) {
            $day = $kurir['day'];
            $endDate = $now->copy()->addDays($day)->translatedFormat('d F Y');
            $price = (-5000 * $day) + 20000;

            $kurirsFormat[] = [
                'name' => $kurir['name'],
                'price' => $price,
                'estimation' => "{$startDate} - {$endDate}",
            ];
        }
        // --- step 2 - end - bentuk harga dan estimasi setiap kurir

        return [
            'kurirs' => $kurirsFormat,
        ];
    }

    /**
     * membuat snapshot checkout dari database sebagai sumber kebenaran backend.
     *
     * Snapshot dihitung ulang dari cart, alamat, ongkir, catatan, serta metode pembayaran yang
     * tersimpan di backend. Harga dan struktur seller tidak dipercaya dari frontend, sehingga hasil
     * ini menjadi dasar checkout key dan pemeriksaan perubahan sebelum pembayaran.
     *
     * @param  string  $user_id_buyer  ID buyer pemilik cart, alamat, atau transaksi.
     * @param  array  $shippingOptions  Pilihan kurir per seller yang dikirim untuk penyusunan snapshot.
     * @param  array  $noteds  Catatan buyer yang dipetakan untuk setiap grup seller.
     * @param  string  $paymentSlug  Slug metode pembayaran yang dipilih.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function buildCheckoutSnapshot(string $user_id_buyer = '', array $shippingOptions = [], array $noteds = [], string $paymentSlug = ''): array
    {
        // --- step 1 - start - sinkronkan availability produk checkout
        $cartState = $this->reconcileCheckoutCart($user_id_buyer);

        $availabilityError = $this->checkoutAvailabilityError($cartState);

        if ($availabilityError !== null) {
            return [
                'status' => 'invalid',
                ...$availabilityError,
            ];
        }
        // --- step 1 - end - sinkronkan availability produk checkout

        // --- step 2 - start - validasi alamat aktif buyer
        $getAlamatBuyer = $this->getAlamatBuyer($user_id_buyer);
        $alamat = $getAlamatBuyer['alamat'];

        if (empty($alamat)) {
            return [
                'status' => 'invalid',
                'code' => 'CHECKOUT_INVALID',
                'message' => 'Alamat belum ditambahkan',
            ];
        }

        if (! $this->alamatService->isVerifiedPinpoint($alamat)) {
            return [
                'status' => 'invalid',
                'code' => 'ADDRESS_REQUIRES_VERIFICATION',
                'message' => 'Alamat pengiriman perlu diverifikasi dengan pinpoint sebelum melanjutkan checkout.',
            ];
        }
        // --- step 2 - end - validasi alamat aktif buyer

        // --- step 3 - start - ambil dan validasi paket checkout
        $getKeranjangCheckout = $this->getKeranjangCheckout($user_id_buyer);
        $checkouts = $getKeranjangCheckout['checkouts'];

        if (count($checkouts) == 0) {
            return [
                'status' => 'invalid',
                'code' => 'CHECKOUT_INVALID',
                'message' => 'Keranjang Not Checked',
            ];
        }

        if ($this->hasUnverifiedSellerAddress($checkouts)) {
            return [
                'status' => 'invalid',
                'code' => 'SELLER_ADDRESS_REQUIRES_VERIFICATION',
                'message' => 'Lokasi toko penjual belum diverifikasi. Checkout belum dapat dilanjutkan.',
            ];
        }
        // --- step 3 - end - ambil dan validasi paket checkout

        // --- step 4 - start - validasi quantity dan status produk checkout
        $invalidCheckoutKeranjangExists = Keranjang::leftJoin('products', 'keranjangs.product_id', '=', 'products.id')
            ->where('keranjangs.user_id_buyer', $user_id_buyer)
            ->where('keranjangs.checkout', 1)
            ->where(function ($query) {
                $query->whereNull('products.id')
                    ->orWhereNotNull('products.deleted_at')
                    ->orWhere('keranjangs.total', '<', 1)
                    ->orWhereColumn('keranjangs.total', '>', 'products.stock');
            })
            ->exists();

        if ($invalidCheckoutKeranjangExists) {
            return [
                'status' => 'invalid',
                'code' => 'CHECKOUT_INVALID',
                'message' => 'Keranjang berubah, silakan cek ulang',
            ];
        }
        // --- step 4 - end - validasi quantity dan status produk checkout

        // --- step 5 - start - validasi metode pembayaran
        $payment = PaymentList::select('slug', 'method', 'name')
            ->where('type', 'incoming')
            ->where('method', 'va')
            ->where('slug', $paymentSlug)
            ->first();

        if (empty($payment)) {
            return [
                'status' => 'error',
                'message' => 'Metode pembayaran tidak tersedia',
            ];
        }

        if ($payment->slug != 'bca' || $payment->name != 'BCA Virtual Account') {
            return [
                'status' => 'error',
                'message' => 'Pembayaran Harus Menggunakan BCA Virtual Account',
            ];
        }
        // --- step 5 - end - validasi metode pembayaran

        // --- step 6 - start - indeks pilihan kurir dan catatan per seller
        $shippingOptionsBySeller = [];
        foreach ($shippingOptions as $shippingOption) {
            $sellerId = $shippingOption['user_id_seller'] ?? '';
            if ($sellerId != '') {
                $shippingOptionsBySeller[$sellerId] = $shippingOption['kurir_name'] ?? '';
            }
        }

        $notedsBySeller = [];
        foreach ($noteds as $noted) {
            $sellerId = $noted['user_id_seller'] ?? '';
            if ($sellerId != '') {
                $notedsBySeller[$sellerId] = $noted['noted'] ?? '';
            }
        }
        // --- step 6 - end - indeks pilihan kurir dan catatan per seller

        // --- step 7 - start - hitung snapshot paket dan total checkout
        $totalProduct = 0;
        $totalShipping = 0;
        $selectedKurirs = [];
        $selectedNoteds = [];
        $cartItemIds = [];

        foreach ($checkouts as $checkout) {
            $sellerId = $checkout['user_id_seller'] ?? '';
            $selectedKurirName = $shippingOptionsBySeller[$sellerId] ?? '';

            if ($selectedKurirName == '') {
                return [
                    'status' => 'error',
                    'message' => 'Kurir harus dipilih',
                ];
            }

            $selectedKurir = null;
            foreach (($checkout['kurirs'] ?? []) as $kurir) {
                if (($kurir['name'] ?? '') == $selectedKurirName) {
                    $selectedKurir = $kurir;
                    break;
                }
            }

            if (empty($selectedKurir)) {
                return [
                    'status' => 'error',
                    'message' => 'Kurir tidak tersedia',
                ];
            }

            $selectedKurirs[] = [
                'user_id_seller' => $sellerId,
                'name' => $selectedKurir['name'],
                'price' => $selectedKurir['price'],
                'estimation' => $selectedKurir['estimation'],
            ];
            $totalShipping += $selectedKurir['price'];

            $selectedNoteds[] = [
                'user_id_seller' => $sellerId,
                'noted' => substr($notedsBySeller[$sellerId] ?? '', 0, 200),
            ];

            foreach (($checkout['keranjangs'] ?? []) as $keranjang) {
                $totalProduct += $keranjang['k_total_price'];
                $cartItemIds[] = $keranjang['k_id'];
            }
        }

        sort($cartItemIds);
        // --- step 7 - end - hitung snapshot paket dan total checkout

        // --- step 8 - start - bentuk snapshot backend dan data pembanding frontend
        $snapshot = [
            'status' => 'success',
            'data' => [
                'alamat' => $alamat,
                'checkouts' => $checkouts,
                'kurirs' => $selectedKurirs,
                'noteds' => $selectedNoteds,
                'payment' => [
                    'method' => $payment->method,
                    'slug' => $payment->slug,
                    'name' => $payment->name,
                ],
                'totals' => [
                    'product' => $totalProduct,
                    'shipping' => $totalShipping,
                    'all' => $totalProduct + $totalShipping,
                ],
            ],
            'clientComparable' => [
                'alamat_id' => $alamat->id,
                'alamat_updated_at' => $alamat->updated_at?->toJSON(),
                'cart_item_ids' => $cartItemIds,
                'total_product' => $totalProduct,
                'total_shipping' => $totalShipping,
                'total_all' => $totalProduct + $totalShipping,
            ],
        ];
        // --- step 8 - end - bentuk snapshot backend dan data pembanding frontend

        return $snapshot;
    }

    /**
     * mengubah snapshot backend menjadi bentuk data yang bisa dipakai frontend untuk refresh checkout.
     *
     * Snapshot authoritative diproyeksikan hanya ke field yang perlu dibandingkan dan ditampilkan
     * frontend. Detail internal serta bentuk relasi database tidak dibocorkan ke kontrak refresh
     * checkout.
     *
     * @param  array  $checkoutSnapshot  Snapshot checkout authoritative yang akan diproses.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function formatCheckoutSnapshotForFrontend(array $checkoutSnapshot = []): array
    {
        $data = $checkoutSnapshot['data'] ?? [];
        $totals = $data['totals'] ?? [];

        return [
            'alamat' => $data['alamat'] ?? null,
            'checkouts' => $data['checkouts'] ?? [],
            'kurirs' => $data['kurirs'] ?? [],
            'noteds' => $data['noteds'] ?? [],
            'totalPrice' => $totals['product'] ?? 0,
            'totalShipping' => $totals['shipping'] ?? 0,
            'totalAll' => $totals['all'] ?? 0,
        ];
    }

    /**
     * membandingkan snapshot backend dan snapshot yang terakhir dilihat user di frontend.
     *
     * Kedua snapshot dinormalisasi sebelum dibandingkan agar perbedaan urutan atau tipe representasi
     * yang tidak bermakna tidak memicu penolakan. Perubahan harga, stok, alamat, ongkir, atau pilihan
     * pembayaran tetap terdeteksi.
     *
     * @param  array  $backendSnapshot  Snapshot terbaru yang dihitung ulang oleh backend.
     * @param  array  $clientSnapshot  Snapshot terakhir yang telah dilihat atau dikonfirmasi frontend.
     *
     * @return bool  True ketika kondisi checkout snapshot changed terpenuhi; false jika tidak.
     */
    public function checkoutSnapshotChanged(array $backendSnapshot = [], array $clientSnapshot = []): bool
    {
        $backendComparable = $backendSnapshot['clientComparable'] ?? [];

        $backendCartItemIds = $backendComparable['cart_item_ids'] ?? [];
        $clientCartItemIds = $clientSnapshot['cart_item_ids'] ?? [];
        sort($backendCartItemIds);
        sort($clientCartItemIds);

        return $backendCartItemIds !== $clientCartItemIds
            || (string) ($backendComparable['alamat_id'] ?? '') !== (string) ($clientSnapshot['alamat_id'] ?? '')
            || (string) ($backendComparable['alamat_updated_at'] ?? '') !== (string) ($clientSnapshot['alamat_updated_at'] ?? '')
            || intval($backendComparable['total_product'] ?? 0) !== intval($clientSnapshot['total_product'] ?? 0)
            || intval($backendComparable['total_shipping'] ?? 0) !== intval($clientSnapshot['total_shipping'] ?? 0)
            || intval($backendComparable['total_all'] ?? 0) !== intval($clientSnapshot['total_all'] ?? 0);
    }

    /**
     * membuat key idempotency dari buyer dan baris keranjang checkout yang sama.
     *
     * @param  string  $user_id_buyer  ID buyer pemilik cart, alamat, atau transaksi.
     * @param  array  $checkoutSnapshot  Snapshot checkout authoritative yang akan diproses.
     *
     * @return string  Nilai teks yang telah dinormalisasi untuk kebutuhan pemanggil.
     */
    public function generateCheckoutKey(string $user_id_buyer = '', array $checkoutSnapshot = []): string
    {
        $cartItemIds = $checkoutSnapshot['clientComparable']['cart_item_ids'] ?? [];
        sort($cartItemIds);

        return hash('sha256', json_encode([
            'buyer_id' => $user_id_buyer,
            'cart_item_ids' => $cartItemIds,
        ]));
    }

    /**
     * mengunci checkout key supaya request checkout yang sama tidak diproses paralel.
     *
     * @param  string  $checkout_key  Kunci idempotensi yang mewakili satu snapshot checkout.
     *
     * @return void  Tidak mengembalikan nilai; proses dinyatakan berhasil ketika selesai tanpa exception.
     */
    public function lockCheckoutKey(string $checkout_key = ''): void
    {
        if (config('database.default') != 'pgsql' || $checkout_key == '') {
            return;
        }

        DB::select('select pg_advisory_lock(hashtext(?))', [$checkout_key]);
    }

    /**
     * melepas lock checkout key setelah proses checkout selesai atau gagal.
     *
     * @param  string  $checkout_key  Kunci idempotensi yang mewakili satu snapshot checkout.
     *
     * @return void  Tidak mengembalikan nilai; proses dinyatakan berhasil ketika selesai tanpa exception.
     */
    public function unlockCheckoutKey(string $checkout_key = ''): void
    {
        if (config('database.default') != 'pgsql' || $checkout_key == '') {
            return;
        }

        DB::select('select pg_advisory_unlock(hashtext(?))', [$checkout_key]);
    }

    /**
     * mencari invoice yang sudah dibuat untuk checkout key yang sama.
     *
     * @param  string  $user_id_buyer  ID buyer pemilik cart, alamat, atau transaksi.
     * @param  string  $checkout_key  Kunci idempotensi yang mewakili satu snapshot checkout.
     *
     * @return TransactionInvoice|null  Hasil proses yang telah dinormalisasi sesuai kontrak function ini.
     */
    public function getExistingCheckoutInvoice(string $user_id_buyer = '', string $checkout_key = ''): ?TransactionInvoice
    {
        if ($user_id_buyer == '' || $checkout_key == '') {
            return null;
        }

        return TransactionInvoice::where('user_id_buyer', $user_id_buyer)
            ->where('checkout_key', $checkout_key)
            ->whereIn('status', ['pending', 'done'])
            ->first();
    }

    /**
     * Menyimpan invoice, transaksi seller, dan produk setelah virtual account berhasil dibuat.
     *
     * Invoice buyer, transaksi per seller, snapshot alamat, serta baris produk dibuat dalam satu
     * transaksi database. Nilai yang disimpan berasal dari snapshot tervalidasi dan respons pembayaran
     * agar pesanan parsial tidak tersisa ketika salah satu tahap gagal.
     *
     * @param  string  $user_id_buyer  ID buyer pemilik cart, alamat, atau transaksi.
     * @param  array  $checkouts  Kumpulan item checkout yang telah dimuat dan divalidasi.
     * @param  array  $kurirs  Pilihan kurir tervalidasi untuk setiap transaksi seller.
     * @param  array  $noteds  Catatan buyer yang dipetakan untuk setiap grup seller.
     * @param  Alamat|null  $alamat_buyer  Snapshot alamat buyer yang akan disimpan pada invoice.
     * @param  string  $payment_method  Jenis metode pembayaran yang disimpan pada invoice.
     * @param  string  $payment_slug  Slug pembayaran yang disimpan pada invoice.
     * @param  string  $payment_name  Nama pembayaran yang ditampilkan pada invoice.
     * @param  string  $expired_at  Waktu kedaluwarsa pembayaran dari provider.
     * @param  int  $price  Nominal uang yang digunakan oleh operasi.
     * @param  string  $checkout_key  Kunci idempotensi yang mewakili satu snapshot checkout.
     * @param  array  $dataXendit  Response pembayaran Xendit yang telah berhasil dibuat.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function saveCheckoutToDatabase(string $user_id_buyer = '', array $checkouts = [], array $kurirs = [], array $noteds = [], ?Alamat $alamat_buyer = null, string $payment_method = '', string $payment_slug = '', string $payment_name = '', string $expired_at = '', int $price = 0, string $checkout_key = '', array $dataXendit = []): array
    {
        // --- step 1 - start - validasi data transaksi checkout
        $requirements = [
            [$user_id_buyer !== '', 'Data User Id buyer Empty'],
            [$checkouts !== [], 'Data Checkout Empty'],
            [$kurirs !== [], 'Data Kurirs Empty'],
            [$noteds !== [], 'Data Noteds Empty'],
            [$alamat_buyer !== null, 'Data Alamat Buyer Empty'],
            [$payment_method !== '', 'Data Payment Method Empty'],
            [$payment_slug !== '', 'Data Payment Slug Empty'],
            [$payment_name !== '', 'Data Payment Name Empty'],
            [$expired_at !== '', 'Expired At Empty'],
            [$price !== 0, 'Data Price Empty'],
            [$checkout_key !== '', 'Data Checkout Key Empty'],
            [$dataXendit !== [], 'Data Xendit Empty'],
        ];

        foreach ($requirements as [$valid, $message]) {
            if (! $valid) {
                return [
                    'status' => 'error',
                    'message' => $message,
                ];
            }
        }
        // --- step 1 - end - validasi data transaksi checkout

        // --- step 2 - start - simpan invoice dan snapshot alamat buyer
        // Salin nilai lokasi agar riwayat transaksi tetap akurat meskipun buyer
        // mengubah atau menghapus alamat utama setelah checkout.
        $transactionInvoice = TransactionInvoice::create([
            'user_id_buyer' => $user_id_buyer,
            'checkout_key' => $checkout_key,
            'alamat_buyer' => $alamat_buyer->alamat,
            'alamat_buyer_latitude' => $alamat_buyer->latitude,
            'alamat_buyer_longitude' => $alamat_buyer->longitude,
            'alamat_buyer_location_source' => $alamat_buyer->location_source ?? 'manual',
            'payment_method' => $payment_method,
            'payment_slug' => $payment_slug,
            'payment_name' => $payment_name,
            'payment_account' => $dataXendit['account_number'] ?? '',
            'payment_reference' => $dataXendit['external_id'] ?? '',
            'price' => $price,
            'expired_at' => $expired_at,
        ]);
        // --- step 2 - end - simpan invoice dan snapshot alamat buyer

        // --- step 3 - start - simpan transaksi dan produk per seller
        foreach ($checkouts as $checkout) {
            $alamatSeller = Alamat::where('user_id', ($checkout['user_id_seller'] ?? ''))
                ->where('type', 'seller')
                ->where('enable', 1)
                ->first();

            $kurir_type = '';
            $kurir_price = 0;
            $kurir_estimate = '';
            foreach ($kurirs as $kurir) {
                if (($kurir['user_id_seller'] ?? '') == ($checkout['user_id_seller'] ?? '')) {
                    $kurir_type = $kurir['name'] ?? '';
                    $kurir_price = $kurir['price'] ?? 0;
                    $kurir_estimate = $kurir['estimation'] ?? '';
                    break;
                }
            }

            $noted = '';
            foreach ($noteds as $item) {
                if (($item['user_id_seller'] ?? '') == ($checkout['user_id_seller'] ?? '')) {
                    $noted = $item['noted'] ?? '';
                    break;
                }
            }

            $transactionNumber = Carbon::now('Asia/Jakarta')->format('YmdHis').'-'.($checkout['user_id_seller'] ?? '').'-'.$user_id_buyer.'-'.($transactionInvoice->id ?? '');
            $transactionUser = TransactionUser::create([
                'user_id_seller' => $checkout['user_id_seller'] ?? '',
                'user_id_buyer' => $user_id_buyer,
                'transaction_invoice_id' => $transactionInvoice->id ?? '',
                'transaction_number' => $transactionNumber,
                'alamat_seller' => $alamatSeller?->alamat,
                'alamat_seller_latitude' => $alamatSeller?->latitude,
                'alamat_seller_longitude' => $alamatSeller?->longitude,
                'alamat_seller_location_source' => $alamatSeller?->location_source ?? 'manual',
                'kurir_type' => $kurir_type,
                'kurir_price' => $kurir_price,
                'kurir_estimate' => $kurir_estimate,
                'noted' => $noted,
            ]);

            $totalPriceKeranjang = 0;
            foreach (($checkout['keranjangs'] ?? []) as $keranjang) {
                $totalPriceKeranjang += $keranjang['k_total_price'];
                TransactionProduct::create([
                    'user_id_seller' => $checkout['user_id_seller'] ?? '',
                    'user_id_buyer' => $user_id_buyer,
                    'product_id' => $keranjang['p_id'],
                    'transaction_user_id' => $transactionUser->id ?? '',
                    'price' => $keranjang['p_price'] ?? '',
                    'total' => $keranjang['k_total'] ?? '',
                ]);
            }

            $transactionUser->product_price = $totalPriceKeranjang;
            $transactionUser->save();
        }
        // --- step 3 - end - simpan transaksi dan produk per seller

        return [
            'status' => 'success',
            'message' => 'Save Chekcout To Database Successfully',
        ];
    }

    /**
     * menghapus item keranjang yang sudah berhasil diproses checkout untuk buyer terkait.
     *
     * Hanya cart yang termasuk snapshot checkout dan dimiliki buyer terkait yang dihapus. Scope ganda
     * mencegah checkout satu user membersihkan item user lain atau item baru yang tidak ikut dibayar.
     *
     * @param  string  $user_id_buyer  ID buyer pemilik cart, alamat, atau transaksi.
     * @param  array  $checkouts  Kumpulan item checkout yang telah dimuat dan divalidasi.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function deleteKeranjangAfterCheckoutForBuyer(string $user_id_buyer = '', array $checkouts = []): array
    {
        // --- step 1 - start - validasi buyer dan data checkout
        if (! $user_id_buyer || ! $checkouts) {
            return [
                'status' => 'error',
                'message' => ! $user_id_buyer ? 'Data User Id buyer Empty' : 'Data Checkout Empty',
            ];
        }
        // --- step 1 - end - validasi buyer dan data checkout

        // --- step 2 - start - kumpulkan dan hapus cart yang sudah diproses
        $keranjangIds = [];
        foreach ($checkouts as $checkout) {
            foreach (($checkout['keranjangs'] ?? []) as $keranjang) {
                $keranjangIds[] = $keranjang['k_id'] ?? '';
            }
        }

        if ($keranjangIds) {
            Keranjang::where('user_id_buyer', $user_id_buyer)
                ->whereIn('id', $keranjangIds)
                ->delete();
        }
        // --- step 2 - end - kumpulkan dan hapus cart yang sudah diproses

        return [
            'status' => 'success',
            'message' => 'Delete Keranjang Successfully',
        ];
    }

    /**
     * mengurangi stok produk berdasarkan quantity checkout yang berhasil diproses.
     *
     * Setiap pengurangan stok memakai kondisi stok masih mencukupi untuk quantity checkout. Ketika
     * satu update gagal, function mengembalikan error agar transaksi pemanggil dapat membatalkan
     * seluruh penyimpanan pesanan.
     *
     * @param  array  $checkouts  Kumpulan item checkout yang telah dimuat dan divalidasi.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function changeStockProductAfterCheckout(array $checkouts = []): array
    {
        // --- step 1 - start - validasi data checkout
        if (! $checkouts) {
            return [
                'status' => 'error',
                'message' => 'Data Checkout Empty',
            ];
        }
        // --- step 1 - end - validasi data checkout

        // --- step 2 - start - kurangi stok setiap produk secara aman
        foreach ($checkouts as $checkout) {
            foreach (($checkout['keranjangs'] ?? []) as $keranjang) {
                $productId = $keranjang['p_id'] ?? '';
                $qty = intval($keranjang['k_total'] ?? 0);

                if ($productId == '' || $qty < 1) {
                    return [
                        'status' => 'error',
                        'message' => 'Data produk checkout tidak valid',
                    ];
                }

                $updated = Product::where('id', $productId)
                    ->where('stock', '>=', $qty)
                    ->decrement('stock', $qty);

                if ($updated == 0) {
                    return [
                        'status' => 'error',
                        'message' => 'Stok produk berubah, silakan cek ulang',
                    ];
                }
            }
        }
        // --- step 2 - end - kurangi stok setiap produk secara aman

        return [
            'status' => 'success',
            'message' => 'Change Stock Product Successfully',
        ];
    }

    /**
     * Mengunci seluruh state mutable checkout lalu memvalidasi ulang snapshot sebelum pembayaran.
     *
     * Row cart, produk, serta alamat aktif buyer dan seller dikunci sebelum availability diperiksa.
     * Perubahan quantity, harga, produk, seller, atau alamat buyer terhadap snapshot awal dihentikan
     * sebagai perubahan checkout, sedangkan item yang tidak lagi tersedia memakai exception
     * availability agar selection dapat diperbaiki setelah transaksi di-rollback.
     *
     * @param  string  $buyerId  ID buyer yang menjadi scope operasi.
     * @param  array  $checkoutSnapshot  Snapshot authoritative yang akan dibandingkan dengan row terkunci.
     *
     * @return void  Tidak mengembalikan nilai; proses dinyatakan berhasil ketika selesai tanpa exception.
     *
     * @throws CheckoutAvailabilityException
     * @throws CheckoutChangedException
     */
    public function lockAndValidateCheckoutItems(string $buyerId, array $checkoutSnapshot): void
    {
        // --- step 1 - start - indeks nilai cart yang menjadi dasar checkout snapshot
        $checkouts = $checkoutSnapshot['data']['checkouts'] ?? [];
        $expectedCartItems = [];
        $expectedCartIds = [];
        foreach ($checkouts as $checkout) {
            foreach (($checkout['keranjangs'] ?? []) as $keranjang) {
                $cartId = $keranjang['k_id'] ?? '';
                if ($cartId !== '') {
                    $expectedCartIds[] = $cartId;
                    $expectedCartItems[$cartId] = [
                        'product_id' => (string) ($keranjang['p_id'] ?? ''),
                        'seller_id' => (string) ($checkout['user_id_seller'] ?? ''),
                        'quantity' => intval($keranjang['k_total'] ?? 0),
                        'price' => intval($keranjang['p_price'] ?? 0),
                    ];
                }
            }
        }
        $expectedCartIds = array_values(array_unique($expectedCartIds));
        // --- step 1 - end - indeks nilai cart yang menjadi dasar checkout snapshot

        // --- step 2 - start - lock row cart dan seluruh produk terkait
        $cartItems = Keranjang::where('user_id_buyer', $buyerId)
            ->whereIn('id', $expectedCartIds)
            ->lockForUpdate()
            ->get();
        $productIds = $cartItems->pluck('product_id')->filter()->unique()->values()->all();
        $products = Product::withTrashed()
            ->whereIn('id', $productIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $sellerIds = $cartItems->pluck('user_id_seller')->filter()->unique()->values()->all();
        $verifiedSellerIds = Alamat::whereIn('user_id', $sellerIds)
            ->where('type', 'seller')
            ->where('enable', 1)
            ->lockForUpdate()
            ->get()
            ->filter(fn (Alamat $alamat) => $this->alamatService->isVerifiedPinpoint($alamat))
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();
        $verifiedSellerLookup = array_fill_keys($verifiedSellerIds, true);

        $activeBuyerAddress = Alamat::where('user_id', $buyerId)
            ->where('type', 'buyer')
            ->where('enable', 1)
            ->lockForUpdate()
            ->first();
        // --- step 2 - end - lock row cart dan seluruh produk terkait

        // --- step 3 - start - validasi snapshot terhadap state yang sudah dikunci
        $lockedCartIds = $cartItems->pluck('id')->all();
        $invalidCartIds = array_values(array_diff($expectedCartIds, $lockedCartIds));
        $snapshotChanged = count($lockedCartIds) !== count($expectedCartIds);

        foreach ($cartItems as $cartItem) {
            $product = $products->get($cartItem->product_id);
            $expectedCartItem = $expectedCartItems[$cartItem->id] ?? null;
            $reason = $this->productAvailabilityService->unavailableReason(
                productExists: $product !== null,
                deletedAt: $product?->deleted_at,
                stock: intval($product?->stock ?? 0),
                sellerLocationVerified: isset($verifiedSellerLookup[$cartItem->user_id_seller]),
            );

            if (
                ! $cartItem->checked
                || ! $cartItem->checkout
                || intval($cartItem->total) < 1
                || $reason !== null
                || $product?->user_id_seller !== $cartItem->user_id_seller
                || intval($cartItem->total) > intval($product?->stock ?? 0)
            ) {
                $invalidCartIds[] = $cartItem->id;
            }

            if (
                $expectedCartItem === null
                || (string) $cartItem->product_id !== $expectedCartItem['product_id']
                || (string) $cartItem->user_id_seller !== $expectedCartItem['seller_id']
                || intval($cartItem->total) !== $expectedCartItem['quantity']
                || intval($product?->price ?? 0) !== $expectedCartItem['price']
            ) {
                $snapshotChanged = true;
            }
        }

        if ($invalidCartIds !== []) {
            throw new CheckoutAvailabilityException(
                'Produk di checkout berubah atau sudah tidak tersedia.',
                array_values(array_unique($invalidCartIds)),
            );
        }

        $expectedAddress = $checkoutSnapshot['clientComparable'] ?? [];
        if (
            ! $this->alamatService->isVerifiedPinpoint($activeBuyerAddress)
            || (string) ($activeBuyerAddress?->id ?? '') !== (string) ($expectedAddress['alamat_id'] ?? '')
            || (string) ($activeBuyerAddress?->updated_at?->toJSON() ?? '') !== (string) ($expectedAddress['alamat_updated_at'] ?? '')
        ) {
            $snapshotChanged = true;
        }

        if ($snapshotChanged) {
            throw new CheckoutChangedException();
        }
        // --- step 3 - end - validasi snapshot terhadap state yang sudah dikunci
    }
}
