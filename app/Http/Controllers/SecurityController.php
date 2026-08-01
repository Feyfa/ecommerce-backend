<?php

namespace App\Http\Controllers;

use App\Services\Clerk\ClerkSecurityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class SecurityController extends Controller
{
    /**
     * Menyiapkan controller dengan layanan keamanan akun Clerk.
     *
     * @param  ClerkSecurityService  $clerkSecurityService  Service clerk security yang digunakan oleh class ini.
     *
     * @return void  Tidak mengembalikan nilai; dependency disimpan pada instance.
     */
    public function __construct(
        protected ClerkSecurityService $clerkSecurityService
    ) {}

    /**
     * Tujuan endpoint ini untuk menampilkan ringkasan keamanan akun
     * yang source of truth-nya berasal dari Clerk.
     *
     * User lokal dan Clerk ID diselesaikan dari request terautentikasi sebelum data provider dibaca.
     * Response hanya berisi ringkasan identity, faktor keamanan, dan koneksi akun yang telah
     * dinormalisasi oleh service.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function summary(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi user Clerk terautentikasi
        $clerkUserId = $this->resolveClerkUserId($request);

        if ($clerkUserId === '') {
            return response()->json([
                'status' => 401,
                'message' => 'Unauthorized',
            ], 401);
        }
        // --- step 1 - end - validasi user Clerk terautentikasi

        try {
            // --- step 2 - start - ambil ringkasan keamanan akun
            $securitySummary = $this->clerkSecurityService->getSummary($clerkUserId);
            // --- step 2 - end - ambil ringkasan keamanan akun

            return response()->json([
                'status' => 200,
                'message' => 'Security summary retrieved successfully.',
                'security' => $securitySummary,
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
     *
     * Clerk ID dan session ID saat ini diambil dari request, lalu seluruh session aktif diformat oleh
     * service. Session yang sedang digunakan ditandai agar frontend tidak menawarkan tindakan revoke
     * yang keliru.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function sessions(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi user dan session Clerk terautentikasi
        $clerkUserId = $this->resolveClerkUserId($request);
        $currentSessionId = $this->resolveClerkSessionId($request);

        if ($clerkUserId === '' || $currentSessionId === '') {
            return response()->json([
                'status' => 401,
                'message' => 'Unauthorized',
            ], 401);
        }
        // --- step 1 - end - validasi user dan session Clerk terautentikasi

        try {
            // --- step 2 - start - ambil daftar session aktif
            $sessionData = $this->clerkSecurityService->getActiveSessions($clerkUserId, $currentSessionId);
            // --- step 2 - end - ambil daftar session aktif

            return response()->json([
                'status' => 200,
                'message' => 'Security sessions retrieved successfully.',
                'session_data' => $sessionData,
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
     *
     * Target session divalidasi sebagai milik Clerk user dan tidak boleh sama dengan session aktif
     * saat ini. Hanya setelah pemeriksaan ownership berhasil service meminta Clerk mencabut session
     * tersebut.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     * @param  string  $sessionId  ID session Clerk yang menjadi target operasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function revokeSession(Request $request, string $sessionId): JsonResponse
    {
        // --- step 1 - start - validasi user dan session Clerk terautentikasi
        $clerkUserId = $this->resolveClerkUserId($request);
        $currentSessionId = $this->resolveClerkSessionId($request);

        if ($clerkUserId === '' || $currentSessionId === '') {
            return response()->json([
                'status' => 401,
                'message' => 'Unauthorized',
            ], 401);
        }
        // --- step 1 - end - validasi user dan session Clerk terautentikasi

        try {
            // --- step 2 - start - cabut session perangkat terpilih
            $result = $this->clerkSecurityService->revokeSession($clerkUserId, $currentSessionId, $sessionId);
            // --- step 2 - end - cabut session perangkat terpilih

            return response()->json([
                'status' => 200,
                'message' => 'Security session revoked successfully.',
                'result' => $result,
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
     *
     * Session aktif saat ini dipertahankan, sedangkan setiap session lain milik Clerk user dicabut
     * melalui service. Response merangkum hasil operasi tanpa menerima daftar session dari client.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function revokeOtherSessions(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi user dan session Clerk terautentikasi
        $clerkUserId = $this->resolveClerkUserId($request);
        $currentSessionId = $this->resolveClerkSessionId($request);

        if ($clerkUserId === '' || $currentSessionId === '') {
            return response()->json([
                'status' => 401,
                'message' => 'Unauthorized',
            ], 401);
        }
        // --- step 1 - end - validasi user dan session Clerk terautentikasi

        try {
            // --- step 2 - start - cabut seluruh session perangkat lain
            $result = $this->clerkSecurityService->revokeOtherSessions($clerkUserId, $currentSessionId);
            // --- step 2 - end - cabut seluruh session perangkat lain

            return response()->json([
                'status' => 200,
                'message' => 'Other security sessions revoked successfully.',
                'result' => $result,
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
     *
     * External account Google terbaru diperiksa terhadap email utama user lokal dan status verifikasi
     * provider. Account sementara yang tidak cocok dibersihkan agar kegagalan linking tidak
     * meninggalkan identity asing pada akun.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function validateGoogleLink(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi user Clerk dan user lokal
        $clerkUserId = $this->resolveClerkUserId($request);
        $user = $request->user();

        if ($clerkUserId === '' || ! $user) {
            return response()->json([
                'status' => 401,
                'message' => 'Unauthorized',
            ], 401);
        }
        // --- step 1 - end - validasi user Clerk dan user lokal

        try {
            // --- step 2 - start - validasi akun Google yang baru dihubungkan
            $google = $this->clerkSecurityService->validateGoogleAccountLink($clerkUserId, $user);
            // --- step 2 - end - validasi akun Google yang baru dihubungkan

            return response()->json([
                'status' => 200,
                'message' => 'Google account linked successfully.',
                'google' => $google,
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
     * Tujuan endpoint ini untuk membersihkan external account Google sementara
     * yang gagal diselesaikan oleh callback OAuth Clerk.
     *
     * Controller meminta service mencari external account Google yang belum selesai diverifikasi.
     * Hanya account sementara yang aman dihapus yang dibersihkan; koneksi Google yang sudah valid
     * dipertahankan.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function cleanupGoogleLink(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi user Clerk terautentikasi
        $clerkUserId = $this->resolveClerkUserId($request);

        if ($clerkUserId === '') {
            return response()->json([
                'status' => 401,
                'message' => 'Unauthorized',
            ], 401);
        }
        // --- step 1 - end - validasi user Clerk terautentikasi

        try {
            // --- step 2 - start - bersihkan akun Google yang gagal dihubungkan
            $google = $this->clerkSecurityService->cleanupFailedGoogleAccountLinks($clerkUserId);
            // --- step 2 - end - bersihkan akun Google yang gagal dihubungkan

            return response()->json([
                'status' => 200,
                'message' => 'Failed Google account link cleaned successfully.',
                'google' => $google,
            ], 200);
        } catch (RuntimeException $runtimeException) {
            return response()->json([
                'status' => 422,
                'message' => $runtimeException->getMessage(),
            ], 422);
        } catch (Throwable $throwable) {
            return response()->json([
                'status' => 422,
                'message' => 'Percobaan menghubungkan Google belum berhasil dibersihkan. Silakan coba lagi.',
            ], 422);
        }
    }

    /**
     * Tujuan helper ini untuk mengambil user id Clerk dari middleware
     * dengan fallback ke user lokal yang sudah login.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return string  Nilai teks yang telah dinormalisasi untuk kebutuhan pemanggil.
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
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return string  Nilai teks yang telah dinormalisasi untuk kebutuhan pemanggil.
     */
    private function resolveClerkSessionId(Request $request): string
    {
        return (string) $request->attributes->get('clerk_session_id', '');
    }
}
