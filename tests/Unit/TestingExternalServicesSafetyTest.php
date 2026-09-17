<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * Memverifikasi fail-safe Redis dan Meilisearch untuk environment testing.
 */
class TestingExternalServicesSafetyTest extends TestCase
{
    /**
     * Memastikan konfigurasi PHPUnit yang terisolasi diterima oleh bootstrap guard.
     *
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_isolated_testing_configuration_is_accepted(): void
    {
        $this->invokeExternalServicesGuard();

        $this->addToAssertionCount(1);
    }

    /**
     * Memastikan konfigurasi scalar mempertahankan hasil cast string yang digunakan sebelumnya.
     *
     * @param  mixed  $value  Nilai konfigurasi scalar atau null yang akan dinormalisasi.
     * @param  string  $expected  Representasi string yang diharapkan oleh dependency aplikasi.
     *
     * @return void Tidak mengembalikan nilai; hasil normalisasi diverifikasi melalui assertion.
     */
    #[DataProvider('stringConfigurationValues')]
    public function test_string_configuration_normalizes_scalar_and_null_values(
        mixed $value,
        string $expected,
    ): void {
        config(['testing.string_configuration' => $value]);

        $provider = new AppServiceProvider($this->app);
        $method = new ReflectionMethod($provider, 'configurationString');

        $this->assertSame($expected, $method->invoke($provider, 'testing.string_configuration'));
    }

    /**
     * Menyediakan nilai konfigurasi yang didukung beserta representasi string existing-nya.
     *
     * @return array<string, array{mixed, string}> Nilai konfigurasi dan hasil normalisasi yang diharapkan.
     */
    public static function stringConfigurationValues(): array
    {
        return [
            'null' => [null, ''],
            'string' => ['testing', 'testing'],
            'integer' => [14, '14'],
            'float' => [1.5, '1.5'],
            'true' => [true, '1'],
            'false' => [false, ''],
        ];
    }

    /**
     * Memastikan konfigurasi nullable mempertahankan null untuk dependency yang mendukungnya.
     *
     * @return void Tidak mengembalikan nilai; null diverifikasi tetap tidak berubah.
     */
    public function test_nullable_string_configuration_preserves_null(): void
    {
        config(['testing.nullable_string_configuration' => null]);

        $provider = new AppServiceProvider($this->app);
        $method = new ReflectionMethod($provider, 'nullableConfigurationString');

        $this->assertNull($method->invoke($provider, 'testing.nullable_string_configuration'));
    }

    /**
     * Memastikan konfigurasi non-scalar ditolak sebelum diteruskan ke dependency aplikasi.
     *
     * @param  mixed  $value  Nilai array atau object yang tidak dapat digunakan sebagai string konfigurasi.
     *
     * @return void Tidak mengembalikan nilai; konfigurasi invalid harus menghasilkan exception.
     */
    #[DataProvider('nonScalarConfigurationValues')]
    public function test_string_configuration_rejects_non_scalar_values(mixed $value): void
    {
        config(['testing.invalid_string_configuration' => $value]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Configuration [testing.invalid_string_configuration] must be a scalar value or null.'
        );

        $provider = new AppServiceProvider($this->app);
        $method = new ReflectionMethod($provider, 'configurationString');
        $method->invoke($provider, 'testing.invalid_string_configuration');
    }

    /**
     * Menyediakan nilai konfigurasi non-scalar yang harus ditolak oleh normalizer.
     *
     * @return array<string, array{mixed}> Nilai array dan object yang tidak valid sebagai konfigurasi string.
     */
    public static function nonScalarConfigurationValues(): array
    {
        return [
            'array' => [['testing']],
            'object' => [(object) ['value' => 'testing']],
        ];
    }

    /**
     * Memastikan setiap koneksi atau namespace eksternal yang tidak aman menghentikan bootstrap test.
     *
     * @param  string  $configKey  Key konfigurasi Laravel yang akan dibuat tidak aman.
     * @param  mixed  $unsafeValue  Nilai yang mensimulasikan resource development atau eksternal.
     * @param  string  $expectedMessage  Bagian pesan exception yang menjelaskan boundary yang ditolak.
     *
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    #[DataProvider('unsafeExternalServiceConfigurations')]
    public function test_unsafe_external_service_configuration_is_rejected(
        string $configKey,
        mixed $unsafeValue,
        string $expectedMessage,
    ): void {
        config([$configKey => $unsafeValue]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->invokeExternalServicesGuard();
    }

    /**
     * Menyediakan konfigurasi yang dapat menyentuh queue, cache, Redis, atau Meilisearch non-testing.
     *
     * @return array<string, array{string, mixed, string}> Skenario konfigurasi tidak aman dan pesan guard-nya.
     */
    public static function unsafeExternalServiceConfigurations(): array
    {
        return [
            'Redis queue driver' => [
                'queue.default',
                'redis',
                'external queue/cache',
            ],
            'Redis cache store' => [
                'cache.default',
                'redis',
                'external queue/cache',
            ],
            'remote Redis host' => [
                'database.redis.default.host',
                'redis.internal.example',
                'unsafe Redis configuration',
            ],
            'development Redis prefix' => [
                'database.redis.options.prefix',
                'ecommerce_database_',
                'unsafe Redis configuration',
            ],
            'development Redis databases' => [
                'database.redis.default.database',
                '0',
                'unsafe Redis configuration',
            ],
            'remote Meilisearch host' => [
                'meilisearch.host',
                'https://search.example.com',
                'unsafe Meilisearch configuration',
            ],
            'development Meilisearch index' => [
                'buyer_product_search.index',
                'buyer_products',
                'unsafe Meilisearch configuration',
            ],
        ];
    }

    /**
     * Menjalankan bootstrap guard eksternal pada provider aplikasi yang aktif untuk test.
     *
     * @return void Tidak mengembalikan nilai; konfigurasi berbahaya diteruskan sebagai exception.
     */
    private function invokeExternalServicesGuard(): void
    {
        $provider = new AppServiceProvider($this->app);
        $method = new ReflectionMethod($provider, 'ensureTestingExternalServicesAreSafe');
        $method->invoke($provider);
    }
}
