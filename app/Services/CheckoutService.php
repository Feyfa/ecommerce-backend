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
use JsonException;

/**
 * Menyiapkan data checkout buyer dan menyusun snapshot dari data terbaru.
 *
 * @phpstan-import-type CartState from KeranjangService
 *
 * @phpstan-type CheckoutPrice int|float|numeric-string|null
 * @phpstan-type CheckoutCartRow array{
 *     k_id: string,
 *     k_user_id_seller: string,
 *     k_total: int|numeric-string,
 *     k_total_price: CheckoutPrice,
 *     p_id: string,
 *     p_name: string|null,
 *     p_price: CheckoutPrice,
 *     p_img: string|null,
 *     u_name_seller: string
 * }
 * @phpstan-type CheckoutItem array{
 *     k_id: string,
 *     k_total: int|numeric-string,
 *     k_total_price: CheckoutPrice,
 *     p_id: string,
 *     p_name: string|null,
 *     p_price: CheckoutPrice,
 *     p_img: string|null
 * }
 * @phpstan-type CheckoutCourier array{name: string, price: int, estimation: string}
 * @phpstan-type CheckoutGroup array{
 *     user_id_seller: string,
 *     user_name_seller: string,
 *     keranjangs: list<CheckoutItem>,
 *     kurirs: list<CheckoutCourier>
 * }
 * @phpstan-type CheckoutSelectedCourier array{
 *     user_id_seller: string,
 *     name: string,
 *     price: int,
 *     estimation: string
 * }
 * @phpstan-type CheckoutSellerNote array{user_id_seller: string, noted: string}
 * @phpstan-type CheckoutComparable array{
 *     alamat_id: string,
 *     alamat_updated_at: string|null,
 *     cart_item_ids: list<string>,
 *     total_product: int|float,
 *     total_shipping: int,
 *     total_all: int|float
 * }
 * @phpstan-type CheckoutSnapshotResult (
 *     array{
 *         status: 'success',
 *         data: array{
 *             alamat: Alamat,
 *             checkouts: list<CheckoutGroup>,
 *             kurirs: list<CheckoutSelectedCourier>,
 *             noteds: list<CheckoutSellerNote>,
 *             payment: array{method: string|null, slug: string|null, name: string|null},
 *             totals: array{product: int|float, shipping: int, all: int|float}
 *         },
 *         clientComparable: CheckoutComparable
 *     }
 *     |array{status: 'invalid', code: string, message: string}
 *     |array{status: 'error', message: string}
 * )
 */
class CheckoutService
{
    /**
     * Menyiapkan dependency yang diperlukan oleh class.
     *
     * @param  AlamatService  $alamatService  Service alamat yang digunakan oleh class ini.
     * @param  KeranjangService  $keranjangService  Service keranjang yang digunakan oleh class ini.
     * @param  ProductAvailabilityService  $productAvailabilityService  Service product availability yang digunakan oleh class ini.
     *
     * @return void Tidak mengembalikan nilai; dependency disimpan pada instance.
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
     * @return CartState State cart terbaru beserta issue ketersediaan pada pilihan awal.
     */
    public function reconcileCheckoutCart(string $buyerId): array
    {
        return $this->keranjangService->getKeranjangs($buyerId);
    }

    /**
     * Membentuk error availability checkout tanpa kehilangan penyebab setelah read-repair cart.
     *
     * Lokasi seller yang kehilangan verifikasi memerlukan pesan tersendiri agar buyer tahu
     * penyebab item dilepas dari checkout.
     *
     * @param  array{
     *     unavailableCheckoutItemIds?: list<string>,
     *     unavailableCheckoutReasons?: array<string, string>
     * }  $cartState  ID item checkout yang tidak tersedia beserta alasannya.
     *
     * @return array{code: string, message: string}|null Kode dan pesan jika ada item yang ditolak.
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
     * @return void Tidak mengembalikan nilai; proses dinyatakan berhasil ketika selesai tanpa exception.
     */
    public function uncheckCheckoutItems(string $buyerId, array $cartIds): void
    {
        $this->keranjangService->uncheckCartItems($buyerId, $cartIds);
    }

