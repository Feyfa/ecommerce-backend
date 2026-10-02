<?php

namespace Tests\Feature;

use App\Models\Alamat;
use App\Models\Product;
use App\Models\User;
use App\Services\KeranjangService;
use App\Services\ProductAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Memastikan kontrak identitas controller cart mempertahankan validasi dan mutasi existing.
 */
class KeranjangControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Memisahkan pengujian controller dari transport autentikasi Clerk tanpa mengubah resolver user.
     *
     * @return void Middleware dinonaktifkan; database dan queue tetap memakai isolasi test base.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
    }

    /**
     * Menolak buyer ID kosong, non-UUID, atau array sebelum service checkout mengakses cart.
     *
     * @return void Respons 422 dan database kosong diverifikasi untuk setiap payload invalid.
     */
    public function test_checkout_rejects_invalid_buyer_ids_before_calling_services(): void
    {
        // --- step 1 - start - siapkan user dan larang akses service sebelum validasi
        $buyer = User::factory()->create();
        $this->actingAs($buyer);
        $this->mock(KeranjangService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('checkAlamatBuyerExist');
            $mock->shouldNotReceive('getKeranjangs');
            $mock->shouldNotReceive('updateCheckoutKeranjang');
        });
        // --- step 1 - end - siapkan user dan larang akses service sebelum validasi

        // --- step 2 - start - periksa payload invalid dan pastikan cart tidak berubah
        foreach ([null, '', 'invalid-uuid', [$buyer->id]] as $buyerId) {
            $this->postJson('/api/keranjang/validate/checkout', [
                'user_id_buyer' => $buyerId,
                'product_ids' => [$buyer->id],
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('user_id_buyer', 'message');
        }

        $this->assertDatabaseCount('keranjangs', 0);
        // --- step 2 - end - periksa payload invalid dan pastikan cart tidak berubah
    }

    /**
     * Mempertahankan respons ownership controller ketika middleware dilewati dan user tidak ada.
     *
     * @return void Respons 403 CART_FORBIDDEN terjadi sebelum service melakukan read-repair.
     */
    public function test_cart_without_a_request_user_keeps_the_ownership_error(): void
    {
        // --- step 1 - start - siapkan buyer tanpa session serta larang read-repair
        $buyer = User::factory()->create();
        $this->mock(KeranjangService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('getKeranjangs');
        });
        // --- step 1 - end - siapkan buyer tanpa session serta larang read-repair

        // --- step 2 - start - periksa respons ownership tanpa mutasi
        $this->getJson("/api/keranjang/{$buyer->id}")
            ->assertForbidden()
            ->assertJson([
                'status' => 'error',
                'code' => 'CART_FORBIDDEN',
                'message' => 'Forbidden',
            ]);

        $this->assertDatabaseCount('keranjangs', 0);
        // --- step 2 - end - periksa respons ownership tanpa mutasi
    }

    /**
     * Menolak seller produk null atau berbeda dari UUID request sebelum lokasi seller diperiksa.
     *
     * @return void Respons 422 existing dipertahankan tanpa membuat item cart.
     */
    public function test_store_rejects_null_or_mismatched_product_sellers(): void
    {
        // --- step 1 - start - siapkan actor dan larang pemeriksaan availability lanjutan
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $otherSeller = User::factory()->create();
        $this->actingAs($buyer);
        $availability = $this->mock(ProductAvailabilityService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('sellerHasVerifiedAddress');
            $mock->shouldNotReceive('unavailableReason');
        });
        // --- step 1 - end - siapkan actor dan larang pemeriksaan availability lanjutan

        // --- step 2 - start - periksa kedua kondisi seller tanpa membuat cart
        foreach ([null, $otherSeller->id] as $productSellerId) {
            $product = Product::create([
                'user_id_seller' => $productSellerId,
                'name' => 'Seller Tidak Cocok',
                'price' => 10000,
                'stock' => 5,
            ]);
            $availability->shouldReceive('findProductForAvailability')
                ->once()
                ->with($product->id)
                ->andReturn($product);

            $this->postJson('/api/keranjang', [
                'user_id_buyer' => $buyer->id,
                'user_id_seller' => $seller->id,
                'product_id' => $product->id,
            ])
                ->assertUnprocessable()
                ->assertJson([
                    'status' => 422,
                    'message' => 'Data seller produk tidak valid',
                ]);
        }

        $this->assertDatabaseCount('keranjangs', 0);
        // --- step 2 - end - periksa kedua kondisi seller tanpa membuat cart
    }

    /**
     * Menggunakan seller yang cocok untuk availability sebelum membuat dan menambah quantity cart.
     *
     * @return void Seller belum terverifikasi ditolak; seller terverifikasi membuat lalu menambah cart.
     */
    public function test_store_preserves_seller_availability_and_existing_cart_quantity(): void
    {
        // --- step 1 - start - siapkan produk dari seller tanpa lokasi terverifikasi
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $product = Product::create([
            'user_id_seller' => $seller->id,
            'name' => 'Produk Cart',
            'price' => 10000,
            'stock' => 5,
        ]);
        $payload = [
            'user_id_buyer' => $buyer->id,
            'user_id_seller' => $seller->id,
            'product_id' => $product->id,
        ];
        $this->actingAs($buyer);
        // --- step 1 - end - siapkan produk dari seller tanpa lokasi terverifikasi

        // --- step 2 - start - periksa penolakan lalu tambahkan lokasi seller terverifikasi
        $this->postJson('/api/keranjang', $payload)
            ->assertConflict()
            ->assertJsonPath('code', ProductAvailabilityService::SELLER_LOCATION_UNVERIFIED);
        $this->assertDatabaseCount('keranjangs', 0);

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
        // --- step 2 - end - periksa penolakan lalu tambahkan lokasi seller terverifikasi

        // --- step 3 - start - periksa pembuatan dan penambahan quantity pada cart yang sama
        foreach ([1, 2] as $quantity) {
            $this->postJson('/api/keranjang', $payload)->assertOk()->assertJson(['status' => 200]);
            $this->assertDatabaseCount('keranjangs', 1);
            $this->assertDatabaseHas('keranjangs', [
                'user_id_buyer' => $buyer->id,
                'user_id_seller' => $seller->id,
                'product_id' => $product->id,
                'total' => $quantity,
                'checked' => 0,
            ]);
        }
        // --- step 3 - end - periksa pembuatan dan penambahan quantity pada cart yang sama
    }
}
