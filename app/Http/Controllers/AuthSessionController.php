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
    /**
     * Menyiapkan controller dengan layanan sesi dan keamanan Clerk.
     *
     * @param  ClerkUserSyncService  $clerkUserSyncService  Service clerk user sync yang digunakan oleh class ini.
     * @param  CompanyService  $companyService  Service company yang digunakan oleh class ini.
     * @param  AuditLogService  $auditLogService  Service audit log yang digunakan oleh class ini.
     *
     * @return void  Tidak mengembalikan nilai; dependency disimpan pada instance.
     */
    public function __construct(
        protected ClerkUserSyncService $clerkUserSyncService,
        protected CompanyService $companyService,
        protected AuditLogService $auditLogService
    ) {}

    /**
     * Tujuan endpoint ini untuk menjadi bootstrap auth utama baru,
     * menggantikan pola lama yang hanya mengecek token valid/tidak valid.
     *
     * Token Clerk digunakan untuk menyinkronkan atau membuat user lokal dalam transaksi. Event
     * registration atau login dicatat berdasarkan status create dan session ID, lalu response
     * menyediakan user bootstrap yang menjadi sumber auth frontend.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
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
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
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
