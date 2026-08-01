<?php

namespace App\Http\Controllers;

use App\Services\SellerDashboardService;
use Illuminate\Http\JsonResponse;

class SellerDashboardController extends Controller
{
    /**
     * Menyiapkan controller dengan layanan dashboard seller.
     *
     * @param  SellerDashboardService  $sellerDashboardService  Layanan ringkasan dashboard seller.
     *
     * @return void  Tidak mengembalikan nilai; dependency disimpan pada instance.
     */
    public function __construct(protected SellerDashboardService $sellerDashboardService) {}

    /**
     * Menampilkan data dashboard yang dibatasi ke user yang sedang login.
     *
     * Controller menolak identifier seller yang tidak sama dengan user terautentikasi sebelum service
     * dipanggil. Ringkasan, performa, transaksi terbaru, dan snapshot produk dikembalikan hanya untuk
     * toko tersebut.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function show(): JsonResponse
    {
        // --- step 1 - start - validasi user
        $user = auth()->user();

        if (empty($user)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - end - validasi user

        // --- step 2 - start - ambil data dashboard
        $dashboard = $this->sellerDashboardService->getDashboard($user->id);
        // --- step 2 - end - ambil data dashboard

        return response()->json([
            'status' => 'success',
            ...$dashboard,
        ]);
    }
}
