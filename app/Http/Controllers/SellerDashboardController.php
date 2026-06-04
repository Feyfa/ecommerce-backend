<?php

namespace App\Http\Controllers;

use App\Services\SellerDashboardService;
use Illuminate\Http\JsonResponse;

class SellerDashboardController extends Controller
{
    protected SellerDashboardService $sellerDashboardService;

    public function __construct()
    {
        $this->sellerDashboardService = new SellerDashboardService();
    }

    /**
     * Menampilkan data dashboard yang dibatasi ke user yang sedang login.
     */
    public function show(): JsonResponse
    {
        /* VALIDATION USER */
        $user = auth()->user();

        if(empty($user))
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        /* VALIDATION USER */

        /* GET DASHBOARD */
        $dashboard = $this->sellerDashboardService->getDashboard($user->id);
        /* GET DASHBOARD */

        /* RESPONSE */
        return response()->json([
            'status' => 'success',
            ...$dashboard,
        ]);
        /* RESPONSE */
    }
}
