<?php

namespace Tests\Integration;

use App\Services\BuyerProductSearchService;
use Meilisearch\Client;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Memverifikasi kontrak pencarian buyer terhadap instance Meilisearch lokal yang nyata.
 */
#[Group('meilisearch-integration')]
class MeilisearchBuyerProductSearchTest extends TestCase
{
    private Client $client;

    private BuyerProductSearchService $searchService;

    private string $indexName = '';

    private bool $indexCreated = false;

    /**
     * Membuat index testing unik, menerapkan settings aplikasi, dan memasukkan fixture pencarian.
     *
     * Index selalu diturunkan dari nama testing pada phpunit.xml sehingga tidak dapat bertabrakan
     * dengan buyer_products milik development.
     *
     * @return void Tidak mengembalikan nilai; fixture tersedia untuk test setelah setup selesai.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // --- step 1 - start - buat identitas index integration yang terisolasi
        $testingIndex = (string) config('buyer_product_search.index');
        $this->assertStringContainsString('testing', $testingIndex);

        $this->indexName = $testingIndex.'_integration_'.bin2hex(random_bytes(6));
        config(['buyer_product_search.index' => $this->indexName]);
        // --- step 1 - end - buat identitas index integration yang terisolasi

        // --- step 2 - start - buat dan konfigurasi index nyata melalui SDK
        $this->client = $this->app->make(Client::class);
        $createTask = $this->client->createIndex($this->indexName, ['primaryKey' => 'id']);
        $this->client->waitForTask($createTask['taskUid'], 10_000);
        $this->indexCreated = true;

        $this->searchService = $this->app->make(BuyerProductSearchService::class);
        $this->searchService->configureIndex();
        // --- step 2 - end - buat dan konfigurasi index nyata melalui SDK

        // --- step 3 - start - masukkan fixture document pencarian
        $addTask = $this->client->index($this->indexName)->addDocuments($this->documents(), 'id');
        $this->client->waitForTask($addTask['taskUid'], 10_000);
        // --- step 3 - end - masukkan fixture document pencarian
    }

    /**
     * Menghapus index integration unik setelah test, termasuk ketika assertion gagal.
     *
     * @return void Tidak mengembalikan nilai; cleanup index selesai sebelum teardown parent.
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
     * Memastikan typo, sinonim, filter, sorting, exclusion, dan lookahead bekerja pada engine nyata.
     *
     * @return void Tidak mengembalikan nilai; kegagalan engine atau kontrak dinyatakan melalui assertion.
     */
    public function test_buyer_search_contract_works_against_real_meilisearch(): void
    {
        // --- step 1 - start - verifikasi typo tolerance dan sinonim aplikasi
        $typoResult = $this->searchService->search('buyer-1', [
            'search_product' => 'spatu',
            'sort_product' => 'relevance',
        ], 1, 10);
        $synonymResult = $this->searchService->search('buyer-1', [
            'search_product' => 'hp',
            'sort_product' => 'relevance',
        ], 1, 10);

        $this->assertSame(['product-sepatu'], array_column($typoResult['products'], 'id'));
        $this->assertSame(['product-handphone'], array_column($synonymResult['products'], 'id'));
        // --- step 1 - end - verifikasi typo tolerance dan sinonim aplikasi

        // --- step 2 - start - verifikasi filter harga dan exclusion seller buyer
        $filteredResult = $this->searchService->search('buyer-1', [
            'min_price' => 300_000,
            'max_price' => 400_000,
            'sort_product' => 'price_lowest',
        ], 1, 10);

        $this->assertSame(['product-sepatu'], array_column($filteredResult['products'], 'id'));
        $this->assertNotContains('product-owned', array_column($filteredResult['products'], 'id'));
        // --- step 2 - end - verifikasi filter harga dan exclusion seller buyer

        // --- step 3 - start - verifikasi sorting harga dan pagination lookahead nyata
        $sortedResult = $this->searchService->search('buyer-1', [
            'sort_product' => 'price_lowest',
        ], 1, 10);
        $firstPage = $this->searchService->search('buyer-1', [
            'sort_product' => 'price_lowest',
        ], 1, 2);
        $secondPage = $this->searchService->search('buyer-1', [
            'sort_product' => 'price_lowest',
        ], 2, 2);

        $this->assertSame(
            ['product-handphone', 'product-sepatu', 'product-laptop'],
            array_column($sortedResult['products'], 'id'),
        );
        $this->assertSame(['product-handphone', 'product-sepatu'], array_column($firstPage['products'], 'id'));
        $this->assertTrue($firstPage['has_more']);
        $this->assertFalse($firstPage['limit_reached']);
        $this->assertSame(['product-laptop'], array_column($secondPage['products'], 'id'));
        $this->assertFalse($secondPage['has_more']);
        $this->assertFalse($secondPage['limit_reached']);
        // --- step 3 - end - verifikasi sorting harga dan pagination lookahead nyata
    }

