<?php

namespace Tests\Feature;

use App\Models\Alamat;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Memverifikasi kontrak cursor keyset Seller Product yang opaque, terikat kriteria, dan bounded.
 */
class SellerProductCursorPaginationTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    /**
     * Menyiapkan seller terautentikasi dengan lokasi valid untuk seluruh skenario pagination.
     *
     * @return void Fixture seller tersedia sebelum setiap test dijalankan.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        $this->seller = User::factory()->create();
        $this->actingAs($this->seller);
        $this->createVerifiedAddress($this->seller);
    }

    /**
     * Memastikan cursor memuat batch lanjutan dan batch terminal tidak menghasilkan cursor kosong tambahan.
     *
     * @return void Kebenaran batch dan metadata dinyatakan melalui assertion.
     */
    public function test_cursor_loads_the_next_batch_and_stops_on_the_terminal_response(): void
    {
        $this->insertProducts(51);

        $first = $this->getJson($this->sellerUrl(['per_page' => 50]))
            ->assertOk()
            ->assertJsonCount(50, 'products')
            ->assertJsonPath('has_more', true);
        $cursor = $first->json('next_cursor');

        $this->assertIsString($cursor);
        $this->assertLessThanOrEqual(2048, strlen($cursor));
        $payload = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['version', 'criteria_hash', 'position'], array_keys($payload));
        $this->assertSame(1, $payload['version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['criteria_hash']);
        $this->assertArrayNotHasKey('seller_id', $payload);
        $this->assertArrayNotHasKey('criteria', $payload);

        $second = $this->getJson($this->sellerUrl(['per_page' => 50, 'cursor' => $cursor]))
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('next_cursor', null)
            ->assertJsonPath('has_more', false);

        $firstIds = array_column($first->json('products'), 'id');
        $secondIds = array_column($second->json('products'), 'id');
        $this->assertCount(51, array_unique([...$firstIds, ...$secondIds]));
    }

    /**
     * Memastikan seluruh sorting memakai UUID sebagai tie-breaker dan tidak melewatkan produk.
     *
     * @return void Kelengkapan ID pada setiap mode sorting dinyatakan melalui assertion.
     */
    public function test_all_sort_modes_return_each_tied_product_exactly_once(): void
    {
        $timestamp = '2026-09-13 10:00:00';
        $expectedIds = $this->insertProducts(7, $timestamp, 10000, 'Nama Sama');

        foreach (Product::SORT_OPTIONS as $sortProduct) {
            $actualIds = $this->collectAllProductIds([
                'per_page' => 2,
                'sort_product' => $sortProduct,
            ]);

            $this->assertCount(7, $actualIds, "Jumlah produk untuk {$sortProduct} tidak sesuai.");
            $this->assertSame($expectedIds, $actualIds, "Tie-breaker UUID untuk {$sortProduct} tidak stabil.");
        }
    }

    /**
     * Memastikan sorting dan pencarian Unicode memakai hasil LOWER database sebagai posisi cursor.
     *
     * Karakter dotted capital I menghasilkan lowercase berbeda di PHP dan database SQLite maupun
     * PostgreSQL. Cursor harus mengikuti nilai database dan tidak mengekspos atribut internalnya.
     *
     * @return void Nilai cursor, hasil pencarian, dan response publik diverifikasi melalui assertion.
     */
    public function test_unicode_name_cursor_and_search_use_database_lowercase_value(): void
    {
        $productIds = $this->insertProducts(2, name: 'İSTANBUL');
        DB::table('products')->where('id', $productIds[1])->update(['name' => 'İZMİR']);

        $first = $this->getJson($this->sellerUrl([
            'per_page' => 1,
            'sort_product' => 'name_asc',
        ]))->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonMissingPath('products.0.cursor_primary_value');

        $cursor = $first->json('next_cursor');
        $this->assertIsString($cursor);

        $payload = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
        $databaseValue = DB::selectOne(
            'SELECT LOWER(CAST(? AS TEXT)) AS normalized_name',
            ['İSTANBUL'],
        )->normalized_name;

        $this->assertSame($databaseValue, $payload['position']['value']);
        $this->assertNotSame(mb_strtolower('İSTANBUL'), $payload['position']['value']);

        $this->getJson($this->sellerUrl(['search_product' => 'İSTANBUL']))
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.name', 'İSTANBUL');
    }

    /**
     * Memastikan nilai primary nullable selalu berada di akhir dan tetap dapat dilalui cursor.
     *
     * Schema legacy mengizinkan harga dan timestamp null. Pagination harus tetap mencapai kelompok
     * tersebut tanpa mengulang atau melewatkan UUID pada sorting naik maupun turun.
     *
     * @return void Urutan nilai nullable dan kelengkapan ID dinyatakan melalui assertion.
     */
    public function test_nullable_primary_values_remain_last_and_are_fully_paginated(): void
    {
        $expectedIds = $this->insertProducts(4);
        DB::table('products')->whereIn('id', array_slice($expectedIds, 2))->update([
            'price' => null,
            'updated_at' => null,
        ]);

        foreach (['latest', 'oldest', 'price_lowest', 'price_highest'] as $sortProduct) {
            $this->assertSame(
                $expectedIds,
                $this->collectAllProductIds(['per_page' => 1, 'sort_product' => $sortProduct]),
                "Nilai null untuk {$sortProduct} tidak berada pada urutan stabil.",
            );
        }
    }

    /**
     * Memastikan cursor terikat pada search, stock filter, sorting, dan seller pembuatnya.
     *
     * @return void Setiap penggunaan cursor yang tidak kompatibel ditolak melalui assertion.
     */
    public function test_cursor_rejects_changed_criteria_and_another_seller(): void
    {
        $this->insertProducts(3, price: 10000, name: 'Target Produk');
        $first = $this->getJson($this->sellerUrl([
            'per_page' => 1,
            'search_product' => 'TARGET',
            'stock_filter' => 'healthy',
            'sort_product' => 'price_lowest',
        ]))->assertOk();
        $cursor = $first->json('next_cursor');

        foreach ([
            ['search_product' => 'berbeda', 'stock_filter' => 'healthy', 'sort_product' => 'price_lowest'],
            ['search_product' => 'target', 'stock_filter' => 'low', 'sort_product' => 'price_lowest'],
            ['search_product' => 'target', 'stock_filter' => 'healthy', 'sort_product' => 'price_highest'],
        ] as $criteria) {
            $this->getJson($this->sellerUrl(['per_page' => 1, 'cursor' => $cursor, ...$criteria]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['cursor'], 'message');
        }

        $otherSeller = User::factory()->create();
        $this->createVerifiedAddress($otherSeller);
        $this->actingAs($otherSeller);
        $this->getJson($this->sellerUrl(['cursor' => $cursor]))->assertForbidden();
        $this->getJson('/api/product/'.$otherSeller->id.'?'.http_build_query(['cursor' => $cursor]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cursor'], 'message');
    }

    /**
     * Memastikan cursor malformed, dimodifikasi, atau memakai versi tidak didukung menghasilkan 422 aman.
     *
     * @return void Seluruh payload tidak tepercaya ditolak melalui assertion.
     */
    public function test_invalid_cursor_payloads_are_rejected_safely(): void
    {
        $this->insertProducts(2);
        $validCursor = $this->getJson($this->sellerUrl(['per_page' => 1]))->json('next_cursor');
        $modifiedCursor = ($validCursor[0] === 'A' ? 'B' : 'A').substr($validCursor, 1);

        $invalidCursors = [
            'not-an-encrypted-cursor',
            substr($validCursor, 0, -8),
            $modifiedCursor,
            Crypt::encryptString('{invalid-json'),
            Crypt::encryptString(json_encode([
                'version' => 2,
                'seller_id' => $this->seller->id,
                'criteria' => [
                    'search_product' => '',
                    'stock_filter' => 'all',
                    'sort_product' => 'latest',
                ],
                'position' => ['value' => '2026-09-13 10:00:00.000000', 'id' => (string) Str::uuid()],
            ])),
        ];

        foreach ($invalidCursors as $cursor) {
            $this->getJson($this->sellerUrl(['cursor' => $cursor]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['cursor'], 'message');
        }
    }

    /**
     * Memastikan katalog 1.000 produk selesai dengan parameter cursor berukuran tetap tanpa duplikasi.
     *
     * @return void Kelengkapan katalog dan batas ukuran cursor dinyatakan melalui assertion.
     */
    public function test_one_thousand_products_paginate_with_bounded_cursor_parameters(): void
    {
        $expectedIds = $this->insertProducts(1000);
        $actualIds = $this->collectAllProductIds(['per_page' => 50]);

        $this->assertSame($expectedIds, $actualIds);
        $this->assertCount(1000, array_unique($actualIds));
    }

    /**
     * Mengambil seluruh batch cursor sampai backend menandai response terminal.
     *
     * @param  array<string, int|string>  $criteria  Kriteria awal yang dipertahankan pada setiap request.
     *
     * @return array<int, string> UUID produk dalam urutan yang dikembalikan endpoint.
     */
    private function collectAllProductIds(array $criteria): array
    {
        $cursor = null;
        $ids = [];

        do {
            $parameters = $criteria;

            if ($cursor !== null) {
                $parameters['cursor'] = $cursor;
            }

            $response = $this->getJson($this->sellerUrl($parameters))->assertOk();
            $ids = [...$ids, ...array_column($response->json('products'), 'id')];
            $cursor = $response->json('next_cursor');

            if ($cursor !== null) {
                $this->assertLessThanOrEqual(2048, strlen($cursor));
            }
        } while ($response->json('has_more'));

        $this->assertNull($cursor);

        return $ids;
    }

    /**
     * Menyisipkan katalog terurut dengan UUID deterministik untuk pengujian tie dan katalog besar.
     *
     * @param  int  $count  Jumlah produk yang akan dibuat untuk seller aktif.
     * @param  string  $timestamp  Timestamp bersama untuk seluruh produk.
     * @param  int  $price  Harga bersama untuk seluruh produk.
     * @param  string  $name  Nama bersama untuk seluruh produk.
     *
     * @return array<int, string> UUID produk dalam urutan ascending yang diharapkan.
     */
    private function insertProducts(
        int $count,
        string $timestamp = '2026-09-13 10:00:00',
        int $price = 10000,
        string $name = 'Produk Cursor',
    ): array {
        $ids = [];
        $rows = [];

        for ($index = 1; $index <= $count; $index++) {
            $id = sprintf('00000000-0000-4000-8000-%012d', $index);
            $ids[] = $id;
            $rows[] = [
                'id' => $id,
                'user_id_seller' => $this->seller->id,
                'img' => 'product-imgs/test.jpg',
                'name' => $name,
                'price' => $price,
                'stock' => 10,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'deleted_at' => null,
            ];

            if (count($rows) === 200) {
                DB::table('products')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('products')->insert($rows);
        }

        return $ids;
    }

    /**
     * Membuat lokasi seller aktif dan terverifikasi agar status endpoint tetap mengikuti kontrak produk.
     *
     * @param  User  $seller  Seller yang akan menerima lokasi terverifikasi.
     *
     * @return void Alamat disimpan sebagai fixture pengujian.
     */
    private function createVerifiedAddress(User $seller): void
    {
        Alamat::create([
            'user_id' => $seller->id,
            'type' => 'seller',
            'enable' => 1,
            'place' => 'Toko',
            'alamat' => 'Blok A, Jakarta, Indonesia',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'formatted_address' => 'Jakarta, Indonesia',
            'address_detail' => 'Blok A',
            'location_source' => 'map',
        ]);
    }

    /**
     * Menyusun URL Seller Product dengan parameter query yang diencode secara konsisten.
     *
     * @param  array<string, int|string>  $parameters  Parameter cursor, ukuran batch, filter, atau sorting.
     *
     * @return string URL endpoint Seller Product untuk seller aktif.
     */
    private function sellerUrl(array $parameters = []): string
    {
        return '/api/product/'.$this->seller->id.'?'.http_build_query($parameters);
    }
}
