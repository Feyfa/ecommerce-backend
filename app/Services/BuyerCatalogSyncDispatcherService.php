<?php

namespace App\Services;

use App\Jobs\SyncBuyerProductSearchJob;
use App\Jobs\SyncSellerBuyerCatalogSearchJob;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * Menerjemahkan pesan buyer catalog yang sudah durable menjadi job Redis idempoten.
 */
class BuyerCatalogSyncDispatcherService
{
    /**
     * Menyiapkan dispatcher bus yang meneruskan job ke koneksi queue Laravel aktif.
     *
     * @param  Dispatcher  $dispatcher  Dispatcher Laravel yang mengirim command queueable.
     */
    public function __construct(private Dispatcher $dispatcher) {}

    /**
     * Mengirim job sinkronisasi satu produk dari publisher outbox ke queue buyer catalog search.
     *
     * @param  string  $productId  ID produk yang proyeksi buyer-nya perlu disinkronkan.
     *
     * @return void Job diserahkan kepada bus Laravel atau exception diteruskan ke publisher.
     */
    public function dispatchProduct(string $productId): void
    {
        $this->dispatcher->dispatch(new SyncBuyerProductSearchJob($productId));
    }

    /**
     * Mengirim job proyeksi ulang seluruh katalog seller dari publisher outbox ke queue buyer catalog search.
     *
     * @param  string  $sellerId  ID seller yang seluruh produk terkaitnya perlu diproyeksikan ulang.
     *
     * @return void Job diserahkan kepada bus Laravel atau exception diteruskan ke publisher.
     */
    public function dispatchSeller(string $sellerId): void
    {
        $this->dispatcher->dispatch(new SyncSellerBuyerCatalogSearchJob($sellerId));
    }
}