    /**
     * Mengambil ID cart dari paket checkout yang gagal diproses.
     *
     * Controller memakai ID ini untuk melepas pilihan item terkait setelah rollback checkout.
     *
     * @param  list<array{keranjangs?: list<array{k_id?: string}>}>  $checkouts  Paket checkout.
     *
     * @return list<string> ID cart unik untuk melepas pilihan item.
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
     * @return bool True ketika kondisi is address verified terpenuhi; false jika tidak.
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
     * @param  array<array-key, array{user_id_seller?: string}>  $checkouts  Grup seller yang dicek.
     *
     * @return bool True jika ada seller tanpa alamat aktif dengan pinpoint terverifikasi.
     */
    public function hasUnverifiedSellerAddress(array $checkouts): bool
    {
        // --- step 1 - kumpulkan ID seller - start ---
        $sellerIds = array_column($checkouts, 'user_id_seller');
        $nonEmptySellerIds = array_filter($sellerIds);
        $sellerIds = array_values(array_unique($nonEmptySellerIds));
        // --- step 1 - kumpulkan ID seller - end ---

        if ($sellerIds === []) {
            return false;
        }

        // --- step 2 - muat ID seller terverifikasi - start ---
        $verifiedSellerIds = Alamat::whereIn('user_id', $sellerIds)
            ->where('type', 'seller')
            ->where('enable', 1)
            ->get()
            ->filter(fn (Alamat $alamat) => $this->alamatService->isVerifiedPinpoint($alamat))
            ->pluck('user_id')
            ->all();
        // --- step 2 - muat ID seller terverifikasi - end ---

        return count(array_diff($sellerIds, $verifiedSellerIds)) > 0;
    }

