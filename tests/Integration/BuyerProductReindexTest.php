<?php

namespace Tests\Integration;

use App\Jobs\SyncBuyerProductSearchJob;
use App\Models\Alamat;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Services\BuyerProductSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Meilisearch\Client;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Memverifikasi full reindex katalog buyer menggunakan database disposable dan index Meilisearch unik.
 */
#[Group('meilisearch-integration')]
class BuyerProductReindexTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private BuyerProductSearchService $searchService;

    private string $indexName = '';

    private bool $indexCreated = false;

    /**
     * Membuat index integration unik yang diturunkan dari namespace testing aplikasi.
     *
     * @return void Tidak mengembalikan nilai; index disposable tersedia untuk skenario reindex.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // --- step 1 - start - buat identitas index integration yang terisolasi
        $testingIndex = (string) config('buyer_product_search.index');
        $this->assertStringContainsString('testing', $testingIndex);

        $this->indexName = $testingIndex.'_reindex_'.bin2hex(random_bytes(6));
        config(['buyer_product_search.index' => $this->indexName]);
        // --- step 1 - end - buat identitas index integration yang terisolasi

        // --- step 2 - start - buat index nyata untuk fixture stale-document
        $this->client = $this->app->make(Client::class);
        $createTask = $this->client->createIndex($this->indexName, ['primaryKey' => 'id']);
        $this->client->waitForTask($createTask['taskUid'], 10_000);
        $this->indexCreated = true;

        $this->searchService = $this->app->make(BuyerProductSearchService::class);
        // --- step 2 - end - buat index nyata untuk fixture stale-document
    }

    /**
     * Menghapus index integration unik walaupun skenario reindex atau assertion gagal.
     *
     * @return void Tidak mengembalikan nilai; index disposable dihapus sebelum teardown parent.
     */
    protected function tearDown(): void
    {
        if ($this->indexCreated) {
            $deleteTask = $this->client->deleteIndex($this->indexName);
            $this->client->waitForTask($deleteTask['taskUid'], 10_000);
        }

        parent::tearDown();
    }

    /**
     * Memastikan reindex membersihkan dokumen basi, mengantrekan seluruh produk, dan membangun ulang
     * hanya dokumen yang masih layak dibeli berdasarkan state database terbaru.
     *
     * @return void Tidak mengembalikan nilai; kegagalan kontrak reindex dinyatakan melalui assertion.
     */
    public function test_full_reindex_rebuilds_the_eligible_catalog_from_disposable_database_state(): void
    {
        // --- step 1 - start - siapkan seller dan produk dengan variasi availability
        $verifiedSeller = User::factory()->create(['name' => 'Verified Seller']);
        $unverifiedSeller = User::factory()->create(['name' => 'Unverified Seller']);

        Company::create([
            'user_id' => $verifiedSeller->id,
            'name' => 'Verified Store',
        ]);

        Alamat::create([
            'user_id' => $verifiedSeller->id,
            'type' => 'seller',
            'place' => 'Testing Store',
            'alamat' => 'Testing Street',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'formatted_address' => 'Testing Street, Indonesia',
            'address_detail' => 'Testing Unit',
            'location_source' => 'map',
            'enable' => 1,
        ]);

        $eligibleProduct = Product::create([
            'user_id_seller' => $verifiedSeller->id,
            'img' => 'product-imgs/reindex-eligible.jpg',
            'name' => 'Eligible Reindex Product',
            'price' => 120_000,
            'stock' => 4,
        ]);
        $outOfStockProduct = Product::create([
            'user_id_seller' => $verifiedSeller->id,
            'img' => 'product-imgs/reindex-empty.jpg',
            'name' => 'Out Of Stock Reindex Product',
            'price' => 90_000,
            'stock' => 0,
        ]);
        $deletedProduct = Product::create([
            'user_id_seller' => $verifiedSeller->id,
            'img' => 'product-imgs/reindex-deleted.jpg',
            'name' => 'Deleted Reindex Product',
            'price' => 80_000,
            'stock' => 3,
        ]);
        $unverifiedSellerProduct = Product::create([
            'user_id_seller' => $unverifiedSeller->id,
            'img' => 'product-imgs/reindex-unverified.jpg',
            'name' => 'Unverified Seller Reindex Product',
            'price' => 70_000,
            'stock' => 2,
        ]);
        $deletedProduct->delete();
        // --- step 1 - end - siapkan seller dan produk dengan variasi availability

        // --- step 2 - start - masukkan dokumen basi sebelum full reindex
        $staleTask = $this->client->index($this->indexName)->addDocuments([
            [
                'id' => 'stale-reindex-document',
                'p_name' => 'Stale Reindex Product',
                'is_purchasable' => true,
            ],
        ], 'id');
        $this->client->waitForTask($staleTask['taskUid'], 10_000);
        // --- step 2 - end - masukkan dokumen basi sebelum full reindex

        // --- step 3 - start - jalankan command dan verifikasi seluruh product ID dikirim
        $this->artisan('buyer-search:reindex', ['--chunk' => 2])
            ->expectsOutput('Buyer product index configured and synchronization jobs dispatched.')
            ->assertSuccessful();

        Queue::assertPushedOn('buyer-catalog-search', SyncBuyerProductSearchJob::class);
        Queue::assertPushed(SyncBuyerProductSearchJob::class, 4);

        $queuedJobs = Queue::pushed(SyncBuyerProductSearchJob::class);
        $queuedProductIds = $queuedJobs
            ->map(fn (SyncBuyerProductSearchJob $job): string => $job->productId)
            ->sort()
            ->values()
            ->all();

        $this->assertSame(collect([
            $eligibleProduct->id,
            $outOfStockProduct->id,
            $deletedProduct->id,
            $unverifiedSellerProduct->id,
        ])->sort()->values()->all(), $queuedProductIds);
        // --- step 3 - end - jalankan command dan verifikasi seluruh product ID dikirim

        // --- step 4 - start - proses job tertangkap terhadap index integration nyata
        $queuedJobs->each(function (SyncBuyerProductSearchJob $job): void {
            $job->handle($this->searchService);
        });
        // --- step 4 - end - proses job tertangkap terhadap index integration nyata

        // --- step 5 - start - verifikasi settings dan hasil akhir proyeksi
        $settings = $this->client->index($this->indexName)->getSettings();
        $documents = $this->client->index($this->indexName)->search('', ['limit' => 20])->getHits();

        $this->assertSame((array) config('buyer_product_search.settings.searchableAttributes'), $settings['searchableAttributes']);
        $this->assertSame([(string) $eligibleProduct->id], array_column($documents, 'id'));
        $this->assertSame('Verified Store', $documents[0]['u_name']);
        $this->assertSame(4, $documents[0]['p_stock']);
        $this->assertNotContains('stale-reindex-document', array_column($documents, 'id'));
        // --- step 5 - end - verifikasi settings dan hasil akhir proyeksi
    }
}
