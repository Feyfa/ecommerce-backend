<?php

namespace Tests\Feature;

use App\Models\SaldoHistory;
use App\Models\SaldoUser;
use App\Models\User;
use App\Services\SaldoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Verifies the balance deduction that holds a withdrawal before Xendit is called, and the refund
 * that returns it after a confirmed failure.
 */
class SaldoWithdrawalDeductionTest extends TestCase
{
    use RefreshDatabase;

    private const STARTING_REFUND = 30000;

    private const STARTING_INCOME = 100000;

    // Larger than the refund, so the deduction must also take part of the income.
    private const MIXED_WITHDRAWAL = 50000;

    // Larger than the refund plus the income.
    private const OVERDRAWN_WITHDRAWAL = 200000;

    private SaldoService $saldoService;

    private User $user;

    private string $paymentUserId;

    /**
     * Creates a user with refund and income balance and resolves the real service.
     *
     * @return void Each test starts from the same balance and an empty history.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // --- step 1 - Create the user balance - start ---
        $this->user = User::factory()->create();

        SaldoUser::create([
            'user_id' => $this->user->id,
            'saldo_income' => self::STARTING_INCOME,
            'saldo_refund' => self::STARTING_REFUND,
        ]);
        // --- step 1 - Create the user balance - end ---

        // --- step 2 - Prepare the deduction inputs - start ---
        // saldo_histories has no foreign key on payment_user_id, so any UUID is stored as is.
        $this->saldoService = app(SaldoService::class);
        $this->paymentUserId = (string) Str::uuid();
        // --- step 2 - Prepare the deduction inputs - end ---
    }

    /**
     * Confirms refund is used first, income covers the rest, and the history records both totals.
     *
     * @return void The returned ID points to the stored withdrawal history row.
     */
    public function test_refund_is_used_before_income_and_history_records_the_totals(): void
    {
        // --- step 1 - Deduct more than the refund - start ---
        $result = $this->saldoService->deductWithdrawalBalance(
            $this->user->id,
            $this->paymentUserId,
            self::MIXED_WITHDRAWAL,
        );
        // --- step 1 - Deduct more than the refund - end ---

        // --- step 2 - Check the remaining balance - start ---
        $startingTotal = self::STARTING_REFUND + self::STARTING_INCOME;
        $remainingIncome = $startingTotal - self::MIXED_WITHDRAWAL;

        $this->assertSame('success', $result['status']);
        $this->assertBalance(0, $remainingIncome);
        // --- step 2 - Check the remaining balance - end ---

        // --- step 3 - Check the portions reported for a later refund - start ---
        $this->assertSame(self::STARTING_REFUND, $result['refundPortion']);
        $this->assertSame(self::MIXED_WITHDRAWAL - self::STARTING_REFUND, $result['incomePortion']);
        // --- step 3 - Check the portions reported for a later refund - end ---

        // --- step 4 - Check the history row - start ---
        $this->assertArrayHasKey('saldoHistoryId', $result);
        $this->assertDatabaseCount('saldo_histories', 1);
        $this->assertDatabaseHas('saldo_histories', [
            'id' => $result['saldoHistoryId'],
            'user_id' => $this->user->id,
            'transaction_user_id' => null,
            'payment_user_id' => $this->paymentUserId,
            'type' => 'withdrawal',
            'price' => self::MIXED_WITHDRAWAL,
            'saldo_before' => $startingTotal,
            'saldo_after' => $remainingIncome,
        ]);
        // --- step 4 - Check the history row - end ---
    }

    /**
     * Keeps income untouched when the refund alone covers the withdrawal.
     *
     * @return void Only the refund decreases.
     */
    public function test_refund_only_withdrawal_leaves_income_untouched(): void
    {
        // --- step 1 - Deduct less than the refund - start ---
        $refundOnlyWithdrawal = 20000;

        $result = $this->saldoService->deductWithdrawalBalance(
            $this->user->id,
            $this->paymentUserId,
            $refundOnlyWithdrawal,
        );
        // --- step 1 - Deduct less than the refund - end ---

        // --- step 2 - Check the remaining balance - start ---
        $remainingRefund = self::STARTING_REFUND - $refundOnlyWithdrawal;

        $this->assertSame('success', $result['status']);
        $this->assertBalance($remainingRefund, self::STARTING_INCOME);
        $this->assertDatabaseCount('saldo_histories', 1);
        // --- step 2 - Check the remaining balance - end ---

        // --- step 3 - Check that no income portion was reported - start ---
        $this->assertSame($refundOnlyWithdrawal, $result['refundPortion']);
        $this->assertSame(0, $result['incomePortion']);
        // --- step 3 - Check that no income portion was reported - end ---
    }

