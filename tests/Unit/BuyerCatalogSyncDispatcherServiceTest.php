<?php

namespace Tests\Unit;

use App\Jobs\SyncBuyerProductSearchJob;
use App\Jobs\SyncSellerBuyerCatalogSearchJob;
use App\Services\BuyerCatalogSyncDispatcherService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Memverifikasi dispatch katalog buyer tetap aman ketika transport queue berhasil atau gagal.
 */
class BuyerCatalogSyncDispatcherServiceTest extends TestCase
{
    /**
     * Memastikan publisher menerjemahkan produk dan seller menjadi job pada queue buyer catalog search.
     *
     * @return void Tidak mengembalikan nilai; kontrak job diverifikasi melalui assertion queue.
     */
    public function test_product_and_seller_messages_dispatch_the_expected_search_jobs(): void
    {
        $dispatcher = $this->app->make(BuyerCatalogSyncDispatcherService::class);

        $dispatcher->dispatchProduct('product-1');
        $dispatcher->dispatchSeller('seller-1');

        Queue::assertPushed(
            SyncBuyerProductSearchJob::class,
            fn (SyncBuyerProductSearchJob $job): bool => $job->productId === 'product-1'
                && $job->queue === 'buyer-catalog-search',
        );
        Queue::assertPushed(
            SyncSellerBuyerCatalogSearchJob::class,
            fn (SyncSellerBuyerCatalogSearchJob $job): bool => $job->sellerId === 'seller-1'
                && $job->queue === 'buyer-catalog-search',
        );
    }

    /**
     * Memastikan kegagalan transport diteruskan agar publisher dapat mempertahankan pesan untuk retry.
     *
     * @return void Tidak mengembalikan nilai; exception transport diverifikasi melalui assertion.
     */
    public function test_transport_failure_is_rethrown_to_the_outbox_publisher(): void
    {
        $exception = new RuntimeException('Redis unavailable.');
        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')
            ->once()
            ->andThrow($exception);

        $dispatcher = new BuyerCatalogSyncDispatcherService($bus);

        $this->expectExceptionObject($exception);

        $dispatcher->dispatchProduct('product-failed');
    }
}
