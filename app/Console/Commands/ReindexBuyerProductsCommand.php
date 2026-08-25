<?php

namespace App\Console\Commands;

use App\Jobs\SyncBuyerProductSearchJob;
use App\Models\Product;
use App\Services\BuyerProductSearchService;
use Illuminate\Console\Command;

/**
 * Mengonfigurasi ulang dan membangun kembali seluruh index katalog buyer dari PostgreSQL.
 */
class ReindexBuyerProductsCommand extends Command
{
    protected $signature = 'buyer-search:reindex {--chunk=200 : Jumlah produk per batch dispatch}';

    protected $description = 'Configure and rebuild the buyer product Meilisearch index from PostgreSQL.';

    /**
     * Mengonfigurasi index lalu mengantrekan sinkronisasi semua produk, termasuk soft-deleted.
     *
     * @param  BuyerProductSearchService  $searchService  Service konfigurasi dan proyeksi Meilisearch.
     *
     * @return int Kode keluar Artisan untuk keberhasilan proses.
     */
    public function handle(BuyerProductSearchService $searchService): int
    {
        $chunk = max(1, (int) $this->option('chunk'));

        $searchService->configureIndex();
        $searchService->clearIndex();

        Product::withTrashed()
            ->select('id')
            ->orderBy('id')
            ->chunkById($chunk, function ($products): void {
                foreach ($products as $product) {
                    SyncBuyerProductSearchJob::dispatch((string) $product->id);
                }
            });

        $this->info('Buyer product index configured and synchronization jobs dispatched.');

        return self::SUCCESS;
    }
}
