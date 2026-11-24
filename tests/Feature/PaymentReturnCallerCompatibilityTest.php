<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateApiRequest;
use App\Models\SaldoUser;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\SaldoService;
use App\Services\XenditService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
        // --- step 1 - Authenticate the withdrawal actor - start ---
        $this->withoutMiddleware(AuthenticateApiRequest::class);
        $user = User::factory()->create();
        $this->actingAs($user);
        // --- step 1 - Authenticate the withdrawal actor - end ---

        // --- step 2 - Return a missing account, then an owned one - start ---
        $this->mock(XenditService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('disbursement');
        });
        $this->mock(PaymentService::class, function (MockInterface $mock) use ($user): void {
            $missingAccount = ['status' => 'error', 'message' => 'rekening anda tidak ditemukan'];
            $ownedAccount = [
                'status' => 'success',
                'payment' => [
                    'id' => 'account-id',
                    'user_name' => 'Jidan',
                    'payment_slug' => 'bca',
                ],
            ];

            $mock->shouldReceive('getWithdrawalPayment')
                ->with($user->id, '001234')
                ->once()
                ->andReturn($missingAccount);
            $mock->shouldReceive('getWithdrawalPayment')
                ->with($user->id, '001234')
                ->once()
                ->andReturn($ownedAccount);
        });
        // --- step 2 - Return a missing account, then an owned one - end ---

        // --- step 3 - Report a zero balance - start ---
        $this->mock(SaldoService::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('lockSaldoTotal')->with($user->id)->once()->andReturn(0);
            $mock->shouldNotReceive('deductWithdrawalBalance');
        });
        // --- step 3 - Report a zero balance - end ---

        // --- step 4 - Check both rejections - start ---
        $input = ['paymentAccount' => '001234', 'wihtdrawPrice' => '100000'];

        $this->postJson('/api/saldo-withdraw', $input)
            ->assertStatus(400)
            ->assertExactJson(['status' => 'error', 'message' => 'rekening anda tidak ditemukan']);
        $this->postJson('/api/saldo-withdraw', $input)
            ->assertStatus(400)
            ->assertExactJson([
                'status' => 'error',
                'message' => 'Saldo Anda Rp0 Anda Tidak Bisa Tarik Saldo',
            ]);
        $this->assertDatabaseCount('saldo_histories', 0);
        // --- step 4 - Check both rejections - end ---
    }

    /**
     * Confirms a provider rejection keeps the API error and returns the held balance.
     *
     * Since TOK-88 the balance is held before Xendit is called, so a rejection is followed by a
     * refund instead of exiting before any balance write.
     *
     * @return void Verifies the API error, the restored balance, and the refund history row.
     */
    public function test_withdrawal_rejection_returns_the_held_balance(): void
    {
        // --- step 1 - Authenticate the withdrawal actor - start ---
        $this->withoutMiddleware(AuthenticateApiRequest::class);
        $user = User::factory()->create();
        $this->actingAs($user);
        // --- step 1 - Authenticate the withdrawal actor - end ---

        // --- step 2 - Supply an owned payout account - start ---
        // The held withdrawal stores this ID in UUID columns, which PostgreSQL validates.
        $accountNumber = '001234';
        $accountId = (string) Str::uuid();
        $this->mock(PaymentService::class, function (MockInterface $mock) use (
            $user,
            $accountNumber,
            $accountId,
        ): void {
            $mock->shouldReceive('getWithdrawalPayment')
                ->with($user->id, $accountNumber)
                ->once()
                ->andReturn([
                    'status' => 'success',
                    'payment' => [
                        'id' => $accountId,
                        'user_name' => 'Jidan',
                        'payment_slug' => 'bca',
                    ],
                ]);
        });
        // --- step 2 - Supply an owned payout account - end ---

        // --- step 3 - Set the balance before payout - start ---
        $withdrawalAmount = 100000;

        SaldoUser::create([
            'user_id' => $user->id,
            'saldo_income' => $withdrawalAmount,
            'saldo_refund' => 0,
        ]);
        // --- step 3 - Set the balance before payout - end ---

        // --- step 4 - Supply a rejected payout response - start ---
        // A non-string message falls back to `Unknown error` in the adapter.
        $providerResponse = new Response(
            HttpStatus::HTTP_BAD_REQUEST,
            [],
            '{"message":["invalid"]}',
        );
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

        // --- step 6 - Check the rejection message - start ---
        $response->assertBadRequest()->assertExactJson([
            'status' => 'error',
            'message' => 'Unknown error',
        ]);
        // --- step 6 - Check the rejection message - end ---

        // --- step 7 - Check the returned balance - start ---
        $this->assertDatabaseHas('saldo_users', [
            'user_id' => $user->id,
            'saldo_income' => $withdrawalAmount,
        ]);
        $this->assertDatabaseHas('saldo_histories', ['type' => 'withdrawal']);
        $this->assertDatabaseHas('saldo_histories', ['type' => 'withdrawal_refund']);
        $this->assertDatabaseHas('saldo_withdrawals', ['status' => 'failed']);
        // --- step 7 - Check the returned balance - end ---
    }
}
