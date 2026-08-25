<?php

namespace App\Jobs;

use App\Services\BuyerProductSearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Menyinkronkan satu proyeksi produk buyer setelah perubahan PostgreSQL committed.
 */
class SyncBuyerProductSearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [5, 30, 120];

    /**
     * Membuat job sinkronisasi untuk ID produk tertentu.
     *
     * @param  string  $productId  ID produk yang proyeksinya akan diperbarui.
     */
    public function __construct(public string $productId)
    {
        $this->onQueue('buyer-catalog-search');
    }

    /**
     * Menjaga agar dua worker tidak menulis dokumen produk yang sama secara bersamaan.
     *
     * @return array<int, object> Middleware queue yang diterapkan sebelum job dijalankan.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('buyer-product-search:'.$this->productId))
                ->releaseAfter(5)
                ->expireAfter($this->timeout + 30),
        ];
    }

    /**
     * Memuat keadaan produk terbaru dan menyamakan dokumen Meilisearch secara idempoten.
     *
     * @param  BuyerProductSearchService  $searchService  Service pemilik proyeksi katalog buyer.
     *
     * @return void Sinkronisasi diterapkan langsung ke index Meilisearch.
     */
    public function handle(BuyerProductSearchService $searchService): void
    {
        Log::info('Processing buyer product search synchronization.', [
            'product_id' => $this->productId,
            'queue_job_id' => $this->job?->getJobId(),
        ]);

        $searchService->syncProduct($this->productId);
    }

    /**
     * Mencatat kegagalan terminal agar operator dapat menindaklanjuti failed job Laravel.
     *
     * @param  Throwable  $exception  Kegagalan terakhir yang membuat job tidak dapat diulang lagi.
     *
     * @return void Kegagalan dicatat langsung ke log aplikasi.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Buyer product search synchronization failed.', [
            'product_id' => $this->productId,
            'exception' => $exception,
        ]);
    }
}
