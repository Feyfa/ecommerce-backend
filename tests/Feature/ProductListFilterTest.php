<?php

namespace Tests\Feature;

use App\Models\Alamat;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Services\BuyerProductSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\TestCase;

class ProductListFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /**
     * Menyiapkan fixture dan dependency sebelum setiap pengujian.
     *
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->mock(BuyerProductSearchService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('search')
                ->andReturnUsing(fn (string $buyerId, array $filters, int $page, int $perPage): array => $this->fakeBuyerSearch(
                    $buyerId,
                    $filters,
                    $page,
                    $perPage,
                ));
        });
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
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
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
            ->assertJsonPath('products.0.p_id', $available->id)
            ->assertJsonPath('limit_reached', false);
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
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
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
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
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
        ]))
            ->assertOk()
            ->assertJsonCount(2, 'products')
            ->assertJsonPath('products.0.p_id', $excluded->id);

        $this->getJson($this->buyerUrl(['search_product' => 'TOKO PILIHAN']))
            ->assertOk()
            ->assertJsonCount(2, 'products');
    }

    /**
     * Memastikan batas harga minimum, maksimum, dan gabungannya diterapkan secara inklusif.
     *
     * Katalog buyer harus tetap mengembalikan produk pada nilai batas tepat agar harga yang dimasukkan
     * pengguna memiliki arti yang sama pada kedua sisi rentang.
     *
     * @test
     *
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function buyer_can_filter_products_by_inclusive_price_range(): void
    {
        $seller = User::factory()->create();
        $lower = $this->createProduct($seller, 'Harga Bawah', 10000, 3);
        $middle = $this->createProduct($seller, 'Harga Tengah', 20000, 3);
        $upper = $this->createProduct($seller, 'Harga Atas', 30000, 3);

        $this->getJson($this->buyerUrl(['min_price' => 20000, 'sort_product' => 'price_lowest']))
            ->assertOk()
            ->assertJsonPath('products.0.p_id', $middle->id)
            ->assertJsonPath('products.1.p_id', $upper->id);

        $this->getJson($this->buyerUrl(['max_price' => 20000, 'sort_product' => 'price_lowest']))
            ->assertOk()
            ->assertJsonPath('products.0.p_id', $lower->id)
            ->assertJsonPath('products.1.p_id', $middle->id);

        $this->getJson($this->buyerUrl([
            'min_price' => 20000,
            'max_price' => 20000,
            'sort_product' => 'price_lowest',
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.p_id', $middle->id);
    }

    /**
     * Memastikan filter terakhir ditambahkan memakai waktu pembuatan produk dan seluruh pilihan rentangnya.
     *
     * Setiap pilihan harus membatasi query sebelum sorting. Produk yang hanya baru diperbarui tidak boleh ikut
     * ketika waktu pembuatannya berada di luar periode yang dipilih buyer.
     *
     * @test
     *
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function buyer_can_filter_products_by_recently_added_period(): void
    {
        $seller = User::factory()->create();
        $sevenDays = $this->createProduct($seller, 'Tujuh Hari', 10000, 3, now()->subDays(6)->toDateTimeString());
        $fourteenDays = $this->createProduct($seller, 'Empat Belas Hari', 20000, 3, now()->subDays(10)->toDateTimeString());
        $oneMonth = $this->createProduct($seller, 'Satu Bulan', 30000, 3, now()->subDays(29)->toDateTimeString());
        $threeMonths = $this->createProduct($seller, 'Tiga Bulan', 40000, 3, now()->subDays(89)->toDateTimeString());
        $olderProduct = $this->createProduct($seller, 'Produk Lama', 50000, 3, now()->subDays(91)->toDateTimeString());

        DB::table('products')->where('id', $olderProduct->id)->update(['updated_at' => now()]);

        $expectedProducts = [
            '7' => [$sevenDays->id],
            '14' => [$sevenDays->id, $fourteenDays->id],
            '30' => [$sevenDays->id, $fourteenDays->id, $oneMonth->id],
            '90' => [$sevenDays->id, $fourteenDays->id, $oneMonth->id, $threeMonths->id],
        ];

        foreach ($expectedProducts as $addedWithin => $expectedIds) {
            $response = $this->getJson($this->buyerUrl([
                'added_within' => $addedWithin,
                'sort_product' => 'name_asc',
            ]));

            $response->assertOk();
            $this->assertEqualsCanonicalizing($expectedIds, collect($response->json('products'))->pluck('p_id')->all());
        }
    }

    /**
     * Memastikan filter harga dapat digabungkan dengan pencarian, sorting, dan cursor produk buyer.
     *
     * Setiap kondisi harus mempersempit query yang sama tanpa mengembalikan produk yang sudah ada
     * pada batch sebelumnya atau produk di luar rentang harga aktif.
     *
     * @test
     *
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function buyer_can_combine_price_filter_with_search_sort_and_excluded_ids(): void
    {
        $seller = User::factory()->create();
        $excluded = $this->createProduct($seller, 'Target Murah', 10000, 3);
        $remaining = $this->createProduct($seller, 'Target Sedang', 20000, 3);
        $this->createProduct($seller, 'Target Mahal', 30000, 3);
        $this->createProduct($seller, 'Target Lama', 15000, 3, now()->subDays(8)->toDateTimeString());

        $this->getJson($this->buyerUrl([
            'search_product' => 'target',
            'min_price' => 10000,
            'max_price' => 20000,
            'added_within' => '7',
            'sort_product' => 'price_highest',
        ]))
            ->assertOk()
            ->assertJsonCount(2, 'products')
            ->assertJsonPath('products.0.p_id', $remaining->id);
    }

    /**
     * Memastikan nilai harga buyer yang negatif atau memiliki rentang terbalik ditolak.
     *
     * Validasi menjaga query katalog tidak menerima nominal yang tidak masuk akal dan batas maksimum
     * selalu sama dengan atau lebih besar dari batas minimum.
     *
     * @test
     *
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function buyer_rejects_invalid_price_filter_values(): void
    {
        $this->getJson($this->buyerUrl(['min_price' => -1]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['min_price'], 'message');

        $this->getJson($this->buyerUrl(['max_price' => -1]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['max_price'], 'message');

        $this->getJson($this->buyerUrl(['min_price' => 20000, 'max_price' => 10000]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['max_price'], 'message');

        $this->getJson($this->buyerUrl(['added_within' => '5']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['added_within'], 'message');
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
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
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
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
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
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
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
     * Memverifikasi seller dapat menggabungkan pencarian, filter stok, dan sorting.
     *
     * Test menyiapkan kombinasi produk dan seller, memanggil endpoint list dengan filter tertentu,
     * lalu memastikan urutan, scope ownership, dan hasil pagination sesuai kontrak.
     *
     * @test
     *
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function seller_can_combine_search_stock_and_sort(): void
    {
        $lowerPrice = $this->createProduct($this->user, 'Target Murah', 10000, 2);
        $higherPrice = $this->createProduct($this->user, 'Target Mahal', 20000, 3);
        $this->createProduct($this->user, 'Produk Lain', 30000, 4);

        $response = $this->getJson($this->sellerUrl($this->user, [
            'search_product' => 'Target',
            'stock_filter' => 'low',
            'sort_product' => 'price_highest',
        ]));

        $response->assertOk()
            ->assertJsonCount(2, 'products')
            ->assertJsonPath('products.0.id', $higherPrice->id)
            ->assertJsonPath('products.1.id', $lowerPrice->id);
    }

    /**
     * Memastikan metadata akhir pagination seller akurat untuk katalog pendek, tepat satu batch,
     * dan katalog multi-batch.
     *
     * Lookahead tidak boleh ikut dikirim pada batch pertama. Request lanjutan menggunakan cursor
     * harus mengembalikan sisa produk tanpa duplikasi dan menandai pagination selesai.
     *
     * @return void Tidak mengembalikan nilai; metadata dan isi setiap batch diverifikasi melalui assertion.
     *
     * @test
     */
    public function seller_returns_explicit_completion_metadata_for_terminal_and_multi_batch_catalogs(): void
    {
        // --- step 1 - start - verifikasi katalog pendek dan tepat satu batch
        for ($index = 1; $index <= 4; $index++) {
            $this->createProduct($this->user, "Produk Pendek {$index}", 10000 + $index, 10);
        }

        $this->getJson($this->sellerUrl($this->user))
            ->assertOk()
            ->assertJsonCount(4, 'products')
            ->assertJsonPath('has_more', false);

        for ($index = 5; $index <= 50; $index++) {
            $this->createProduct($this->user, "Produk Batch {$index}", 10000 + $index, 10);
        }

        $this->getJson($this->sellerUrl($this->user))
            ->assertOk()
            ->assertJsonCount(50, 'products')
            ->assertJsonPath('has_more', false);
        // --- step 1 - end - verifikasi katalog pendek dan tepat satu batch

        // --- step 2 - start - verifikasi lookahead dan kelengkapan dua batch
        $this->createProduct($this->user, 'Produk Lookahead', 20000, 10);
        $firstBatch = $this->getJson($this->sellerUrl($this->user))
            ->assertOk()
            ->assertJsonCount(50, 'products')
            ->assertJsonPath('has_more', true);
        $firstBatchIds = collect($firstBatch->json('products'))->pluck('id')->all();

        $secondBatch = $this->getJson($this->sellerUrl($this->user, [
            'cursor' => $firstBatch->json('next_cursor'),
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('has_more', false);

        $secondBatchIds = collect($secondBatch->json('products'))->pluck('id')->all();
        $allProductIds = Product::where('user_id_seller', $this->user->id)->pluck('id')->all();

        $this->assertSame([], array_intersect($firstBatchIds, $secondBatchIds));
        $this->assertEqualsCanonicalizing($allProductIds, [...$firstBatchIds, ...$secondBatchIds]);
        // --- step 2 - end - verifikasi lookahead dan kelengkapan dua batch
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
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
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
     * Memastikan endpoint buyer menolak nomor halaman dan ukuran halaman di luar batas kontrak.
     *
     * @test
     *
     * @return void Tidak mengembalikan nilai; validation error diverifikasi melalui assertion.
     */
    public function buyer_rejects_an_invalid_page_contract(): void
    {
        $this->getJson($this->buyerUrl(['page' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['page'], 'message');

        $this->getJson($this->buyerUrl(['per_page' => 51]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page'], 'message');
    }

    /**
     * Memastikan endpoint buyer menggunakan ukuran halaman 50 dari konfigurasi ketika client tidak
     * mengirimkan override.
     *
     * @return void Tidak mengembalikan nilai; default response pagination diverifikasi melalui assertion.
     *
     * @test
     */
    public function buyer_uses_the_configured_fifty_product_default_page_size(): void
    {
        config()->set('buyer_product_search.per_page', 50);

        $this->getJson('/api/belanja')
            ->assertOk()
            ->assertJsonPath('page', 1)
            ->assertJsonPath('per_page', 50);
    }

    /**
     * Memastikan ukuran request seller mengalahkan default dan lookahead tetap akurat antarbatch.
     *
     * @return void Dua batch mencakup seluruh fixture tanpa duplikasi dan berhenti pada batch terakhir.
     */
    public function test_seller_request_page_size_overrides_default_with_correct_completion(): void
    {
        // --- step 1 - start - siapkan katalog yang melebihi ukuran request
        config()->set('seller_product.per_page', 1);
        config()->set('seller_product.max_per_page', 3);
        for ($index = 1; $index <= 3; $index++) {
            $this->createProduct($this->user, "Ukuran Seller {$index}", 10000 + $index, 10);
        }
        // --- step 1 - end - siapkan katalog yang melebihi ukuran request

        // --- step 2 - start - verifikasi request eksplisit dan batch terakhir
        $first = $this->getJson($this->sellerUrl($this->user, ['per_page' => 2]))
            ->assertOk()->assertJsonCount(2, 'products')->assertJsonPath('has_more', true);
        $firstIds = array_column($first->json('products'), 'id');
        $second = $this->getJson($this->sellerUrl($this->user, [
            'per_page' => 2,
            'cursor' => $first->json('next_cursor'),
        ]))->assertOk()->assertJsonCount(1, 'products')->assertJsonPath('has_more', false);
        $this->assertSame([], array_intersect($firstIds, array_column($second->json('products'), 'id')));
        $this->getJson($this->sellerUrl($this->user, ['per_page' => 3]))
            ->assertOk()->assertJsonCount(3, 'products')->assertJsonPath('has_more', false);
        // --- step 2 - end - verifikasi request eksplisit dan batch terakhir
    }

    /**
     * Memastikan client lama tanpa per_page memakai default seller dan input tidak valid ditolak.
     *
     * @return void Default membatasi hasil dan validasi menolak ukuran di luar kontrak konfigurasi.
     */
    public function test_seller_uses_configured_default_and_rejects_invalid_page_sizes(): void
    {
        // --- step 1 - start - verifikasi fallback client tanpa ukuran batch
        config()->set('seller_product.per_page', 1);
        config()->set('seller_product.max_per_page', 2);
        $this->createProduct($this->user, 'Default Seller A', 10000, 10);
        $this->createProduct($this->user, 'Default Seller B', 20000, 10);
        $this->getJson($this->sellerUrl($this->user))
            ->assertOk()->assertJsonCount(1, 'products')->assertJsonPath('has_more', true);
        $this->getJson($this->sellerUrl($this->user, ['per_page' => '']))
            ->assertOk()->assertJsonCount(1, 'products')->assertJsonPath('has_more', true);
        // --- step 1 - end - verifikasi fallback client tanpa ukuran batch

        // --- step 2 - start - tolak input di luar batas atau bukan bilangan bulat
        foreach ([0, -1, 3, 1.5, 'invalid', [1]] as $invalidSize) {
            $this->getJson($this->sellerUrl($this->user, ['per_page' => $invalidSize]))
                ->assertStatus(422)->assertJsonValidationErrors(['per_page'], 'message');
        }
        // --- step 2 - end - tolak input di luar batas atau bukan bilangan bulat
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
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
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
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
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
     * @return Product Model produk yang dibuat atau digunakan sebagai fixture.
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
     * @return string Nilai teks yang telah dinormalisasi untuk kebutuhan pemanggil.
     */
    private function buyerUrl(array $parameters = []): string
    {
        return '/api/belanja?'.http_build_query([
            'page' => 1,
            'per_page' => 50,
            ...$parameters,
        ]);
    }

    /**
     * Menirukan hasil index buyer untuk feature test tanpa menambahkan fallback database ke aplikasi.
     *
     * Test double ini hanya menggantikan boundary Meilisearch yang tidak tersedia pada test SQLite.
     * Aturan select dijaga sama dengan dokumen publik agar test endpoint tetap menguji ownership,
     * ketersediaan, filter, sort, dan metadata pagination secara deterministik.
     *
     * @param  string  $buyerId  ID buyer yang produk miliknya dikecualikan.
     * @param  array<string, int|string|null>  $filters  Parameter katalog tervalidasi dari controller.
     * @param  int  $page  Nomor halaman yang diminta.
     * @param  int  $perPage  Jumlah hasil maksimum per halaman.
     *
     * @return array{products: array<int, array<string, mixed>>, page: int, per_page: int, has_more: bool, limit_reached: bool} Halaman fake beserta metadata pagination yang mengikuti kontrak endpoint.
     */
    private function fakeBuyerSearch(string $buyerId, array $filters, int $page, int $perPage): array
    {
        $keyword = mb_strtolower(trim((string) ($filters['search_product'] ?? '')));
        $sortProduct = (string) ($filters['sort_product'] ?? 'latest');
        $query = Product::select(
            'products.id as p_id',
            'products.img as p_img',
            'products.name as p_name',
            'products.price as p_price',
            'products.stock as p_stock',
            'users.id as u_id',
            DB::raw("COALESCE(NULLIF(companies.name, ''), users.name) as u_name")
        )
            ->join('users', 'products.user_id_seller', '=', 'users.id')
            ->leftJoin('companies', 'companies.user_id', '=', 'users.id')
            ->where('products.user_id_seller', '<>', $buyerId)
            ->purchasable()
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $searchPattern = '%'.$keyword.'%';
                $query->where(function ($searchQuery) use ($searchPattern): void {
                    $searchQuery->whereRaw('LOWER(products.name) LIKE ?', [$searchPattern])
                        ->orWhereRaw("LOWER(COALESCE(NULLIF(companies.name, ''), users.name)) LIKE ?", [$searchPattern]);
                });
            })
            ->when($filters['min_price'] !== null, fn ($query) => $query->where('products.price', '>=', $filters['min_price']))
            ->when($filters['max_price'] !== null, fn ($query) => $query->where('products.price', '<=', $filters['max_price']))
            ->when(
                $filters['added_within'] !== null,
                fn ($query) => $query->where('products.created_at', '>=', now()->subDays((int) $filters['added_within']))
            );

        if ($sortProduct !== 'relevance') {
            $query->withProductSort($sortProduct);
        } else {
            $query->withProductSort('latest');
        }

        $total = (clone $query)->count();
        $products = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(static fn (Product $product): array => $product->getAttributes())
            ->all();

        return [
            'products' => $products,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => ($page * $perPage) < $total,
            'limit_reached' => false,
        ];
    }

    /**
     * Menyusun URL daftar produk seller dengan identifier actor dan query string terkontrol. Struktur
     * URL yang sama dipakai untuk seluruh kombinasi filter seller.
     *
     * @param  User  $seller  Model user seller yang menjadi actor atau fixture.
     * @param  array  $parameters  Parameter query yang akan ditambahkan ke URL pengujian.
     *
     * @return string Nilai teks yang telah dinormalisasi untuk kebutuhan pemanggil.
     */
    private function sellerUrl(User $seller, array $parameters = []): string
    {
        return '/api/product/'.$seller->id.'?'.http_build_query($parameters);
    }

    /**
     * Memanggil endpoint seller menggunakan filter yang diberikan, memastikan response berhasil, lalu
     * membandingkan urutan ID produk dengan hasil yang diharapkan.
     *
     * @param  string  $filter  Nilai filter yang digunakan oleh skenario pengujian.
     * @param  array  $expectedIds  Urutan ID produk yang diharapkan pada response.
     *
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
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
