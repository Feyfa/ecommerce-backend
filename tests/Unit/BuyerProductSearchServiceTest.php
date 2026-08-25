<?php

namespace Tests\Unit;

use App\Services\BuyerProductSearchService;
use App\Services\ProductAvailabilityService;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Search\SearchResult;
use Mockery;
use Tests\TestCase;

/**
 * Memverifikasi pagination lookahead pada service pencarian katalog buyer.
 */
class BuyerProductSearchServiceTest extends TestCase
{
    /**
     * Memastikan hit tambahan menandai halaman berikutnya tanpa ikut dikirim dalam response.
     *
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_search_uses_an_extra_hit_to_mark_the_next_page(): void
    {
        $hits = array_map(
            fn (int $id): array => ['id' => (string) $id],
            range(1, 25),
        );
        $service = $this->searchServiceReturning(
            hits: $hits,
            page: 1,
            perPage: 24,
            estimatedTotalHits: 24,
        );

        $result = $service->search('buyer-1', [], 1, 24);

        $this->assertTrue($result['has_more']);
        $this->assertFalse($result['limit_reached']);
        $this->assertCount(24, $result['products']);
        $this->assertSame('24', $result['products'][23]['id']);
    }

    /**
     * Memastikan halaman berukuran penuh berhenti ketika tidak memiliki hit lookahead tambahan.
     *
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_search_marks_a_full_final_page_without_an_extra_hit(): void
    {
        $hits = array_map(
            fn (int $id): array => ['id' => (string) $id],
            range(1, 24),
        );
        $service = $this->searchServiceReturning(
            hits: $hits,
            page: 1,
            perPage: 24,
            estimatedTotalHits: 999,
        );

        $result = $service->search('buyer-1', [], 1, 24);

        $this->assertFalse($result['has_more']);
        $this->assertFalse($result['limit_reached']);
        $this->assertCount(24, $result['products']);
    }

    /**
     * Memastikan hasil kosong tidak melaporkan halaman berikutnya meskipun estimasi total lebih besar.
     *
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_search_marks_an_empty_page_as_complete(): void
    {
        $service = $this->searchServiceReturning(
            hits: [],
            page: 2,
            perPage: 24,
            estimatedTotalHits: 999,
        );

        $result = $service->search('buyer-1', [], 2, 24);

        $this->assertFalse($result['has_more']);
        $this->assertFalse($result['limit_reached']);
        $this->assertSame([], $result['products']);
    }

    /**
     * Memastikan seluruh sort eksplisit menggunakan ID ascending sebagai tie-breaker terakhir.
     *
     * @return void Tidak mengembalikan nilai; parameter sort Meilisearch diverifikasi melalui assertion.
     */
    public function test_explicit_sorts_use_a_deterministic_id_tie_breaker(): void
    {
        $expectedSorts = [
            'latest' => ['updated_at_timestamp:desc', 'id:asc'],
            'oldest' => ['updated_at_timestamp:asc', 'id:asc'],
            'price_lowest' => ['p_price:asc', 'id:asc'],
            'price_highest' => ['p_price:desc', 'id:asc'],
            'name_asc' => ['p_name_sort:asc', 'id:asc'],
            'name_desc' => ['p_name_sort:desc', 'id:asc'],
        ];

        foreach ($expectedSorts as $sortProduct => $expectedSort) {
            $service = $this->searchServiceReturning(
                hits: [],
                page: 1,
                perPage: 24,
                estimatedTotalHits: 0,
                expectedSort: $expectedSort,
            );

            $service->search('buyer-1', ['sort_product' => $sortProduct], 1, 24);
        }
    }

    /**
     * Memastikan relevance tidak mengirim sort sehingga ranking native tetap mendahului tie-breaker settings.
     *
     * @return void Tidak mengembalikan nilai; absence parameter sort diverifikasi melalui assertion.
     */
    public function test_relevance_does_not_send_a_runtime_sort(): void
    {
        $service = $this->searchServiceReturning(
            hits: [],
            page: 1,
            perPage: 24,
            estimatedTotalHits: 0,
            query: 'sepatu',
            expectedSort: null,
        );

        $service->search('buyer-1', [
            'search_product' => 'sepatu',
            'sort_product' => 'relevance',
        ], 1, 24);
    }