    /**
     * Returns each held portion to its own bucket and records a refund history row.
     *
     * @return void The balance is back to its starting split and the history chain continues.
     */
    public function test_refund_returns_each_portion_to_its_bucket(): void
    {
        // --- step 1 - Deduct across both buckets - start ---
        $deduction = $this->saldoService->deductWithdrawalBalance(
            $this->user->id,
            $this->paymentUserId,
            self::MIXED_WITHDRAWAL,
        );

        // The refund below needs the portions of a successful deduction.
        $this->assertSame('success', $deduction['status']);
        // --- step 1 - Deduct across both buckets - end ---

        // --- step 2 - Refund the held portions - start ---
        $refundHistoryId = $this->saldoService->refundWithdrawalBalance(
            $this->user->id,
            $this->paymentUserId,
            $deduction['refundPortion'],
            $deduction['incomePortion'],
        );
        // --- step 2 - Refund the held portions - end ---

        // --- step 3 - Check the restored balance - start ---
        // Putting everything back into income would turn refund money into withdrawable income.
        $this->assertBalance(self::STARTING_REFUND, self::STARTING_INCOME);
        // --- step 3 - Check the restored balance - end ---

        // --- step 4 - Check the refund history row - start ---
        $startingTotal = self::STARTING_REFUND + self::STARTING_INCOME;
        $heldTotal = $startingTotal - self::MIXED_WITHDRAWAL;

        $this->assertDatabaseHas('saldo_histories', [
            'id' => $refundHistoryId,
            'user_id' => $this->user->id,
            'payment_user_id' => $this->paymentUserId,
            'type' => 'withdrawal_refund',
            'price' => self::MIXED_WITHDRAWAL,
            'saldo_before' => $heldTotal,
            'saldo_after' => $startingTotal,
        ]);
        // --- step 4 - Check the refund history row - end ---
    }

    /**
     * Refuses a refund for a user whose balance row is missing instead of creating one.
     *
     * @return void The caller's transaction must roll back on the exception.
     */
    public function test_refund_without_a_balance_row_throws(): void
    {
        // Creating a row here would hide data corruption behind a silent refund.
        $userWithoutBalance = User::factory()->create();

        $this->expectException(RuntimeException::class);

        $this->saldoService->refundWithdrawalBalance(
            $userWithoutBalance->id,
            $this->paymentUserId,
            self::MIXED_WITHDRAWAL,
            0,
        );
    }

    /**
     * Rejects zero and negative amounts, which would otherwise raise the balance.
     *
     * @return void Neither amount changes the balance or writes history.
     */
    public function test_amounts_below_one_rupiah_are_rejected_without_changes(): void
    {
        // Subtracting a negative amount would raise the balance that can be withdrawn again.
        foreach ([0, -5000] as $amount) {
            $result = $this->saldoService->deductWithdrawalBalance(
                $this->user->id,
                $this->paymentUserId,
                $amount,
            );

            $this->assertSame(
                ['status' => 'error', 'message' => 'price must be at least 1'],
                $result,
            );
            $this->assertNothingWasDeducted();
        }
    }

    /**
     * Rejects a withdrawal larger than the balance instead of clamping the balance to zero.
     *
     * @return void The balance and history stay unchanged.
     */
    public function test_insufficient_balance_is_rejected_instead_of_clamped(): void
    {
        // The old code set the balance to zero and still recorded the full withdrawal.
        $result = $this->saldoService->deductWithdrawalBalance(
            $this->user->id,
            $this->paymentUserId,
            self::OVERDRAWN_WITHDRAWAL,
        );

        $this->assertSame(['status' => 'error', 'message' => 'saldo is not enough'], $result);
        $this->assertNothingWasDeducted();
    }

