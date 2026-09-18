<?php

namespace Tests\Unit;

use App\Http\Controllers\SecurityController;
use App\Models\User;
use App\Services\Clerk\ClerkSecurityService;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Memverifikasi normalisasi identity Clerk pada batas request security controller.
 */
class SecurityControllerTest extends TestCase
{
    /**
     * Memastikan Clerk user ID string dari middleware diprioritaskan dari fallback user lokal.
     *
     * @return void Tidak mengembalikan nilai; hasil resolusi diverifikasi melalui assertion.
     */
    public function test_clerk_user_id_attribute_is_used_when_valid(): void
    {
        $request = Request::create('/api/security/summary');
        $request->attributes->set('clerk_user_id', 'user_from_middleware');
        $request->setUserResolver(fn (): User => new User([
            'clerk_user_id' => 'user_from_database',
        ]));

        $this->assertSame('user_from_middleware', $this->resolveClerkUserId($request));
    }

    /**
     * Memastikan attribute Clerk yang bukan string tidak dicast dan menggunakan user lokal yang valid.
     *
     * @return void Tidak mengembalikan nilai; hasil fallback diverifikasi melalui assertion.
     */
    public function test_malformed_clerk_user_id_attribute_uses_local_user_fallback(): void
    {
        $request = Request::create('/api/security/summary');
        $request->attributes->set('clerk_user_id', ['invalid']);
        $request->setUserResolver(fn (): User => new User([
            'clerk_user_id' => 'user_from_database',
        ]));

        $this->assertSame('user_from_database', $this->resolveClerkUserId($request));
    }

    /**
     * Memastikan request tanpa identity Clerk atau user lokal tetap menghasilkan response unauthorized.
     *
     * @return void Tidak mengembalikan nilai; status dan payload unauthorized diverifikasi melalui assertion.
     */
    public function test_missing_clerk_identity_returns_unauthorized_response(): void
    {
        $request = Request::create('/api/security/summary');
        $request->setUserResolver(fn () => null);

        $controller = new SecurityController($this->createMock(ClerkSecurityService::class));
        $response = $controller->summary($request);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([
            'status' => 401,
            'message' => 'Unauthorized',
        ], $response->getData(true));
    }

    /**
     * Memastikan hanya attribute session berbentuk string yang diterima sebagai Clerk session ID.
     *
     * @param  mixed  $attributeValue  Nilai attribute session dari middleware atau request malformed.
     * @param  string  $expected  Session ID yang diharapkan setelah normalisasi.
     *
     * @return void Tidak mengembalikan nilai; hasil normalisasi diverifikasi melalui assertion.
     */
    #[DataProvider('clerkSessionIdCases')]
    public function test_clerk_session_id_accepts_only_strings(mixed $attributeValue, string $expected): void
    {
        $request = Request::create('/api/security/sessions');
        $request->attributes->set('clerk_session_id', $attributeValue);

        $controller = new SecurityController($this->createMock(ClerkSecurityService::class));
        $method = new ReflectionMethod($controller, 'resolveClerkSessionId');

        $this->assertSame($expected, $method->invoke($controller, $request));
    }

    /**
     * Menyediakan attribute session valid dan malformed beserta hasil normalisasi yang diharapkan.
     *
     * @return array<string, array{mixed, string}> Nilai attribute dan session ID hasil normalisasi.
     */
    public static function clerkSessionIdCases(): array
    {
        return [
            'valid string' => ['session_test', 'session_test'],
            'array' => [['session_test'], ''],
            'integer' => [123, ''],
            'null' => [null, ''],
        ];
    }

    /**
     * Menjalankan helper private untuk memverifikasi kontrak fallback Clerk user ID secara terisolasi.
     *
     * @param  Request  $request  Request yang berisi attribute atau user lokal untuk diselesaikan.
     *
     * @return string Clerk user ID hasil normalisasi controller.
     */
    private function resolveClerkUserId(Request $request): string
    {
        $controller = new SecurityController($this->createMock(ClerkSecurityService::class));
        $method = new ReflectionMethod($controller, 'resolveClerkUserId');

        return $method->invoke($controller, $request);
    }
}
