<?php

namespace App\Providers;

use Carbon\Carbon;
use Illuminate\Database\ConfigurationUrlParser;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
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
