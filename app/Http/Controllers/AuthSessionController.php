<?php

namespace App\Http\Controllers;

use App\Services\AuditLogService;
use App\Services\Clerk\ClerkUserSyncService;
use App\Services\CompanyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class AuthSessionController extends Controller
{
    public function __construct(
        protected ClerkUserSyncService $clerkUserSyncService,
        protected CompanyService $companyService,
        protected AuditLogService $auditLogService
    ) {}

    /**
     * Tujuan endpoint ini untuk menjadi bootstrap auth utama baru,
     * menggantikan pola lama yang hanya mengecek token valid/tidak valid.
     */
    public function show(Request $request): JsonResponse
    {
        // --- step 1 - start - ambil identity provider yang sudah diverifikasi middleware
        $clerkUserId = (string) $request->attributes->get('clerk_user_id', '');
        // --- step 1 - end - ambil identity provider yang sudah diverifikasi middleware

        if ($clerkUserId === '') {
            return response()->json([
                'status' => 401,
                'message' => 'Unauthorized',
            ], 401);
        }

        // --- step 2 - start - sync Clerk dan catat register atau login dalam transaction yang sama
        try {
            $syncResult = $this->clerkUserSyncService->syncByClerkUserIdWithStatus(
                $clerkUserId,
                function ($user, bool $wasCreated) use ($request): void {
                    if ($wasCreated) {
                        $this->auditLogService->recordRegistration($user, $request);

                        return;
                    }

                    $this->auditLogService->recordLogin($user, $request);
                }
            );
            $user = $syncResult['user'];
        } catch (RuntimeException $runtimeException) {
            return response()->json([
                'status' => 422,
                'message' => $runtimeException->getMessage(),
            ], 422);
        } catch (Throwable $throwable) {
            return response()->json([
                'status' => 500,
                'message' => 'Authenticated session could not be synchronized locally.',
            ], 500);
        }
        // --- step 2 - end - sync Clerk dan catat register atau login dalam transaction yang sama

        // --- step 3 - start - tetap pakai formatter company lama agar response konsisten
        $company = $this->companyService->getCompany($user->id)['company'];
        // --- step 3 - end - tetap pakai formatter company lama agar response konsisten

        return response()->json([
            'status' => 200,
            'message' => 'authenticated',
            'user' => $user,
            'company' => $company,
        ], 200);
    }

    /**
     * Mencatat logout user-initiated sebelum frontend menutup session Clerk.
     * Frontend tetap wajib melanjutkan sign-out jika endpoint ini gagal.
     */
    public function logout(Request $request): JsonResponse
    {
        $auditLog = $this->auditLogService->recordLogout($request->user(), $request);

        return response()->json([
            'status' => 200,
            'message' => 'Logout activity recorded successfully.',
            'recorded' => $auditLog !== null,
        ], 200);
    }
}
