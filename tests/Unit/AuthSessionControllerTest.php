<?php

namespace Tests\Unit;

use App\Http\Controllers\AuthSessionController;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Clerk\ClerkUserSyncService;
use App\Services\CompanyService;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Memverifikasi narrowing identity pada bootstrap dan logout Clerk.
 */
class AuthSessionControllerTest extends TestCase
{
    /**
     * Memastikan bootstrap meneruskan Clerk user ID string yang sudah diverifikasi middleware.
     *
     * @return void Tidak mengembalikan nilai; response bootstrap dan interaksi service diverifikasi.
     */
    public function test_show_accepts_string_clerk_user_id(): void
    {
        $request = Request::create('/api/auth/me');
        $request->attributes->set('clerk_user_id', 'user_clerk_123');

        $user = new User();
        $user->id = 'user-local-123';

        $syncService = $this->createMock(ClerkUserSyncService::class);
        $syncService
            ->expects($this->once())
            ->method('syncByClerkUserIdWithStatus')
            ->with('user_clerk_123', $this->isInstanceOf(\Closure::class))
            ->willReturn(['user' => $user, 'was_created' => false]);

        $companyService = $this->createMock(CompanyService::class);
        $companyService
            ->expects($this->once())
            ->method('getCompany')
            ->with('user-local-123')
            ->willReturn(['status' => 'success', 'company' => ['name' => 'TokShop']]);

        $controller = new AuthSessionController(
            $syncService,
            $companyService,
            $this->createMock(AuditLogService::class),
        );

        $response = $controller->show($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('authenticated', $response->getData(true)['message']);
        $this->assertSame(['name' => 'TokShop'], $response->getData(true)['company']);
    }

    /**
     * Memastikan Clerk user ID kosong atau non-string ditolak sebelum proses sinkronisasi.
     *
     * @param  mixed  $clerkUserId  Nilai attribute request yang tidak boleh dipakai sebagai identity.
     *
     * @return void Tidak mengembalikan nilai; response unauthorized diverifikasi.
     */
    #[DataProvider('invalidClerkUserIds')]
    public function test_show_rejects_empty_or_malformed_clerk_user_id(mixed $clerkUserId): void
    {
        $request = Request::create('/api/auth/me');
        $request->attributes->set('clerk_user_id', $clerkUserId);

        $syncService = $this->createMock(ClerkUserSyncService::class);
        $syncService
            ->expects($this->never())
            ->method('syncByClerkUserIdWithStatus');

        $controller = new AuthSessionController(
            $syncService,
            $this->createMock(CompanyService::class),
            $this->createMock(AuditLogService::class),
        );

        $response = $controller->show($request);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([
            'status' => 401,
            'message' => 'Unauthorized',
        ], $response->getData(true));
    }

    /**
     * Menyediakan Clerk user ID kosong dan malformed untuk kontrak bootstrap.
     *
     * @return array<string, array{mixed}> Daftar attribute yang harus menghasilkan unauthorized.
     */
    public static function invalidClerkUserIds(): array
    {
        return [
            'empty string' => [''],
            'null' => [null],
            'integer' => [123],
            'boolean' => [true],
            'array' => [['user_clerk_123']],
        ];
    }

    /**
     * Memastikan logout user lokal tetap mencatat audit dan mempertahankan payload sukses.
     *
     * @return void Tidak mengembalikan nilai; actor audit dan response logout diverifikasi.
     */
    public function test_logout_records_authenticated_user(): void
    {
        $user = new User();
        $request = Request::create('/api/auth/logout', 'POST');
        $request->setUserResolver(static fn (): User => $user);

        $auditLogService = $this->createMock(AuditLogService::class);
        $auditLogService
            ->expects($this->once())
            ->method('recordLogout')
            ->with($this->identicalTo($user), $this->identicalTo($request))
            ->willReturn(null);

        $controller = new AuthSessionController(
            $this->createMock(ClerkUserSyncService::class),
            $this->createMock(CompanyService::class),
            $auditLogService,
        );

        $response = $controller->logout($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'status' => 200,
            'message' => 'Logout activity recorded successfully.',
            'recorded' => false,
        ], $response->getData(true));
    }

    /**
     * Memastikan logout tanpa user lokal berhenti dengan payload unauthorized yang konsisten.
     *
     * @return void Tidak mengembalikan nilai; audit tidak dipanggil dan response diverifikasi.
     */
    public function test_logout_rejects_request_without_authenticated_user(): void
    {
        $request = Request::create('/api/auth/logout', 'POST');

        $auditLogService = $this->createMock(AuditLogService::class);
        $auditLogService
            ->expects($this->never())
            ->method('recordLogout');

        $controller = new AuthSessionController(
            $this->createMock(ClerkUserSyncService::class),
            $this->createMock(CompanyService::class),
            $auditLogService,
        );

        $response = $controller->logout($request);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([
            'status' => 401,
            'message' => 'Unauthorized',
        ], $response->getData(true));
    }
}
