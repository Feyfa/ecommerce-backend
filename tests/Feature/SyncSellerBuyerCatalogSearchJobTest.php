<?php

namespace Tests\Feature;

use App\Jobs\SyncSellerBuyerCatalogSearchJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Memverifikasi queue ownership dan structured logging job sinkronisasi katalog seller.
 */
class SyncSellerBuyerCatalogSearchJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Memastikan job selalu memakai queue buyer catalog search tanpa bergantung pada konfigurasi call site.
     *
     * @return void Tidak mengembalikan nilai; queue default diverifikasi melalui assertion.
     */
    public function test_job_uses_the_buyer_catalog_search_queue_by_default(): void
    {
        $job = new SyncSellerBuyerCatalogSearchJob('00000000-0000-0000-0000-000000000001');

        $this->assertSame('buyer-catalog-search', $job->queue);
    }

    /**
     * Memastikan proses seller-wide mencatat ID seller untuk kebutuhan troubleshooting operator.
     *
     * @return void Tidak mengembalikan nilai; structured log diverifikasi melalui assertion.
     */
    public function test_handle_logs_the_seller_context(): void
    {
        $sellerId = '00000000-0000-0000-0000-000000000002';
        Log::spy();

        (new SyncSellerBuyerCatalogSearchJob($sellerId))->handle();

        Log::shouldHaveReceived('info')
            ->once()
            ->with('Processing seller buyer catalog search synchronization.', [
                'seller_id' => $sellerId,
                'queue_job_id' => null,
            ]);
    }

    /**
     * Memastikan kegagalan terminal mencatat ID seller dan exception asli untuk tindak lanjut.
     *
     * @return void Tidak mengembalikan nilai; structured failure log diverifikasi melalui assertion.
     */
    public function test_failed_logs_the_seller_and_exception_context(): void
    {
        $sellerId = '00000000-0000-0000-0000-000000000003';
        $exception = new RuntimeException('Seller synchronization failed.');
        Log::spy();

        (new SyncSellerBuyerCatalogSearchJob($sellerId))->failed($exception);

        Log::shouldHaveReceived('error')
            ->once()
            ->with(
                'Seller buyer catalog search synchronization failed.',
                Mockery::on(
                    fn (array $context): bool => $context['seller_id'] === $sellerId
                        && $context['exception'] === $exception,
                ),
            );
    }
}
