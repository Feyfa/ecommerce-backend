<?php

namespace Tests\Unit;

use App\Jobs\SyncBuyerProductSearchJob;
use App\Jobs\SyncSellerBuyerCatalogSearchJob;
use Tests\TestCase;

/**
 * Memverifikasi kontrak waktu queue Redis untuk sinkronisasi katalog buyer.
 */
class QueueConfigurationTest extends TestCase
{
    /**
     * Memastikan Redis tidak menyerahkan ulang job sebelum timeout job search terlama berakhir.
     *
     * @return void Tidak mengembalikan nilai; pelanggaran kontrak dinyatakan melalui assertion.
     */
    public function test_redis_retry_after_exceeds_longest_search_job_timeout(): void
    {
        $longestJobTimeout = max(
            (new SyncBuyerProductSearchJob('00000000-0000-0000-0000-000000000000'))->timeout,
            (new SyncSellerBuyerCatalogSearchJob('00000000-0000-0000-0000-000000000000'))->timeout,
        );

        $retryAfter = config('queue.connections.redis.retry_after');

        $this->assertIsInt($retryAfter);
        $this->assertGreaterThan($longestJobTimeout, $retryAfter);
    }
}
