<?php

namespace Tests\Feature;

use App\Models\Alamat;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductListFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /**
     * Menyiapkan fixture dan dependency sebelum setiap pengujian.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    /**
     * Memverifikasi aturan filter dan pengurutan katalog produk pada skenario buyer only receives
     * purchasable products.
     *
     * Test menyiapkan kombinasi produk dan seller, memanggil endpoint list dengan filter tertentu,
     * lalu memastikan urutan, scope ownership, dan hasil pagination sesuai kontrak.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function buyer_only_receives_purchasable_products(): void
    {
        $seller = User::factory()->create();
        $available = $this->createProduct($seller, 'Tersedia', 10000, 1);
        $this->createProduct($seller, 'Habis', 20000, 0);
        $this->createProduct($seller, 'Stok Negatif', 30000, -1);
        $this->createProduct($this->user, 'Produk Sendiri', 40000, 10);

        $response = $this->getJson($this->buyerUrl());

        $response->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.p_id', $available->id);
    }

    /**
     * Memverifikasi aturan filter dan pengurutan katalog produk pada skenario buyer and seller use the
     * same product sort options.
     *
     * Test menyiapkan kombinasi produk dan seller, memanggil endpoint list dengan filter tertentu,
     * lalu memastikan urutan, scope ownership, dan hasil pagination sesuai kontrak.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function buyer_and_seller_use_the_same_product_sort_options(): void
    {
        $seller = User::factory()->create();
        $alpha = $this->createProduct($seller, 'Alpha', 30000, 10, '2026-01-01 00:00:00');
        $middle = $this->createProduct($seller, 'Middle', 10000, 10, '2026-01-02 00:00:00');
        $zulu = $this->createProduct($seller, 'Zulu', 20000, 10, '2026-01-03 00:00:00');
        $expectedFirstIds = [
            'latest' => $zulu->id,
            'oldest' => $alpha->id,
            'price_lowest' => $middle->id,
            'price_highest' => $alpha->id,
            'name_asc' => $alpha->id,
            'name_desc' => $zulu->id,
        ];

        foreach ($expectedFirstIds as $sort => $expectedId) {
            $this->getJson($this->buyerUrl(['sort_product' => $sort]))
                ->assertOk()
                ->assertJsonPath('products.0.p_id', $expectedId);
        }

        $this->actingAs($seller);

        foreach ($expectedFirstIds as $sort => $expectedId) {
            $this->getJson($this->sellerUrl($seller, ['sort_product' => $sort]))
                ->assertOk()
                ->assertJsonPath('products.0.id', $expectedId);
        }
    }

    /**
     * Memverifikasi aturan filter dan pengurutan katalog produk pada skenario buyer can combine case
     * insensitive search sort and excluded ids.
     *
     * Test menyiapkan kombinasi produk dan seller, memanggil endpoint list dengan filter tertentu,
     * lalu memastikan urutan, scope ownership, dan hasil pagination sesuai kontrak.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function buyer_can_combine_case_insensitive_search_sort_and_excluded_ids(): void
    {
        $seller = User::factory()->create(['name' => 'Toko Pilihan']);
        $excluded = $this->createProduct($seller, 'Target Mahal', 30000, 3);
        $remaining = $this->createProduct($seller, 'Target Murah', 10000, 2);
        $this->createProduct(User::factory()->create(), 'Produk Lain', 20000, 4);

        $this->getJson($this->buyerUrl([
            'search_product' => 'target',
            'sort_product' => 'price_highest',
            'products_current_id' => json_encode([$excluded->id]),
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.p_id', $remaining->id);

        $this->getJson($this->buyerUrl(['search_product' => 'TOKO PILIHAN']))
            ->assertOk()
            ->assertJsonCount(2, 'products');
    }

    /**
     * Memverifikasi aturan filter dan pengurutan katalog produk pada skenario buyer catalog
     * prioritizes and searches the store name.
     *
     * Test menyiapkan kombinasi produk dan seller, memanggil endpoint list dengan filter tertentu,
     * lalu memastikan urutan, scope ownership, dan hasil pagination sesuai kontrak.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function buyer_catalog_prioritizes_and_searches_the_store_name(): void
    {
        $seller = User::factory()->create(['name' => 'Nama Akun Seller']);
        Company::create([
            'user_id' => $seller->id,
            'name' => 'SpaceX',
        ]);
        $product = $this->createProduct($seller, 'Roket Mini', 10000, 3);

        $this->getJson($this->buyerUrl())
            ->assertOk()
            ->assertJsonPath('products.0.p_id', $product->id)
            ->assertJsonPath('products.0.u_name', 'SpaceX');

        $this->getJson($this->buyerUrl(['search_product' => 'spacex']))
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.p_id', $product->id);
    }

    /**
     * Memverifikasi aturan filter dan pengurutan katalog produk pada skenario buyer ignores legacy
     * stock filter and keeps purchasable invariant.
     *
     * Test menyiapkan kombinasi produk dan seller, memanggil endpoint list dengan filter tertentu,
     * lalu memastikan urutan, scope ownership, dan hasil pagination sesuai kontrak.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function buyer_ignores_legacy_stock_filter_and_keeps_purchasable_invariant(): void
    {
        $seller = User::factory()->create();
        $available = $this->createProduct($seller, 'Tersedia', 10000, 1);
        $this->createProduct($seller, 'Habis', 20000, 0);

        $this->getJson($this->buyerUrl(['stock_filter' => 'empty']))
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.p_id', $available->id);
    }

    /**
     * Memverifikasi aturan filter dan pengurutan katalog produk pada skenario seller stock conditions
     * are exclusive and all is the default.
     *
     * Test menyiapkan kombinasi produk dan seller, memanggil endpoint list dengan filter tertentu,
     * lalu memastikan urutan, scope ownership, dan hasil pagination sesuai kontrak.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function seller_stock_conditions_are_exclusive_and_all_is_the_default(): void
    {
        $stockSix = $this->createProduct($this->user, 'Stok Enam', 10000, 6);
        $stockFive = $this->createProduct($this->user, 'Stok Lima', 10000, 5);
        $stockOne = $this->createProduct($this->user, 'Stok Satu', 10000, 1);
        $stockZero = $this->createProduct($this->user, 'Stok Nol', 10000, 0);

        $this->getJson($this->sellerUrl($this->user))
            ->assertOk()
            ->assertJsonCount(4, 'products');

        $this->assertSellerFilterReturns('healthy', [$stockSix->id]);
        $this->assertSellerFilterReturns('available', [$stockSix->id]);
        $this->assertSellerFilterReturns('low', [$stockFive->id, $stockOne->id]);
        $this->assertSellerFilterReturns('empty', [$stockZero->id]);
    }

    /**
     * Memverifikasi aturan filter dan pengurutan katalog produk pada skenario seller can combine
     * search stock sort and excluded ids.
     *
     * Test menyiapkan kombinasi produk dan seller, memanggil endpoint list dengan filter tertentu,
     * lalu memastikan urutan, scope ownership, dan hasil pagination sesuai kontrak.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function seller_can_combine_search_stock_sort_and_excluded_ids(): void
    {
        $lowerPrice = $this->createProduct($this->user, 'Target Murah', 10000, 2);
        $higherPrice = $this->createProduct($this->user, 'Target Mahal', 20000, 3);
        $this->createProduct($this->user, 'Produk Lain', 30000, 4);

        $response = $this->getJson($this->sellerUrl($this->user, [
            'search_product' => 'Target',
            'stock_filter' => 'low',
            'sort_product' => 'price_highest',
            'products_current_id' => json_encode([$higherPrice->id]),
        ]));

        $response->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.id', $lowerPrice->id);
    }

    /**
     * Memverifikasi aturan filter dan pengurutan katalog produk pada skenario legacy stock sort values
     * are rejected.
     *
     * Test menyiapkan kombinasi produk dan seller, memanggil endpoint list dengan filter tertentu,
     * lalu memastikan urutan, scope ownership, dan hasil pagination sesuai kontrak.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function legacy_stock_sort_values_are_rejected(): void
    {
        foreach (['stock_highest', 'stock_lowest'] as $legacySort) {
            $this->getJson($this->sellerUrl($this->user, ['sort_product' => $legacySort]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['sort_product'], 'message');
        }
    }

    /**
     * Memverifikasi aturan filter dan pengurutan katalog produk pada skenario buyer rejects a
     * malformed product cursor.
     *
     * Test menyiapkan kombinasi produk dan seller, memanggil endpoint list dengan filter tertentu,
     * lalu memastikan urutan, scope ownership, dan hasil pagination sesuai kontrak.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function buyer_rejects_a_malformed_product_cursor(): void
    {
        $this->getJson($this->buyerUrl(['products_current_id' => 'invalid']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['products_current_id'], 'message');

        $this->getJson($this->buyerUrl(['products_current_id' => json_encode('not-an-array')]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['products_current_id'], 'message');
    }

    /**
     * Memverifikasi aturan filter dan pengurutan katalog produk pada skenario seller cannot read
     * another sellers product list.
     *
     * Test menyiapkan kombinasi produk dan seller, memanggil endpoint list dengan filter tertentu,
     * lalu memastikan urutan, scope ownership, dan hasil pagination sesuai kontrak.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function seller_cannot_read_another_sellers_product_list(): void
    {
        $otherSeller = User::factory()->create();

        $this->getJson($this->sellerUrl($otherSeller))->assertForbidden();
    }

    /**
     * Memverifikasi aturan filter dan pengurutan katalog produk pada skenario legacy buyer route is
     * not available.
     *
     * Test menyiapkan kombinasi produk dan seller, memanggil endpoint list dengan filter tertentu,
     * lalu memastikan urutan, scope ownership, dan hasil pagination sesuai kontrak.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function legacy_buyer_route_is_not_available(): void
    {
        $this->getJson('/api/belanja/'.$this->user->id.'?'.http_build_query([
            'products_current_id' => json_encode([]),
        ]))->assertNotFound();
    }

    /**
     * Membuat fixture produk untuk seller tertentu dengan stok, harga, nama, dan waktu update yang
     * dapat dioverride. Data deterministik ini dipakai untuk menguji kombinasi filter serta
     * tie-breaker sorting.
     *
     * @param  User  $seller  Model user seller yang menjadi actor atau fixture.
     * @param  string  $name  Nama user, rekening, atau resource sesuai konteks operasi.
     * @param  int  $price  Nominal uang yang digunakan oleh operasi.
     * @param  int  $stock  Jumlah stok produk untuk skenario atau perubahan terkait.
     * @param  string|null  $updatedAt  Waktu update produk untuk menguji urutan yang stabil.
     *
     * @return Product  Model produk yang dibuat atau digunakan sebagai fixture.
     */
    private function createProduct(
        User $seller,
        string $name,
        int $price,
        int $stock,
        ?string $updatedAt = null,
    ): Product {
        Alamat::firstOrCreate(
            ['user_id' => $seller->id, 'type' => 'seller', 'enable' => 1],
            [
                'place' => 'Toko',
                'alamat' => 'Blok A, Jakarta, Indonesia',
                'latitude' => -6.2,
                'longitude' => 106.8,
                'formatted_address' => 'Jakarta, Indonesia',
                'address_detail' => 'Blok A',
                'location_source' => 'map',
            ],
        );

        $product = Product::create([
            'user_id_seller' => $seller->id,
            'img' => 'product-imgs/test.jpg',
            'name' => $name,
            'price' => $price,
            'stock' => $stock,
        ]);

        if ($updatedAt !== null) {
            DB::table('products')->where('id', $product->id)->update([
                'created_at' => $updatedAt,
                'updated_at' => $updatedAt,
            ]);
            $product->refresh();
        }

        return $product;
    }

    /**
     * Menyusun URL katalog buyer dengan query string yang telah diencode. Helper menjaga setiap test
     * memakai route dan format parameter yang sama.
     *
     * @param  array  $parameters  Parameter query yang akan ditambahkan ke URL pengujian.
     *
     * @return string  Nilai teks yang telah dinormalisasi untuk kebutuhan pemanggil.
     */
    private function buyerUrl(array $parameters = []): string
    {
        return '/api/belanja?'.http_build_query([
            'products_current_id' => json_encode([]),
            ...$parameters,
        ]);
    }

    /**
     * Menyusun URL daftar produk seller dengan identifier actor dan query string terkontrol. Struktur
     * URL yang sama dipakai untuk seluruh kombinasi filter seller.
     *
     * @param  User  $seller  Model user seller yang menjadi actor atau fixture.
     * @param  array  $parameters  Parameter query yang akan ditambahkan ke URL pengujian.
     *
     * @return string  Nilai teks yang telah dinormalisasi untuk kebutuhan pemanggil.
     */
    private function sellerUrl(User $seller, array $parameters = []): string
    {
        return '/api/product/'.$seller->id.'?'.http_build_query([
            'products_current_id' => json_encode([]),
            ...$parameters,
        ]);
    }

    /**
     * Memanggil endpoint seller menggunakan filter yang diberikan, memastikan response berhasil, lalu
     * membandingkan urutan ID produk dengan hasil yang diharapkan.
     *
     * @param  string  $filter  Nilai filter yang digunakan oleh skenario pengujian.
     * @param  array  $expectedIds  Urutan ID produk yang diharapkan pada response.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    private function assertSellerFilterReturns(string $filter, array $expectedIds): void
    {
        $response = $this->getJson($this->sellerUrl($this->user, [
            'stock_filter' => $filter,
            'sort_product' => 'name_asc',
        ]));

        $response->assertOk();
        $this->assertEqualsCanonicalizing($expectedIds, collect($response->json('products'))->pluck('id')->all());
    }
}
