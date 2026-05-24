<?php

namespace Tests\Feature;

use App\Services\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    protected CheckoutService $checkoutService;

    public function setUp(): void
    {
        parent::setUp();
        
        $this->checkoutService = new CheckoutService();
    }

    public function test_satu()
    {
        $this->checkoutService->getKeranjangCheckout('00000000-0000-0000-0000-000000000002');
        $this->assertTrue(true);
    }
}
