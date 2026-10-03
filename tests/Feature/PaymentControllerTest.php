<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateApiRequest;
use App\Models\PaymentList;
use App\Models\PaymentUser;
use App\Models\TransactionInvoice;
use App\Models\User;
use App\Services\XenditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Verifies payment input boundaries and existing account/VA behavior with a mocked provider.
 */
class PaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Keeps normalization middleware while bypassing only external Clerk transport.
     *
     * @return void API requests use local authenticated fixtures with no external calls.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(AuthenticateApiRequest::class);
        $this->mock(XenditService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('simulateVirtualAccountFixed');
        });
    }

    /**
     * Keeps all five endpoints unauthorized before malformed input can reach business logic.
     *
     * @return void Missing and deleted local users retain the same 401 JSON response.
     */
    public function test_authentication_precedes_payload_validation(): void
    {
        foreach ([false, true] as $deleted) {
            if ($deleted) {
                $user = User::factory()->create();
                $this->actingAs($user);
                $user->delete();
            }
            foreach ([['GET', '/api/payment'], ['POST', '/api/payment/account/validate'], ['POST', '/api/payment'], ['DELETE', '/api/payment/missing'], ['POST', '/api/payment/simulate/charge-virtual-account']] as [$verb, $url]) {
                $this->json($verb, $url, ['searchPayment' => ['bad']])
                    ->assertUnauthorized()->assertExactJson(['status' => 'error', 'message' => 'Unauthorized']);
            }
        }
    }

    /**
     * Keeps successful account creation, list refresh and deletion including leading-zero accounts.
     *
     * @return void The fixture belongs to its owner and all successful API fields remain unchanged.
     */
    public function test_account_creation_and_deletion_preserve_success_contracts(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $bank = PaymentList::create(['type' => 'withdrawal', 'slug' => 'bca', 'name' => 'BCA']);
        $this->postJson('/api/payment', ['paymentName' => 'BCA', 'paymentSlug' => 'bca', 'paymentAccount' => '001234', 'paymentUsername' => 'Jidan'])
            ->assertOk()->assertJsonPath('message', 'Rekening Berhasil Ditambah')->assertJsonPath('payments.0.account', '001234');
        $account = PaymentUser::where('user_id', $user->id)->firstOrFail();
        $this->assertSame($bank->id, $account->payment_id);
        $this->getJson('/api/payment')->assertOk()->assertJsonCount(1, 'payments');
        $this->deleteJson('/api/payment/'.$account->id)->assertOk()->assertJsonPath('message', 'Rekening Berhasil Dihapus')->assertJsonCount(0, 'payments');
        $this->assertDatabaseMissing('payment_users', ['id' => $account->id]);
    }

    /**
     * Preserves business errors for blank fields, unknown banks, duplicates and foreign deletion.
     *
     * @return void Business failures keep their original messages and do not remove foreign data.
     */
    public function test_account_business_errors_remain_unchanged(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $bank = PaymentList::create(['type' => 'withdrawal', 'slug' => 'bca', 'name' => 'BCA']);
        $account = PaymentUser::create(['user_id' => $user->id, 'payment_id' => $bank->id, 'account' => '001', 'name' => 'Jidan']);
        foreach ([[], ['paymentAccount' => null], ['paymentAccount' => '   ']] as $input) {
            $this->postJson('/api/payment/account/validate', $input)->assertStatus(400)->assertJsonPath('message', 'Nomor Rekening Tidak Boleh Kosong');
        }
        $this->postJson('/api/payment/account/validate', ['paymentAccount' => '001'])->assertStatus(400)->assertJsonPath('message', 'Payment Slug Empty');
        $this->postJson('/api/payment/account/validate', ['paymentAccount' => '001', 'paymentSlug' => 'unknown'])->assertStatus(400)->assertJsonPath('message', 'Nama Bank unknown Tidak Tersedia');
        $this->postJson('/api/payment/account/validate', ['paymentAccount' => '001', 'paymentSlug' => 'bca'])->assertStatus(400)->assertJsonPath('message', 'Nomor Rekening Sudah Digunakan');
        $this->actingAs(User::factory()->create());
        $this->deleteJson('/api/payment/'.$account->id)->assertStatus(400)->assertJsonPath('message', 'Data Rekening Tidak Ditemukan');
        $this->assertDatabaseHas('payment_users', ['id' => $account->id]);
    }

    /**
     * Verifies account-limit and unavailable bank checks without allowing an extra insert.
     *
     * @return void Ten accounts remain ten and unknown bank combinations keep the existing 400 response.
     */
    public function test_account_limit_and_unavailable_bank_are_preserved(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $bank = PaymentList::create(['type' => 'withdrawal', 'slug' => 'bca', 'name' => 'BCA']);
        $input = ['paymentName' => 'Wrong', 'paymentSlug' => 'bca', 'paymentAccount' => '001', 'paymentUsername' => 'Jidan'];
        $this->postJson('/api/payment', $input)->assertStatus(400)->assertJsonPath('message', 'Tipe Rekening Bank Tidak Tersedia');
        for ($index = 0; $index < 10; $index++) {
            PaymentUser::create(['user_id' => $user->id, 'payment_id' => $bank->id, 'account' => (string) $index]);
        }
        $this->postJson('/api/payment', array_replace($input, ['paymentName' => 'BCA']))->assertStatus(400)->assertJsonPath('message', 'Rekening Tidak Boleh Lebih Dari 10');
        $this->assertDatabaseCount('payment_users', 10);
    }

    /**
     * Rejects malformed text fields before queries can mutate accounts or contact the provider.
     *
     * @return void Every field rejects scalar and compound non-strings with field-specific 422 errors.
     */
    public function test_non_string_inputs_are_rejected_before_mutation(): void
    {
        // This matrix exceeds the HTTP rate limit; throttle behavior is outside input validation.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $user = User::factory()->create();
        $this->actingAs($user);
        $bank = PaymentList::create(['type' => 'withdrawal', 'slug' => 'bca', 'name' => 'BCA']);
        $account = PaymentUser::create(['user_id' => $user->id, 'payment_id' => $bank->id, 'account' => '001', 'name' => 'Jidan']);
        $valid = ['paymentName' => 'BCA', 'paymentSlug' => 'bca', 'paymentAccount' => '002', 'paymentUsername' => 'Jidan'];
        $cases = [
            ['GET', '/api/payment', [], ['searchPayment']],
            ['POST', '/api/payment', $valid, ['paymentName', 'paymentSlug', 'paymentAccount', 'paymentUsername', 'searchPayment']],
            ['DELETE', '/api/payment/'.$account->id, [], ['searchPayment']],
            ['POST', '/api/payment/account/validate', ['paymentAccount' => '002', 'paymentSlug' => 'bca'], ['paymentAccount', 'paymentSlug']],
            ['POST', '/api/payment/simulate/charge-virtual-account', ['payment_slug' => 'bca', 'payment_account' => '001234'], ['payment_slug', 'payment_account']],
        ];
        foreach ($cases as [$verb, $url, $base, $fields]) {
            foreach ($fields as $field) {
                foreach ([true, false, 123, 0, 1.5, ['bad'], (object) ['bad' => 'value']] as $value) {
                    $this->json($verb, $url, array_replace($base, [$field => $value]))
                        ->assertUnprocessable()->assertJsonPath('status', 'error')->assertJsonValidationErrors([$field], 'message');
                    $this->assertDatabaseCount('payment_users', 1);
                    $this->assertDatabaseHas('payment_users', ['id' => $account->id, 'account' => '001']);
                }
            }
        }
    }

    /**
     * Keeps optional search defaults at all three callers and required account-field validation.
     *
     * @return void Missing, null and empty searches allow successful mutations; required fields still return 422.
     */
    public function test_search_defaults_and_required_fields_are_preserved(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        PaymentList::create(['type' => 'withdrawal', 'slug' => 'bca', 'name' => 'BCA']);
        $valid = ['paymentName' => 'BCA', 'paymentSlug' => 'bca', 'paymentAccount' => '001234', 'paymentUsername' => 'Jidan'];
        foreach (['paymentName', 'paymentSlug', 'paymentAccount', 'paymentUsername'] as $field) {
            foreach ([null, '', '   '] as $value) {
                $this->postJson('/api/payment', array_replace($valid, [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors([$field], 'message');
            }
            $missing = $valid;
            unset($missing[$field]);
            $this->postJson('/api/payment', $missing)->assertUnprocessable()->assertJsonValidationErrors([$field], 'message');
        }
        foreach ([[], ['searchPayment' => null], ['searchPayment' => ''], ['searchPayment' => '   '], ['searchPayment' => '0']] as $search) {
            $this->postJson('/api/payment', array_replace($valid, $search))->assertOk()->assertJsonPath('payments.0.account', '001234');
            $account = PaymentUser::where('user_id', $user->id)->firstOrFail();
            $this->json('GET', '/api/payment', $search)->assertOk()->assertJsonCount(1, 'payments');
            $this->deleteJson('/api/payment/'.$account->id, $search)->assertOk()->assertJsonCount(0, 'payments');
        }
    }

    /**
     * Builds a pending fixed-VA fixture owned by the supplied buyer.
     *
     * @param  User  $user  Buyer owning the invoice.
     * @param  float|null  $price  Stored nominal for simulation conversion checks.
     *
     * @return TransactionInvoice Invoice available for a mocked simulation request.
     */
    private function invoice(User $user, ?float $price = 150000.0): TransactionInvoice
    {
        return TransactionInvoice::create(['user_id_buyer' => $user->id, 'payment_method' => 'va', 'payment_slug' => 'bca', 'payment_account' => '001234', 'payment_reference' => 'reference', 'price' => $price, 'status' => 'pending', 'expired_at' => now()->addDay()]);
    }

    /**
     * Rejects missing, foreign and expired VA records before the provider is contacted.
     *
     * @return void The existing 400 business errors leave invoice status unchanged.
     */
    public function test_simulation_checks_ownership_expiry_and_empty_fields(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $this->postJson('/api/payment/simulate/charge-virtual-account', [])->assertStatus(400)->assertJsonPath('message', 'Nama Bank Harus Dipilih');
        $this->postJson('/api/payment/simulate/charge-virtual-account', ['payment_slug' => 'bca'])->assertStatus(400)->assertJsonPath('message', 'Nomor Virtual Account Harus Dipilih');
        $input = ['payment_slug' => 'bca', 'payment_account' => '001234'];
        $this->postJson('/api/payment/simulate/charge-virtual-account', $input)->assertStatus(400)->assertJsonPath('message', 'Nomor Virtual Account Tidak Ditemukan');
        $foreign = $this->invoice(User::factory()->create());
        $this->postJson('/api/payment/simulate/charge-virtual-account', $input)->assertStatus(400);
        $foreign->delete();
        $invoice = $this->invoice($owner);
        $invoice->update(['expired_at' => now()->subDay()]);
        $this->postJson('/api/payment/simulate/charge-virtual-account', $input)->assertStatus(400)->assertJsonPath('message', 'Nomor Virtual Account Ini Sudah Expired');
        $this->assertSame('pending', $invoice->fresh()->status);
    }

    /**
     * Locks the existing integer conversion for integral, fractional and absent stored amounts.
     *
     * @return void Explicit integer casts preserve truncation and null fallback without real payment calls.
     */
    public function test_simulation_amount_conversion_preserves_existing_values(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->mock(XenditService::class, function (MockInterface $mock): void {
            foreach ([150000, 150000, 0] as $amount) {
                $mock->shouldReceive('simulateVirtualAccountFixed')->with('reference', $amount)->once()->andReturn(['status' => 'success']);
            }
        });
        foreach ([150000.0, 150000.75, null] as $price) {
            $invoice = $this->invoice($user, $price);
            $this->postJson('/api/payment/simulate/charge-virtual-account', ['payment_slug' => 'bca', 'payment_account' => '001234'])->assertOk();
            $this->assertSame('done', $invoice->fresh()->status);
        }
    }

    /**
     * Confirms provider failure preserves status and success marks the owned invoice done.
     *
     * @return void Provider calls use the expected integer amount without any external HTTP.
     */
    public function test_simulation_preserves_provider_failure_and_success_behavior(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $invoice = $this->invoice($user);
        $this->mock(XenditService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('simulateVirtualAccountFixed')->with('reference', 150000)->once()->andReturn(['status' => 'error', 'message' => 'Provider unavailable']);
            $mock->shouldReceive('simulateVirtualAccountFixed')->with('reference', 150000)->once()->andReturn(['status' => 'success']);
        });
        $input = ['payment_slug' => 'bca', 'payment_account' => '001234'];
        $this->postJson('/api/payment/simulate/charge-virtual-account', $input)->assertStatus(400)->assertJsonPath('message', 'Provider unavailable');
        $this->assertSame('pending', $invoice->fresh()->status);
        $this->postJson('/api/payment/simulate/charge-virtual-account', $input)->assertOk()->assertJsonPath('message', 'Success Charge Virtual Account');
        $this->assertSame('done', $invoice->fresh()->status);
    }
}