    /**
     * Memastikan halaman terakhir dibatasi ke sisa kapasitas dan menandai boundary engine tercapai.
     *
     * @return void Tidak mengembalikan nilai; limit query dan metadata boundary diverifikasi.
     */
    public function test_last_page_at_the_configured_boundary_is_marked_as_limit_reached(): void
    {
        config(['buyer_product_search.max_total_hits' => 50]);
        $service = $this->searchServiceReturning(
            hits: [
                ['id' => '49'],
                ['id' => '50'],
            ],
            page: 3,
            perPage: 24,
            estimatedTotalHits: 100,
            expectedLimit: 2,
        );

        $result = $service->search('buyer-1', [], 3, 24);

        $this->assertFalse($result['has_more']);
        $this->assertTrue($result['limit_reached']);
        $this->assertCount(2, $result['products']);
    }

    /**
     * Memastikan halaman setelah boundary tidak membuat query engine dan tetap mengembalikan kontrak aman.
     *
     * @return void Tidak mengembalikan nilai; short-circuit dan metadata response diverifikasi.
     */
    public function test_page_after_the_configured_boundary_skips_meilisearch(): void
    {
        config(['buyer_product_search.max_total_hits' => 50]);
        $client = Mockery::mock(Client::class);
        $client->shouldNotReceive('index');
        $service = new BuyerProductSearchService(
            $client,
            Mockery::mock(ProductAvailabilityService::class),
        );

        $result = $service->search('buyer-1', [], 4, 24);

        $this->assertSame([], $result['products']);
        $this->assertFalse($result['has_more']);
        $this->assertTrue($result['limit_reached']);
    }

    /**
     * Memastikan konfigurasi index menyediakan boundary 10.000 dan tie-breaker ID terendah prioritasnya.
     *
     * @return void Tidak mengembalikan nilai; settings aplikasi diverifikasi langsung.
     */
    public function test_index_settings_define_pagination_and_id_tie_breakers(): void
    {
        $settings = (array) config('buyer_product_search.settings');

        $this->assertSame(10000, config('buyer_product_search.max_total_hits'));
        $this->assertSame(10000, $settings['pagination']['maxTotalHits']);
        $this->assertContains('id', $settings['sortableAttributes']);
        $this->assertSame('id:asc', $settings['rankingRules'][array_key_last($settings['rankingRules'])]);
    }

    /**
     * Membuat service dengan response Meilisearch terkontrol dan memverifikasi parameter pagination.
     *
     * Nilai estimated total sengaja dapat berbeda dari hit aktual untuk membuktikan bahwa keputusan
     * halaman berikutnya hanya menggunakan dokumen lookahead.
     *
     * @param  array<int, array<string, mixed>>  $hits  Dokumen yang dikembalikan SDK Meilisearch.
     * @param  int  $page  Halaman katalog yang diminta oleh test.
     * @param  int  $perPage  Jumlah dokumen yang boleh dikirim per halaman.
     * @param  int  $estimatedTotalHits  Estimasi total yang disertakan dalam response SDK.
     * @param  string  $query  Keyword yang diharapkan diteruskan kepada Meilisearch.
     * @param  array<int, string>|null  $expectedSort  Sort yang diharapkan atau null untuk relevance native.
     * @param  int|null  $expectedLimit  Limit engine khusus atau null untuk lookahead normal.
     *
     * @return BuyerProductSearchService Service dengan client dan index Meilisearch yang dimock.
     */
    private function searchServiceReturning(
        array $hits,
        int $page,
        int $perPage,
        int $estimatedTotalHits,
        string $query = '',
        ?array $expectedSort = ['updated_at_timestamp:desc', 'id:asc'],
        ?int $expectedLimit = null,
    ): BuyerProductSearchService {
        $expectedLimit ??= min(
            $perPage + 1,
            (int) config('buyer_product_search.max_total_hits') - (($page - 1) * $perPage),
        );
        $searchResult = new SearchResult([
            'hits' => $hits,
            'offset' => ($page - 1) * $perPage,
            'limit' => $expectedLimit,
            'estimatedTotalHits' => $estimatedTotalHits,
            'processingTimeMs' => 1,
            'query' => $query,
        ]);
        $index = Mockery::mock(Indexes::class);
        $index->shouldReceive('search')
            ->once()
            ->with($query, Mockery::on(function (array $parameters) use (
                $page,
                $perPage,
                $expectedSort,
                $expectedLimit,
            ): bool {
                $this->assertSame(($page - 1) * $perPage, $parameters['offset']);
                $this->assertSame($expectedLimit, $parameters['limit']);

                if ($expectedSort === null) {
                    $this->assertArrayNotHasKey('sort', $parameters);
                } else {
                    $this->assertSame($expectedSort, $parameters['sort']);
                }

                return true;
            }))
            ->andReturn($searchResult);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('index')
            ->once()
            ->with((string) config('buyer_product_search.index'))
            ->andReturn($index);

        return new BuyerProductSearchService(
            $client,
            Mockery::mock(ProductAvailabilityService::class),
        );
    }
}
