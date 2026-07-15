<?php

namespace Tests\Unit;

use App\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Memverifikasi resolusi client IP tanpa mempercayai forwarded header secara global.
 */
class TrustProxiesTest extends TestCase
{
    private bool $remoteAddressWasSet;

    private ?string $originalRemoteAddress;

    /**
     * Menyimpan server state agar perubahan REMOTE_ADDR tidak bocor ke test lain.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->remoteAddressWasSet = array_key_exists('REMOTE_ADDR', $_SERVER);
        $this->originalRemoteAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    }

    /**
     * Mengembalikan trusted proxy dan server state setelah setiap test.
     */
    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        if ($this->remoteAddressWasSet) {
            $_SERVER['REMOTE_ADDR'] = $this->originalRemoteAddress;
        } else {
            unset($_SERVER['REMOTE_ADDR']);
        }

        parent::tearDown();
    }

    /**
     * Memastikan Laravel memakai IP publik yang sudah dinormalisasi oleh Nginx.
     */
    public function test_trusted_reverse_proxy_resolves_forwarded_client_ip(): void
    {
        $resolvedIp = $this->resolveClientIp(
            trustedProxies: 'REMOTE_ADDR',
            remoteAddress: '172.18.0.2',
            forwardedFor: '180.10.20.30'
        );

        $this->assertSame('180.10.20.30', $resolvedIp);
    }

    /**
     * Memastikan forwarded header diabaikan ketika proxy belum dikonfigurasi.
     */
    public function test_unconfigured_proxy_ignores_forwarded_header(): void
    {
        $resolvedIp = $this->resolveClientIp(
            trustedProxies: null,
            remoteAddress: '198.51.100.20',
            forwardedFor: '203.0.113.99'
        );

        $this->assertSame('198.51.100.20', $resolvedIp);
    }

    /**
     * Memastikan IP palsu di sisi kiri chain tidak mengalahkan hop terdekat yang tidak dipercaya.
     */
    public function test_spoofed_forwarded_ip_does_not_override_untrusted_closest_hop(): void
    {
        $resolvedIp = $this->resolveClientIp(
            trustedProxies: 'REMOTE_ADDR,192.168.1.202',
            remoteAddress: '172.18.0.2',
            forwardedFor: '203.0.113.99, 192.168.1.50'
        );

        $this->assertSame('192.168.1.50', $resolvedIp);
    }

    /**
     * Menjalankan middleware trusted proxy terhadap request sintetis.
     */
    private function resolveClientIp(
        ?string $trustedProxies,
        string $remoteAddress,
        string $forwardedFor
    ): string {
        // --- step 1 - start - samakan global REMOTE_ADDR dengan koneksi langsung request
        $_SERVER['REMOTE_ADDR'] = $remoteAddress;
        config()->set('trustedproxy.proxies', $trustedProxies);
        // --- step 1 - end - samakan global REMOTE_ADDR dengan koneksi langsung request

        // --- step 2 - start - bentuk request dengan forwarded chain yang akan dievaluasi middleware
        $request = Request::create('/proxy-test', 'GET', [], [], [], [
            'REMOTE_ADDR' => $remoteAddress,
            'HTTP_X_FORWARDED_FOR' => $forwardedFor,
        ]);
        // --- step 2 - end - bentuk request dengan forwarded chain yang akan dievaluasi middleware

        // --- step 3 - start - terapkan trust boundary dan ambil hasil resolusi client IP
        (new TrustProxies)->handle(
            $request,
            static fn (Request $request): Request => $request
        );
        // --- step 3 - end - terapkan trust boundary dan ambil hasil resolusi client IP

        return (string) $request->ip();
    }
}
