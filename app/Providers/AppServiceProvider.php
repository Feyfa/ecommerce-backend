<?php

namespace App\Providers;

use App\Services\XenditService;
use Carbon\Carbon;
use Illuminate\Database\ConfigurationUrlParser;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Meilisearch\Client;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Mendaftarkan singleton service pembayaran dan client Meilisearch yang dikonfigurasi aplikasi.
     *
     * @return void Dependency tersedia melalui service container setelah registrasi selesai.
     */
    public function register(): void
    {
        $this->app->singleton(
            XenditService::class,
            fn () => new XenditService((string) config('xendit.key')),
        );

        $this->app->singleton(
            Client::class,
            fn () => new Client(
                (string) config('meilisearch.host'),
                config('meilisearch.key'),
            ),
        );
    }

    /**
     * Menjalankan safety guard testing lalu menerapkan default schema dan locale aplikasi.
     *
     * @return void Konfigurasi global diterapkan ketika seluruh resource testing dinyatakan aman.
     *
     * @throws RuntimeException Ketika database atau external service testing dapat menyentuh resource non-testing.
     */
    public function boot(): void
    {
        $this->ensureTestingDatabaseIsSafe();
        $this->ensureTestingExternalServicesAreSafe();

        Schema::defaultStringLength(255);
        Carbon::setLocale('id');
    }

    /**
     * Menolak bootstrap environment testing sebelum koneksi database
     * yang berpotensi menghapus data development sempat dibuat.
     *
     * Koneksi aktif diperiksa terhadap driver, nama database, dan environment yang diizinkan sebelum
     * test dapat menjalankan migration. Guard ini menghentikan proses lebih awal ketika konfigurasi
     * berpotensi menunjuk database development atau database bersama.
     *
     * @return void  Tidak mengembalikan nilai; proses dinyatakan berhasil ketika selesai tanpa exception.
     */
    private function ensureTestingDatabaseIsSafe(): void
    {
        if (! $this->app->environment('testing')) {
            return;
        }

        // --- step 1 - start - resolve konfigurasi final termasuk override DATABASE_URL
        $connectionName = (string) config('database.default');
        $connectionConfig = config("database.connections.{$connectionName}");

        if (! is_array($connectionConfig)) {
            throw new RuntimeException(
                "Refusing to boot testing environment with unknown database connection [{$connectionName}]."
            );
        }

        $resolvedConfig = (new ConfigurationUrlParser)->parseConfiguration($connectionConfig);
        $connection = (string) ($resolvedConfig['driver'] ?? $connectionName);
        $database = (string) ($resolvedConfig['database'] ?? '');
        // --- step 1 - end - resolve konfigurasi final termasuk override DATABASE_URL

        // --- step 2 - start - izinkan SQLite memory atau database yang namanya eksplisit testing
        $usesInMemorySqlite = $connection === 'sqlite' && $database === ':memory:';
        $usesNamedTestingDatabase = $this->hasTestingIdentifier(basename($database));

        if (! $usesInMemorySqlite && ! $usesNamedTestingDatabase) {
            throw new RuntimeException(
                "Refusing to boot testing environment on unsafe database [{$connection}:{$database}]. "
                .'Use SQLite :memory: or a database whose name explicitly contains test/testing.'
            );
        }
        // --- step 2 - end - izinkan SQLite memory atau database yang namanya eksplisit testing
    }

    /**
     * Menolak konfigurasi testing yang dapat menyentuh Redis atau index Meilisearch development.
     *
     * Standard test wajib memakai queue synchronous dan cache array sehingga tidak menghubungi
     * Redis. Konfigurasi Redis cadangan tetap dibatasi ke loopback, database testing terpisah, dan
     * prefix testing. Meilisearch juga harus memakai loopback serta index bernama testing agar test
     * integrasi yang dijalankan secara eksplisit tidak dapat mengubah index development.
     *
     * @return void Tidak mengembalikan nilai; konfigurasi tidak aman dihentikan dengan exception.
     *
     * @throws RuntimeException Ketika queue, cache, Redis, atau Meilisearch tidak terisolasi untuk testing.
     */
    private function ensureTestingExternalServicesAreSafe(): void
    {
        if (! $this->app->environment('testing')) {
            return;
        }

        // --- step 1 - start - pastikan standard test tidak memakai driver eksternal
        $queueConnection = (string) config('queue.default');
        $cacheStore = (string) config('cache.default');

        if ($queueConnection !== 'sync' || $cacheStore !== 'array') {
            throw new RuntimeException(
                "Refusing to boot testing environment with external queue/cache [{$queueConnection}:{$cacheStore}]. "
                .'Use the sync queue and array cache for the standard test suite.'
            );
        }
        // --- step 1 - end - pastikan standard test tidak memakai driver eksternal

        // --- step 2 - start - validasi namespace dan koneksi Redis testing
        $redisHost = (string) config('database.redis.default.host');
        $redisPrefix = (string) config('database.redis.options.prefix');
        $redisDatabase = (string) config('database.redis.default.database');
        $redisCacheDatabase = (string) config('database.redis.cache.database');

        if (
            ! $this->isLoopbackHost($redisHost)
            || ! $this->hasTestingIdentifier($redisPrefix)
            || $redisDatabase !== '14'
            || $redisCacheDatabase !== '15'
        ) {
            throw new RuntimeException(
                "Refusing to boot testing environment with unsafe Redis configuration [{$redisHost}:{$redisDatabase}:{$redisCacheDatabase}:{$redisPrefix}]. "
                .'Use loopback Redis databases 14/15 and a prefix containing test/testing.'
            );
        }
        // --- step 2 - end - validasi namespace dan koneksi Redis testing

        // --- step 3 - start - validasi host dan index Meilisearch testing
        $meilisearchHost = (string) config('meilisearch.host');
        $meilisearchHostname = (string) parse_url($meilisearchHost, PHP_URL_HOST);
        $meilisearchIndex = (string) config('buyer_product_search.index');

        if (
            ! $this->isLoopbackHost($meilisearchHostname)
            || ! $this->hasTestingIdentifier($meilisearchIndex)
        ) {
            throw new RuntimeException(
                "Refusing to boot testing environment with unsafe Meilisearch configuration [{$meilisearchHost}:{$meilisearchIndex}]. "
                .'Use a loopback host and an index containing test/testing.'
            );
        }
        // --- step 3 - end - validasi host dan index Meilisearch testing
    }

    /**
     * Menentukan apakah nama resource mempunyai segmen test atau testing yang eksplisit.
     *
     * @param  string  $value  Nama database, prefix, atau index yang akan diperiksa.
     *
     * @return bool True ketika nama memiliki segmen testing yang terpisah dan tidak ambigu.
     */
    private function hasTestingIdentifier(string $value): bool
    {
        return preg_match('/(?:^|[_\-.])test(?:ing)?(?:$|[_\-.])/i', $value) === 1;
    }

    /**
     * Menentukan apakah hostname hanya menunjuk kembali ke komputer yang menjalankan test.
     *
     * @param  string  $host  Hostname Redis atau Meilisearch yang akan diverifikasi.
     *
     * @return bool True untuk localhost serta alamat loopback IPv4 atau IPv6.
     */
    private function isLoopbackHost(string $host): bool
    {
        return in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    }
}
