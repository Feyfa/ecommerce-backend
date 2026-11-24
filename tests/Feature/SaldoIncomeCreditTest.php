<?php

namespace Tests\Feature;

use App\Models\SaldoHistory;
use App\Models\SaldoUser;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Verifies the seller income credit that runs after a transaction is approved.
 */
class SaldoIncomeCreditTest extends TestCase
{
    use RefreshDatabase;

    private const STARTING_INCOME = 50000;

    private const STARTING_REFUND = 10000;

    private const CREDIT_AMOUNT = 150000;

    private TransactionService $transactionService;

    private User $seller;

    private string $transactionUserId;

    /**
     * Creates a seller with an existing balance row and resolves the real service.
     *
     * @return void Each test starts from the same balance and an empty history.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // --- step 1 - Create the seller balance - start ---
        $this->seller = User::factory()->create();

        SaldoUser::create([
            'user_id' => $this->seller->id,
            'saldo_income' => self::STARTING_INCOME,
            'saldo_refund' => self::STARTING_REFUND,
        ]);
        // --- step 1 - Create the seller balance - end ---

        // --- step 2 - Prepare the credit inputs - start ---
        // saldo_histories has no foreign key on transaction_user_id, so any UUID is stored as is.
        $this->transactionService = app(TransactionService::class);
        $this->transactionUserId = (string) Str::uuid();
        // --- step 2 - Prepare the credit inputs - end ---
    }

    /**
     * Confirms the credit raises income and records the totals before and after it.
     *
     * @return void Refund stays unchanged.
     */
    public function test_credit_adds_income_and_records_the_totals(): void
    {
        // --- step 1 - Credit the seller - start ---
        $this->transactionService->transferSaldo(
            user_id: $this->seller->id,
            transaction_user_id: $this->transactionUserId,
            price: self::CREDIT_AMOUNT,
            type: 'incoming',
        );
        // --- step 1 - Credit the seller - end ---

        // --- step 2 - Check the balance - start ---
        // Refund is part of the history totals but must not change.
        $this->assertDatabaseHas('saldo_users', [
            'user_id' => $this->seller->id,
            'saldo_income' => self::STARTING_INCOME + self::CREDIT_AMOUNT,
            'saldo_refund' => self::STARTING_REFUND,
        ]);
        // --- step 2 - Check the balance - end ---

        // --- step 3 - Check the history row - start ---
        $startingTotal = self::STARTING_INCOME + self::STARTING_REFUND;

        $this->assertDatabaseCount('saldo_histories', 1);
        $this->assertDatabaseHas('saldo_histories', [
            'user_id' => $this->seller->id,
            'transaction_user_id' => $this->transactionUserId,
            'type' => 'incoming',
            'price' => self::CREDIT_AMOUNT,
            'saldo_before' => $startingTotal,
            'saldo_after' => $startingTotal + self::CREDIT_AMOUNT,
        ]);
        // --- step 3 - Check the history row - end ---
    }

    /**
     * Rolls back the income credit when the history row cannot be written.
     *
     * @return void The balance keeps its starting value after the exception.
     */
    public function test_failed_history_write_rolls_back_the_credit(): void
    {
        // --- step 1 - Make the history write fail - start ---
        // The listener runs after the balance row was saved inside the same transaction.
        SaldoHistory::creating(function (): never {
            throw new RuntimeException('History write failed.');
        });
        // --- step 1 - Make the history write fail - end ---

        // --- step 2 - Capture the failed credit - start ---
        $exception = null;

        try {
            $this->creditSeller();
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }
        // --- step 2 - Capture the failed credit - end ---

        // --- step 3 - Check the rollback - start ---
        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertDatabaseHas('saldo_users', [
            'user_id' => $this->seller->id,
            'saldo_income' => self::STARTING_INCOME,
        ]);
        $this->assertDatabaseCount('saldo_histories', 0);
        // --- step 3 - Check the rollback - end ---
    }

    /**
     * Confirms the balance row is read with FOR UPDATE on PostgreSQL.
     *
     * @return void SQLite drops the lock clause, so the check only runs on PostgreSQL.
     */
    public function test_credit_reads_the_balance_row_with_a_row_lock(): void
    {
        // --- step 1 - Skip drivers without row locks - start ---
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The FOR UPDATE clause is verified on isolated PostgreSQL.');
        }
        // --- step 1 - Skip drivers without row locks - end ---

        // --- step 2 - Record the credit queries - start ---
        DB::enableQueryLog();

        $this->creditSeller();

