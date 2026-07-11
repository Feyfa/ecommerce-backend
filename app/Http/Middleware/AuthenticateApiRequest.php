<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Clerk\ClerkBackendClientService;
use Clerk\Backend\Helpers\Jwks\AuthenticateRequest;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticateApiRequest
{
    /**
     * Mode khusus untuk endpoint seperti /auth/me:
     * token Clerk tetap wajib valid, tapi row users lokal boleh belum ada.
     *
     * Contoh case: user baru pertama kali register/login lewat Clerk,
     * lalu frontend memanggil /auth/me saat data user tersebut belum ada
     * di table users lokal. Controller /auth/me akan melakukan sync/create
     * user lokal setelah token Clerk berhasil diverifikasi middleware ini.
     */
    private const MODE_OPTIONAL_USER = 'optional-user';

    public function __construct(
        protected ClerkBackendClientService $clerkBackendClientService
    ) {
    }

    /**
     * Tujuan middleware ini untuk menerima request API protected
     * yang sudah membawa token sesi auth utama.
     */
    public function handle(Request $request, Closure $next, string $mode = 'strict'): Response
    {
        /* step 1: verifikasi request lewat provider auth utama */
        try {
            $requestState = AuthenticateRequest::authenticateRequest(
                $request,
                $this->clerkBackendClientService->makeAuthenticateRequestOptions()
            );
        } catch (Throwable $throwable) {
            return response()->json([
                'status' => 500,
                'message' => 'Authentication service is not configured correctly.',
            ], 500);
        }

        if (!$requestState->isAuthenticated()) {
            return response()->json([
                'status' => 401,
                'message' => 'Unauthorized',
            ], 401);
        }

        /* step 2: ambil identity provider dari token yang sudah valid */
        try {
            $authObject = $requestState->toAuth();
            $clerkUserId = property_exists($authObject, 'sub')
                ? (string) $authObject->sub
                : (property_exists($authObject, 'user_id') ? (string) $authObject->user_id : '');
            $clerkSessionId = property_exists($authObject, 'sid')
                ? (string) $authObject->sid
                : (property_exists($authObject, 'session_id') ? (string) $authObject->session_id : '');

            if ($clerkUserId === '') {
                return response()->json([
                    'status' => 401,
                    'message' => 'Unauthorized',
                ], 401);
            }
        } catch (Throwable $throwable) {
            return response()->json([
                'status' => 500,
                'message' => 'Authenticated session could not be resolved.',
            ], 500);
        }
        /* step 2 */

        /* step 3: resolve user lokal tanpa melakukan sync ke Clerk pada setiap request */
        $request->attributes->set('clerk_user_id', $clerkUserId);
        $request->attributes->set('clerk_session_id', $clerkSessionId);

        $user = User::query()
            ->where('clerk_user_id', $clerkUserId)
            ->first();

        if (!$user && $mode !== self::MODE_OPTIONAL_USER) {
            return response()->json([
                'status' => 401,
                'message' => 'Unauthorized',
            ], 401);
        }

        if ($user) {
            $this->setAuthenticatedUser($request, $user);
        }
        /* step 3 */

        return $next($request);
    }

    /**
     * Tujuan helper ini untuk memastikan auth()->user() dan request->user()
     * sama-sama mengenali user lokal yang sudah berhasil diautentikasi.
     */
    private function setAuthenticatedUser(Request $request, User $user): void
    {
        Auth::shouldUse('web');
        Auth::setUser($user);

        $request->setUserResolver(static fn () => $user);
    }
}
