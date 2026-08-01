<?php

namespace App\Http\Controllers;

use App\Models\Keranjang;
use App\Models\Product;
use App\Services\KeranjangService;
use App\Services\ProductAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class KeranjangController extends Controller
{
    /**
     * Menyiapkan controller dengan layanan keranjang dan pemeriksaan ketersediaan produk.
     *
     * @param  KeranjangService  $keranjangService  Layanan pengelolaan keranjang.
     * @param  ProductAvailabilityService  $productAvailabilityService  Layanan pemeriksaan ketersediaan produk.
     *
     * @return void  Tidak mengembalikan nilai; dependency disimpan pada instance.
     */
    public function __construct(
        protected KeranjangService $keranjangService,
        private ProductAvailabilityService $productAvailabilityService,
    ) {}

    /**
     * Menampilkan keranjang buyer setelah menyelaraskan kondisi produk terkini.
     *
     * Identitas buyer diverifikasi sebelum service melakukan read-repair terhadap item yang stok atau
     * ketersediaannya berubah. Response mengembalikan state keranjang terbaru beserta alasan item
     * tidak dapat dibeli agar UI tidak perlu menebak kondisi backend.
     *
     * @param  Request  $request  Request terautentikasi.
     * @param  string  $user_id_buyer  ID buyer pemilik keranjang.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function index(Request $request, string $user_id_buyer): JsonResponse
    {
        // --- step 1 - start - validasi request dan ambil data
        $validator = Validator::make(
            [
                'user_id_buyer' => $user_id_buyer,
            ],
            [
                'user_id_buyer' => ['required', 'uuid'],
            ]
        );

        if ($validator->fails()) {
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);
        }

        $validate = $validator->validate();
        // --- step 1 - end - validasi request dan ambil data

        if ($response = $this->buyerOwnershipResponse($request, $validate['user_id_buyer'])) {
            return $response;
        }

        $getKeranjangs = $this->keranjangService->getKeranjangs($validate['user_id_buyer']);
        $keranjangs = $getKeranjangs['keranjangs'] ?? [];
        $totalPrice = $getKeranjangs['totalPrice'] ?? 0;

        return response()->json(['status' => 200, 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice], 200);
    }

    /**
     * Menambahkan produk ke keranjang buyer.
     *
     * Request divalidasi terhadap identitas buyer, produk, seller, dan jumlah yang diminta. Function
     * menolak akses lintas akun serta produk yang tidak tersedia, lalu memperbarui item lama atau
     * membuat item baru tanpa melampaui stok aktual.
     *
     * @param  Request  $request  Data item keranjang.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function store(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi request dan ambil data
        $validator = Validator::make($request->all(),
            [
                'user_id_seller' => ['required', 'uuid'],
                'user_id_buyer' => ['required', 'uuid'],
                'product_id' => ['required', 'uuid'],
            ]
        );

        if ($validator->fails()) {
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);
        }

        $validate = $validator->validate();
        // --- step 1 - end - validasi request dan ambil data

        if ($response = $this->buyerOwnershipResponse($request, $validate['user_id_buyer'])) {
            return $response;
        }

        $validate['checked'] = 0;
        $validate['total'] = 1;

        $product = $this->productAvailabilityService
            ->findProductForAvailability($validate['product_id']);

        // --- step 2 - start - validasi produk
        if (! $product) {
            return response()->json(['status' => 404, 'message' => 'Produk tidak ditemukan'], 404);
        }

        if ($product->user_id_seller !== $validate['user_id_seller']) {
            return response()->json(['status' => 422, 'message' => 'Data seller produk tidak valid'], 422);
        }

        $unavailableReason = $this->productAvailabilityService->unavailableReason(
            productExists: true,
            deletedAt: $product->deleted_at,
            stock: intval($product->stock),
            sellerLocationVerified: $this->productAvailabilityService
                ->sellerHasVerifiedAddress($product->user_id_seller),
        );

        if ($unavailableReason !== null) {
            return response()->json([
                'status' => 409,
                'code' => $unavailableReason,
                'message' => 'Produk sementara tidak dapat ditambahkan ke keranjang.',
            ], 409);
        }
        // --- step 2 - end - validasi produk

        // --- step 3 - start - ambil item keranjang
        $keranjang = Keranjang::where('user_id_seller', $validate['user_id_seller'])
            ->where('user_id_buyer', $validate['user_id_buyer'])
            ->where('product_id', $validate['product_id'])
            ->first();
        // --- step 3 - end - ambil item keranjang

        // --- step 4 - start - proses item keranjang yang sudah tersedia
        if (! empty($keranjang)) {
            // --- step 5 - start - validasi batas maksimum stok produk
            if ($keranjang->total >= $product->stock) {
                return response()->json(['status' => 422, 'message' => ['stock_maximum' => ["This product stock is a maximum of {$product->stock}"]]], 422);
            }
            // --- step 5 - end - validasi batas maksimum stok produk

            $keranjang->total += 1;
            $keranjang->save();
        }
        // --- step 4 - end - proses item keranjang yang sudah tersedia

        // --- step 6 - start - buat item keranjang baru
        else {
            Keranjang::create($validate);
        }
        // --- step 6 - end - buat item keranjang baru

        return response()->json(['status' => 200], 200);
    }

    /**
     * Menghapus produk tertentu dari keranjang buyer.
     *
     * Kepemilikan buyer dan kecocokan produk diperiksa sebelum row keranjang dihapus. Setelah
     * penghapusan, response dibangun dari state keranjang terbaru agar total dan grouping seller tetap
     * konsisten.
     *
     * @param  Request  $request  Request terautentikasi.
     * @param  string  $user_id_buyer  ID buyer pemilik keranjang.
     * @param  string  $product_id  ID produk yang dihapus.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function delete(Request $request, string $user_id_buyer, string $product_id): JsonResponse
    {
        // --- step 1 - start - validasi request dan ambil data
        $validator = Validator::make(
            [
                'user_id_buyer' => $user_id_buyer,
                'product_id' => $product_id,
            ],
            [
                'user_id_buyer' => ['required', 'uuid'],
                'product_id' => ['required', 'uuid'],
            ]
        );

        if ($validator->fails()) {
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);
        }

        $validate = $validator->validate();
        // --- step 1 - end - validasi request dan ambil data

        if ($response = $this->buyerOwnershipResponse($request, $validate['user_id_buyer'])) {
            return $response;
        }

        // --- step 2 - start - hapus item keranjang
        $keranjangs = Keranjang::where('user_id_buyer', $validate['user_id_buyer'])
            ->where('product_id', $validate['product_id'])
            ->delete();
        // --- step 2 - end - hapus item keranjang

        $getKeranjangs = $this->keranjangService->getKeranjangs($validate['user_id_buyer']);
        $keranjangs = $getKeranjangs['keranjangs'] ?? [];
        $totalPrice = $getKeranjangs['totalPrice'] ?? 0;

        return response()->json(['status' => 200, 'message' => 'Item In Basket Has Been Delete', 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice], 200);
    }

    /**
     * Mengubah status pilihan satu item keranjang.
     *
     * Function memastikan item benar-benar milik buyer terautentikasi dan masih layak dipilih.
     * Perubahan checked dibatalkan ketika produk tidak tersedia atau quantity tidak lagi valid
     * terhadap stok.
     *
     * @param  Request  $request  Data item dan status pilihan.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function checked(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi request dan ambil data
        $validator = Validator::make($request->all(),
            [
                'user_id_buyer' => ['required', 'uuid'],
                'product_id' => ['required', 'uuid'],
                'checked' => ['required', 'boolean'],
            ]
        );

        if ($validator->fails()) {
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);
        }

        $validate = $validator->validate();
        // --- step 1 - end - validasi request dan ambil data

        if ($response = $this->buyerOwnershipResponse($request, $validate['user_id_buyer'])) {
            return $response;
        }

        // --- step 2 - start - ubah pilihan dan validasi availability produk
        $keranjang = Keranjang::where('product_id', $validate['product_id'])
            ->where('user_id_buyer', $validate['user_id_buyer'])
            ->first();

        if (! $keranjang) {
            $getKeranjangs = $this->keranjangService->getKeranjangs($validate['user_id_buyer']);
            $keranjangs = $getKeranjangs['keranjangs'] ?? [];
            $totalPrice = $getKeranjangs['totalPrice'] ?? 0;

            return response()->json(['status' => 404, 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice, 'message' => 'Keranjang tidak ditemukan'], 404);
        }

        $productSoldOutIds = $this->keranjangService->checkProductUnavailableByIds([$validate['product_id']]);

        $keranjang->checked = ($validate['checked']) && empty($productSoldOutIds['ids']) ? true : false;
        $keranjang->save();
        // --- step 2 - end - ubah pilihan dan validasi availability produk

        $getKeranjangs = $this->keranjangService->getKeranjangs($validate['user_id_buyer']);
        $keranjangs = $getKeranjangs['keranjangs'] ?? [];
        $totalPrice = $getKeranjangs['totalPrice'] ?? 0;

        return response()->json(['status' => 200, 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice], 200);
    }

    /**
     * Mengubah status pilihan seluruh item dari satu seller.
     *
     * Semua item dalam grup seller diperiksa menggunakan identitas buyer yang sama. Saat grup
     * diaktifkan, item yang tidak tersedia tetap tidak dipilih dan alasannya dikembalikan bersama
     * state hasil rekonsiliasi.
     *
     * @param  Request  $request  Data seller dan status pilihan.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function checkedGroup(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi request dan ambil data
        $validator = Validator::make($request->all(),
            [
                'user_id_buyer' => ['required', 'uuid'],
                'checked' => ['required', 'boolean'],
                'user_id_seller' => ['required', 'uuid'],
            ]
        );

        if ($validator->fails()) {
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);
        }

        $validate = $validator->validate();
        // --- step 1 - end - validasi request dan ambil data

        if ($response = $this->buyerOwnershipResponse($request, $validate['user_id_buyer'])) {
            return $response;
        }

        // --- step 2 - start - ubah pilihan dan validasi availability produk
        $groupQuery = Keranjang::where('user_id_seller', $validate['user_id_seller'])
            ->where('user_id_buyer', $validate['user_id_buyer']);
        $groupQuery->update(['checked' => 0]);

        if ($validate['checked']) {
            $groupQuery->whereIn(
                'product_id',
                Product::purchasable()
                    ->where('user_id_seller', $validate['user_id_seller'])
                    ->select('id')
            )->update(['checked' => 1]);
        }
        // --- step 2 - end - ubah pilihan dan validasi availability produk

        // --- step 3 - start - ambil state keranjang terbaru
        $getKeranjangs = $this->keranjangService->getKeranjangs($validate['user_id_buyer']);
        $keranjangs = $getKeranjangs['keranjangs'] ?? [];
        $totalPrice = $getKeranjangs['totalPrice'] ?? 0;
        // --- step 3 - end - ambil state keranjang terbaru

        return response()->json(['status' => 200, 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice], 200);
    }

    /**
     * Mengubah status pilihan seluruh item keranjang buyer.
     *
     * Operasi hanya memengaruhi item buyer terautentikasi. Pemilihan massal tetap menghormati
     * ketersediaan serta stok setiap produk sehingga item bermasalah tidak ikut masuk checkout.
     *
     * @param  Request  $request  Data buyer dan status pilihan.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function checkedAll(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi request dan ambil data
        $validator = Validator::make($request->all(),
            [
                'user_id_buyer' => ['required', 'uuid'],
                'checked' => ['required', 'boolean'],
            ]
        );

        if ($validator->fails()) {
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);
        }

        $validate = $validator->validate();
        // --- step 1 - end - validasi request dan ambil data

        if ($response = $this->buyerOwnershipResponse($request, $validate['user_id_buyer'])) {
            return $response;
        }

        // --- step 2 - start - reset pilihan keranjang
        Keranjang::where('user_id_buyer', $validate['user_id_buyer'])
            ->update(['checked' => 0]);
        // --- step 2 - end - reset pilihan keranjang

        // --- step 3 - start - pilih seluruh item keranjang yang tersedia
        if ($validate['checked']) {
            Keranjang::where('user_id_buyer', $validate['user_id_buyer'])
                ->whereIn('product_id', Product::purchasable()->select('id'))
                ->update(['checked' => 1]);
        }
        // --- step 3 - end - pilih seluruh item keranjang yang tersedia

        // --- step 4 - start - ambil state keranjang terbaru
        $getKeranjangs = $this->keranjangService->getKeranjangs($validate['user_id_buyer']);
        $keranjangs = $getKeranjangs['keranjangs'] ?? [];
        $totalPrice = $getKeranjangs['totalPrice'] ?? 0;
        // --- step 4 - end - ambil state keranjang terbaru

        return response()->json(['status' => 200, 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice], 200);
    }

    /**
     * Menambah kuantitas item keranjang dengan tetap mematuhi stok tersedia.
     *
     * Function memverifikasi kepemilikan cart, ketersediaan produk, dan stok terbaru sebelum menaikkan
     * quantity. Jika state produk berubah, quantity lama dipertahankan dan response menjelaskan
     * penyebab penolakan.
     *
     * @param  Request  $request  Data item keranjang.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function plusTotalKeranjang(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi request dan ambil data
        $validator = Validator::make($request->all(),
            [
                'user_id_buyer' => ['required', 'uuid'],
                'product_id' => ['required', 'uuid'],
            ]
        );

        if ($validator->fails()) {
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);
        }

        $validate = $validator->validate();
        // --- step 1 - end - validasi request dan ambil data

        if ($response = $this->buyerOwnershipResponse($request, $validate['user_id_buyer'])) {
            return $response;
        }

        $keranjang = Keranjang::select('id', 'product_id', 'user_id_seller', 'user_id_buyer', 'checked', 'total')
            ->where('user_id_buyer', $validate['user_id_buyer'])
            ->where('product_id', $validate['product_id'])
            ->first();

        if (! $keranjang) {
            $getKeranjangs = $this->keranjangService->getKeranjangs($validate['user_id_buyer']);
            $keranjangs = $getKeranjangs['keranjangs'] ?? [];
            $totalPrice = $getKeranjangs['totalPrice'] ?? 0;

            return response()->json(['status' => 404, 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice, 'message' => 'Keranjang tidak ditemukan'], 404);
        }

        if ($response = $this->unavailableQuantityResponse($validate['user_id_buyer'], $keranjang->product_id)) {
            return $response;
        }

        $product = Product::purchasable()
            ->select('stock')
            ->where('id', $keranjang->product_id)
            ->first();

        if (! $product) {
            $getKeranjangs = $this->keranjangService->getKeranjangs($validate['user_id_buyer']);
            $keranjangs = $getKeranjangs['keranjangs'] ?? [];
            $totalPrice = $getKeranjangs['totalPrice'] ?? 0;

            return response()->json(['status' => 404, 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice, 'message' => 'Produk tidak ditemukan'], 404);
        }

        // --- step 2 - start - validasi batas maksimum stok produk
        if ($keranjang->total >= $product->stock) {
            return response()->json(['status' => 422, 'message' => ['stock_maximum' => ["This product stock is a maximum of {$product->stock}"]]], 422);
        }
        // --- step 2 - end - validasi batas maksimum stok produk

        // --- step 3 - start - tambah satu quantity keranjang
        $keranjang->total += 1;
        $keranjang->save();
        // --- step 3 - end - tambah satu quantity keranjang

        // --- step 4 - start - ambil state keranjang terbaru
        $getKeranjangs = $this->keranjangService->getKeranjangs($validate['user_id_buyer']);
        $keranjangs = $getKeranjangs['keranjangs'] ?? [];
        $totalPrice = $getKeranjangs['totalPrice'] ?? 0;
        // --- step 4 - end - ambil state keranjang terbaru

        return response()->json(['status' => 200, 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice], 200);
    }

    /**
     * Mengurangi kuantitas item keranjang.
     *
     * Function memverifikasi kepemilikan item lalu menurunkan quantity tanpa melewati batas minimum.
     * State terbaru dikembalikan agar UI menggunakan nilai backend sebagai sumber kebenaran.
     *
     * @param  Request  $request  Data item keranjang.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function minusTotalKeranjang(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi request dan ambil data
        $validator = Validator::make($request->all(),
            [
                'user_id_buyer' => ['required', 'uuid'],
                'product_id' => ['required', 'uuid'],
            ]
        );

        if ($validator->fails()) {
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);
        }

        $validate = $validator->validate();
        // --- step 1 - end - validasi request dan ambil data

        if ($response = $this->buyerOwnershipResponse($request, $validate['user_id_buyer'])) {
            return $response;
        }

        // --- step 2 - start - tambah satu quantity keranjang
        $keranjang = Keranjang::select('id', 'user_id_seller', 'user_id_buyer', 'product_id', 'checked', 'total')
            ->where('user_id_buyer', $validate['user_id_buyer'])
            ->where('product_id', $validate['product_id'])
            ->first();

        if (! $keranjang) {
            $getKeranjangs = $this->keranjangService->getKeranjangs($validate['user_id_buyer']);
            $keranjangs = $getKeranjangs['keranjangs'] ?? [];
            $totalPrice = $getKeranjangs['totalPrice'] ?? 0;

            return response()->json(['status' => 404, 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice, 'message' => 'Keranjang tidak ditemukan'], 404);
        }

        if ($response = $this->unavailableQuantityResponse($validate['user_id_buyer'], $keranjang->product_id)) {
            return $response;
        }

        if ($keranjang->total <= 1) {
            return response()->json(['status' => 422, 'message' => ['total_minimum' => ['This product total is a minimum of 1']]], 422);
        }

        $keranjang->total -= 1;
        $keranjang->save();
        // --- step 2 - end - tambah satu quantity keranjang

        // --- step 3 - start - ambil state keranjang terbaru
        $getKeranjangs = $this->keranjangService->getKeranjangs($validate['user_id_buyer']);
        $keranjangs = $getKeranjangs['keranjangs'] ?? [];
        $totalPrice = $getKeranjangs['totalPrice'] ?? 0;
        // --- step 3 - end - ambil state keranjang terbaru

        return response()->json(['status' => 200, 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice], 200);
    }

    /**
     * Menetapkan kuantitas item keranjang ke nilai yang diminta buyer.
     *
     * Nilai quantity dinormalisasi dan divalidasi terhadap item milik buyer serta stok produk terkini.
     * Update hanya dilakukan ketika seluruh invariant terpenuhi; kegagalan mempertahankan quantity
     * sebelumnya dan mengembalikan alasan yang dapat ditampilkan UI.
     *
     * @param  Request  $request  Data item dan kuantitas baru.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function changeTotalKeranjang(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi request dan ambil data
        $validator = Validator::make($request->all(),
            [
                'user_id_buyer' => ['required', 'uuid'],
                'product_id' => ['required', 'uuid'],
                'total' => ['required', 'integer', 'min:1'],
            ]
        );

        if ($validator->fails()) {
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);
        }

        $validate = $validator->validate();
        // --- step 1 - end - validasi request dan ambil data

        if ($response = $this->buyerOwnershipResponse($request, $validate['user_id_buyer'])) {
            return $response;
        }

        $keranjang = Keranjang::select('id', 'product_id', 'user_id_seller', 'user_id_buyer', 'checked', 'total')
            ->where('user_id_buyer', $validate['user_id_buyer'])
            ->where('product_id', $validate['product_id'])
            ->first();

        if (! $keranjang) {
            $getKeranjangs = $this->keranjangService->getKeranjangs($validate['user_id_buyer']);
            $keranjangs = $getKeranjangs['keranjangs'] ?? [];
            $totalPrice = $getKeranjangs['totalPrice'] ?? 0;

            return response()->json(['status' => 404, 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice, 'message' => 'Keranjang tidak ditemukan'], 404);
        }

        if ($response = $this->unavailableQuantityResponse($validate['user_id_buyer'], $keranjang->product_id)) {
            return $response;
        }

        $product = Product::purchasable()
            ->select('stock')
            ->where('id', $keranjang->product_id)
            ->first();

        if (! $product) {
            $getKeranjangs = $this->keranjangService->getKeranjangs($validate['user_id_buyer']);
            $keranjangs = $getKeranjangs['keranjangs'] ?? [];
            $totalPrice = $getKeranjangs['totalPrice'] ?? 0;

            return response()->json(['status' => 404, 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice, 'message' => 'Produk tidak ditemukan'], 404);
        }

        // --- step 2 - start - validasi quantity terhadap stok produk
        if ($validate['total'] > $product->stock) {
            // --- step 3 - start - ambil state keranjang terbaru
            $getKeranjangs = $this->keranjangService->getKeranjangs($validate['user_id_buyer']);
            $keranjangs = $getKeranjangs['keranjangs'] ?? [];
            $totalPrice = $getKeranjangs['totalPrice'] ?? 0;
            // --- step 3 - end - ambil state keranjang terbaru

            return response()->json(['status' => 422, 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice, 'message' => ['stock_maximum' => ["This product stock is a maximum of {$product->stock}"]]], 422);
        }
        // --- step 2 - end - validasi quantity terhadap stok produk

        // --- step 4 - start - ubah quantity keranjang
        $keranjang->total = $validate['total'];
        $keranjang->save();
        // --- step 4 - end - ubah quantity keranjang

        // --- step 5 - start - ambil state keranjang terbaru
        $getKeranjangs = $this->keranjangService->getKeranjangs($validate['user_id_buyer']);
        $keranjangs = $getKeranjangs['keranjangs'] ?? [];
        $totalPrice = $getKeranjangs['totalPrice'] ?? 0;
        // --- step 5 - end - ambil state keranjang terbaru

        return response()->json(['status' => 200, 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice], 200);
    }

    /**
     * Memvalidasi item terpilih sebelum buyer memasuki halaman checkout.
     *
     * Function merekonsiliasi seluruh item terpilih dengan stok, status produk, lokasi seller, dan
     * alamat buyer sebelum checkout dimulai. Item bermasalah dilepas dari pilihan tanpa mereset
     * quantity, sedangkan item valid dipertahankan sehingga perubahan pada satu seller tidak merusak
     * seller lain.
     *
     * @param  Request  $request  Request buyer terautentikasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function validateCheckout(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi request dan ambil data
        $validator = Validator::make($request->all(), [
            'product_ids' => ['required', 'array'],
            'product_ids.*' => ['uuid'],
            'user_id_buyer' => ['required', 'uuid'],
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->messages()], 422);
        }

        if ($response = $this->buyerOwnershipResponse($request, $request->user_id_buyer)) {
            return $response;
        }
        // --- step 1 - end - validasi request dan ambil data

        // --- step 2 - start - validasi alamat buyer
        $checkAlamatBuyerExist = $this->keranjangService->checkAlamatBuyerExist($request->user_id_buyer);

        if (! $checkAlamatBuyerExist['exists']) {
            return response()->json([
                'status' => 'error',
                'code' => 'BUYER_ADDRESS_REQUIRED',
                'message' => 'Tambahkan alamat pengiriman sebelum melanjutkan checkout.',
            ], 400);
        }
        // --- step 2 - end - validasi alamat buyer

        // --- step 3 - start - sinkronkan availability produk terbaru
        $currentCart = $this->keranjangService->getKeranjangs($request->user_id_buyer);
        $selectedStockIssues = $currentCart['selectedStockIssues'] ?? [];
        $unavailableSelectedReasons = $currentCart['unavailableSelectedReasons'] ?? [];
        $hasNonStockUnavailableItem = collect($unavailableSelectedReasons)
            ->contains(fn (string $reason): bool => $reason !== ProductAvailabilityService::OUT_OF_STOCK);

        if ($this->productAvailabilityService->hasOnlyUnavailableReason(
            $unavailableSelectedReasons,
            ProductAvailabilityService::SELLER_LOCATION_UNVERIFIED,
        )) {
            return response()->json([
                'status' => 'error',
                'code' => 'SELLER_ADDRESS_REQUIRES_VERIFICATION',
                'message' => 'Lokasi toko penjual belum diverifikasi. Produk terkait tidak dapat dilanjutkan ke checkout.',
                'keranjangs' => $currentCart['keranjangs'] ?? [],
                'totalPrice' => $currentCart['totalPrice'] ?? 0,
            ], 409);
        }

        if ($selectedStockIssues !== [] && ! $hasNonStockUnavailableItem) {
            return $this->stockChangedResponse($currentCart, $selectedStockIssues);
        }

        if (($currentCart['unavailableSelectedItemIds'] ?? []) !== []) {
            return response()->json([
                'status' => 'error',
                'code' => 'CHECKOUT_INVALID',
                'message' => 'Produk yang dipilih berubah atau sudah tidak tersedia.',
                'keranjangs' => $currentCart['keranjangs'] ?? [],
                'totalPrice' => $currentCart['totalPrice'] ?? 0,
            ], 409);
        }
        // --- step 3 - end - sinkronkan availability produk terbaru

        // --- step 4 - start - validasi item keranjang terpilih
        $keranjangNotChecked = $this->keranjangService->checkKeranjangNotChecked($request->user_id_buyer);

        if (! $keranjangNotChecked['checked']) {
            $keranjangs = $currentCart['keranjangs'] ?? [];
            $totalPrice = $currentCart['totalPrice'] ?? 0;

            return response()->json(['status' => 'error', 'message' => 'Keranjang belum ada yang di checked', 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice], 400);
        }
        // --- step 4 - end - validasi item keranjang terpilih

        // --- step 5 - start - validasi state keranjang frontend
        $checkedProductIds = Keranjang::where('user_id_buyer', $request->user_id_buyer)
            ->where('checked', 1)
            ->where('total', '>', 0)
            ->pluck('product_id')
            ->toArray();

        $requestProductIds = array_values(array_unique($request->product_ids));
        $checkedProductIds = array_values(array_unique($checkedProductIds));
        sort($requestProductIds);
        sort($checkedProductIds);

        if ($requestProductIds !== $checkedProductIds) {
            $getKeranjangs = $this->keranjangService->getKeranjangs($request->user_id_buyer);
            $keranjangs = $getKeranjangs['keranjangs'] ?? [];
            $totalPrice = $getKeranjangs['totalPrice'] ?? 0;

            return response()->json(['status' => 'error', 'message' => 'Keranjang berubah, silakan cek ulang sebelum checkout', 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice], 409);
        }
        // --- step 5 - end - validasi state keranjang frontend

        // --- step 6 - start - periksa availability produk terpilih
        $productSoldOutIds = $this->keranjangService->checkProductUnavailableByIds($request->product_ids);

        if (! empty($productSoldOutIds['ids'])) {
            $getKeranjangs = $this->keranjangService->getKeranjangs($request->user_id_buyer);
            $keranjangs = $getKeranjangs['keranjangs'] ?? [];
            $totalPrice = $getKeranjangs['totalPrice'] ?? 0;
            $selectedStockIssues = $getKeranjangs['selectedStockIssues'] ?? [];
            $unavailableSelectedReasons = $getKeranjangs['unavailableSelectedReasons'] ?? [];
            $hasNonStockUnavailableItem = collect($unavailableSelectedReasons)
                ->contains(fn (string $reason): bool => $reason !== ProductAvailabilityService::OUT_OF_STOCK);

            if ($selectedStockIssues !== [] && ! $hasNonStockUnavailableItem) {
                return $this->stockChangedResponse($getKeranjangs, $selectedStockIssues);
            }

            return response()->json([
                'status' => 'error',
                'code' => 'CHECKOUT_INVALID',
                'message' => 'Produk yang dipilih berubah atau sudah tidak tersedia.',
                'keranjangs' => $keranjangs,
                'totalPrice' => $totalPrice,
            ], 409);
        }
        // --- step 6 - end - periksa availability produk terpilih

        // --- step 7 - start - validasi quantity checkout
        $invalidCheckoutKeranjangIds = Keranjang::leftJoin('products', 'keranjangs.product_id', '=', 'products.id')
            ->where('keranjangs.user_id_buyer', $request->user_id_buyer)
            ->where('keranjangs.checked', 1)
            ->where(function ($query) {
                $query->whereNull('products.id')
                    ->orWhereNotNull('products.deleted_at')
                    ->orWhere('keranjangs.total', '<', 1)
                    ->orWhereColumn('keranjangs.total', '>', 'products.stock');
            })
            ->pluck('keranjangs.id')
            ->all();

        if ($invalidCheckoutKeranjangIds !== []) {
            Keranjang::where('user_id_buyer', $request->user_id_buyer)
                ->whereIn('id', $invalidCheckoutKeranjangIds)
                ->update(['checked' => 0, 'checkout' => 0]);

            $getKeranjangs = $this->keranjangService->getKeranjangs($request->user_id_buyer);
            $keranjangs = $getKeranjangs['keranjangs'] ?? [];
            $totalPrice = $getKeranjangs['totalPrice'] ?? 0;

            $stockIssues = $getKeranjangs['stockIssues'] ?? [];

            if ($stockIssues !== []) {
                return $this->stockChangedResponse($getKeranjangs, $stockIssues);
            }

            return response()->json(['status' => 'error', 'message' => 'Jumlah produk di keranjang berubah, silakan cek ulang sebelum checkout', 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice], 409);
        }
        // --- step 7 - end - validasi quantity checkout

        // --- step 8 - start - perbarui item checkout
        $this->keranjangService->updateCheckoutKeranjang($request->user_id_buyer);
        // --- step 8 - end - perbarui item checkout

        return response()->json(['status' => 'success', 'message' => 'Checkout validation successful']);
    }

    /**
     * Mengembalikan kontrak terstruktur agar UI dapat menunjukkan produk yang perlu diperbaiki.
     *
     * @param  array<string, mixed>  $cartState  State cart terbaru beserta issue ketersediaannya.
     * @param  array<int, array<string, mixed>>  $issues  Daftar masalah ketersediaan yang akan diterjemahkan ke response.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    private function stockChangedResponse(array $cartState, array $issues): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'code' => 'CART_STOCK_CHANGED',
            'message' => 'Stok beberapa produk berubah. Periksa produk yang ditandai sebelum checkout.',
            'issues' => array_values($issues),
            'keranjangs' => $cartState['keranjangs'] ?? [],
            'totalPrice' => $cartState['totalPrice'] ?? 0,
        ], 409);
    }

    /**
     * Memastikan seluruh operasi cart hanya menggunakan identitas user terautentikasi.
     *
     * Identifier buyer dari route dibandingkan dengan user pada request. Ketidaksesuaian dihentikan
     * sebagai forbidden sebelum query atau mutasi cart dijalankan.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     * @param  string  $buyerId  ID buyer yang menjadi scope operasi.
     *
     * @return JsonResponse|null  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    private function buyerOwnershipResponse(Request $request, string $buyerId): ?JsonResponse
    {
        if ((string) optional($request->user())->id === $buyerId) {
            return null;
        }

        return response()->json([
            'status' => 'error',
            'code' => 'CART_FORBIDDEN',
            'message' => 'Forbidden',
        ], 403);
    }

    /**
     * Menolak mutasi quantity untuk item yang tidak dapat dibeli tanpa mengubah quantity tersimpan.
     *
     * Ketersediaan produk diperiksa melalui service yang memakai definisi sama dengan katalog dan
     * checkout. Response menyertakan kode alasan stabil serta state cart tanpa menurunkan atau
     * menaikkan quantity secara diam-diam.
     *
     * @param  string  $buyerId  ID buyer yang menjadi scope operasi.
     * @param  string  $productId  ID produk yang menjadi target operasi.
     *
     * @return JsonResponse|null  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    private function unavailableQuantityResponse(string $buyerId, string $productId): ?JsonResponse
    {
        $availability = $this->keranjangService->checkProductUnavailableByIds([$productId]);

        if ($availability['ids'] === []) {
            return null;
        }

        $currentCart = $this->keranjangService->getKeranjangs($buyerId);

        return response()->json([
            'status' => 409,
            'code' => $availability['reasons'][$productId] ?? 'PRODUCT_UNAVAILABLE',
            'message' => 'Produk sudah tidak tersedia. Quantity keranjang tetap dipertahankan.',
            'keranjangs' => $currentCart['keranjangs'] ?? [],
            'totalPrice' => $currentCart['totalPrice'] ?? 0,
        ], 409);
    }
}
