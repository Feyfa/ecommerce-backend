<?php

namespace Tests\Feature;

use App\Enums\OutboxAggregateType;
use App\Enums\OutboxEventType;
use App\Enums\OutboxStatus;
use App\Exceptions\CheckoutAvailabilityException;
use App\Exceptions\CheckoutChangedException;
use App\Http\Middleware\AuthenticateApiRequest;
use App\Models\Alamat;
use App\Models\Company;
use App\Models\Keranjang;
use App\Models\OutboxMessage;
use App\Models\PaymentList;
use App\Models\Product;
use App\Models\TransactionInvoice;
use App\Models\User;
use App\Services\AlamatService;
use App\Services\CheckoutService;
use App\Services\KeranjangService;
use App\Services\OutboxRecorderService;
use App\Services\PaymentService;
use App\Services\ProductAvailabilityService;
use App\Services\XenditService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mockery;
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
     * Menjaga bentuk harga lama pada respons checkout yang dipakai halaman buyer.
     *
     * @return void Memastikan harga kosong tetap tersedia sebagai null di paket seller.
     */
    public function test_checkout_data_preserves_null_item_prices(): void
    {
        // --- step 1 - Siapkan checkout buyer terverifikasi - start ---
        $this->withoutMiddleware();
        $fixture = $this->createCheckoutLockFixture();
        $this->enableCheckoutPayment();
        // --- step 1 - Siapkan checkout buyer terverifikasi - end ---

        // --- step 2 - Tambahkan item lama tanpa harga - start ---
        // Skema produk mengizinkan harga null pada data yang sudah tersimpan.
        $legacyProduct = $fixture['product']->replicate();
        $legacyProduct->price = null;
        $legacyProduct->save();

        $legacyCart = $fixture['cart']->replicate();
        $legacyCart->product_id = $legacyProduct->id;
        $legacyCart->save();
        // --- step 2 - Tambahkan item lama tanpa harga - end ---

        // --- step 3 - Muat paket checkout buyer - start ---
        $response = $this->actingAs($fixture['buyer'])
            ->getJson('/api/checkout/data');
        // --- step 3 - Muat paket checkout buyer - end ---

        // --- step 4 - Periksa ringkasan checkout - start ---
        $response->assertOk()
            ->assertJsonCount(1, 'checkouts')
            ->assertJsonPath('checkouts.0.user_id_seller', $fixture['seller']->id)
            ->assertJsonCount(2, 'checkouts.0.keranjangs')
            ->assertJsonPath('checkouts.0.kurirs.0.price', self::JNT_CHECKOUT_SHIPPING)
            ->assertJsonPath('totalPrice', (int) $fixture['product']->price);
        // --- step 4 - Periksa ringkasan checkout - end ---

        // --- step 5 - Periksa harga item lama - start ---
        $itemsByProduct = array_column($response->json('checkouts.0.keranjangs'), null, 'p_id');
        $legacyItem = $itemsByProduct[$legacyProduct->id];

        $this->assertArrayHasKey('p_price', $legacyItem);
        $this->assertArrayHasKey('k_total_price', $legacyItem);
        $this->assertNull($legacyItem['p_price']);
        $this->assertNull($legacyItem['k_total_price']);
        // --- step 5 - Periksa harga item lama - end ---
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
     * Memastikan item yang kehilangan harga setelah snapshot dibuat ditolak saat row dikunci.
     *
     * @return void Memverifikasi exception yang dipakai controller untuk melepas pilihan item.
     */
    public function test_locked_checkout_rejects_price_that_became_null(): void
    {
        // --- step 1 - kosongkan harga produk setelah snapshot - start ---
        $fixture = $this->createCheckoutLockFixture();

        $fixture['product']->update(['price' => null]);
        // --- step 1 - kosongkan harga produk setelah snapshot - end ---

        // --- step 2 - verifikasi penolakan pada row terkunci - start ---
        $this->expectException(CheckoutAvailabilityException::class);

        $this->checkoutService->lockAndValidateCheckoutItems(
            $fixture['buyer']->id,
            $fixture['snapshot'],
        );
        // --- step 2 - verifikasi penolakan pada row terkunci - end ---
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
     * Memastikan produk lama tanpa harga tidak mencapai pembuatan Virtual Account saat submit.
     *
     * @return void Memverifikasi respons penolakan serta cart yang masih tersedia untuk direview.
     */
    public function test_null_price_blocks_virtual_account_creation(): void
    {
        // --- step 1 - siapkan checkout produk tanpa harga - start ---
        $this->withoutMiddleware();
        $fixture = $this->createCheckoutLockFixture();
        $this->enableCheckoutPayment();

        $fixture['product']->update(['price' => null]);
        // --- step 1 - siapkan checkout produk tanpa harga - end ---

        // --- step 2 - siapkan mock Xendit - start ---
        // Permintaan VA yang terjadi sebelum penolakan dapat tercatat di luar transaksi database.
        $xendit = $this->mock(XenditService::class);
        $xendit->shouldReceive('createVirtualAccount')->andReturn([
            'status' => 'error',
            'message' => 'Unexpected Virtual Account request in the null price test.',
        ]);
        // --- step 2 - siapkan mock Xendit - end ---

        $response = $this->processCheckoutFixture($fixture);

        // --- step 3 - periksa respons harga kosong - start ---
        $response->assertConflict()
            ->assertJsonPath('code', 'CHECKOUT_INVALID')
            ->assertJsonPath(
                'message',
                'Harga salah satu produk belum tersedia. Silakan cek keranjang.',
            );

        $xendit->shouldNotHaveReceived('createVirtualAccount');
        // --- step 3 - periksa respons harga kosong - end ---

        // --- step 4 - periksa state checkout - start ---
        $this->assertDatabaseCount('transaction_invoices', 0);
        $this->assertDatabaseHas('keranjangs', ['id' => $fixture['cart']->id, 'checkout' => true]);
        // --- step 4 - periksa state checkout - end ---
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
     * Memastikan request tanpa buyer terautentikasi ditolak sebelum pembayaran diproses.
     *
     * @return void Memverifikasi respons 401 yang sudah ada tanpa permintaan Virtual Account.
     */
    public function test_process_checkout_rejects_a_request_without_an_authenticated_buyer(): void
    {
        // --- step 1 - siapkan request tanpa buyer - start ---
        // Middleware dimatikan agar guard di controller yang diuji, bukan middleware auth.
        $this->withoutMiddleware();
        $this->mock(XenditService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('createVirtualAccount');
        });
        // --- step 1 - siapkan request tanpa buyer - end ---

        $response = $this->postJson('/api/checkout/process', []);

        $response->assertUnauthorized()
            ->assertExactJson(['result' => 'error', 'message' => 'Unauthorized']);
    }

    /**
     * Memastikan buyer yang record lokalnya sudah dihapus tidak dapat membuat pembayaran.
     *
     * Model user masih terbawa pada request, sehingga controller wajib memeriksa database sebelum
     * nama buyer dipakai sebagai nama pemilik Virtual Account.
     *
     * @return void Memverifikasi respons 401 yang sudah ada tanpa permintaan Virtual Account.
     */
    public function test_process_checkout_rejects_a_buyer_deleted_after_authentication(): void
    {
        // --- step 1 - siapkan request dari buyer yang sudah terhapus - start ---
        $this->withoutMiddleware();
        $buyer = User::factory()->create();
        $this->actingAs($buyer);
        $buyer->delete();

        $this->mock(XenditService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('createVirtualAccount');
        });
        // --- step 1 - siapkan request dari buyer yang sudah terhapus - end ---

        $response = $this->postJson('/api/checkout/process', []);

        $response->assertUnauthorized()
            ->assertExactJson(['result' => 'error', 'message' => 'Unauthorized']);
    }

    /**
     * Rejects malformed checkout payload fields before cart reconciliation or payment.
     *
     * @param  string  $field  Dot path of the checkout field to invalidate.
     * @param  mixed  $value  Value that violates the field's input type.
     *
     * @return void Verifies the endpoint returns the field error without preparing payment.
     */
    #[DataProvider('invalidCheckoutPayloadFields')]
    public function test_process_checkout_rejects_invalid_payload_fields(
        string $field,
        mixed $value,
    ): void {
        // --- step 1 - Prepare the buyer checkout - start ---
        $this->withoutMiddleware(AuthenticateApiRequest::class);
        $fixture = $this->createCheckoutLockFixture();
        // --- step 1 - Prepare the buyer checkout - end ---

        // --- step 2 - Guard against checkout side effects - start ---
        // Reconciliation can change selected cart rows before any Virtual Account is created.
        $this->mock(CheckoutService::class)->shouldNotReceive('buildCheckoutSnapshot');
        $this->mock(XenditService::class)->shouldNotReceive('createVirtualAccount');
        // --- step 2 - Guard against checkout side effects - end ---

        // --- step 3 - Verify the validation response - start ---
        $this->processCheckoutFixture($fixture, [$field => $value])
            ->assertUnprocessable()
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors($field, 'message');
        // --- step 3 - Verify the validation response - end ---
    }

    /**
     * Provides request types that the snapshot service cannot consume as checkout fields.
     *
     * @return array<string, array{string, mixed}> Field paths with malformed values.
     */
    public static function invalidCheckoutPayloadFields(): array
    {
        // Each case changes one field so the response can be tied to its validation rule.
        return [
            'payment slug array' => ['payment_slug', ['bca']],
            'non-array shipping row' => ['shipping_options.0', 'JNT'],
            'non-string shipping seller' => ['shipping_options.0.user_id_seller', 1],
            'non-string courier name' => ['shipping_options.0.kurir_name', ['JNT']],
            'non-array note row' => ['noteds.0', 'Leave at the door'],
            'non-string note seller' => ['noteds.0.user_id_seller', 1],
            'non-string note text' => ['noteds.0.noted', ['Leave at the door']],
            'non-string cart item ID' => ['client_snapshot.cart_item_ids.0', 1],
        ];
    }

    /**
     * Preserves numeric-string totals and the frontend's blank seller note through checkout.
     *
     * @return void Verifies checkout succeeds and the blank note is saved as an empty string.
     */
    public function test_process_checkout_accepts_numeric_string_totals_and_a_blank_note(): void
    {
        // --- step 1 - Prepare numeric-string snapshot totals - start ---
        // Request normalization turns the frontend's blank note into null before validation.
        $this->withoutMiddleware(AuthenticateApiRequest::class);
        $fixture = $this->createCheckoutLockFixture();
        $productTotal = $fixture['product']->price;
        $shippingTotal = self::JNT_CHECKOUT_SHIPPING;

        $overrides = [
            'client_snapshot.total_product' => (string) $productTotal,
            'client_snapshot.total_shipping' => (string) $shippingTotal,
            'client_snapshot.total_all' => (string) ($productTotal + $shippingTotal),
        ];
        // --- step 1 - Prepare numeric-string snapshot totals - end ---

        // --- step 2 - Fake Virtual Account creation - start ---
        $this->enableCheckoutPayment();
        $this->mock(XenditService::class)
            ->shouldReceive('createVirtualAccount')
            ->once()
            ->andReturn([
                'status' => 'suceess',
                'data' => [
                    'account_number' => '001234',
                    'external_id' => 'va-numeric-totals',
                ],
            ]);
        // --- step 2 - Fake Virtual Account creation - end ---

        // --- step 3 - Verify the accepted checkout - start ---
        $response = $this->processCheckoutFixture($fixture, $overrides);
        $response->assertOk()->assertJsonPath('status', 'success');
        // --- step 3 - Verify the accepted checkout - end ---

        // --- step 4 - Verify the saved seller note - start ---
        $this->assertDatabaseHas('transaction_users', [
            'transaction_invoice_id' => $response->json('transaction_invoice_id'),
            'noted' => '',
        ]);
        // --- step 4 - Verify the saved seller note - end ---
    }

    /**
     * Returns the current server quote when the total reviewed by the buyer is stale.
     *
     * @return void Verifies the conflict response before any Virtual Account is created.
     */
    public function test_checkout_returns_the_current_quote_for_a_stale_total(): void
    {
        // --- step 1 - Prepare a stale buyer snapshot - start ---
        $this->withoutMiddleware(AuthenticateApiRequest::class);
        $fixture = $this->createCheckoutLockFixture();
        $this->enableCheckoutPayment();

        $productTotal = $fixture['product']->price;
        $currentTotal = $productTotal + self::JNT_CHECKOUT_SHIPPING;
        $staleTotal = (string) $productTotal;
        // --- step 1 - Prepare a stale buyer snapshot - end ---

        $this->mock(XenditService::class)->shouldNotReceive('createVirtualAccount');

        // --- step 2 - Verify the refreshed quote - start ---
        $this->processCheckoutFixture($fixture, ['client_snapshot.total_all' => $staleTotal])
            ->assertConflict()
            ->assertJsonPath('code', 'CHECKOUT_CHANGED')
            ->assertJsonPath('checkout.totalAll', $currentTotal);
        // --- step 2 - Verify the refreshed quote - end ---
    }

    /**
     * Reuses the buyer's validated selections when the locked state requires a refreshed quote.
     *
     * @return void Verifies the refreshed courier and note are returned before payment.
     */
    public function test_process_checkout_reuses_validated_selections_after_a_lock_conflict(): void
    {
        // --- step 1 - Prepare the buyer checkout - start ---
        $this->withoutMiddleware(AuthenticateApiRequest::class);
        $fixture = $this->createCheckoutLockFixture();
        $this->enableCheckoutPayment();

        $note = 'Leave at the door';
        // --- step 1 - Prepare the buyer checkout - end ---

        // --- step 2 - Simulate checkout changing under the lock - start ---
        // Both snapshots still use the real code, including cart and address dependencies.
        $checkout = Mockery::mock(CheckoutService::class, [
            $this->app->make(AlamatService::class),
            $this->app->make(KeranjangService::class),
            $this->app->make(ProductAvailabilityService::class),
        ])->makePartial();

        $checkout->shouldReceive('lockAndValidateCheckoutItems')
            ->once()
            ->andThrow(new CheckoutChangedException('Checkout berubah saat dikunci'));
        $this->instance(CheckoutService::class, $checkout);

        $this->mock(XenditService::class)->shouldNotReceive('createVirtualAccount');
        // --- step 2 - Simulate checkout changing under the lock - end ---

        // --- step 3 - Verify the refreshed checkout selections - start ---
        $this->processCheckoutFixture($fixture, ['noteds.0.noted' => $note])
            ->assertConflict()
            ->assertJsonPath('code', 'CHECKOUT_CHANGED')
            ->assertJsonPath('checkout.kurirs.0.name', 'JNT')
            ->assertJsonPath('checkout.noteds.0.noted', $note);
        // --- step 3 - Verify the refreshed checkout selections - end ---
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
     * Submits the fixture's cart using the quote shown to a buyer before payment.
     *
     *
     * @param  array{
     *     buyer: User, seller: User, buyerAddress: Alamat, product: Product, cart: Keranjang
     * }  $fixture  Buyer checkout data.
     * @param  array<string, mixed>  $overrides  Fixture overrides by field path.
     *
     * @return TestResponse Endpoint response before assertions.
     */
    private function processCheckoutFixture(array $fixture, array $overrides = []): TestResponse
    {
        // --- step 1 - Susun snapshot buyer - start ---
        // The read endpoint counts a missing price as zero in the displayed product total.
        $productTotal = $fixture['product']->price ?? 0;
        $jntShipping = self::JNT_CHECKOUT_SHIPPING;

        $snapshot = [
            'alamat_id' => $fixture['buyerAddress']->id,
            'alamat_updated_at' => $fixture['buyerAddress']->updated_at?->toJSON(),
            'cart_item_ids' => [$fixture['cart']->id],
            'total_product' => $productTotal,
            'total_shipping' => $jntShipping,
            'total_all' => $productTotal + $jntShipping,
        ];
        // --- step 1 - Susun snapshot buyer - end ---

        // --- step 2 - Susun payload checkout buyer - start ---
        $sellerId = $fixture['seller']->id;

        $payload = [
            'payment_slug' => 'bca',
            'shipping_options' => [['user_id_seller' => $sellerId, 'kurir_name' => 'JNT']],
            'noteds' => [['user_id_seller' => $sellerId, 'noted' => '']],
            'client_snapshot' => $snapshot,
        ];
        // --- step 2 - Susun payload checkout buyer - end ---

        // --- step 3 - Terapkan variasi payload - start ---
        foreach ($overrides as $field => $value) {
            Arr::set($payload, $field, $value);
        }
        // --- step 3 - Terapkan variasi payload - end ---

        return $this->actingAs($fixture['buyer'])->postJson('/api/checkout/process', $payload);
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
