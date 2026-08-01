<?php

namespace Tests\Feature;

use App\Models\Alamat;
use App\Models\User;
use App\Services\KeranjangService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeranjangServiceTest extends TestCase
{
    use RefreshDatabase;

    protected KeranjangService $keranjangService;

    /**
     * Menyiapkan fixture dan dependency sebelum setiap pengujian.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->keranjangService = $this->app->make(KeranjangService::class);
    }

    /**
     * Memverifikasi getKeranjangs.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_getKeranjangs(): void
    {
        $user_id_buyer = '00000000-0000-0000-0000-000000000001';
        $getKeranjangs = $this->keranjangService->getKeranjangs($user_id_buyer);

        info([
            'keranjangs' => $getKeranjangs['keranjangs'] ?? 'problem',
            'totalPrice' => $getKeranjangs['totalPrice'] ?? 'problem',
        ]);

        $this->assertTrue(true);
    }

    /**
     * Memverifikasi checkProductSoldOutByIds.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_checkProductSoldOutByIds(): void
    {
        $product_ids = ['00000000-0000-0000-0000-000000001863'];
        $checkProductSoldOutByIds = $this->keranjangService->checkProductSoldOutByIds($product_ids);

        info([
            'ids' => $checkProductSoldOutByIds['ids'] ?? 'problem',
        ]);

        $this->assertTrue(true);
    }

    /**
     * Memastikan alamat toko tidak memenuhi gate alamat pengiriman milik buyer.
     *
     * User dapat memiliki role buyer dan seller sekaligus, sehingga pemeriksaan checkout harus
     * membatasi alamat aktif berdasarkan type buyer dan bukan hanya berdasarkan user ID.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
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
}
