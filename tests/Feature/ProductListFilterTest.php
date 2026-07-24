<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductListFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
    public function legacy_stock_sort_values_are_rejected(): void
    {
        foreach (['stock_highest', 'stock_lowest'] as $legacySort) {
            $this->getJson($this->sellerUrl($this->user, ['sort_product' => $legacySort]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['sort_product'], 'message');
        }
    }

    /** @test */
    public function buyer_rejects_a_malformed_product_cursor(): void
    {
        $this->getJson($this->buyerUrl(['products_current_id' => 'invalid']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['products_current_id'], 'message');

        $this->getJson($this->buyerUrl(['products_current_id' => json_encode('not-an-array')]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['products_current_id'], 'message');
    }

    /** @test */
    public function seller_cannot_read_another_sellers_product_list(): void
    {
        $otherSeller = User::factory()->create();

        $this->getJson($this->sellerUrl($otherSeller))->assertForbidden();
    }

    /** @test */
    public function legacy_buyer_route_is_not_available(): void
    {
        $this->getJson('/api/belanja/'.$this->user->id.'?'.http_build_query([
            'products_current_id' => json_encode([]),
        ]))->assertNotFound();
    }

    private function createProduct(
        User $seller,
        string $name,
        int $price,
        int $stock,
        ?string $updatedAt = null,
    ): Product {
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

    private function buyerUrl(array $parameters = []): string
    {
        return '/api/belanja?'.http_build_query([
            'products_current_id' => json_encode([]),
            ...$parameters,
        ]);
    }

    private function sellerUrl(User $seller, array $parameters = []): string
    {
        return '/api/product/'.$seller->id.'?'.http_build_query([
            'products_current_id' => json_encode([]),
            ...$parameters,
        ]);
    }

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
