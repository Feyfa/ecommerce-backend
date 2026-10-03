<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateApiRequest;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\SaldoService;
use App\Services\XenditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Confirms the accurate payment result shape preserves its existing withdrawal caller behavior.
 */
class PaymentReturnCallerCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Verifies guaranteed status keys retain payment errors and the zero-balance business boundary.
     *
     * @return void Neither result shape reaches disbursement or balance mutation.
     */
    public function test_payment_result_contract_preserves_withdrawal_caller_responses(): void
    {
        $this->withoutMiddleware(AuthenticateApiRequest::class);
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->mock(XenditService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('disbursement');
        });
        $this->mock(PaymentService::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('getWithdrawalPayment')->with($user->id, '001234')->once()->andReturn(['status' => 'error', 'message' => 'rekening anda tidak ditemukan']);
            $mock->shouldReceive('getWithdrawalPayment')->with($user->id, '001234')->once()->andReturn(['status' => 'success', 'payment' => ['id' => 'account-id', 'user_name' => 'Jidan', 'payment_slug' => 'bca']]);
        });
        $this->mock(SaldoService::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('getSaldo')->with($user->id)->once()->andReturn(['status' => 'success', 'saldoTotal' => 0]);
            $mock->shouldNotReceive('saveSaldoAfterDisbursement');
        });

        $input = ['paymentAccount' => '001234', 'wihtdrawPrice' => '100000'];
        $this->postJson('/api/saldo-withdraw', $input)->assertStatus(400)->assertExactJson(['status' => 'error', 'message' => 'rekening anda tidak ditemukan']);
        $this->postJson('/api/saldo-withdraw', $input)->assertStatus(400)->assertExactJson(['status' => 'error', 'message' => 'Saldo Anda Rp0 Anda Tidak Bisa Tarik Saldo']);
        $this->assertDatabaseCount('saldo_histories', 0);
    }
}
