<?php

namespace Tests\Unit;

use App\Services\Clerk\ClerkBackendClientService;
use Clerk\Backend\ClerkBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;
use Tests\TestCase;

/**
 * Memverifikasi kontrak konfigurasi saat membuat client backend Clerk.
 */
class ClerkBackendClientServiceTest extends TestCase
{
    /**
     * Memastikan secret key string non-empty dapat membangun SDK tanpa request jaringan.
     *
     * @return void Tidak mengembalikan nilai; tipe SDK hasil konstruksi diverifikasi.
     */
    public function test_make_sdk_accepts_non_empty_string_secret_key(): void
    {
        config(['clerk.secret_key' => '  sk_test_valid  ']);

        $sdk = (new ClerkBackendClientService())->makeSdk();

        $this->assertInstanceOf(ClerkBackend::class, $sdk);
    }

    /**
     * Memastikan opsi autentikasi mempertahankan secret, authorized parties, dan tipe token existing.
     *
     * @return void Tidak mengembalikan nilai; seluruh getter opsi diverifikasi tanpa autentikasi jaringan.
     */
    public function test_make_authenticate_request_options_accepts_valid_configuration(): void
    {
        config([
            'clerk.secret_key' => '  sk_test_valid  ',
            'clerk.authorized_parties' => ['https://app.example.test', 'https://admin.example.test'],
        ]);

        $options = (new ClerkBackendClientService())->makeAuthenticateRequestOptions();

        $this->assertSame('sk_test_valid', $options->getSecretKey());
        $this->assertSame([
            'https://app.example.test',
            'https://admin.example.test',
        ], $options->getAuthorizedParties());
        $this->assertSame(['session_token'], $options->getAcceptsToken());
    }

    /**
     * Memastikan daftar authorized party kosong tetap menjadi konfigurasi valid.
     *
     * @return void Tidak mengembalikan nilai; allowlist kosong diverifikasi apa adanya.
     */
    public function test_make_authenticate_request_options_accepts_empty_authorized_parties(): void
    {
        config([
            'clerk.secret_key' => 'sk_test_valid',
            'clerk.authorized_parties' => [],
        ]);

        $options = (new ClerkBackendClientService())->makeAuthenticateRequestOptions();

        $this->assertSame([], $options->getAuthorizedParties());
    }

    /**
     * Memastikan secret key kosong atau non-string gagal dengan exception konfigurasi existing.
     *
     * @param  mixed  $secretKey  Nilai konfigurasi yang tidak boleh menjadi credential Clerk.
     *
     * @return void Tidak mengembalikan nilai; exception fail-closed diverifikasi.
     */
    #[DataProvider('invalidSecretKeys')]
    public function test_make_sdk_rejects_empty_or_non_string_secret_key(mixed $secretKey): void
    {
        config(['clerk.secret_key' => $secretKey]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Clerk secret key is not configured yet.');

        (new ClerkBackendClientService())->makeSdk();
    }

    /**
     * Menyediakan secret key kosong dan non-string yang tidak boleh dicast menjadi credential.
     *
     * @return array<string, array{mixed}> Daftar nilai secret key invalid untuk pengujian.
     */
    public static function invalidSecretKeys(): array
    {
        return [
            'empty string' => [''],
            'whitespace string' => ['   '],
            'null' => [null],
            'integer' => [123],
            'boolean' => [true],
            'array' => [['sk_test_invalid']],
            'object' => [new stdClass()],
        ];
    }

    /**
     * Memastikan konfigurasi authorized parties non-array ditolak sebelum SDK digunakan.
     *
     * @return void Tidak mengembalikan nilai; exception konfigurasi terkontrol diverifikasi.
     */
    public function test_make_authenticate_request_options_rejects_non_array_authorized_parties(): void
    {
        config([
            'clerk.secret_key' => 'sk_test_valid',
            'clerk.authorized_parties' => 'https://app.example.test',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Clerk authorized parties must be configured as an array of strings.'
        );

        (new ClerkBackendClientService())->makeAuthenticateRequestOptions();
    }

    /**
     * Memastikan anggota authorized parties non-string tidak diteruskan sebagai origin valid.
     *
     * @return void Tidak mengembalikan nilai; exception konfigurasi terkontrol diverifikasi.
     */
    public function test_make_authenticate_request_options_rejects_non_string_authorized_party(): void
    {
        config([
            'clerk.secret_key' => 'sk_test_valid',
            'clerk.authorized_parties' => ['https://app.example.test', 123],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Clerk authorized parties must be configured as an array of strings.'
        );

        (new ClerkBackendClientService())->makeAuthenticateRequestOptions();
    }
}
