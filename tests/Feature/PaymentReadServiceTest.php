<?php

namespace Tests\Feature;

use App\Models\PaymentList;
use App\Models\PaymentUser;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Locks payment read contracts, ownership and PostgreSQL search semantics without provider calls.
 */
class PaymentReadServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Checks checkout filtering, projection and the empty result without changing collection types.
     *
     * @return void Assertions preserve the BCA incoming VA catalogue contract.
     */
    public function test_checkout_catalogue_preserves_projection_and_empty_results(): void
    {
        $service = app(PaymentService::class);
        $this->assertSame(['payments' => []], $service->getCheckoutPayment());
        PaymentList::create(['type' => 'incoming', 'method' => 'va', 'slug' => 'bca', 'name' => null]);
        PaymentList::create(['type' => 'withdrawal', 'method' => 'bank_transfer', 'slug' => 'bca', 'name' => 'BCA']);
        PaymentList::create(['type' => 'incoming', 'method' => 'va', 'slug' => 'bni', 'name' => 'BNI']);

        $this->assertSame(['payments' => [['slug' => 'bca', 'method' => 'va', 'name' => null]]], $service->getCheckoutPayment());
    }

    /**
     * Ensures user and withdrawal scopes, selected aliases and descending account order remain intact.
     *
     * @return void The result remains an Eloquent collection including when empty.
     */
    public function test_withdrawal_reads_preserve_collection_ownership_and_order(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $bank = PaymentList::create(['type' => 'withdrawal', 'slug' => 'bca', 'name' => 'BCA']);
        $incoming = PaymentList::create(['type' => 'incoming', 'slug' => 'bca', 'name' => 'BCA']);
        $old = PaymentUser::create(['user_id' => $owner->id, 'payment_id' => $bank->id, 'account' => '001', 'name' => 'Old', 'created_at' => now()->subDay()]);
        $new = PaymentUser::create(['user_id' => $owner->id, 'payment_id' => $bank->id, 'account' => '002', 'name' => 'New']);
        PaymentUser::create(['user_id' => $other->id, 'payment_id' => $bank->id, 'account' => '003']);
        PaymentUser::create(['user_id' => $owner->id, 'payment_id' => $incoming->id, 'account' => '004']);

        $service = app(PaymentService::class);
        foreach (['', '   ', '0'] as $search) {
            $result = $service->getWithdrawalPayments($owner->id, $search);
            $this->assertSame('success', $result['status']);
            $this->assertInstanceOf(Collection::class, $result['payments']);
            $this->assertSame([$new->id, $old->id], $result['payments']->pluck('id')->all());
            $this->assertSame(['id', 'slug', 'name', 'account', 'username'], array_keys($result['payments']->first()->getAttributes()));
        }
        $this->assertCount(0, $service->getWithdrawalPayments(User::factory()->create()->id)['payments']);
    }

    /**
     * Tests the production ILIKE dialect rather than substituting SQLite LIKE semantics.
     *
     * @return void PostgreSQL confirms every search column, whitespace, wildcards and ownership.
     */
    public function test_postgresql_search_preserves_existing_ilike_and_wildcards(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('ILIKE search is verified on isolated PostgreSQL.');
        }
        $owner = User::factory()->create();
        $bank = PaymentList::create(['type' => 'withdrawal', 'slug' => 'bca', 'name' => 'Bank Central']);
        $account = PaymentUser::create(['user_id' => $owner->id, 'payment_id' => $bank->id, 'name' => 'Jidan', 'account' => '001234']);
        PaymentUser::create(['user_id' => User::factory()->create()->id, 'payment_id' => $bank->id, 'name' => 'Jidan', 'account' => '001234']);
        foreach (['BCA', 'CENTRAL', 'JIDAN', '1234', '%', '_'] as $search) {
            $this->assertSame([$account->id], app(PaymentService::class)->getWithdrawalPayments($owner->id, $search)['payments']->pluck('id')->all());
        }
        $this->assertCount(0, app(PaymentService::class)->getWithdrawalPayments($owner->id, ' Jidan ')['payments']);
    }

    /**
     * Preserves account lookup errors, leading zeroes and nullable joined fields.
     *
     * @return void Success and business failures expose their exact existing result shapes.
     */
    public function test_account_detail_preserves_errors_and_nullable_fields(): void
    {
        $owner = User::factory()->create();
        $bank = PaymentList::create(['type' => 'withdrawal', 'slug' => 'bca']);
        $account = PaymentUser::create(['user_id' => $owner->id, 'payment_id' => $bank->id, 'name' => 'Jidan', 'account' => '001234']);
        $service = app(PaymentService::class);
        $this->assertSame(['status' => 'success', 'payment' => ['id' => $account->id, 'user_name' => 'Jidan', 'payment_slug' => 'bca']], $service->getWithdrawalPayment($owner->id, '001234'));
        $this->assertSame('user id cannot be empty', $service->getWithdrawalPayment('', '001234')['message']);
        $this->assertSame('payment account cannot be empty', $service->getWithdrawalPayment($owner->id, ' ')['message']);
        $this->assertSame('rekening anda tidak ditemukan', $service->getWithdrawalPayment(User::factory()->create()->id, '001234')['message']);
        $this->assertSame('rekening anda tidak ditemukan', $service->getWithdrawalPayment($owner->id, 'missing')['message']);
        $account->update(['name' => null]);
        $this->assertSame('User Name Cannot Be Empty', $service->getWithdrawalPayment($owner->id, '001234')['message']);
        $bank->update(['slug' => null]);
        $this->assertSame('Payment Slug Cannot Be Empty', $service->getWithdrawalPayment($owner->id, '001234')['message']);
    }
}
