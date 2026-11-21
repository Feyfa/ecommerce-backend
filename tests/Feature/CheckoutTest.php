<?php

namespace Tests\Feature;

use App\Enums\OutboxAggregateType;
use App\Enums\OutboxEventType;
use App\Enums\OutboxStatus;
use App\Exceptions\CheckoutChangedException;
use App\Models\Alamat;
use App\Models\Company;
use App\Models\Keranjang;
use App\Models\OutboxMessage;
use App\Models\PaymentList;
use App\Models\Product;
use App\Models\TransactionInvoice;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\OutboxRecorderService;
use App\Services\PaymentService;
use App\Services\XenditService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const JNT_CHECKOUT_SHIPPING = 15000;

    protected CheckoutService $checkoutService;

    /**
     * Menyiapkan fixture dan dependency sebelum setiap pengujian.
     *
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->checkoutService = $this->app->make(CheckoutService::class);
    }

    /**
     * Memverifikasi satu.
     *
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
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
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
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
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
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
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
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
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function checkout_validation_marks_only_matching_checked_rows_for_the_authenticated_buyer(): void
    {
        $this->withoutMiddleware();

        // --- step 1 - siapkan buyer, seller, dan alamat terverifikasi - start ---
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
        // --- step 1 - siapkan buyer, seller, dan alamat terverifikasi - end ---

        // --- step 2 - siapkan cart terpilih, tidak terpilih, dan milik buyer lain - start ---
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
        // --- step 2 - siapkan cart terpilih, tidak terpilih, dan milik buyer lain - end ---

        // --- step 3 - validasi checkout dan pastikan update hanya mengenai cart yang tepat - start ---
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
        // --- step 3 - validasi checkout dan pastikan update hanya mengenai cart yang tepat - end ---
    }

    /**
     * Memastikan perubahan quantity setelah snapshot dibuat membatalkan checkout sebelum pembayaran.
     *
     * @test
     *
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
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
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
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
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
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
     * Memverifikasi aturan checkout buyer pada skenario successful checkout returns the created
     * invoice id.
     *
     * Test menyiapkan buyer, alamat seller terverifikasi, cart, serta metode pembayaran, memalsukan
     * pembuatan virtual account, lalu memastikan response sukses membawa id invoice yang benar benar
     * tersimpan. Id tersebut adalah kontrak yang dipakai frontend untuk menandai transaksi hasil
     * checkout, sedangkan produk yang stoknya berubah harus dijadwalkan untuk disinkronkan ke
     * Meilisearch setelah transaksi berhasil.
     *
     * @test
     *
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function successful_checkout_returns_the_created_invoice_id(): void
    {
        $this->withoutMiddleware();

        // --- step 1 - Siapkan fixture checkout - start ---
        $fixture = $this->createCheckoutLockFixture();
        $buyer = $fixture['buyer'];
        $paymentAccount = '001659999724432';
        $paymentReference = 'va-bca-checkout-test';
        $this->enableCheckoutPayment();
        // --- step 1 - Siapkan fixture checkout - end ---

        // --- step 2 - palsukan pembuatan virtual account agar test tidak memanggil provider - start ---
        $this->mock(XenditService::class, function (MockInterface $mock) use (
            $paymentAccount,
            $paymentReference,
        ): void {
            $mock->shouldReceive('createVirtualAccount')
                ->once()
                ->andReturn([
                    'status' => 'suceess',
                    'data' => [
                        'account_number' => $paymentAccount,
                        'external_id' => $paymentReference,
                    ],
                ]);
        });
        // --- step 2 - palsukan pembuatan virtual account agar test tidak memanggil provider - end ---

        // --- step 3 - proses checkout dan periksa identitas pesanan pada response - start ---
        $response = $this->processCheckoutFixture($fixture)
            ->assertOk()
            ->assertJson([
                'status' => 'success',
                'message' => 'Pembayaran Berhasil',
            ]);
        // --- step 3 - proses checkout dan periksa identitas pesanan pada response - end ---

        // --- step 4 - pastikan id invoice pada response cocok dengan baris yang tersimpan - start ---
        $invoice = TransactionInvoice::where('user_id_buyer', $buyer->id)->firstOrFail();

        $this->assertDatabaseHas('transaction_users', ['transaction_invoice_id' => $invoice->id]);

        $response->assertJsonPath('transaction_invoice_id', $invoice->id);
        $this->assertSame($paymentAccount, $invoice->payment_account);
        $this->assertSame($paymentReference, $invoice->payment_reference);
        // --- step 4 - pastikan id invoice pada response cocok dengan baris yang tersimpan - end ---

        // --- step 5 - pastikan stok terbaru dicatat durable bersama checkout - start ---
        $outbox = OutboxMessage::query()->sole();
        $this->assertSame(OutboxEventType::BUYER_CATALOG_PRODUCT_SYNC->value, $outbox->event_type);
        $this->assertSame(OutboxAggregateType::PRODUCT->value, $outbox->aggregate_type);
        $this->assertSame($fixture['product']->id, $outbox->aggregate_id);
        $this->assertSame(OutboxStatus::PENDING, $outbox->status);
        $this->assertEquals([
            'schema_version' => 1,
            'source' => OutboxRecorderService::SOURCE_CHECKOUT_STOCK_CHANGED,
        ], $outbox->payload);
        Queue::assertNothingPushed();
        // --- step 5 - pastikan stok terbaru dicatat durable bersama checkout - end ---
    }

    /**
     * Verifies rejected provider responses leave the checkout available for review.
     *
     * @param  int  $status  HTTP status supplied by the fake provider.
     * @param  string  $body  Body returned by the fake provider.
     * @param  string  $message  Expected error shown to the buyer.
     *
     * @return void Checks the response and related checkout state.
     */
    #[DataProvider('rejectedProviderResponses')]
    public function test_checkout_rejects_unusable_provider_responses(
        int $status,
        string $body,
        string $message,
    ): void {
        // --- step 1 - Prepare the buyer checkout - start ---
        $this->withoutMiddleware();
        $fixture = $this->createCheckoutLockFixture();
        $stockBefore = $fixture['product']->stock;
        $this->enableCheckoutPayment();
        // --- step 1 - Prepare the buyer checkout - end ---

        // --- step 2 - Supply a fake provider response - start ---
        $handler = HandlerStack::create(new MockHandler([new Response($status, [], $body)]));
        $client = new Client(['handler' => $handler]);
        $this->instance(XenditService::class, new XenditService('test-key', $client));
        // --- step 2 - Supply a fake provider response - end ---

        // --- step 3 - Submit the checkout - start ---
        $response = $this->processCheckoutFixture($fixture);
        // --- step 3 - Submit the checkout - end ---

        // --- step 4 - Check the rejection - start ---
        $response->assertBadRequest()->assertExactJson(['status' => 'error', 'message' => $message]);
        // --- step 4 - Check the rejection - end ---

        // --- step 5 - Check checkout state after rejection - start ---
        // A buyer must retain their cart if the provider response cannot create an invoice.
        $this->assertDatabaseCount('transaction_invoices', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertDatabaseHas('keranjangs', ['id' => $fixture['cart']->id, 'checkout' => true]);
        $this->assertSame($stockBefore, $fixture['product']->refresh()->stock);
        // --- step 5 - Check checkout state after rejection - end ---
    }

    /**
     * Covers unusable provider responses while preserving the empty-payload error.
     *
     * @return array<string, array{int, string, string}> Fake HTTP status, body, and API message.
     */
    public static function rejectedProviderResponses(): array
    {
        return [
            'unexpected scalar' => [HttpStatus::HTTP_OK, 'true', 'Payment creation failed'],
            'existing empty-payload handling' => [HttpStatus::HTTP_OK, 'null', 'Data Xendit Empty'],
            'missing payment identifiers' => [
                HttpStatus::HTTP_OK,
                '{"id":"fake-va"}',
                'Payment creation failed',
            ],
            'missing virtual account number' => [
                HttpStatus::HTTP_OK,
                '{"external_id":"va-checkout-test"}',
                'Payment creation failed',
            ],
            'missing payment reference' => [
                HttpStatus::HTTP_OK,
                '{"account_number":"001234"}',
                'Payment creation failed',
            ],
            'blank payment reference' => [
                HttpStatus::HTTP_OK,
                '{"account_number":"001234","external_id":"  "}',
                'Payment creation failed',
            ],
            'non-string virtual account number' => [
                HttpStatus::HTTP_OK,
                '{"account_number":1234,"external_id":"va-checkout-test"}',
                'Payment creation failed',
            ],
            'non-string error' => [
                HttpStatus::HTTP_BAD_REQUEST,
                '{"message":["invalid"]}',
                'Unknown error',
            ],
        ];
    }

    /**
     * Enables BCA VA so the fixture can reach the payment provider.
     *
     * @return void Creates the payment option expected by the submitted quote.
     */
    private function enableCheckoutPayment(): void
    {
        PaymentList::create([
            'type' => 'incoming',
            'method' => 'va',
            'slug' => 'bca',
            'name' => 'BCA Virtual Account',
        ]);
    }

    /**
     * Submits the same valid quote for both accepted and rejected provider responses.
     *
     * @param  array{
     *     buyer: User, seller: User, buyerAddress: Alamat, product: Product, cart: Keranjang
     * }  $fixture  Buyer checkout data.
     *
     * @return TestResponse Endpoint response before assertions.
     */
    private function processCheckoutFixture(array $fixture): TestResponse
    {
        // Use the fixture's product price to quote the same cart the server will validate.
        $productTotal = $fixture['product']->price;
        $jntShipping = self::JNT_CHECKOUT_SHIPPING;
        $snapshot = [
            'alamat_id' => $fixture['buyerAddress']->id,
            'alamat_updated_at' => $fixture['buyerAddress']->updated_at?->toJSON(),
            'cart_item_ids' => [$fixture['cart']->id],
            'total_product' => $productTotal,
            'total_shipping' => $jntShipping,
            'total_all' => $productTotal + $jntShipping,
        ];

        return $this->actingAs($fixture['buyer'])->postJson('/api/checkout/process', [
            'payment_slug' => 'bca',
            'shipping_options' => [['user_id_seller' => $fixture['seller']->id, 'kurir_name' => 'JNT']],
            'noteds' => [['user_id_seller' => $fixture['seller']->id, 'noted' => '']],
            'client_snapshot' => $snapshot,
        ]);
    }

    /**
     * Membuat snapshot checkout minimal beserta row database yang cocok untuk pengujian row lock.
     *
     * Fixture memuat alamat buyer dan seller terverifikasi, satu produk tersedia, serta satu cart
     * aktif. Snapshot mempertahankan nilai awal agar setiap test dapat mengubah satu state mutable
     * dan membuktikan bahwa perubahan tersebut terdeteksi sebelum pembayaran.
     *
     * @return array{buyer: User, seller: User, buyerAddress: Alamat, product: Product, cart: Keranjang, snapshot: array<string, mixed>} Fixture checkout dan snapshot awalnya.
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
            'seller' => $seller,
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