    /**
     * Rejects a missing user ID, an unknown user, and a user without a balance row.
     *
     * @return void None of the requests write history.
     */
    public function test_missing_users_and_balance_rows_are_rejected(): void
    {
        // --- step 1 - Reject a missing or unknown user - start ---
        $userNotFound = ['status' => 'error', 'message' => 'user not found'];

        foreach ([null, (string) Str::uuid()] as $userId) {
            $result = $this->saldoService->deductWithdrawalBalance(
                $userId,
                $this->paymentUserId,
                self::MIXED_WITHDRAWAL,
            );

            $this->assertSame($userNotFound, $result);
        }
        // --- step 1 - Reject a missing or unknown user - end ---

        // --- step 2 - Reject a user without a balance row - start ---
        // The old code called save() on null here and failed with an Error.
        $userWithoutBalance = User::factory()->create();

        $result = $this->saldoService->deductWithdrawalBalance(
            $userWithoutBalance->id,
            $this->paymentUserId,
            self::MIXED_WITHDRAWAL,
        );

        $this->assertSame(['status' => 'error', 'message' => 'saldo not found'], $result);
        $this->assertNothingWasDeducted();
        // --- step 2 - Reject a user without a balance row - end ---
    }

    /**
     * Rolls back the balance deduction when the history row cannot be written.
     *
     * @return void The balance keeps its starting value after the exception.
     */
    public function test_failed_history_write_rolls_back_the_balance(): void
    {
        // --- step 1 - Make the history write fail - start ---
        // The listener runs after the balance row was saved inside the same transaction.
        SaldoHistory::creating(function (): never {
            throw new RuntimeException('History write failed.');
        });
        // --- step 1 - Make the history write fail - end ---

        // --- step 2 - Capture the failed deduction - start ---
        $exception = null;

        try {
            $this->saldoService->deductWithdrawalBalance(
                $this->user->id,
                $this->paymentUserId,
                self::MIXED_WITHDRAWAL,
            );
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }
        // --- step 2 - Capture the failed deduction - end ---

        // --- step 3 - Check the rollback - start ---
        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertNothingWasDeducted();
        // --- step 3 - Check the rollback - end ---
    }

    /**
     * Confirms the balance row is read with FOR UPDATE on PostgreSQL.
     *
     * @return void SQLite drops the lock clause, so the check only runs on PostgreSQL.
     */
    public function test_balance_row_is_read_with_a_row_lock(): void
    {
        // --- step 1 - Skip drivers without row locks - start ---
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The FOR UPDATE clause is verified on isolated PostgreSQL.');
        }
        // --- step 1 - Skip drivers without row locks - end ---

        // --- step 2 - Record the deduction queries - start ---
        DB::enableQueryLog();

        $this->saldoService->deductWithdrawalBalance(
            $this->user->id,
            $this->paymentUserId,
            self::MIXED_WITHDRAWAL,
        );

        $queries = array_column(DB::getQueryLog(), 'query');

        DB::disableQueryLog();
        // --- step 2 - Record the deduction queries - end ---

        // --- step 3 - Check the locked read - start ---
        $lockedReads = array_filter(
            $queries,
            fn (string $query): bool => str_contains($query, 'from "saldo_users"')
                && str_ends_with($query, 'for update'),
        );

        $this->assertCount(1, $lockedReads);
        // --- step 3 - Check the locked read - end ---
    }

    /**
     * Checks the user's refund and income balance.
     *
     * @param  int  $refund  Expected refund balance in whole rupiah.
     * @param  int  $income  Expected income balance in whole rupiah.
     *
     * @return void Fails when either balance differs.
     */
    private function assertBalance(int $refund, int $income): void
    {
        $this->assertDatabaseHas('saldo_users', [
            'user_id' => $this->user->id,
            'saldo_refund' => $refund,
            'saldo_income' => $income,
        ]);
    }

    /**
     * Confirms the starting balance is unchanged and no history row was written.
     *
     * @return void Fails when a rejected or failed deduction left a change behind.
     */
    private function assertNothingWasDeducted(): void
    {
        $this->assertBalance(self::STARTING_REFUND, self::STARTING_INCOME);
        $this->assertDatabaseCount('saldo_histories', 0);
    }
}