        $queries = array_column(DB::getQueryLog(), 'query');

        DB::disableQueryLog();
        // --- step 2 - Record the credit queries - end ---

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
     * Confirms the first credit of a seller without a balance row creates exactly one row.
     *
     * @return void The new row holds only the credit and the history starts from zero.
     */
    public function test_first_credit_creates_one_balance_row(): void
    {
        // --- step 1 - Credit a seller without a balance row - start ---
        $newSeller = $this->creditSellerWithoutBalance();
        // --- step 1 - Credit a seller without a balance row - end ---

        // --- step 2 - Check the new balance row - start ---
        // insertOrIgnore bypasses Eloquent, so the service must fill the id and timestamps.
        $balanceRows = SaldoUser::where('user_id', $newSeller->id)->get();
        $balanceRow = $balanceRows->first();

        $this->assertCount(1, $balanceRows);
        $this->assertEquals(self::CREDIT_AMOUNT, $balanceRow?->saldo_income);
        $this->assertEquals(0, $balanceRow?->saldo_refund);
        $this->assertTrue(Str::isUuid((string) $balanceRow?->id));
        $this->assertNotNull($balanceRow?->created_at);
        $this->assertNotNull($balanceRow?->updated_at);
        // --- step 2 - Check the new balance row - end ---

        // --- step 3 - Check the history row - start ---
        $this->assertDatabaseCount('saldo_histories', 1);
        $this->assertDatabaseHas('saldo_histories', [
            'user_id' => $newSeller->id,
            'transaction_user_id' => $this->transactionUserId,
            'type' => 'incoming',
            'price' => self::CREDIT_AMOUNT,
            'saldo_before' => 0,
            'saldo_after' => self::CREDIT_AMOUNT,
        ]);
        // --- step 3 - Check the history row - end ---
    }

    /**
     * Confirms the first credit creates the balance row before the locked read on PostgreSQL.
     *
     * @return void The insert must come first, otherwise the lock finds no row to wait on.
     */
    public function test_first_credit_creates_the_row_before_locking_it(): void
    {
        // --- step 1 - Skip drivers without row locks - start ---
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The FOR UPDATE clause is verified on isolated PostgreSQL.');
        }
        // --- step 1 - Skip drivers without row locks - end ---

        // --- step 2 - Record the credit queries - start ---
        DB::enableQueryLog();

        $this->creditSellerWithoutBalance();

        $queries = array_column(DB::getQueryLog(), 'query');

        DB::disableQueryLog();
        // --- step 2 - Record the credit queries - end ---

        // --- step 3 - Find the idempotent row insert - start ---
        // The old code read first and only then ran a plain INSERT, which two first credits
        // could both reach.
        $rowInserts = array_keys(array_filter(
            $queries,
            fn (string $query): bool => str_starts_with($query, 'insert into "saldo_users"')
                && str_ends_with($query, 'on conflict do nothing'),
        ));
        // --- step 3 - Find the idempotent row insert - end ---

        // --- step 4 - Find the locked balance read - start ---
        $lockedReads = array_keys(array_filter(
            $queries,
            fn (string $query): bool => str_contains($query, 'from "saldo_users"')
                && str_ends_with($query, 'for update'),
        ));
        // --- step 4 - Find the locked balance read - end ---

        // --- step 5 - Check the query order - start ---
        $this->assertCount(1, $rowInserts);
        $this->assertCount(1, $lockedReads);
        $this->assertLessThan($lockedReads[0], $rowInserts[0]);
        // --- step 5 - Check the query order - end ---
    }

    /**
     * Credits a freshly created seller that has no balance row yet.
     *
     * @return User The credited seller, used to look up the new balance and history rows.
     */
    private function creditSellerWithoutBalance(): User
    {
        // setUp gives $this->seller a balance row, so the first-credit path needs another user.
        $newSeller = User::factory()->create();

        $this->transactionService->transferSaldo(
            user_id: $newSeller->id,
            transaction_user_id: $this->transactionUserId,
            price: self::CREDIT_AMOUNT,
            type: 'incoming',
        );

        return $newSeller;
    }

    /**
     * Credits the seller with the fixed amount from the same approved transaction.
     *
     * @return void The service writes the balance and history or throws.
     */
    private function creditSeller(): void
    {
        $this->transactionService->transferSaldo(
            user_id: $this->seller->id,
            transaction_user_id: $this->transactionUserId,
            price: self::CREDIT_AMOUNT,
            type: 'incoming',
        );
    }
}
