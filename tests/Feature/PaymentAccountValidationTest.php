<?php

namespace Tests\Feature;

use App\Models\PaymentList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Memverifikasi endpoint validasi rekening tetap mengembalikan placeholder nama pemilik rekening.
 */
class PaymentAccountValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /**
     * Menyiapkan user terautentikasi dan satu metode pembayaran withdrawal yang tersedia.
     *
     * @return void Tidak mengembalikan nilai; fixture disimpan pada instance test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        PaymentList::create([
            'type' => 'withdrawal',
            'method' => 'bank_transfer',
            'slug' => 'bca',
            'name' => 'BCA',
        ]);
    }

    /**
     * Memverifikasi endpoint mengembalikan nama sintetis yang tidak pernah kosong.
     *
     * Skenario ini mengunci kontrak yang dipakai controller setelah generator berhenti memakai Faker:
     * key `name` selalu tersedia sehingga response tidak lagi membutuhkan fallback string kosong.
     *
     * @test
     *
     * @return void Tidak mengembalikan nilai; assertion menyatakan keberhasilan skenario.
     */
    public function account_validation_returns_a_non_empty_synthetic_owner_name(): void
    {
        $response = $this->postJson('/api/payment/account/validate', [
            'paymentAccount' => '1234567890',
            'paymentSlug' => 'bca',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'success');

        $username = $response->json('username');

        $this->assertIsString($username);
        $this->assertNotSame('', $username);
        $this->assertContains($username, [
            'Andi Pratama',
            'Budi Santoso',
            'Citra Lestari',
            'Dedi Kurniawan',
            'Eka Wulandari',
            'Fajar Nugraha',
            'Gita Permata',
            'Hendra Wijaya',
            'Intan Sari',
            'Joko Saputra',
        ]);
    }
}