    /**
     * Memastikan settings engine nyata memakai boundary 10.000 dan ID sebagai tie-breaker terakhir.
     *
     * @return void Tidak mengembalikan nilai; settings index diverifikasi melalui SDK Meilisearch.
     */
    public function test_real_index_uses_the_configured_pagination_and_tie_breaker_settings(): void
    {
        $settings = $this->client->index($this->indexName)->getSettings();

        $this->assertSame(10000, $settings['pagination']['maxTotalHits']);
        $this->assertContains('id', $settings['sortableAttributes']);
        $this->assertSame('id:asc', $settings['rankingRules'][array_key_last($settings['rankingRules'])]);
    }

    /**
     * Memastikan nilai sort dan relevance yang sama tetap stabil lintas beberapa halaman.
     *
     * @return void Tidak mengembalikan nilai; urutan ID dan metadata lookahead diverifikasi.
     */
    public function test_equal_ranked_documents_use_id_as_the_final_tie_breaker(): void
    {
        $documents = [
            $this->document('tie-c', 'seller-c', 'Deterministic Tie', 99_000, 1),
            $this->document('tie-a', 'seller-a', 'Deterministic Tie', 99_000, 1),
            $this->document('tie-b', 'seller-b', 'Deterministic Tie', 99_000, 1),
        ];
        $task = $this->client->index($this->indexName)->addDocuments($documents, 'id');
        $this->client->waitForTask($task['taskUid'], 10_000);

        $relevance = $this->searchService->search('buyer-1', [
            'search_product' => 'Deterministic Tie',
            'sort_product' => 'relevance',
        ], 1, 10);
        $firstPage = $this->searchService->search('buyer-1', [
            'search_product' => 'Deterministic Tie',
            'sort_product' => 'price_lowest',
        ], 1, 2);
        $secondPage = $this->searchService->search('buyer-1', [
            'search_product' => 'Deterministic Tie',
            'sort_product' => 'price_lowest',
        ], 2, 2);

        $this->assertSame(['tie-a', 'tie-b', 'tie-c'], array_column($relevance['products'], 'id'));
        $this->assertSame(['tie-a', 'tie-b'], array_column($firstPage['products'], 'id'));
        $this->assertTrue($firstPage['has_more']);
        $this->assertSame(['tie-c'], array_column($secondPage['products'], 'id'));
        $this->assertFalse($secondPage['has_more']);
    }

    /**
     * Memastikan hasil setelah posisi 1.000 dapat dijangkau ketika maxTotalHits dinaikkan ke 10.000.
     *
     * @return void Tidak mengembalikan nilai; dokumen ke-1.001 diverifikasi melalui engine nyata.
     */
    public function test_search_can_reach_a_document_after_the_previous_one_thousand_limit(): void
    {
        $documents = [];

        for ($index = 1; $index <= 1001; $index++) {
            $documents[] = $this->document(
                sprintf('deep-%04d', $index),
                'seller-deep',
                'Deep Pagination Fixture',
                1_000,
                1,
            );
        }

        $task = $this->client->index($this->indexName)->addDocuments($documents, 'id');
        $this->client->waitForTask($task['taskUid'], 20_000);

        $result = $this->searchService->search('buyer-1', [
            'search_product' => 'Deep Pagination Fixture',
            'sort_product' => 'price_lowest',
        ], 1001, 1);

        $this->assertSame(['deep-1001'], array_column($result['products'], 'id'));
        $this->assertFalse($result['has_more']);
        $this->assertFalse($result['limit_reached']);
    }

    /**
     * Menyediakan document aman yang mencakup pencarian typo, sinonim, harga, dan ownership buyer.
     *
     * @return array<int, array<string, bool|int|string>> Fixture document untuk index integration.
     */
    private function documents(): array
    {
        return [
            $this->document('product-sepatu', 'seller-1', 'Sepatu Running', 350_000, 4),
            $this->document('product-handphone', 'seller-2', 'Handphone Android', 250_000, 3),
            $this->document('product-laptop', 'seller-3', 'Laptop Gaming', 15_000_000, 2),
            $this->document('product-owned', 'buyer-1', 'Produk Milik Buyer', 360_000, 5),
        ];
    }

    /**
     * Membentuk satu document katalog dengan field yang dimiliki BuyerProductSearchService.
     *
     * @param  string  $id  ID unik document integration.
     * @param  string  $sellerId  ID seller untuk filter ownership buyer.
     * @param  string  $name  Nama produk yang menjadi sumber full-text search.
     * @param  int  $price  Harga produk yang digunakan untuk filter dan sorting.
     * @param  int  $stock  Stok positif yang ditampilkan pada hasil integration.
     *
     * @return array<string, bool|int|string> Document katalog siap dimasukkan ke Meilisearch.
     */
    private function document(string $id, string $sellerId, string $name, int $price, int $stock): array
    {
        return [
            'id' => $id,
            'p_id' => $id,
            'seller_id' => $sellerId,
            'u_id' => $sellerId,
            'p_name' => $name,
            'p_name_sort' => mb_strtolower($name),
            'u_name' => 'Toko '.$sellerId,
            'p_img' => 'product-imgs/integration.jpg',
            'p_price' => $price,
            'p_stock' => $stock,
            'created_at_timestamp' => 1_750_000_000,
            'updated_at_timestamp' => 1_750_000_000,
            'is_purchasable' => true,
        ];
    }
}
