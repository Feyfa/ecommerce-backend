<?php

namespace App\Http\Controllers;

use App\Services\Clerk\ClerkUserSyncService;
use App\Services\CompanyService;
use Illuminate\Http\Request;
use Throwable;

class AuthSessionController extends Controller
{
    public function __construct(
        protected ClerkUserSyncService $clerkUserSyncService,
        protected CompanyService $companyService
    ) {
    }

    /**
     * Tujuan endpoint ini untuk menjadi bootstrap auth utama baru,
     * menggantikan pola lama yang hanya mengecek token valid/tidak valid.
     */
    public function show(Request $request)
    {
        /* step 1: ambil identity provider yang sudah diverifikasi middleware */
        $clerkUserId = (string) $request->attributes->get('clerk_user_id', '');

        if ($clerkUserId === '') {
            return response()->json([
                'status' => 401,
                'message' => 'Unauthorized',
            ], 401);
        }
        /* step 1 */

        /* step 2: sync Clerk hanya pada endpoint bootstrap auth utama */
        try {
            $user = $this->clerkUserSyncService->syncByClerkUserId($clerkUserId);
        } catch (Throwable $throwable) {
            return response()->json([
                'status' => 500,
                'message' => 'Authenticated session could not be synchronized locally.',
            ], 500);
        }
        /* step 2 */

        /* step 3: tetap pakai formatter company lama agar response konsisten */
        $company = $this->companyService->getCompany($user->id)['company'];
        /* step 3 */

        return response()->json([
            'status' => 200,
            'message' => 'authenticated',
            'user' => $user,
            'company' => $company,
        ], 200);
    }
}