    /**
     * Mengambil alamat aktif buyer untuk diperiksa sebelum checkout.
     *
     * Pemanggil memilih respons untuk alamat yang belum ada atau belum terverifikasi.
     *
     * @param  string  $user_id_buyer  ID buyer pemilik alamat.
     *
     * @return array{alamat: Alamat|null} Alamat buyer aktif jika tersedia.
     */
    public function getAlamatBuyer(string $user_id_buyer = ''): array
    {
        // Akun yang sama dapat memiliki alamat aktif untuk buyer dan seller.
        $alamat = Alamat::where('user_id', $user_id_buyer)
            ->where('type', 'buyer')
            ->where('enable', 1)
            ->first();

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
     * @param  string  $user_id_buyer  ID buyer pemilik cart yang dipilih.
     *
     * @return array{checkouts: list<CheckoutGroup>, totalPrice: int|float} Paket dan total produk.
     */
    public function getKeranjangCheckout(string $user_id_buyer = ''): array
    {
        // --- step 1 - pilih kolom item checkout - start ---
        $query = Keranjang::selectRaw('
                keranjangs.id as k_id,
                keranjangs.user_id_seller as k_user_id_seller,
                keranjangs.total as k_total,
                (keranjangs.total * products.price) as k_total_price,
                products.id as p_id,
                products.name as p_name,
                products.price as p_price,
                products.img as p_img,
                COALESCE(NULLIF(companies.name, \'\'), users.name) as u_name_seller
            ');
        // --- step 1 - pilih kolom item checkout - end ---

        // --- step 2 - muat item checkout buyer - start ---
        $keranjangs = $query
            ->join('users', 'keranjangs.user_id_seller', '=', 'users.id')
            ->leftJoin('companies', 'companies.user_id', '=', 'users.id')
            ->join('products', 'keranjangs.product_id', '=', 'products.id')
            ->where('keranjangs.user_id_buyer', $user_id_buyer)
            ->where('keranjangs.checkout', 1)
            ->where('keranjangs.total', '>', 0)
            ->whereNull('products.deleted_at')
            ->orderBy('k_user_id_seller', 'ASC')
            ->get();
        // --- step 2 - muat item checkout buyer - end ---

        // --- step 3 - hitung total harga produk - start ---
        $totalPrice = 0;

        foreach ($keranjangs as $keranjang) {
            // Alias SQL dapat berupa string numerik di PostgreSQL. Harga null tetap dikirim
            // pada item lama dan hanya dihitung sebagai nol untuk total produk.
            /** @var CheckoutPrice $itemTotalPrice */
            $itemTotalPrice = $keranjang->getAttribute('k_total_price');
            $totalPrice += $itemTotalPrice ?? 0;
        }
        // --- step 3 - hitung total harga produk - end ---

        // --- step 4 - bentuk paket checkout per seller - start ---
        // Setiap grup terbentuk dari baris yang membawa identitas seller.
        /** @var array<string, non-empty-list<CheckoutCartRow>> $cartRowsBySeller */
        $cartRowsBySeller = $keranjangs->groupBy('k_user_id_seller')->toArray();
        $checkouts = [];

        foreach ($cartRowsBySeller as $sellerCartRows) {
            $firstCartRow = $sellerCartRows[0];
            $formattedItems = $this->generateFormatKeranjangs($sellerCartRows);
            $formattedCouriers = $this->generateFormatKurirs();

            $checkouts[] = [
                'user_id_seller' => $firstCartRow['k_user_id_seller'],
                'user_name_seller' => $firstCartRow['u_name_seller'],
                'keranjangs' => $formattedItems['keranjangs'],
                'kurirs' => $formattedCouriers['kurirs'],
            ];
        }
        // --- step 4 - bentuk paket checkout per seller - end ---

        return [
            'checkouts' => $checkouts,
            'totalPrice' => $totalPrice,
        ];
    }

    /**
     * Membentuk daftar item yang dipakai halaman checkout dan snapshot pembayaran.
     *
     * @param  list<CheckoutCartRow>  $keranjangs  Baris cart dari paket satu seller.
     *
     * @return array{keranjangs: list<CheckoutItem>} Item dan nilai harga asli dari query.
     */
    private function generateFormatKeranjangs(array $keranjangs = []): array
    {
        // Identitas seller sudah tersedia pada level paket checkout.
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
     * Menyiapkan tarif dan estimasi kurir yang dipakai paket seller dan snapshot checkout.
     *
     * @return array{kurirs: list<CheckoutCourier>} Opsi kurir dalam urutan tampilan checkout.
     */
    private function generateFormatKurirs(): array
    {
        // --- step 1 - siapkan waktu dan daftar kurir - start ---
        Carbon::setLocale('id');
        $now = Carbon::now('Asia/Jakarta');
        $startDate = $now->translatedFormat('d F Y');

        $kurirsFormat = [];
        $kurirslists = [
            ['name' => 'JNT', 'day' => 1],
            ['name' => 'Anter Aja', 'day' => 2],
            ['name' => 'Si Cepat Halu', 'day' => 3],
        ];
        // --- step 1 - siapkan waktu dan daftar kurir - end ---

        // --- step 2 - bentuk harga dan estimasi setiap kurir - start ---
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
        // --- step 2 - bentuk harga dan estimasi setiap kurir - end ---

        return [
            'kurirs' => $kurirsFormat,
        ];
    }

    /**
     * Membuat snapshot pembayaran checkout dari database.
     *
     * Snapshot dihitung ulang dari cart, alamat, ongkir, catatan, serta metode pembayaran yang
     * tersimpan di backend. Harga dan struktur seller tidak dipercaya dari frontend, sehingga hasil
     * ini menjadi dasar checkout key dan pemeriksaan perubahan sebelum pembayaran.
     * Harga satuan serta subtotal wajib tersedia sebelum snapshot dapat dipakai untuk pembayaran.
     *
     * @param  string  $user_id_buyer  ID buyer pemilik cart, alamat, atau transaksi.
     * @param  array  $shippingOptions  Pilihan kurir per seller yang dikirim untuk penyusunan snapshot.
     * @param  array  $noteds  Catatan buyer yang dipetakan untuk setiap grup seller.
     * @param  string  $paymentSlug  Slug metode pembayaran yang dipilih.
     *
     * @return CheckoutSnapshotResult Snapshot yang siap dibayar atau alasan checkout ditolak.
     */
    public function buildCheckoutSnapshot(
        string $user_id_buyer = '',
        array $shippingOptions = [],
        array $noteds = [],
        string $paymentSlug = '',
    ): array {
        // --- step 1 - sinkronkan availability produk checkout - start ---
        $cartState = $this->reconcileCheckoutCart($user_id_buyer);

        $availabilityError = $this->checkoutAvailabilityError($cartState);

        if ($availabilityError !== null) {
            return [
                'status' => 'invalid',
                ...$availabilityError,
            ];
        }
        // --- step 1 - sinkronkan availability produk checkout - end ---

        // --- step 2 - validasi alamat aktif buyer - start ---
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
        // --- step 2 - validasi alamat aktif buyer - end ---

        // --- step 3 - ambil dan validasi paket checkout - start ---
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
        // --- step 3 - ambil dan validasi paket checkout - end ---

        // --- step 4 - validasi quantity dan status produk checkout - start ---
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
        // --- step 4 - validasi quantity dan status produk checkout - end ---

        // --- step 5 - validasi metode pembayaran - start ---
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
        // --- step 5 - validasi metode pembayaran - end ---

        // --- step 6 - indeks pilihan kurir dan catatan per seller - start ---
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
        // --- step 6 - indeks pilihan kurir dan catatan per seller - end ---

        // --- step 7 - hitung snapshot paket dan total checkout - start ---
        $totalProduct = 0;
        $totalShipping = 0;
        $selectedKurirs = [];
        $selectedNoteds = [];
        $cartItemIds = [];

        foreach ($checkouts as $checkout) {
            // --- step 7.1 - ambil pilihan kurir buyer - start ---
            $sellerId = $checkout['user_id_seller'];
            $selectedKurirName = $shippingOptionsBySeller[$sellerId] ?? '';
            // --- step 7.1 - ambil pilihan kurir buyer - end ---

            if ($selectedKurirName == '') {
                return [
                    'status' => 'error',
                    'message' => 'Kurir harus dipilih',
                ];
            }

            // --- step 7.2 - cari opsi kurir seller - start ---
            // Cocokkan nama dari request agar ongkir memakai tarif dari opsi yang dibuat backend.
            $selectedKurir = null;
            foreach ($checkout['kurirs'] as $kurir) {
                if ($kurir['name'] == $selectedKurirName) {
                    $selectedKurir = $kurir;
                    break;
                }
            }
            // --- step 7.2 - cari opsi kurir seller - end ---

            if (empty($selectedKurir)) {
                return [
                    'status' => 'error',
                    'message' => 'Kurir tidak tersedia',
                ];
            }

            // --- step 7.3 - catat pengiriman paket - start ---
            $selectedKurirs[] = [
                'user_id_seller' => $sellerId,
                'name' => $selectedKurir['name'],
                'price' => $selectedKurir['price'],
                'estimation' => $selectedKurir['estimation'],
            ];
            $totalShipping += $selectedKurir['price'];
            // --- step 7.3 - catat pengiriman paket - end ---

            // --- step 7.4 - siapkan catatan untuk seller - start ---
            $selectedNoteds[] = [
                'user_id_seller' => $sellerId,
                'noted' => substr($notedsBySeller[$sellerId] ?? '', 0, 200),
            ];
            // --- step 7.4 - siapkan catatan untuk seller - end ---

            // --- step 7.5 - rekap item checkout - start ---
            // PostgreSQL menolak fallback string kosong pada penyimpanan harga. Tolak harga
            // kosong di sini agar Xendit tidak membuat VA untuk pesanan yang gagal disimpan.
            foreach ($checkout['keranjangs'] as $keranjang) {
                $itemPrice = $keranjang['p_price'];
                $itemSubtotal = $keranjang['k_total_price'];

                if ($itemPrice === null || $itemSubtotal === null) {
                    return [
                        'status' => 'invalid',
                        'code' => 'CHECKOUT_INVALID',
                        'message' => 'Harga salah satu produk belum tersedia. Silakan cek keranjang.',
                    ];
                }

                $totalProduct += $itemSubtotal;
                $cartItemIds[] = $keranjang['k_id'];
            }
            // --- step 7.5 - rekap item checkout - end ---
        }

        sort($cartItemIds);
        // --- step 7 - hitung snapshot paket dan total checkout - end ---

        // --- step 8 - bentuk snapshot backend dan data pembanding frontend - start ---
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
        // --- step 8 - bentuk snapshot backend dan data pembanding frontend - end ---

        return $snapshot;
    }

    /**
     * Menyiapkan snapshot backend untuk ditinjau kembali oleh buyer.
     *
     * Nilai default menjaga bentuk respons saat data snapshot hanya tersedia sebagian.
     *
     * @param  array{data?: array{
     *     alamat?: Alamat|null,
     *     checkouts?: list<CheckoutGroup>,
     *     kurirs?: list<CheckoutSelectedCourier>,
     *     noteds?: list<CheckoutSellerNote>,
     *     totals?: array{product?: int|float, shipping?: int, all?: int|float}
     * }}  $checkoutSnapshot  Data backend untuk memuat ulang halaman checkout.
     *
     * @return array{
     *     alamat: Alamat|null,
     *     checkouts: list<CheckoutGroup>,
     *     kurirs: list<CheckoutSelectedCourier>,
     *     noteds: list<CheckoutSellerNote>,
     *     totalPrice: int|float,
     *     totalShipping: int,
     *     totalAll: int|float
     * } Data alamat, paket, dan total yang dipakai frontend untuk meninjau checkout.
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
     * Memeriksa apakah data yang dilihat buyer masih cocok dengan snapshot backend.
     *
     * ID cart diurutkan dan total dibandingkan sebagai bilangan bulat sesuai nominal pembayaran.
     * Buyer perlu meninjau ulang checkout jika alamat, item, atau total berbeda.
     *
     * @param  array{clientComparable?: array{
     *     alamat_id?: string,
     *     alamat_updated_at?: string|null,
     *     cart_item_ids?: list<string>,
     *     total_product?: int|float,
     *     total_shipping?: int,
     *     total_all?: int|float
     * }}  $backendSnapshot  Alamat, ID cart, dan total yang dibentuk backend.
     * @param  array  $clientSnapshot  Snapshot terakhir yang telah dilihat atau dikonfirmasi frontend.
     *
     * @return bool True jika ID cart, identitas atau versi alamat, atau salah satu total berbeda.
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
     * Membentuk checkout key dari buyer dan kumpulan item cart yang akan dibayar.
     *
     * Pengurutan ID menghasilkan key yang sama untuk kumpulan item yang sama. Checkout memakai
     * key tersebut untuk advisory lock PostgreSQL dan pencarian invoice yang sudah dibuat.
     *
     * @param  string  $user_id_buyer  ID buyer yang menjadi bagian dari checkout key.
     * @param  array{clientComparable?: array{
     *     cart_item_ids?: list<string>
     * }}  $checkoutSnapshot  ID cart yang dipakai untuk membentuk checkout key.
     *
     * @return string Hash SHA-256 yang dipakai untuk lock dan pencarian invoice.
     *
     * @throws JsonException Saat payload key tidak dapat di-encode sebagai JSON.
     */
    public function generateCheckoutKey(string $user_id_buyer = '', array $checkoutSnapshot = []): string
    {
        $cartItemIds = $checkoutSnapshot['clientComparable']['cart_item_ids'] ?? [];
        sort($cartItemIds);

        // Encoding yang gagal tidak boleh di-hash sebagai string kosong, karena semua checkout
        // yang gagal akan berbagi key advisory lock dan pencarian invoice yang sama.
        // Flag ini tidak mengubah JSON yang berhasil, sehingga key invoice lama tetap cocok.
        return hash('sha256', json_encode([
            'buyer_id' => $user_id_buyer,
            'cart_item_ids' => $cartItemIds,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * mengunci checkout key supaya request checkout yang sama tidak diproses paralel.
     *
     * @param  string  $checkout_key  Kunci idempotensi yang mewakili satu snapshot checkout.
     *
     * @return void Tidak mengembalikan nilai; proses dinyatakan berhasil ketika selesai tanpa exception.
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
     * @return void Tidak mengembalikan nilai; proses dinyatakan berhasil ketika selesai tanpa exception.
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
     * @return TransactionInvoice|null Hasil proses yang telah dinormalisasi sesuai kontrak function ini.
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
     * Respons tanpa nomor VA atau referensi pembayaran yang dapat dipakai ditolak sebelum invoice
     * dibuat, sehingga controller tidak menghapus keranjang atau mengurangi stok.
     *
     * @param  string  $user_id_buyer  ID buyer pemilik cart, alamat, atau transaksi.
     * @param  list<array{
     *     user_id_seller?: string,
     *     keranjangs?: list<array{
     *         p_id: string,
     *         p_price?: CheckoutPrice,
     *         k_total?: int|numeric-string,
     *         k_total_price: CheckoutPrice
     *     }>
     * }>  $checkouts  Kumpulan item checkout yang telah dimuat dan divalidasi.
     * @param  list<array{
     *     user_id_seller?: string,
     *     name?: string,
     *     price?: int,
     *     estimation?: string
     * }>  $kurirs  Pilihan kurir tervalidasi untuk setiap transaksi seller.
     * @param  list<array{
     *     user_id_seller?: string,
     *     noted?: string
     * }>  $noteds  Catatan buyer yang dipetakan untuk setiap grup seller.
     * @param  Alamat|null  $alamat_buyer  Snapshot alamat buyer yang akan disimpan pada invoice.
     * @param  string  $payment_method  Jenis metode pembayaran yang disimpan pada invoice.
     * @param  string  $payment_slug  Slug pembayaran yang disimpan pada invoice.
     * @param  string  $payment_name  Nama pembayaran yang ditampilkan pada invoice.
     * @param  string  $expired_at  Waktu kedaluwarsa pembayaran dari provider.
     * @param  int  $price  Nominal uang yang digunakan oleh operasi.
     * @param  string  $checkout_key  Kunci idempotensi yang mewakili satu snapshot checkout.
     * @param  array<mixed>  $dataXendit  Response pembayaran Xendit yang belum dipercaya.
     *                                    Nomor VA dan referensi divalidasi sebelum invoice dibuat.
     *
     * @return array{status: 'error', message: string}|array{
     *     status: 'success',
     *     message: string,
     *     transaction_invoice_id: string
     * } Status, pesan, dan id invoice yang baru dibuat. Id invoice dikembalikan agar pemanggil
     *   dapat menandai transaksi hasil checkout tanpa menebak data terbaru buyer. Satu invoice
     *   mencakup seluruh seller pada checkout ini.
     */
    public function saveCheckoutToDatabase(
        string $user_id_buyer = '',
        array $checkouts = [],
        array $kurirs = [],
        array $noteds = [],
        ?Alamat $alamat_buyer = null,
        string $payment_method = '',
        string $payment_slug = '',
        string $payment_name = '',
        string $expired_at = '',
        int $price = 0,
        string $checkout_key = '',
        array $dataXendit = [],
    ): array {
        // --- step 1 - validasi data transaksi checkout - start ---
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

        // Loop di atas sudah menolak alamat kosong. Guard ini hanya membuat tipe Alamat terlihat
        // oleh analisis statis tanpa mengubah urutan pesan error saat beberapa data kosong.
        if ($alamat_buyer === null) {
            return [
                'status' => 'error',
                'message' => 'Data Alamat Buyer Empty',
            ];
        }
        // --- step 1 - validasi data transaksi checkout - end ---

        // --- step 2 - validasi data pembayaran virtual account - start ---
        // Tolak respons yang tidak menyertakan kedua field agar buyer tidak menerima invoice
        // tanpa nomor bayar setelah keranjang dihapus. Pertahankan nol di depan nomor VA.
        $paymentAccount = $dataXendit['account_number'] ?? null;
        $paymentReference = $dataXendit['external_id'] ?? null;

        $hasPaymentAccount = is_string($paymentAccount) && trim($paymentAccount) !== '';
        $hasPaymentReference = is_string($paymentReference) && trim($paymentReference) !== '';
        // --- step 2 - validasi data pembayaran virtual account - end ---

        if (! $hasPaymentAccount || ! $hasPaymentReference) {
            return [
                'status' => 'error',
                'message' => 'Payment creation failed',
            ];
        }

        // --- step 3 - simpan invoice checkout - start ---
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
            'payment_account' => $paymentAccount,
            'payment_reference' => $paymentReference,
            'price' => $price,
            'expired_at' => $expired_at,
        ]);
        // --- step 3 - simpan invoice checkout - end ---

        // --- step 4 - simpan transaksi per seller - start ---
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
        // --- step 4 - simpan transaksi per seller - end ---

        return [
            'status' => 'success',
            'message' => 'Save Chekcout To Database Successfully',
            'transaction_invoice_id' => $transactionInvoice->id,
        ];
    }

    /**
     * menghapus item keranjang yang sudah berhasil diproses checkout untuk buyer terkait.
     *
     * Hanya cart yang termasuk snapshot checkout dan dimiliki buyer terkait yang dihapus. Scope ganda
     * mencegah checkout satu user membersihkan item user lain atau item baru yang tidak ikut dibayar.
     *
     * @param  string  $user_id_buyer  ID buyer pemilik cart, alamat, atau transaksi.
     * @param  list<array{
     *     keranjangs?: list<array{k_id?: string}>
     * }>  $checkouts  Kumpulan item checkout yang telah dimuat dan divalidasi.
     *
     * @return array{status: 'error'|'success', message: string} Status dan pesan penghapusan cart.
     */
    public function deleteKeranjangAfterCheckoutForBuyer(string $user_id_buyer = '', array $checkouts = []): array
    {
        // --- step 1 - validasi buyer dan data checkout - start ---
        if (! $user_id_buyer || ! $checkouts) {
            return [
                'status' => 'error',
                'message' => ! $user_id_buyer ? 'Data User Id buyer Empty' : 'Data Checkout Empty',
            ];
        }
        // --- step 1 - validasi buyer dan data checkout - end ---

        // --- step 2 - kumpulkan dan hapus cart yang sudah diproses - start ---
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
        // --- step 2 - kumpulkan dan hapus cart yang sudah diproses - end ---

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
     * @param  list<array{
     *     keranjangs?: list<array{p_id?: string, k_total?: int|numeric-string}>
     * }>  $checkouts  Kumpulan item checkout yang telah dimuat dan divalidasi.
     *
     * @return array{status: 'error'|'success', message: string} Status dan pesan pengurangan stok.
     */
    public function changeStockProductAfterCheckout(array $checkouts = []): array
    {
        // --- step 1 - validasi data checkout - start ---
        if (! $checkouts) {
            return [
                'status' => 'error',
                'message' => 'Data Checkout Empty',
            ];
        }
        // --- step 1 - validasi data checkout - end ---

        // --- step 2 - kurangi stok setiap produk secara aman - start ---
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
        // --- step 2 - kurangi stok setiap produk secara aman - end ---

        return [
            'status' => 'success',
            'message' => 'Change Stock Product Successfully',
        ];
    }

    /**
     * Mengunci seluruh state mutable checkout lalu memvalidasi ulang snapshot sebelum pembayaran.
     *
     * Row cart, produk, serta alamat aktif buyer dan seller dikunci sebelum validasi. Jika harga
     * hilang sejak snapshot dibuat, controller dapat melepas pilihan item setelah rollback.
     * Perubahan yang masih dapat dibayar meminta buyer meninjau ulang snapshot checkout.
     *
     * @param  string  $buyerId  ID buyer pemilik item yang akan dikunci.
     * @param  array{
     *     data?: array{
     *         checkouts?: list<array{
     *             user_id_seller?: string,
     *             keranjangs?: list<array{
     *                 k_id?: string,
     *                 p_id?: string,
     *                 k_total?: int|numeric-string,
     *                 p_price?: CheckoutPrice
     *             }>
     *         }>
     *     },
     *     clientComparable?: array{alamat_id?: string, alamat_updated_at?: string|null}
     * }  $checkoutSnapshot  Item dan versi alamat sebelum transaksi dimulai.
     *
     * @return void Pembayaran dapat diteruskan setelah seluruh pemeriksaan lolos.
     *
     * @throws CheckoutAvailabilityException Saat item checkout tidak dapat diproses.
     * @throws CheckoutChangedException Saat checkout yang masih bisa diproses berubah.
     */
    public function lockAndValidateCheckoutItems(string $buyerId, array $checkoutSnapshot): void
    {
        // --- step 1 - indeks item dari snapshot - start ---
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
        // --- step 1 - indeks item dari snapshot - end ---

        // --- step 2 - kunci item keranjang - start ---
        // Scope buyer menjaga penguncian tetap terbatas pada keranjang yang dikirim untuk dibayar.
        $cartItems = Keranjang::where('user_id_buyer', $buyerId)
            ->whereIn('id', $expectedCartIds)
            ->lockForUpdate()
            ->get();
        // --- step 2 - kunci item keranjang - end ---

        // --- step 3 - kunci produk checkout - start ---
        // Muat produk soft-deleted agar statusnya diperiksa sebelum permintaan Virtual Account.
        $productIds = $cartItems->pluck('product_id')->filter()->unique()->values()->all();
        $products = Product::withTrashed()
            ->whereIn('id', $productIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        // --- step 3 - kunci produk checkout - end ---

        // --- step 4 - kunci lokasi aktif seller - start ---
        $sellerIds = $cartItems->pluck('user_id_seller')->filter()->unique()->values()->all();
        $sellerAddresses = Alamat::whereIn('user_id', $sellerIds)
            ->where('type', 'seller')
            ->where('enable', 1)
            ->lockForUpdate()
            ->get();
        // --- step 4 - kunci lokasi aktif seller - end ---

        // --- step 5 - indeks seller yang terverifikasi - start ---
        // Status terverifikasi dinilai dari lokasi yang sudah dikunci untuk transaksi ini.
        $verifiedSellerIds = $sellerAddresses
            ->filter(fn (Alamat $alamat) => $this->alamatService->isVerifiedPinpoint($alamat))
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();
        $verifiedSellerLookup = array_fill_keys($verifiedSellerIds, true);
        // --- step 5 - indeks seller yang terverifikasi - end ---

        // --- step 6 - kunci alamat aktif buyer - start ---
        $activeBuyerAddress = Alamat::where('user_id', $buyerId)
            ->where('type', 'buyer')
            ->where('enable', 1)
            ->lockForUpdate()
            ->first();
        // --- step 6 - kunci alamat aktif buyer - end ---

        // --- step 7 - identifikasi item keranjang yang hilang - start ---
        $lockedCartIds = $cartItems->pluck('id')->all();
        $invalidCartIds = array_values(array_diff($expectedCartIds, $lockedCartIds));
        $snapshotChanged = count($lockedCartIds) !== count($expectedCartIds);
        // --- step 7 - identifikasi item keranjang yang hilang - end ---

        // --- step 8 - periksa item checkout terkunci - start ---
        foreach ($cartItems as $cartItem) {
            // --- step 8.1 - baca ketersediaan produk terkunci - start ---
            $product = $products->get($cartItem->product_id);
            $productPrice = $product?->price;

            $reason = $this->productAvailabilityService->unavailableReason(
                productExists: $product !== null,
                deletedAt: $product?->deleted_at,
                stock: intval($product?->stock ?? 0),
                sellerLocationVerified: isset($verifiedSellerLookup[$cartItem->user_id_seller]),
            );
            // --- step 8.1 - baca ketersediaan produk terkunci - end ---

            // --- step 8.2 - tandai item yang tidak tersedia - start ---
            // Harga dapat hilang sejak snapshot dibangun. Baris yang sudah dikunci menjadi
            // dasar penolakan sebelum Xendit diminta membuat Virtual Account.
            if (
                ! $cartItem->checked
                || ! $cartItem->checkout
                || intval($cartItem->total) < 1
                || $reason !== null
                || $product?->user_id_seller !== $cartItem->user_id_seller
                || intval($cartItem->total) > intval($product?->stock ?? 0)
                || $productPrice === null
            ) {
                $invalidCartIds[] = $cartItem->id;
            }
            // --- step 8.2 - tandai item yang tidak tersedia - end ---

            // --- step 8.3 - bandingkan nilai item dengan snapshot - start ---
            $expectedCartItem = $expectedCartItems[$cartItem->id] ?? null;

            if (
                $expectedCartItem === null
                || (string) $cartItem->product_id !== $expectedCartItem['product_id']
                || (string) $cartItem->user_id_seller !== $expectedCartItem['seller_id']
                || intval($cartItem->total) !== $expectedCartItem['quantity']
                || intval($productPrice ?? 0) !== $expectedCartItem['price']
            ) {
                $snapshotChanged = true;
            }
            // --- step 8.3 - bandingkan nilai item dengan snapshot - end ---
        }
        // --- step 8 - periksa item checkout terkunci - end ---

        // --- step 9 - tolak checkout dengan item tidak tersedia - start ---
        if ($invalidCartIds !== []) {
            throw new CheckoutAvailabilityException(
                'Produk di checkout berubah atau sudah tidak tersedia.',
                array_values(array_unique($invalidCartIds)),
            );
        }
        // --- step 9 - tolak checkout dengan item tidak tersedia - end ---

        // --- step 10 - bandingkan alamat aktif buyer - start ---
        $expectedAddress = $checkoutSnapshot['clientComparable'] ?? [];

        if (
            ! $this->alamatService->isVerifiedPinpoint($activeBuyerAddress)
            || (string) ($activeBuyerAddress?->id ?? '')
                !== (string) ($expectedAddress['alamat_id'] ?? '')
            || (string) ($activeBuyerAddress?->updated_at?->toJSON() ?? '')
                !== (string) ($expectedAddress['alamat_updated_at'] ?? '')
        ) {
            $snapshotChanged = true;
        }
        // --- step 10 - bandingkan alamat aktif buyer - end ---

        if ($snapshotChanged) {
            throw new CheckoutChangedException();
        }
    }
}
