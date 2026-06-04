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
     * Menampilkan data dashboard untuk seller yang sedang login.
     */
    public function show(): JsonResponse
    {
        /* VALIDATION USER */
        $user = auth()->user();

        if(empty($user))
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);

        if($user->account_type != 'seller')
            return response()->json(['status' => 'error', 'message' => 'This dashboard only for seller'], 403);
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
