<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Memastikan root backend tidak lagi menampilkan halaman welcome Laravel.
     */
    public function test_the_root_endpoint_returns_backend_health_response(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'service' => 'backend',
            ]);
    }
}
