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
