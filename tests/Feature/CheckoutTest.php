<?php

namespace Tests\Feature;

use App\Services\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

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
