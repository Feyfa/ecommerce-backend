<?php

namespace App\Http\Controllers;

use App\Services\Clerk\ClerkSecurityService;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class SecurityController extends Controller
{
    public function __construct(
        protected ClerkSecurityService $clerkSecurityService
    ) {
    }

    /**
     * Tujuan endpoint ini untuk menampilkan ringkasan keamanan akun
     * yang source of truth-nya berasal dari Clerk.
     */
    public function summary(Request $request)
    {
        $clerkUserId = $this->resolveClerkUserId($request);

        if ($clerkUserId === '') {
            return response()->json([
                'status' => 401,
                'message' => 'Unauthorized',
            ], 401);
        }

        try {
            return response()->json([
                'status' => 200,
                'message' => 'Security summary retrieved successfully.',
                'security' => $this->clerkSecurityService->getSummary($clerkUserId),
            ], 200);
        } catch (Throwable $throwable) {
            return response()->json([
                'status' => 500,
                'message' => 'Security summary could not be loaded.',
            ], 500);
        }
    }

    /**
     * Tujuan endpoint ini untuk menampilkan session aktif
     * dan membedakan session yang sedang dipakai user.
     */
    public function sessions(Request $request)
    {
        $clerkUserId = $this->resolveClerkUserId($request);
        $currentSessionId = $this->resolveClerkSessionId($request);

        if ($clerkUserId === '' || $currentSessionId === '') {
            return response()->json([
                'status' => 401,
                'message' => 'Unauthorized',
            ], 401);
        }

        try {
            return response()->json([
                'status' => 200,
                'message' => 'Security sessions retrieved successfully.',
                'session_data' => $this->clerkSecurityService->getActiveSessions($clerkUserId, $currentSessionId),
            ], 200);
        } catch (Throwable $throwable) {
            return response()->json([
                'status' => 500,
                'message' => 'Security sessions could not be loaded.',
            ], 500);
        }
    }

    /**
     * Tujuan endpoint ini untuk mengeluarkan satu perangkat lain
     * dari akun yang sedang login.
     */
    public function revokeSession(Request $request, string $sessionId)
    {
        $clerkUserId = $this->resolveClerkUserId($request);
        $currentSessionId = $this->resolveClerkSessionId($request);

        if ($clerkUserId === '' || $currentSessionId === '') {
            return response()->json([
                'status' => 401,
                'message' => 'Unauthorized',
            ], 401);
        }

        try {
            return response()->json([
                'status' => 200,
                'message' => 'Security session revoked successfully.',
                'result' => $this->clerkSecurityService->revokeSession($clerkUserId, $currentSessionId, $sessionId),
            ], 200);
        } catch (Throwable $throwable) {
            return response()->json([
                'status' => 422,
                'message' => 'Security session could not be revoked.',
            ], 422);
        }
    }

    /**
     * Tujuan endpoint ini untuk mengeluarkan semua perangkat lain
     * tanpa memutus session yang sedang dipakai.
     */
    public function revokeOtherSessions(Request $request)
    {
        $clerkUserId = $this->resolveClerkUserId($request);
        $currentSessionId = $this->resolveClerkSessionId($request);

        if ($clerkUserId === '' || $currentSessionId === '') {
            return response()->json([
                'status' => 401,
                'message' => 'Unauthorized',
            ], 401);
        }

        try {
            return response()->json([
                'status' => 200,
                'message' => 'Other security sessions revoked successfully.',
                'result' => $this->clerkSecurityService->revokeOtherSessions($clerkUserId, $currentSessionId),
            ], 200);
        } catch (Throwable $throwable) {
            return response()->json([
                'status' => 422,
                'message' => 'Other security sessions could not be revoked.',
            ], 422);
        }
    }

    /**
     * Tujuan endpoint ini untuk memvalidasi hasil hubungkan Google
     * agar email Google sama dengan email akun lokal yang sedang login.
     */
    public function validateGoogleLink(Request $request)
    {
        $clerkUserId = $this->resolveClerkUserId($request);
        $user = $request->user();

        if ($clerkUserId === '' || !$user) {
            return response()->json([
                'status' => 401,
                'message' => 'Unauthorized',
            ], 401);
        }

        try {
            return response()->json([
                'status' => 200,
                'message' => 'Google account linked successfully.',
                'google' => $this->clerkSecurityService->validateGoogleAccountLink($clerkUserId, $user),
            ], 200);
        } catch (RuntimeException $runtimeException) {
            return response()->json([
                'status' => 422,
                'message' => $runtimeException->getMessage(),
            ], 422);
        } catch (Throwable $throwable) {
            return response()->json([
                'status' => 422,
                'message' => 'Akun Google belum berhasil dihubungkan.',
            ], 422);
        }
    }

    /**
     * Tujuan helper ini untuk mengambil user id Clerk dari middleware
     * dengan fallback ke user lokal yang sudah login.
     */
    private function resolveClerkUserId(Request $request): string
    {
        $clerkUserId = (string) $request->attributes->get('clerk_user_id', '');

        if ($clerkUserId !== '') {
            return $clerkUserId;
        }

        return (string) optional($request->user())->clerk_user_id;
    }

    /**
     * Tujuan helper ini untuk mengambil session id Clerk yang dikirim
     * dari token session aktif.
     */
    private function resolveClerkSessionId(Request $request): string
    {
        return (string) $request->attributes->get('clerk_session_id', '');
    }
}
