<?php

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Menjadwalkan ulang seluruh proyeksi produk seller ketika identitas atau lokasinya berubah.
 */
class SyncSellerBuyerCatalogSearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [5, 30, 120];

    /**
     * Membuat job sinkronisasi seluruh katalog milik seller.
     *
     * @param  string  $sellerId  ID seller yang seluruh produknya harus disinkronkan kembali.
     */
    public function __construct(public string $sellerId)
    {
        $this->onQueue('buyer-catalog-search');
    }

    /**
     * Mengirim satu job idempoten per produk aktif maupun soft-deleted seller.
     *
     * @return void Setiap produk seller dijadwalkan untuk menyamakan proyeksi buyer terbarunya.
     */
    public function handle(): void
    {
        Log::info('Processing seller buyer catalog search synchronization.', [
            'seller_id' => $this->sellerId,
            'queue_job_id' => $this->job?->getJobId(),
        ]);

        Product::withTrashed()
            ->where('user_id_seller', $this->sellerId)
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function ($products): void {
                foreach ($products as $product) {
                    SyncBuyerProductSearchJob::dispatch((string) $product->id);
                }
            });
    }

    /**
     * Mencatat kegagalan terminal seller-wide agar operator dapat menindaklanjuti failed job Laravel.
     *
     * @param  Throwable  $exception  Kegagalan terakhir yang membuat job tidak dapat diulang lagi.
     *
     * @return void Kegagalan dicatat langsung ke log aplikasi bersama ID seller.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Seller buyer catalog search synchronization failed.', [
            'seller_id' => $this->sellerId,
            'exception' => $exception,
        ]);
    }
}
