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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KeranjangServiceTest extends TestCase
{
    use RefreshDatabase;

    protected KeranjangService $keranjangService;

    /**
     * Menyiapkan fixture dan dependency sebelum setiap pengujian.
     *
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->keranjangService = $this->app->make(KeranjangService::class);
    }

    /**
     * Memastikan buyer tanpa item memperoleh state cart kosong yang lengkap tanpa read-repair.
     *
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_getKeranjangs(): void
    {
        $buyer = User::factory()->create();

        $this->assertSame([
            'totalPrice' => 0,
            'keranjangs' => [],
            'unavailableSelectedItemIds' => [],
            'unavailableSelectedReasons' => [],
            'unavailableCheckoutItemIds' => [],
            'unavailableCheckoutReasons' => [],
            'stockIssues' => [],
            'selectedStockIssues' => [],
        ], $this->keranjangService->getKeranjangs($buyer->id));
        $this->assertDatabaseCount('keranjangs', 0);
    }

    /**
     * Memastikan pemeriksaan availability menghasilkan ID unik dan alasan yang sama melalui alias lama.
     *
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_checkProductSoldOutByIds(): void
    {
        // --- step 1 - start - siapkan produk dengan setiap kondisi availability
        $seller = User::factory()->create();
        $this->createVerifiedSellerAddress($seller);
        $unverifiedSeller = User::factory()->create();
        $available = $this->createProduct($seller, 'Tersedia');
        $deleted = $this->createProduct($seller, 'Dihapus');
        $deleted->delete();
        $outOfStock = $this->createProduct($seller, 'Stok habis', stock: 0);
        $unverified = $this->createProduct($unverifiedSeller, 'Lokasi belum valid');
        $missingId = (string) Str::uuid();
        $productIds = [$available->id, $deleted->id, $outOfStock->id, $unverified->id, $missingId, $deleted->id, ''];
        // --- step 1 - end - siapkan produk dengan setiap kondisi availability

        // --- step 2 - start - bandingkan kontrak pemeriksaan dan alias kompatibilitas
        $expected = [
            'ids' => [$deleted->id, $outOfStock->id, $unverified->id, $missingId],
            'reasons' => [
                $deleted->id => ProductAvailabilityService::PRODUCT_DELETED,
                $outOfStock->id => ProductAvailabilityService::OUT_OF_STOCK,
                $unverified->id => ProductAvailabilityService::SELLER_LOCATION_UNVERIFIED,
                $missingId => ProductAvailabilityService::PRODUCT_DELETED,
            ],
        ];

        $this->assertSame($expected, $this->keranjangService->checkProductUnavailableByIds($productIds));
        $this->assertSame($expected, $this->keranjangService->checkProductSoldOutByIds($productIds));
        $keyedProductIds = array_combine(
            array_map(fn (int $index): string => 'item_'.$index, array_keys($productIds)),
            $productIds,
        );
        $this->assertSame($expected, $this->keranjangService->checkProductUnavailableByIds($keyedProductIds));
        $this->assertSame($expected, $this->keranjangService->checkProductSoldOutByIds($keyedProductIds));
        $this->assertSame(['ids' => [], 'reasons' => []], $this->keranjangService->checkProductUnavailableByIds());
        // --- step 2 - end - bandingkan kontrak pemeriksaan dan alias kompatibilitas
    }

    /**
     * Memastikan proyeksi cart mempertahankan grouping, nama seller, ownership, dan harga pecahan.
     *
     * Hanya item checked dan selectable dihitung; pemuatan alamat seller tetap dilakukan secara batch.
     *
     * @return void Assertion memverifikasi state response dan flag cart yang tersimpan.
     */
    public function test_cart_projection_preserves_grouping_totals_and_ownership(): void
    {
        // --- step 1 - start - siapkan cart multi-seller dan cart buyer lain
        $buyer = User::factory()->create();
        $otherBuyer = User::factory()->create();
        $seller = User::factory()->create(['name' => 'Nama akun seller']);
        $fallbackSeller = User::factory()->create(['name' => 'Nama seller fallback']);
        $this->createVerifiedSellerAddress($seller);
        $this->createVerifiedSellerAddress($fallbackSeller);
        Company::create(['user_id' => $seller->id, 'name' => 'Nama toko']);
        Company::create(['user_id' => $fallbackSeller->id, 'name' => '']);

        $product = $this->createProduct($seller, 'Harga pecahan', price: 100.25);
        $fallbackProduct = $this->createProduct($fallbackSeller, 'Produk fallback', price: 50.5);
        $checked = $this->createCart($buyer, $product, quantity: 3);
        $unchecked = $this->createCart($buyer, $product, quantity: 2, checked: false, checkout: false);
        $fallbackCart = $this->createCart($buyer, $fallbackProduct, quantity: 2);
        $otherCart = $this->createCart($otherBuyer, $product, quantity: 9);
        // --- step 1 - end - siapkan cart multi-seller dan cart buyer lain

        // --- step 2 - start - periksa query batch dan hasil proyeksi
        DB::enableQueryLog();
        DB::flushQueryLog();
        $state = $this->keranjangService->getKeranjangs($buyer->id);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(2, $queries);
        $this->assertEqualsWithDelta(401.75, $state['totalPrice'], 0.00001);
        $this->assertCount(2, $state['keranjangs']);
        $this->assertCount(2, $state['keranjangs'][$seller->id]);
        $this->assertCount(1, $state['keranjangs'][$fallbackSeller->id]);
        $items = collect($state['keranjangs'])->flatten(1)->keyBy('k_id');
        $item = $items->get($checked->id);
        $this->assertSame([
            'k_id', 'k_user_id_seller', 'k_checked', 'k_checkout', 'k_total', 'k_total_price',
            'u_seller_name', 'p_id', 'p_exists_id', 'p_name', 'p_price', 'p_stock', 'p_img',
            'p_deleted_at', 'is_purchasable', 'unavailable_reason', 'stock_issue', 'is_selectable',
        ], array_keys($item));
        $this->assertSame('Nama toko', $item['u_seller_name']);
        $this->assertSame($product->id, $item['p_id']);
        $this->assertSame($product->id, $item['p_exists_id']);
        $this->assertSame('Harga pecahan', $item['p_name']);
        $this->assertSame(1, $item['k_checked']);
        $this->assertSame(1, $item['k_checkout']);
        $this->assertTrue($item['is_purchasable']);
        $this->assertTrue($item['is_selectable']);
        $this->assertNull($item['unavailable_reason']);
        $this->assertNull($item['stock_issue']);
        $this->assertEqualsWithDelta(300.75, $item['k_total_price'], 0.00001);
        $this->assertSame(0, $items->get($unchecked->id)['k_checked']);
        $this->assertSame('Nama seller fallback', $items->get($fallbackCart->id)['u_seller_name']);
        $this->assertFalse($items->has($otherCart->id));
        $this->assertSame([], $state['stockIssues']);
        $this->assertSame([], $state['unavailableSelectedItemIds']);
        $this->assertSame([], $state['unavailableCheckoutItemIds']);
        // --- step 2 - end - periksa query batch dan hasil proyeksi

        // --- step 3 - start - pastikan pembacaan cart valid tidak mengubah quantity atau pilihan
        foreach ([$checked, $fallbackCart, $otherCart] as $cart) {
            $this->assertDatabaseHas('keranjangs', [
                'id' => $cart->id,
                'total' => $cart->total,
                'checked' => true,
                'checkout' => true,
            ]);
        }
        // --- step 3 - end - pastikan pembacaan cart valid tidak mengubah quantity atau pilihan
    }

    /**
     * Memastikan issue dan alasan unavailable mencatat pilihan awal sebelum read-repair menetralkan flag.
     *
     * Quantity dan cart buyer lain dipertahankan, sedangkan item valid tetap menyumbang total harga.
     *
     * @return void Assertion memverifikasi issue response serta perubahan flag yang dibatasi ke buyer.
     */
    public function test_read_repair_preserves_initial_selection_issues_and_quantities(): void
    {
        // --- step 1 - start - siapkan item valid, stok berubah, dan produk dihapus
        $buyer = User::factory()->create();
        $otherBuyer = User::factory()->create();
        $seller = User::factory()->create(['name' => 'Seller issue']);
        $this->createVerifiedSellerAddress($seller);
        $limited = $this->createProduct($seller, 'Stok terbatas', stock: 2);
        $deleted = $this->createProduct($seller, 'Produk dihapus');
        $deleted->delete();
        $outOfStock = $this->createProduct($seller, 'Stok nol', stock: 0);
        $valid = $this->createProduct($seller, 'Masih valid');
        $limitedCart = $this->createCart($buyer, $limited, quantity: 5);
        $deletedCart = $this->createCart($buyer, $deleted, quantity: 3);
        $outOfStockCart = $this->createCart($buyer, $outOfStock, quantity: 4, checked: false);
        $validCart = $this->createCart($buyer, $valid, quantity: 2);
        $otherCart = $this->createCart($otherBuyer, $limited, quantity: 5);
        // --- step 1 - end - siapkan item valid, stok berubah, dan produk dihapus

        // --- step 2 - start - periksa issue dan alasan sebelum pilihan dinetralkan
        $state = $this->keranjangService->getKeranjangs($buyer->id);
        $items = collect($state['keranjangs'])->flatten(1)->keyBy('k_id');
        $expectedIssue = [
            'code' => 'QUANTITY_EXCEEDS_STOCK',
            'cart_id' => $limitedCart->id,
            'product_id' => $limited->id,
            'product_name' => 'Stok terbatas',
            'seller_id' => $seller->id,
            'seller_name' => 'Seller issue',
            'cart_quantity' => 5,
            'available_stock' => 2,
        ];
        $this->assertSame($expectedIssue, $items->get($limitedCart->id)['stock_issue']);
        $this->assertSame([$expectedIssue], $state['selectedStockIssues']);
        $this->assertCount(2, $state['stockIssues']);
        $this->assertSame([$deletedCart->id], $state['unavailableSelectedItemIds']);
        $this->assertSame([
            $deletedCart->id => ProductAvailabilityService::PRODUCT_DELETED,
        ], $state['unavailableSelectedReasons']);
        $this->assertEqualsCanonicalizing(
            [$deletedCart->id, $outOfStockCart->id],
            $state['unavailableCheckoutItemIds'],
        );
        $this->assertSame(ProductAvailabilityService::PRODUCT_DELETED, $state['unavailableCheckoutReasons'][$deletedCart->id]);
        $this->assertSame(ProductAvailabilityService::OUT_OF_STOCK, $state['unavailableCheckoutReasons'][$outOfStockCart->id]);
        $this->assertNotNull($items->get($deletedCart->id)['p_deleted_at']);
        $this->assertEqualsWithDelta(200.5, $state['totalPrice'], 0.00001);
        // --- step 2 - end - periksa issue dan alasan sebelum pilihan dinetralkan

        // --- step 3 - start - periksa read-repair tanpa mereset quantity atau cart lain
        foreach ([$limitedCart, $deletedCart, $outOfStockCart] as $cart) {
            $this->assertSame(0, $items->get($cart->id)['k_checked']);
            $this->assertSame(0, $items->get($cart->id)['k_checkout']);
            $this->assertFalse($items->get($cart->id)['is_selectable']);
            $this->assertDatabaseHas('keranjangs', [
                'id' => $cart->id,
                'total' => $cart->total,
                'checked' => false,
                'checkout' => false,
            ]);
        }
        foreach ([$validCart, $otherCart] as $cart) {
            $this->assertDatabaseHas('keranjangs', [
                'id' => $cart->id,
                'total' => $cart->total,
                'checked' => true,
                'checkout' => true,
            ]);
        }
        // --- step 3 - end - periksa read-repair tanpa mereset quantity atau cart lain
    }

    /**
     * Memastikan produk yang hilang tetap tampil dengan atribut LEFT JOIN null dan quantity tersimpan.
     *
     * @return void Assertion memverifikasi proyeksi nullable dan alasan read-repair produk hilang.
     */
    public function test_missing_product_preserves_nullable_projection(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $missingId = (string) Str::uuid();
        $cart = Keranjang::create([
            'user_id_buyer' => $buyer->id,
            'user_id_seller' => $seller->id,
            'product_id' => $missingId,
            'checked' => true,
            'checkout' => true,
            'total' => 3,
        ]);

        $state = $this->keranjangService->getKeranjangs($buyer->id);
        $item = $state['keranjangs'][$seller->id][0];

        $this->assertSame($missingId, $item['p_id']);
        foreach (['p_exists_id', 'p_name', 'p_price', 'p_stock', 'p_img', 'p_deleted_at', 'stock_issue'] as $attribute) {
            $this->assertNull($item[$attribute]);
        }
        $this->assertEquals(0, $item['k_total_price']);
        $this->assertSame(0, $state['totalPrice']);
        $this->assertSame(ProductAvailabilityService::PRODUCT_DELETED, $item['unavailable_reason']);
        $this->assertSame([$cart->id], $state['unavailableSelectedItemIds']);
        $this->assertSame([$cart->id], $state['unavailableCheckoutItemIds']);
        $this->assertDatabaseHas('keranjangs', ['id' => $cart->id, 'total' => 3, 'checked' => false, 'checkout' => false]);
    }

    /**
     * Memastikan harga dan nama produk nullable tidak diubah oleh pembacaan cart atau pembentukan issue.
     *
     * @return void Assertion memverifikasi COALESCE harga existing dan nama null pada detail issue.
     */
    public function test_nullable_product_values_preserve_price_and_issue_contracts(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $this->createVerifiedSellerAddress($seller);
        $product = $this->createProduct($seller, 'Nama awal', price: null, stock: 1);
        $product->update(['name' => null]);
        $cart = $this->createCart($buyer, $product, quantity: 2);

        $state = $this->keranjangService->getKeranjangs($buyer->id);
        $item = $state['keranjangs'][$seller->id][0];

        $this->assertNull($item['p_price']);
        $this->assertNull($item['p_name']);
        $this->assertNull($item['stock_issue']['product_name']);
        $this->assertEquals(0, $item['k_total_price']);
        $this->assertSame(0, $state['totalPrice']);
        $this->assertSame($cart->id, $item['stock_issue']['cart_id']);
    }

    /**
     * Memastikan alamat toko tidak memenuhi gate alamat pengiriman milik buyer.
     *
     * User dapat memiliki role buyer dan seller sekaligus, sehingga pemeriksaan checkout harus
     * membatasi alamat aktif berdasarkan type buyer dan bukan hanya berdasarkan user ID.
     *
     * @test
     *
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function seller_address_does_not_count_as_an_enabled_buyer_address(): void
    {
        $user = User::factory()->create();
        Alamat::create([
            'user_id' => $user->id,
            'type' => 'seller',
            'alamat' => 'Lokasi toko',
            'enable' => true,
        ]);

        $result = $this->keranjangService->checkAlamatBuyerExist($user->id);

        $this->assertFalse($result['exists']);
    }

    /**
     * Menyiapkan alamat seller terverifikasi untuk pemeriksaan batch tanpa provider eksternal.
     *
     * @param  User  $seller  Seller yang produknya dapat dipilih pada cart fixture.
     *
     * @return void Alamat aktif seller disimpan dengan metadata pinpoint yang lengkap.
     */
    private function createVerifiedSellerAddress(User $seller): void
    {
        Alamat::create([
            'user_id' => $seller->id,
            'type' => 'seller',
            'alamat' => 'Alamat toko fixture',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'formatted_address' => 'Jakarta, Indonesia',
            'address_detail' => 'Blok A',
            'location_source' => 'map',
            'enable' => true,
        ]);
    }

    /**
     * Menyiapkan produk dengan harga nullable dan stok terkontrol untuk karakterisasi hasil query cart.
     *
     * @param  User  $seller  Pemilik produk fixture.
     * @param  string  $name  Nama produk sebelum kemungkinan perubahan pada skenario test.
     * @param  float|null  $price  Harga yang disimpan, termasuk pecahan atau nilai null legacy.
     * @param  int  $stock  Stok tersedia untuk pemeriksaan availability.
     *
     * @return Product Produk yang tersimpan dan dapat dirujuk cart fixture.
     */
    private function createProduct(User $seller, string $name, ?float $price = 100.25, int $stock = 10): Product
    {
        return Product::create([
            'user_id_seller' => $seller->id,
            'name' => $name,
            'price' => $price,
            'stock' => $stock,
            'img' => 'product-imgs/fixture.jpg',
        ]);
    }

    /**
     * Menyiapkan item cart dengan quantity dan flag terpisah agar read-repair dapat dibandingkan dengan state awal.
     *
     * @param  User  $buyer  Pemilik cart yang menjadi scope query service.
     * @param  Product  $product  Produk dan seller yang dirujuk item cart.
     * @param  int  $quantity  Quantity tersimpan, termasuk nilai yang melebihi stok.
     * @param  bool  $checked  Pilihan buyer sebelum pembacaan cart.
     * @param  bool  $checkout  Status checkout sebelum pembacaan cart.
     *
     * @return Keranjang Item cart tersimpan untuk assertion response dan database.
     */
    private function createCart(User $buyer, Product $product, int $quantity = 1, bool $checked = true, bool $checkout = true): Keranjang
    {
        return Keranjang::create([
            'user_id_buyer' => $buyer->id,
            'user_id_seller' => $product->user_id_seller,
            'product_id' => $product->id,
            'checked' => $checked,
            'checkout' => $checkout,
            'total' => $quantity,
        ]);
    }
}
