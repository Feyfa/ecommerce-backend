<?php

namespace App\Providers;

use App\Services\XenditService;
use Carbon\Carbon;
use Illuminate\Database\ConfigurationUrlParser;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void  Tidak mengembalikan nilai; proses dinyatakan berhasil ketika selesai tanpa exception.
     */
    public function register(): void
    {
        $this->app->singleton(
            XenditService::class,
            fn () => new XenditService((string) config('xendit.key')),
        );
    }

    /**
     * Bootstrap any application services.
     *
     * @return void  Tidak mengembalikan nilai; proses dinyatakan berhasil ketika selesai tanpa exception.
     */
    public function boot(): void
    {
        $this->ensureTestingDatabaseIsSafe();

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
        $usesNamedTestingDatabase = preg_match(
            '/(?:^|[_\-.])test(?:ing)?(?:$|[_\-.])/i',
            basename($database)
        ) === 1;

        if (! $usesInMemorySqlite && ! $usesNamedTestingDatabase) {
            throw new RuntimeException(
                "Refusing to boot testing environment on unsafe database [{$connection}:{$database}]. "
                .'Use SQLite :memory: or a database whose name explicitly contains test/testing.'
            );
        }
        // --- step 2 - end - izinkan SQLite memory atau database yang namanya eksplisit testing
    }
}
