<?php

namespace Tests\Feature;

use App\Services\KeranjangService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class KeranjangServiceTest extends TestCase
{
    protected KeranjangService $keranjangService;

    public function setUp(): void
    {
        parent::setUp();
        
        $this->keranjangService = new KeranjangService();
    }

    public function test_getKeranjangs(): void 
    {
        $user_id_buyer = 1;
        $getKeranjangs = $this->keranjangService->getKeranjangs($user_id_buyer);

        info([
            'keranjangs' => $getKeranjangs['keranjangs'] ?? "problem",
            'totalPrice' => $getKeranjangs['totalPrice'] ?? "problem"
        ]);

        $this->assertTrue(true);
    }

    public function test_checkProductSoldOutByIds(): void 
    {
        $product_ids = [1863];
        $checkProductSoldOutByIds = $this->keranjangService->checkProductSoldOutByIds($product_ids);

        info([
            'ids' => $checkProductSoldOutByIds['ids'] ?? "problem"
        ]);

        $this->assertTrue(true);
    }
}
