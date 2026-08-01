<?php

namespace Tests\Feature;

use App\Exceptions\CheckoutChangedException;
use App\Models\Alamat;
use App\Models\Company;
use App\Models\Keranjang;
use App\Models\Product;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected CheckoutService $checkoutService;

    /**
     * Menyiapkan fixture dan dependency sebelum setiap pengujian.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->checkoutService = $this->app->make(CheckoutService::class);
    }

    /**
     * Memverifikasi satu.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_satu(): void
    {
        $this->checkoutService->getKeranjangCheckout('00000000-0000-0000-0000-000000000002');
        $this->assertTrue(true);
    }

    /**
     * Memverifikasi aturan checkout buyer pada skenario checkout groups use the store name instead of
     * the seller account name.
     *
     * Test menyiapkan buyer, alamat, cart, dan pembayaran yang relevan, menjalankan endpoint checkout,
     * lalu memastikan validasi dan mutasi hanya berlaku pada data milik actor tersebut.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function checkout_groups_use_the_store_name_instead_of_the_seller_account_name(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create(['name' => 'Nama Akun Seller']);
        Company::create([
            'user_id' => $seller->id,
            'name' => 'SpaceX',
        ]);
        $product = Product::create([
            'user_id_seller' => $seller->id,
            'img' => 'product-imgs/store-name-checkout.jpg',
            'name' => 'Roket Mini',
            'price' => 10000,
            'stock' => 5,
        ]);
        Keranjang::create([
            'user_id_buyer' => $buyer->id,
            'user_id_seller' => $seller->id,
            'product_id' => $product->id,
            'checked' => 1,
            'checkout' => 1,
            'total' => 1,
        ]);

        $checkout = $this->checkoutService->getKeranjangCheckout($buyer->id);

        $this->assertSame('SpaceX', $checkout['checkouts'][0]['user_name_seller']);
    }

    /**
     * Memverifikasi aturan checkout buyer pada skenario checkout validation rejects buyer without
     * enabled address without updating checkout rows.
     *
     * Test menyiapkan buyer, alamat, cart, dan pembayaran yang relevan, menjalankan endpoint checkout,
     * lalu memastikan validasi dan mutasi hanya berlaku pada data milik actor tersebut.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function checkout_validation_rejects_buyer_without_enabled_address_without_updating_checkout_rows(): void
    {
        $this->withoutMiddleware();

        $buyer = User::factory()->create();
        $seller = User::factory()->create();
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
        $product = Product::create([
            'user_id_seller' => $seller->id,
            'img' => 'product-imgs/checkout-address-test.jpg',
            'name' => 'Produk Checkout Tanpa Alamat',
            'price' => 10000,
            'stock' => 5,
        ]);
        $cart = Keranjang::create([
            'user_id_buyer' => $buyer->id,
            'user_id_seller' => $seller->id,
            'product_id' => $product->id,
            'checked' => 1,
            'checkout' => 0,
            'total' => 1,
        ]);

        $this->actingAs($buyer)
            ->postJson('/api/keranjang/validate/checkout', [
                'user_id_buyer' => $buyer->id,
                'product_ids' => [$product->id],
            ])
            ->assertStatus(400)
            ->assertJson([
                'status' => 'error',
                'code' => 'BUYER_ADDRESS_REQUIRED',
                'message' => 'Tambahkan alamat pengiriman sebelum melanjutkan checkout.',
            ]);

        $this->assertDatabaseMissing('alamats', [
            'user_id' => $buyer->id,
            'enable' => 1,
        ]);
        $this->assertDatabaseHas('keranjangs', [
            'id' => $cart->id,
            'checked' => 1,
            'checkout' => 0,
            'total' => 1,
        ]);
    }

    /**
     * Memverifikasi aturan checkout buyer pada skenario checkout data rejects buyer without checkout
     * rows before loading payment methods.
     *
     * Test menyiapkan buyer, alamat, cart, dan pembayaran yang relevan, menjalankan endpoint checkout,
     * lalu memastikan validasi dan mutasi hanya berlaku pada data milik actor tersebut.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function checkout_data_rejects_buyer_without_checkout_rows_before_loading_payment_methods(): void
    {
        $this->withoutMiddleware();

        $buyer = User::factory()->create();
        Alamat::create([
            'user_id' => $buyer->id,
            'type' => 'buyer',
            'place' => 'Rumah',
            'alamat' => 'Blok B, Jakarta, Indonesia',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'formatted_address' => 'Jakarta, Indonesia',
            'address_detail' => 'Blok B',
            'location_source' => 'map',
            'enable' => 1,
        ]);

        // Payment methods must not be queried when no cart row is eligible for checkout.
        $this->mock(PaymentService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('getCheckoutPayment');
        });

        $this->actingAs($buyer)
            ->getJson('/api/checkout/data')
            ->assertConflict()
            ->assertJson([
                'status' => 'error',
                'code' => 'CHECKOUT_INVALID',
                'message' => 'Keranjang Not Checked',
            ]);

        $this->assertDatabaseMissing('keranjangs', [
            'user_id_buyer' => $buyer->id,
            'checkout' => 1,
        ]);
    }

    /**
     * Memverifikasi aturan checkout buyer pada skenario checkout validation marks only matching
     * checked rows for the authenticated buyer.
     *
     * Test menyiapkan buyer, alamat, cart, dan pembayaran yang relevan, menjalankan endpoint checkout,
     * lalu memastikan validasi dan mutasi hanya berlaku pada data milik actor tersebut.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function checkout_validation_marks_only_matching_checked_rows_for_the_authenticated_buyer(): void
    {
        $this->withoutMiddleware();

        // --- step 1 - start - siapkan buyer, seller, dan alamat terverifikasi
        $buyer = User::factory()->create();
        $otherBuyer = User::factory()->create();
        $seller = User::factory()->create();
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
        // --- step 1 - end - siapkan buyer, seller, dan alamat terverifikasi

        // --- step 2 - start - siapkan cart terpilih, tidak terpilih, dan milik buyer lain
        $selectedProduct = Product::create([
            'user_id_seller' => $seller->id,
            'img' => 'product-imgs/selected-checkout-test.jpg',
            'name' => 'Produk Terpilih Pertama',
            'price' => 10000,
            'stock' => 5,
        ]);
        $secondSelectedProduct = Product::create([
            'user_id_seller' => $seller->id,
            'img' => 'product-imgs/second-selected-checkout-test.jpg',
            'name' => 'Produk Terpilih Kedua',
            'price' => 15000,
            'stock' => 4,
        ]);
        $unselectedProduct = Product::create([
            'user_id_seller' => $seller->id,
            'img' => 'product-imgs/unselected-checkout-test.jpg',
            'name' => 'Produk Tidak Terpilih',
            'price' => 20000,
            'stock' => 3,
        ]);
        $selectedCart = Keranjang::create([
            'user_id_buyer' => $buyer->id,
            'user_id_seller' => $seller->id,
            'product_id' => $selectedProduct->id,
            'checked' => 1,
            'checkout' => 0,
            'total' => 2,
        ]);
        $secondSelectedCart = Keranjang::create([
            'user_id_buyer' => $buyer->id,
            'user_id_seller' => $seller->id,
            'product_id' => $secondSelectedProduct->id,
            'checked' => 1,
            'checkout' => 0,
            'total' => 1,
        ]);
        $unselectedCart = Keranjang::create([
            'user_id_buyer' => $buyer->id,
            'user_id_seller' => $seller->id,
            'product_id' => $unselectedProduct->id,
            'checked' => 0,
            'checkout' => 1,
            'total' => 1,
        ]);
        $otherBuyerCart = Keranjang::create([
            'user_id_buyer' => $otherBuyer->id,
            'user_id_seller' => $seller->id,
            'product_id' => $selectedProduct->id,
            'checked' => 1,
            'checkout' => 0,
            'total' => 1,
        ]);
        // --- step 2 - end - siapkan cart terpilih, tidak terpilih, dan milik buyer lain

        // --- step 3 - start - validasi checkout dan pastikan update hanya mengenai cart yang tepat
        $this->actingAs($buyer)
            ->postJson('/api/keranjang/validate/checkout', [
                'user_id_buyer' => $buyer->id,
                'product_ids' => [$secondSelectedProduct->id, $selectedProduct->id],
            ])
            ->assertOk()
            ->assertJson([
                'status' => 'success',
                'message' => 'Checkout validation successful',
            ]);

        $this->assertDatabaseHas('keranjangs', [
            'id' => $selectedCart->id,
            'checked' => 1,
            'checkout' => 1,
            'total' => 2,
        ]);
        $this->assertDatabaseHas('keranjangs', [
            'id' => $secondSelectedCart->id,
            'checked' => 1,
            'checkout' => 1,
            'total' => 1,
        ]);
        $this->assertDatabaseHas('keranjangs', [
            'id' => $unselectedCart->id,
            'checked' => 0,
            'checkout' => 0,
            'total' => 1,
        ]);
        $this->assertDatabaseHas('keranjangs', [
            'id' => $otherBuyerCart->id,
            'checked' => 1,
            'checkout' => 0,
            'total' => 1,
        ]);
        // --- step 3 - end - validasi checkout dan pastikan update hanya mengenai cart yang tepat
    }

    /**
     * Memastikan perubahan quantity setelah snapshot dibuat membatalkan checkout sebelum pembayaran.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function locked_checkout_rejects_a_quantity_change_from_the_initial_snapshot(): void
    {
        $fixture = $this->createCheckoutLockFixture();
        $fixture['cart']->update(['total' => 2]);

        $this->expectException(CheckoutChangedException::class);

        $this->checkoutService->lockAndValidateCheckoutItems(
            $fixture['buyer']->id,
            $fixture['snapshot'],
        );
    }

    /**
     * Memastikan perubahan harga setelah snapshot dibuat membatalkan checkout sebelum pembayaran.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function locked_checkout_rejects_a_price_change_from_the_initial_snapshot(): void
    {
        $fixture = $this->createCheckoutLockFixture();
        $fixture['product']->update(['price' => 12000]);

        $this->expectException(CheckoutChangedException::class);

        $this->checkoutService->lockAndValidateCheckoutItems(
            $fixture['buyer']->id,
            $fixture['snapshot'],
        );
    }

    /**
     * Memastikan pergantian alamat aktif setelah snapshot dibuat membatalkan checkout sebelum pembayaran.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function locked_checkout_rejects_an_active_buyer_address_change(): void
    {
        $fixture = $this->createCheckoutLockFixture();
        $fixture['buyerAddress']->update(['enable' => false]);
        Alamat::create([
            'user_id' => $fixture['buyer']->id,
            'type' => 'buyer',
            'place' => 'Kantor',
            'alamat' => 'Blok C, Jakarta, Indonesia',
            'latitude' => -6.22,
            'longitude' => 106.82,
            'formatted_address' => 'Jakarta, Indonesia',
            'address_detail' => 'Blok C',
            'location_source' => 'map',
            'enable' => true,
        ]);

        $this->expectException(CheckoutChangedException::class);

        $this->checkoutService->lockAndValidateCheckoutItems(
            $fixture['buyer']->id,
            $fixture['snapshot'],
        );
    }

    /**
     * Membuat snapshot checkout minimal beserta row database yang cocok untuk pengujian row lock.
     *
     * Fixture memuat alamat buyer dan seller terverifikasi, satu produk tersedia, serta satu cart
     * aktif. Snapshot mempertahankan nilai awal agar setiap test dapat mengubah satu state mutable
     * dan membuktikan bahwa perubahan tersebut terdeteksi sebelum pembayaran.
     *
     * @return array{buyer: User, buyerAddress: Alamat, product: Product, cart: Keranjang, snapshot: array<string, mixed>} Fixture checkout dan snapshot awalnya.
     */
    private function createCheckoutLockFixture(): array
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $buyerAddress = Alamat::create([
            'user_id' => $buyer->id,
            'type' => 'buyer',
            'place' => 'Rumah',
            'alamat' => 'Blok B, Jakarta, Indonesia',
            'latitude' => -6.21,
            'longitude' => 106.81,
            'formatted_address' => 'Jakarta, Indonesia',
            'address_detail' => 'Blok B',
            'location_source' => 'map',
            'enable' => true,
        ]);
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
            'enable' => true,
        ]);
        $product = Product::create([
            'user_id_seller' => $seller->id,
            'img' => 'product-imgs/checkout-lock-test.jpg',
            'name' => 'Produk Checkout Lock',
            'price' => 10000,
            'stock' => 5,
        ]);
        $cart = Keranjang::create([
            'user_id_buyer' => $buyer->id,
            'user_id_seller' => $seller->id,
            'product_id' => $product->id,
            'checked' => true,
            'checkout' => true,
            'total' => 1,
        ]);

        return [
            'buyer' => $buyer,
            'buyerAddress' => $buyerAddress,
            'product' => $product,
            'cart' => $cart,
            'snapshot' => [
                'data' => [
                    'checkouts' => [[
                        'user_id_seller' => $seller->id,
                        'keranjangs' => [[
                            'k_id' => $cart->id,
                            'k_total' => 1,
                            'p_id' => $product->id,
                            'p_price' => 10000,
                        ]],
                    ]],
                ],
                'clientComparable' => [
                    'alamat_id' => $buyerAddress->id,
                    'alamat_updated_at' => $buyerAddress->updated_at?->toJSON(),
                ],
            ],
        ];
    }
}
