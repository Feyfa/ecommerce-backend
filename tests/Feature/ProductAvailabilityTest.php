<?php

namespace Tests\Feature;

use App\Models\Alamat;
use App\Models\Company;
use App\Models\Keranjang;
use App\Models\Product;
use App\Models\User;
use App\Services\KeranjangService;
use App\Services\ProductAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Menyiapkan fixture dan dependency sebelum setiap pengujian.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        Storage::fake('public');
    }

    /**
     * Memverifikasi aturan ketersediaan produk sepanjang katalog, cart, dan checkout pada skenario
     * seller must verify the store location before creating a product.
     *
     * Test membangun state seller, produk, stok, alamat, dan cart yang diperlukan, menjalankan
     * endpoint terkait, lalu memastikan alasan penolakan serta read-repair tetap konsisten.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function seller_must_verify_the_store_location_before_creating_a_product(): void
    {
        $seller = User::factory()->create();
        $this->actingAs($seller);

        $this->post('/api/product', $this->productPayload($seller))
            ->assertConflict()
            ->assertJsonPath('code', ProductAvailabilityService::SELLER_LOCATION_UNVERIFIED);

        $this->createVerifiedSellerAddress($seller);

        $this->post('/api/product', $this->productPayload($seller))
            ->assertOk()
            ->assertJsonPath('product.user_id_seller', $seller->id);
    }

    /**
     * Memverifikasi aturan ketersediaan produk sepanjang katalog, cart, dan checkout pada skenario
     * buyer catalog excludes unverified and soft deleted products.
     *
     * Test membangun state seller, produk, stok, alamat, dan cart yang diperlukan, menjalankan
     * endpoint terkait, lalu memastikan alasan penolakan serta read-repair tetap konsisten.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function buyer_catalog_excludes_unverified_and_soft_deleted_products(): void
    {
        $buyer = User::factory()->create();
        $verifiedSeller = User::factory()->create();
        $unverifiedSeller = User::factory()->create();
        $this->createVerifiedSellerAddress($verifiedSeller);

        $available = $this->createProduct($verifiedSeller, 'Tersedia', 3);
        $deleted = $this->createProduct($verifiedSeller, 'Dihapus', 3);
        $this->createProduct($unverifiedSeller, 'Tanpa Lokasi', 3);
        $deleted->delete();

        $this->actingAs($buyer)
            ->getJson('/api/belanja?'.http_build_query([
                'products_current_id' => json_encode([]),
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.p_id', $available->id);
    }

    /**
     * Memverifikasi aturan ketersediaan produk sepanjang katalog, cart, dan checkout pada skenario
     * cart keeps quantity and explains a soft deleted product.
     *
     * Test membangun state seller, produk, stok, alamat, dan cart yang diperlukan, menjalankan
     * endpoint terkait, lalu memastikan alasan penolakan serta read-repair tetap konsisten.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function cart_keeps_quantity_and_explains_a_soft_deleted_product(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $this->createVerifiedSellerAddress($seller);
        $product = $this->createProduct($seller, 'Produk Lama', 10);
        $cart = $this->createCart($buyer, $seller, $product, 5);
        $product->delete();

        $state = $this->app->make(KeranjangService::class)->getKeranjangs($buyer->id);
        $item = collect($state['keranjangs'])->flatten(1)->first();

        $this->assertSame(ProductAvailabilityService::PRODUCT_DELETED, $item['unavailable_reason']);
        $this->assertFalse($item['is_purchasable']);
        $this->assertSame(5, $item['k_total']);
        $this->assertSame('Produk Lama', $item['p_name']);
        $this->assertDatabaseHas('keranjangs', [
            'id' => $cart->id,
            'checked' => 0,
            'checkout' => 0,
            'total' => 5,
        ]);
    }

    /**
     * Memverifikasi aturan ketersediaan produk sepanjang katalog, cart, dan checkout pada skenario
     * quantity endpoints reject unavailable products without changing quantity.
     *
     * Test membangun state seller, produk, stok, alamat, dan cart yang diperlukan, menjalankan
     * endpoint terkait, lalu memastikan alasan penolakan serta read-repair tetap konsisten.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function quantity_endpoints_reject_unavailable_products_without_changing_quantity(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $this->createVerifiedSellerAddress($seller);
        $product = $this->createProduct($seller, 'Tidak Bisa Diubah', 10);
        $cart = $this->createCart($buyer, $seller, $product, 5);
        $product->delete();

        $payloads = [
            '/api/keranjang/total/plus' => [],
            '/api/keranjang/total/minus' => [],
            '/api/keranjang/total/change' => ['total' => 2],
        ];

        foreach ($payloads as $endpoint => $extraPayload) {
            $this->actingAs($buyer)
                ->postJson($endpoint, [
                    'user_id_buyer' => $buyer->id,
                    'product_id' => $product->id,
                    ...$extraPayload,
                ])
                ->assertConflict()
                ->assertJsonPath('code', ProductAvailabilityService::PRODUCT_DELETED);

            $this->assertDatabaseHas('keranjangs', [
                'id' => $cart->id,
                'total' => 5,
            ]);
        }
    }

    /**
     * Memverifikasi aturan ketersediaan produk sepanjang katalog, cart, dan checkout pada skenario
     * quantity endpoints reject out of stock products without changing quantity.
     *
     * Test membangun state seller, produk, stok, alamat, dan cart yang diperlukan, menjalankan
     * endpoint terkait, lalu memastikan alasan penolakan serta read-repair tetap konsisten.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function quantity_endpoints_reject_out_of_stock_products_without_changing_quantity(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $this->createVerifiedSellerAddress($seller);
        $product = $this->createProduct($seller, 'Stok Nol Tidak Bisa Diubah', 5);
        $cart = $this->createCart($buyer, $seller, $product, 4);
        $product->update(['stock' => 0]);

        $payloads = [
            '/api/keranjang/total/plus' => [],
            '/api/keranjang/total/minus' => [],
            '/api/keranjang/total/change' => ['total' => 2],
        ];

        foreach ($payloads as $endpoint => $extraPayload) {
            $this->actingAs($buyer)
                ->postJson($endpoint, [
                    'user_id_buyer' => $buyer->id,
                    'product_id' => $product->id,
                    ...$extraPayload,
                ])
                ->assertConflict()
                ->assertJsonPath('code', ProductAvailabilityService::OUT_OF_STOCK)
                ->assertJsonPath('totalPrice', 0);

            $this->assertDatabaseHas('keranjangs', [
                'id' => $cart->id,
                'checked' => 0,
                'checkout' => 0,
                'total' => 4,
            ]);
        }
    }

    /**
     * Memverifikasi aturan ketersediaan produk sepanjang katalog, cart, dan checkout pada skenario
     * cart read repairs injected out of stock selection without resetting quantity.
     *
     * Test membangun state seller, produk, stok, alamat, dan cart yang diperlukan, menjalankan
     * endpoint terkait, lalu memastikan alasan penolakan serta read-repair tetap konsisten.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function cart_read_repairs_injected_out_of_stock_selection_without_resetting_quantity(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $this->createVerifiedSellerAddress($seller);
        $product = $this->createProduct($seller, 'Read Repair Stok Nol', 5);
        $cart = $this->createCart($buyer, $seller, $product, 2);
        $product->update(['stock' => 0]);

        $this->actingAs($buyer)
            ->getJson("/api/keranjang/{$buyer->id}")
            ->assertOk()
            ->assertJsonPath("keranjangs.{$seller->id}.0.k_checked", 0)
            ->assertJsonPath("keranjangs.{$seller->id}.0.k_checkout", 0)
            ->assertJsonPath("keranjangs.{$seller->id}.0.k_total", 2)
            ->assertJsonPath(
                "keranjangs.{$seller->id}.0.unavailable_reason",
                ProductAvailabilityService::OUT_OF_STOCK,
            )
            ->assertJsonPath('totalPrice', 0);

        $this->assertDatabaseHas('keranjangs', [
            'id' => $cart->id,
            'checked' => 0,
            'checkout' => 0,
            'total' => 2,
        ]);
    }

    /**
     * Memverifikasi aturan ketersediaan produk sepanjang katalog, cart, dan checkout pada skenario
     * cart read exposes and repairs a quantity that exceeds positive stock.
     *
     * Test membangun state seller, produk, stok, alamat, dan cart yang diperlukan, menjalankan
     * endpoint terkait, lalu memastikan alasan penolakan serta read-repair tetap konsisten.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function cart_read_exposes_and_repairs_a_quantity_that_exceeds_positive_stock(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $this->createVerifiedSellerAddress($seller);
        $product = $this->createProduct($seller, 'Quantity Melebihi Stok', 5);
        $cart = $this->createCart($buyer, $seller, $product, 3);
        $product->update(['stock' => 1]);

        $this->actingAs($buyer)
            ->getJson("/api/keranjang/{$buyer->id}")
            ->assertOk()
            ->assertJsonPath("keranjangs.{$seller->id}.0.k_checked", 0)
            ->assertJsonPath("keranjangs.{$seller->id}.0.k_checkout", 0)
            ->assertJsonPath("keranjangs.{$seller->id}.0.k_total", 3)
            ->assertJsonPath("keranjangs.{$seller->id}.0.is_purchasable", true)
            ->assertJsonPath("keranjangs.{$seller->id}.0.is_selectable", false)
            ->assertJsonPath(
                "keranjangs.{$seller->id}.0.stock_issue.code",
                'QUANTITY_EXCEEDS_STOCK',
            )
            ->assertJsonPath("keranjangs.{$seller->id}.0.stock_issue.cart_quantity", 3)
            ->assertJsonPath("keranjangs.{$seller->id}.0.stock_issue.available_stock", 1)
            ->assertJsonPath('totalPrice', 0);

        $this->assertDatabaseHas('keranjangs', [
            'id' => $cart->id,
            'checked' => 0,
            'checkout' => 0,
            'total' => 3,
        ]);
    }

    /**
     * Memverifikasi aturan ketersediaan produk sepanjang katalog, cart, dan checkout pada skenario
     * cart endpoints reject an authenticated user targeting another buyers cart.
     *
     * Test membangun state seller, produk, stok, alamat, dan cart yang diperlukan, menjalankan
     * endpoint terkait, lalu memastikan alasan penolakan serta read-repair tetap konsisten.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function cart_endpoints_reject_an_authenticated_user_targeting_another_buyers_cart(): void
    {
        // --- step 1 - start - siapkan cart korban dan user penyerang terautentikasi
        $buyer = User::factory()->create();
        $attacker = User::factory()->create();
        $seller = User::factory()->create();
        $this->createVerifiedSellerAddress($seller);
        $product = $this->createProduct($seller, 'Cart Milik Buyer Lain', 8);
        $cart = $this->createCart($buyer, $seller, $product, 2);
        // --- step 1 - end - siapkan cart korban dan user penyerang terautentikasi

        // --- step 2 - start - coba seluruh endpoint cart menggunakan id korban
        $requests = [
            ['GET', "/api/keranjang/{$buyer->id}", []],
            ['POST', '/api/keranjang', [
                'user_id_seller' => $seller->id,
                'user_id_buyer' => $buyer->id,
                'product_id' => $product->id,
            ]],
            ['DELETE', "/api/keranjang/{$buyer->id}/{$product->id}", []],
            ['POST', '/api/keranjang/checked', [
                'user_id_buyer' => $buyer->id,
                'product_id' => $product->id,
                'checked' => false,
            ]],
            ['POST', '/api/keranjang/checked/group', [
                'user_id_buyer' => $buyer->id,
                'user_id_seller' => $seller->id,
                'checked' => false,
            ]],
            ['POST', '/api/keranjang/checked/all', [
                'user_id_buyer' => $buyer->id,
                'checked' => false,
            ]],
            ['POST', '/api/keranjang/total/plus', [
                'user_id_buyer' => $buyer->id,
                'product_id' => $product->id,
            ]],
            ['POST', '/api/keranjang/total/minus', [
                'user_id_buyer' => $buyer->id,
                'product_id' => $product->id,
            ]],
            ['POST', '/api/keranjang/total/change', [
                'user_id_buyer' => $buyer->id,
                'product_id' => $product->id,
                'total' => 3,
            ]],
            ['POST', '/api/keranjang/validate/checkout', [
                'user_id_buyer' => $buyer->id,
                'product_ids' => [$product->id],
            ]],
        ];

        $this->actingAs($attacker);
        foreach ($requests as [$method, $uri, $payload]) {
            $this->json($method, $uri, $payload)
                ->assertForbidden()
                ->assertJsonPath('code', 'CART_FORBIDDEN');
        }
        // --- step 2 - end - coba seluruh endpoint cart menggunakan id korban

        // --- step 3 - start - pastikan tidak ada state korban yang berubah
        $this->assertDatabaseCount('keranjangs', 1);
        $this->assertDatabaseHas('keranjangs', [
            'id' => $cart->id,
            'user_id_buyer' => $buyer->id,
            'checked' => 1,
            'checkout' => 1,
            'total' => 2,
        ]);
        $this->assertDatabaseMissing('keranjangs', [
            'user_id_buyer' => $attacker->id,
        ]);
        // --- step 3 - end - pastikan tidak ada state korban yang berubah
    }

    /**
     * Memverifikasi aturan ketersediaan produk sepanjang katalog, cart, dan checkout pada skenario
     * cart uses out of stock before unverified seller location.
     *
     * Test membangun state seller, produk, stok, alamat, dan cart yang diperlukan, menjalankan
     * endpoint terkait, lalu memastikan alasan penolakan serta read-repair tetap konsisten.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function cart_uses_out_of_stock_before_unverified_seller_location(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $product = $this->createProduct($seller, 'Stok Habis', 0);
        $this->createCart($buyer, $seller, $product, 4);

        $state = $this->app->make(KeranjangService::class)->getKeranjangs($buyer->id);
        $item = collect($state['keranjangs'])->flatten(1)->first();

        $this->assertSame(ProductAvailabilityService::OUT_OF_STOCK, $item['unavailable_reason']);
        $this->assertSame(4, $item['k_total']);
        $this->assertSame(0, $state['totalPrice']);
    }

    /**
     * Memverifikasi aturan ketersediaan produk sepanjang katalog, cart, dan checkout pada skenario
     * cart marks stocked products from an unverified seller.
     *
     * Test membangun state seller, produk, stok, alamat, dan cart yang diperlukan, menjalankan
     * endpoint terkait, lalu memastikan alasan penolakan serta read-repair tetap konsisten.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function cart_marks_stocked_products_from_an_unverified_seller(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $product = $this->createProduct($seller, 'Belum Siap Dikirim', 7);
        $this->createCart($buyer, $seller, $product, 2);

        $state = $this->app->make(KeranjangService::class)->getKeranjangs($buyer->id);
        $item = collect($state['keranjangs'])->flatten(1)->first();

        $this->assertSame(
            ProductAvailabilityService::SELLER_LOCATION_UNVERIFIED,
            $item['unavailable_reason'],
        );
        $this->assertFalse($item['is_purchasable']);
        $this->assertSame(2, $item['k_total']);
    }

    /**
     * Memverifikasi aturan ketersediaan produk sepanjang katalog, cart, dan checkout pada skenario
     * cart checkout validation reports a concurrently unverified seller.
     *
     * Test membangun state seller, produk, stok, alamat, dan cart yang diperlukan, menjalankan
     * endpoint terkait, lalu memastikan alasan penolakan serta read-repair tetap konsisten.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function cart_checkout_validation_reports_a_concurrently_unverified_seller(): void
    {
        // --- step 1 - start - siapkan pilihan cart yang awalnya valid
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $this->createBuyerAddress($buyer);
        $this->createVerifiedSellerAddress($seller);
        $product = $this->createProduct($seller, 'Seller Berubah Sebelum Checkout', 5);
        $cart = $this->createCart($buyer, $seller, $product, 1);
        $cart->update(['checkout' => 0]);
        // --- step 1 - end - siapkan pilihan cart yang awalnya valid

        // --- step 2 - start - hilangkan verifikasi seller lalu validasi dari cart
        $this->invalidateSellerAddress($seller);

        $this->actingAs($buyer)
            ->postJson('/api/keranjang/validate/checkout', [
                'user_id_buyer' => $buyer->id,
                'product_ids' => [$product->id],
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'SELLER_ADDRESS_REQUIRES_VERIFICATION')
            ->assertJsonPath(
                "keranjangs.{$seller->id}.0.unavailable_reason",
                ProductAvailabilityService::SELLER_LOCATION_UNVERIFIED,
            );
        // --- step 2 - end - hilangkan verifikasi seller lalu validasi dari cart

        $this->assertDatabaseHas('keranjangs', [
            'id' => $cart->id,
            'checked' => 0,
            'checkout' => 0,
            'total' => 1,
        ]);
    }

    /**
     * Memverifikasi aturan ketersediaan produk sepanjang katalog, cart, dan checkout pada skenario
     * refreshing checkout reports a concurrently unverified seller.
     *
     * Test membangun state seller, produk, stok, alamat, dan cart yang diperlukan, menjalankan
     * endpoint terkait, lalu memastikan alasan penolakan serta read-repair tetap konsisten.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function refreshing_checkout_reports_a_concurrently_unverified_seller(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $this->createBuyerAddress($buyer);
        $this->createVerifiedSellerAddress($seller);
        $product = $this->createProduct($seller, 'Seller Berubah Saat Checkout Dimuat', 5);
        $cart = $this->createCart($buyer, $seller, $product, 1);

        $this->invalidateSellerAddress($seller);

        $this->actingAs($buyer)
            ->getJson('/api/checkout/data')
            ->assertConflict()
            ->assertJson([
                'status' => 'error',
                'code' => 'SELLER_ADDRESS_REQUIRES_VERIFICATION',
                'message' => 'Lokasi toko penjual belum diverifikasi. Checkout belum dapat dilanjutkan.',
            ]);

        $this->assertDatabaseHas('keranjangs', [
            'id' => $cart->id,
            'checked' => 0,
            'checkout' => 0,
            'total' => 1,
        ]);
    }

    /**
     * Memverifikasi aturan ketersediaan produk sepanjang katalog, cart, dan checkout pada skenario
     * processing checkout reports a concurrently unverified seller before payment.
     *
     * Test membangun state seller, produk, stok, alamat, dan cart yang diperlukan, menjalankan
     * endpoint terkait, lalu memastikan alasan penolakan serta read-repair tetap konsisten.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function processing_checkout_reports_a_concurrently_unverified_seller_before_payment(): void
    {
        // --- step 1 - start - siapkan checkout yang sudah terbuka saat seller masih valid
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $this->createBuyerAddress($buyer);
        $this->createVerifiedSellerAddress($seller);
        $product = $this->createProduct($seller, 'Seller Berubah Sebelum Pembayaran', 5);
        $cart = $this->createCart($buyer, $seller, $product, 1);
        // --- step 1 - end - siapkan checkout yang sudah terbuka saat seller masih valid

        // --- step 2 - start - hilangkan verifikasi seller tepat sebelum pembayaran
        $this->invalidateSellerAddress($seller);

        $this->actingAs($buyer)
            ->postJson('/api/checkout/process', [
                'payment_slug' => 'bca',
                'shipping_options' => [[
                    'user_id_seller' => $seller->id,
                    'kurir_name' => 'JNT',
                ]],
                'noteds' => [[
                    'user_id_seller' => $seller->id,
                    'noted' => '',
                ]],
                'client_snapshot' => [
                    'alamat_id' => 'stale-address-id',
                    'alamat_updated_at' => '2026-07-30T00:00:00.000000Z',
                    'cart_item_ids' => [$cart->id],
                    'total_product' => 10000,
                    'total_shipping' => 15000,
                    'total_all' => 25000,
                ],
            ])
            ->assertConflict()
            ->assertJson([
                'status' => 'error',
                'code' => 'SELLER_ADDRESS_REQUIRES_VERIFICATION',
                'message' => 'Lokasi toko penjual belum diverifikasi. Checkout belum dapat dilanjutkan.',
            ]);
        // --- step 2 - end - hilangkan verifikasi seller tepat sebelum pembayaran

        $this->assertDatabaseCount('transaction_invoices', 0);
        $this->assertDatabaseHas('keranjangs', [
            'id' => $cart->id,
            'checked' => 0,
            'checkout' => 0,
            'total' => 1,
        ]);
    }

    /**
     * Memverifikasi aturan ketersediaan produk sepanjang katalog, cart, dan checkout pada skenario
     * cart checkout validation unchecks an item without resetting quantity.
     *
     * Test membangun state seller, produk, stok, alamat, dan cart yang diperlukan, menjalankan
     * endpoint terkait, lalu memastikan alasan penolakan serta read-repair tetap konsisten.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function cart_checkout_validation_unchecks_an_item_without_resetting_quantity(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $this->createBuyerAddress($buyer);
        $this->createVerifiedSellerAddress($seller);
        $product = $this->createProduct($seller, 'Berubah Saat Dipilih', 5);
        $cart = $this->createCart($buyer, $seller, $product, 3);
        $cart->checkout = 0;
        $cart->save();
        $product->stock = 0;
        $product->save();

        $this->actingAs($buyer)
            ->postJson('/api/keranjang/validate/checkout', [
                'user_id_buyer' => $buyer->id,
                'product_ids' => [$product->id],
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'CART_STOCK_CHANGED')
            ->assertJsonPath('issues.0.code', ProductAvailabilityService::OUT_OF_STOCK)
            ->assertJsonPath('issues.0.cart_quantity', 3)
            ->assertJsonPath('issues.0.available_stock', 0)
            ->assertJsonPath('totalPrice', 0);

        $this->assertDatabaseHas('keranjangs', [
            'id' => $cart->id,
            'checked' => 0,
            'total' => 3,
        ]);
    }

    /**
     * Memverifikasi aturan ketersediaan produk sepanjang katalog, cart, dan checkout pada skenario
     * stock change blocks the first checkout but preserves valid multi seller items.
     *
     * Test membangun state seller, produk, stok, alamat, dan cart yang diperlukan, menjalankan
     * endpoint terkait, lalu memastikan alasan penolakan serta read-repair tetap konsisten.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function stock_change_blocks_the_first_checkout_but_preserves_valid_multi_seller_items(): void
    {
        // --- step 1 - start - siapkan dua seller dengan satu item berubah dan satu item valid
        $buyer = User::factory()->create();
        $changedSeller = User::factory()->create();
        $validSeller = User::factory()->create();
        $this->createBuyerAddress($buyer);
        $this->createVerifiedSellerAddress($changedSeller);
        $this->createVerifiedSellerAddress($validSeller);
        Company::create([
            'user_id' => $changedSeller->id,
            'name' => 'Toko Stok Dinamis',
        ]);

        $changedProduct = $this->createProduct($changedSeller, 'Stok Berubah', 5);
        $validProduct = $this->createProduct($validSeller, 'Tetap Valid', 5);
        $changedCart = $this->createCart($buyer, $changedSeller, $changedProduct, 3);
        $validCart = $this->createCart($buyer, $validSeller, $validProduct, 2);
        $changedCart->update(['checkout' => 0]);
        $validCart->update(['checkout' => 0]);
        $changedProduct->update(['stock' => 1]);
        // --- step 1 - end - siapkan dua seller dengan satu item berubah dan satu item valid

        // --- step 2 - start - checkout pertama dibatalkan dengan detail masalah stok
        $this->actingAs($buyer)
            ->postJson('/api/keranjang/validate/checkout', [
                'user_id_buyer' => $buyer->id,
                'product_ids' => [$changedProduct->id, $validProduct->id],
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'CART_STOCK_CHANGED')
            ->assertJsonPath('issues.0.code', 'QUANTITY_EXCEEDS_STOCK')
            ->assertJsonPath('issues.0.cart_id', $changedCart->id)
            ->assertJsonPath('issues.0.product_id', $changedProduct->id)
            ->assertJsonPath('issues.0.product_name', 'Stok Berubah')
            ->assertJsonPath('issues.0.seller_id', $changedSeller->id)
            ->assertJsonPath('issues.0.seller_name', 'Toko Stok Dinamis')
            ->assertJsonPath('issues.0.cart_quantity', 3)
            ->assertJsonPath('issues.0.available_stock', 1)
            ->assertJsonPath("keranjangs.{$changedSeller->id}.0.is_selectable", false)
            ->assertJsonPath("keranjangs.{$changedSeller->id}.0.u_seller_name", 'Toko Stok Dinamis')
            ->assertJsonPath("keranjangs.{$validSeller->id}.0.k_checked", 1)
            ->assertJsonPath('totalPrice', 20000);

        $this->assertDatabaseHas('keranjangs', [
            'id' => $changedCart->id,
            'checked' => 0,
            'checkout' => 0,
            'total' => 3,
        ]);
        $this->assertDatabaseHas('keranjangs', [
            'id' => $validCart->id,
            'checked' => 1,
            'checkout' => 0,
            'total' => 2,
        ]);
        // --- step 2 - end - checkout pertama dibatalkan dengan detail masalah stok

        // --- step 3 - start - checkout kedua hanya melanjutkan item yang tetap valid
        $this->postJson('/api/keranjang/validate/checkout', [
            'user_id_buyer' => $buyer->id,
            'product_ids' => [$validProduct->id],
        ])->assertOk();

        $this->assertDatabaseHas('keranjangs', [
            'id' => $changedCart->id,
            'checked' => 0,
            'checkout' => 0,
            'total' => 3,
        ]);
        $this->assertDatabaseHas('keranjangs', [
            'id' => $validCart->id,
            'checked' => 1,
            'checkout' => 1,
            'total' => 2,
        ]);
        // --- step 3 - end - checkout kedua hanya melanjutkan item yang tetap valid
    }

    /**
     * Memverifikasi aturan ketersediaan produk sepanjang katalog, cart, dan checkout pada skenario
     * refreshing checkout rejects a soft deleted product and preserves the cart.
     *
     * Test membangun state seller, produk, stok, alamat, dan cart yang diperlukan, menjalankan
     * endpoint terkait, lalu memastikan alasan penolakan serta read-repair tetap konsisten.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function refreshing_checkout_rejects_a_soft_deleted_product_and_preserves_the_cart(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $this->createBuyerAddress($buyer);
        $this->createVerifiedSellerAddress($seller);
        $product = $this->createProduct($seller, 'Dihapus Saat Checkout', 5);
        $cart = $this->createCart($buyer, $seller, $product, 2);
        $product->delete();

        $this->actingAs($buyer)
            ->getJson('/api/checkout/data')
            ->assertConflict()
            ->assertJsonPath('code', 'CHECKOUT_INVALID');

        $this->assertDatabaseHas('keranjangs', [
            'id' => $cart->id,
            'checked' => 0,
            'checkout' => 0,
            'total' => 2,
        ]);
    }

    /**
     * Menyusun payload produk valid untuk seller dan menggabungkan override stok atau field lain.
     * Helper mempertahankan kontrak gambar yang diperlukan endpoint create.
     *
     * @param  User  $seller  Model user seller yang menjadi actor atau fixture.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    private function productPayload(User $seller): array
    {
        return [
            'user_id_seller' => $seller->id,
            'name' => 'Produk Baru',
            'price' => 10000,
            'stock' => 3,
            'images' => [UploadedFile::fake()->image('product.jpg')],
            'image_order' => ['new:0'],
        ];
    }

    /**
     * Membuat produk fixture secara langsung untuk seller dengan stok dan attribute yang dapat
     * dioverride. Cara ini memungkinkan test menyiapkan state yang sulit dicapai melalui endpoint.
     *
     * @param  User  $seller  Model user seller yang menjadi actor atau fixture.
     * @param  string  $name  Nama user, rekening, atau resource sesuai konteks operasi.
     * @param  int  $stock  Jumlah stok produk untuk skenario atau perubahan terkait.
     *
     * @return Product  Model produk yang dibuat atau digunakan sebagai fixture.
     */
    private function createProduct(User $seller, string $name, int $stock): Product
    {
        return Product::create([
            'user_id_seller' => $seller->id,
            'img' => 'product-imgs/test.jpg',
            'name' => $name,
            'price' => 10000,
            'stock' => $stock,
        ]);
    }

    /**
     * Membuat item cart buyer terhadap produk tertentu dengan quantity dan flag pilihan terkontrol.
     * Fixture digunakan untuk menguji read-repair serta validasi checkout.
     *
     * @param  User  $buyer  Model user buyer yang menjadi actor atau fixture.
     * @param  User  $seller  Model user seller yang menjadi actor atau fixture.
     * @param  Product  $product  Model produk yang menjadi target atau sumber data.
     * @param  int  $quantity  Quantity produk yang digunakan pada cart atau checkout.
     *
     * @return Keranjang  Model item keranjang yang dibuat untuk skenario terkait.
     */
    private function createCart(User $buyer, User $seller, Product $product, int $quantity): Keranjang
    {
        return Keranjang::create([
            'user_id_buyer' => $buyer->id,
            'user_id_seller' => $seller->id,
            'product_id' => $product->id,
            'checked' => 1,
            'checkout' => 1,
            'total' => $quantity,
        ]);
    }

    /**
     * Membuat alamat seller aktif dengan metadata pinpoint lengkap agar produknya dianggap dapat
     * dibeli. Helper tidak memanggil provider eksternal.
     *
     * @param  User  $seller  Model user seller yang menjadi actor atau fixture.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    private function createVerifiedSellerAddress(User $seller): void
    {
        Alamat::create([
            'user_id' => $seller->id,
            'type' => 'seller',
            'place' => 'Toko',
            'alamat' => 'Blok A, Jakarta, Indonesia',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'formatted_address' => 'Jakarta, Indonesia',
            'address_detail' => 'Blok A',
            'location_source' => 'map',
            'enable' => 1,
        ]);
    }

    /**
     * Mengubah alamat seller yang semula valid menjadi tidak memenuhi invariant pinpoint. Mutasi ini
     * mensimulasikan perubahan lokasi bersamaan setelah produk masuk cart.
     *
     * @param  User  $seller  Model user seller yang menjadi actor atau fixture.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    private function invalidateSellerAddress(User $seller): void
    {
        Alamat::where('user_id', $seller->id)
            ->where('type', 'seller')
            ->where('enable', 1)
            ->update([
                'latitude' => null,
                'longitude' => null,
                'geoapify_place_id' => null,
                'formatted_address' => null,
                'address_detail' => null,
                'location_source' => 'manual',
            ]);
    }

    /**
     * Membuat alamat buyer aktif dan terverifikasi untuk memenuhi prasyarat checkout. Metadata dibuat
     * deterministik agar snapshot alamat dapat diuji.
     *
     * @param  User  $buyer  Model user buyer yang menjadi actor atau fixture.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    private function createBuyerAddress(User $buyer): void
    {
        Alamat::create([
            'user_id' => $buyer->id,
            'type' => 'buyer',
            'place' => 'Rumah',
            'alamat' => 'Blok B, Jakarta, Indonesia',
            'latitude' => -6.21,
            'longitude' => 106.81,
            'formatted_address' => 'Jakarta, Indonesia',
            'address_detail' => 'Blok B',
            'location_source' => 'map',
            'enable' => 1,
        ]);
    }
}
