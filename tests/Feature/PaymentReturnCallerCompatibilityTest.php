<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateApiRequest;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\SaldoService;
use App\Services\XenditService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
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
            $mock->shouldReceive('lockSaldoTotal')->with($user->id)->once()->andReturn(0);
            $mock->shouldNotReceive('saveSaldoAfterDisbursement');
        });

        $input = ['paymentAccount' => '001234', 'wihtdrawPrice' => '100000'];
        $this->postJson('/api/saldo-withdraw', $input)->assertStatus(400)->assertExactJson(['status' => 'error', 'message' => 'rekening anda tidak ditemukan']);
        $this->postJson('/api/saldo-withdraw', $input)->assertStatus(400)->assertExactJson(['status' => 'error', 'message' => 'Saldo Anda Rp0 Anda Tidak Bisa Tarik Saldo']);
        $this->assertDatabaseCount('saldo_histories', 0);
    }

    /**
     * Confirms a provider rejection cannot reach the balance write.
     *
     * @return void Verifies the API error and guards the write boundary.
     */
    public function test_withdrawal_rejection_exits_before_balance_mutation(): void
    {
        // --- step 1 - Authenticate the withdrawal actor - start ---
        $this->withoutMiddleware(AuthenticateApiRequest::class);
        $user = User::factory()->create();
        $this->actingAs($user);
        // --- step 1 - Authenticate the withdrawal actor - end ---

        // --- step 2 - Supply an owned payout account - start ---
        $accountNumber = '001234';
        $this->mock(PaymentService::class, function (MockInterface $mock) use (
            $user,
            $accountNumber,
        ): void {
            $mock->shouldReceive('getWithdrawalPayment')
                ->with($user->id, $accountNumber)
                ->once()
                ->andReturn([
                    'status' => 'success',
                    'payment' => [
                        'id' => 'account-id',
                        'user_name' => 'Jidan',
                        'payment_slug' => 'bca',
                    ],
                ]);
        });
        // --- step 2 - Supply an owned payout account - end ---

        // --- step 3 - Set the balance before payout - start ---
        $withdrawalAmount = 100000;
        $this->mock(SaldoService::class, function (MockInterface $mock) use (
            $user,
            $withdrawalAmount,
        ): void {
            $mock->shouldReceive('lockSaldoTotal')
                ->with($user->id)
                ->once()
                ->andReturn($withdrawalAmount);
            $mock->shouldNotReceive('saveSaldoAfterDisbursement');
        });
        // --- step 3 - Set the balance before payout - end ---

        // --- step 4 - Supply a rejected payout response - start ---
        $providerResponse = new Response(HttpStatus::HTTP_BAD_REQUEST, [], '{"message":["invalid"]}');
        $handler = HandlerStack::create(new MockHandler([$providerResponse]));
        $client = new Client(['handler' => $handler]);
        $this->instance(XenditService::class, new XenditService('test-key', $client));
        // --- step 4 - Supply a rejected payout response - end ---

        // --- step 5 - Request withdrawal - start ---
        $response = $this->postJson('/api/saldo-withdraw', [
            'paymentAccount' => $accountNumber,
            'wihtdrawPrice' => (string) $withdrawalAmount,
        ]);
        // --- step 5 - Request withdrawal - end ---

        // --- step 6 - Check rejection before payout history - start ---
        $response->assertBadRequest()->assertExactJson([
            'status' => 'error',
            'message' => 'Unknown error',
        ]);
        $this->assertDatabaseCount('saldo_histories', 0);
        // --- step 6 - Check rejection before payout history - end ---
    }
}
