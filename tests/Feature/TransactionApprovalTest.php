<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateApiRequest;
use App\Models\SaldoHistory;
use App\Models\TransactionInvoice;
use App\Models\TransactionUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Verifies seller approval responses and the balance credit boundary of the approval endpoint.
 */
class TransactionApprovalTest extends TestCase
{
    use RefreshDatabase;

    private const APPROVAL_ENDPOINT = '/api/transaction/approved';

    private const PRODUCT_PRICE = 150000;

    private const SELLER_ONLY_MESSAGE = 'This Action Only For Seller';

    private const EMPTY_ID_MESSAGE = 'Transaction User ID Cannot Be Empty';

    private const NON_STRING_ID_MESSAGE = 'Transaction User ID Must Be A String';

    private const UNPAID_MESSAGE = 'Transaksi Belum Dibayar Pembeli';

    private const PAID_INVOICE_STATUS = 'done';

    /**
     * Bypasses only Clerk transport authentication and keeps request normalization middleware.
     *
     * @return void The tests use Laravel's local authenticated user without external calls.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(AuthenticateApiRequest::class);
    }

    /**
     * Confirms a valid approval marks the row done and credits the product price exactly once.
     *
     * @return void The seller receives the refreshed transaction response shape.
     */
    public function test_seller_approval_marks_done_and_credits_income_once(): void
    {
        // --- step 1 - Prepare an approvable seller transaction - start ---
        $seller = User::factory()->create();
        $transaction = $this->createTransaction($seller);

        $this->actingAs($seller);
        // --- step 1 - Prepare an approvable seller transaction - end ---

        // --- step 2 - Approve the transaction - start ---
        $response = $this->postJson(self::APPROVAL_ENDPOINT, [
            'user_type' => 'seller',
            'transaction_user_id' => $transaction->id,
        ]);
        // --- step 2 - Approve the transaction - end ---

        // --- step 3 - Check the response shape - start ---
        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['status', 'transactions', 'counts', 'pagination']);
        // --- step 3 - Check the response shape - end ---

        // --- step 4 - Check the approved status - start ---
        $approvedTransaction = $transaction->fresh();

        $this->assertSame('done', $approvedTransaction?->status);
        // --- step 4 - Check the approved status - end ---

        // --- step 5 - Check the balance credit - start ---
        // One row and one history entry prove the product price was credited exactly once.
        $this->assertDatabaseHas('saldo_users', [
            'user_id' => $seller->id,
            'saldo_income' => self::PRODUCT_PRICE,
        ]);
        $this->assertDatabaseCount('saldo_histories', 1);
        $this->assertDatabaseHas('saldo_histories', [
            'user_id' => $seller->id,
            'transaction_user_id' => $transaction->id,
            'type' => 'incoming',
            'price' => self::PRODUCT_PRICE,
        ]);
        // --- step 5 - Check the balance credit - end ---
    }

    /**
     * Keeps the existing 401 response for a missing identity and for a deleted local user.
     *
     * @return void Neither request reaches the transaction row.
     */
    public function test_missing_and_deleted_users_receive_401_without_approval(): void
    {
        // --- step 1 - Prepare a transaction owned by a deleted seller - start ---
        $seller = User::factory()->create();
        $transaction = $this->createTransaction($seller);
        $payload = ['user_type' => 'seller', 'transaction_user_id' => $transaction->id];
        // --- step 1 - Prepare a transaction owned by a deleted seller - end ---

        // --- step 2 - Request approval without an identity - start ---
        $this->postJson(self::APPROVAL_ENDPOINT, $payload)
            ->assertUnauthorized()
            ->assertExactJson(['status' => 'error', 'message' => 'Unauthorized']);

        $this->assertTransactionWasNotApproved($transaction);
        // --- step 2 - Request approval without an identity - end ---

        // --- step 3 - Request approval after the seller is deleted - start ---
        $this->actingAs($seller);
        $seller->delete();

        $this->postJson(self::APPROVAL_ENDPOINT, $payload)
            ->assertUnauthorized()
            ->assertExactJson(['status' => 'error', 'message' => 'Unauthorized']);

        $this->assertTransactionWasNotApproved($transaction);
        // --- step 3 - Request approval after the seller is deleted - end ---
    }

    /**
     * Keeps the existing 400 response for perspectives other than the seller string.
     *
     * @return void Missing, buyer, and array perspectives do not change the transaction.
     */
    public function test_non_seller_perspectives_keep_the_existing_400(): void
    {
        // --- step 1 - Prepare an approvable seller transaction - start ---
        $seller = User::factory()->create();
        $transaction = $this->createTransaction($seller);

        $this->actingAs($seller);
        // --- step 1 - Prepare an approvable seller transaction - end ---

        // --- step 2 - Reject each non-seller perspective - start ---
        foreach ([[], ['user_type' => 'buyer'], ['user_type' => ['seller']]] as $perspective) {
            $payload = [...$perspective, 'transaction_user_id' => $transaction->id];

            $this->postJson(self::APPROVAL_ENDPOINT, $payload)
                ->assertBadRequest()
                ->assertExactJson(['status' => 'error', 'message' => self::SELLER_ONLY_MESSAGE]);

            $this->assertTransactionWasNotApproved($transaction);
        }
        // --- step 2 - Reject each non-seller perspective - end ---
    }

    /**
     * Rejects a JSON boolean perspective that loose comparison used to treat as the seller string.
     *
     * @return void The request receives 400 before the transaction or balance changes.
     */
    public function test_boolean_true_perspective_is_rejected_without_approval(): void
    {
        // --- step 1 - Prepare an approvable seller transaction - start ---
        $seller = User::factory()->create();
        $transaction = $this->createTransaction($seller);

        $this->actingAs($seller);
        // --- step 1 - Prepare an approvable seller transaction - end ---

        // --- step 2 - Request approval with a boolean perspective - start ---
        $this->postJson(self::APPROVAL_ENDPOINT, [
            'user_type' => true,
            'transaction_user_id' => $transaction->id,
        ])
            ->assertBadRequest()
            ->assertExactJson(['status' => 'error', 'message' => self::SELLER_ONLY_MESSAGE]);

        $this->assertTransactionWasNotApproved($transaction);
        // --- step 2 - Request approval with a boolean perspective - end ---
    }

    /**
     * Keeps the existing 400 response for every value that the empty check already rejected.
     *
     * @return void Missing, null, blank, and falsy identifiers do not reach the transaction query.
     */
    public function test_empty_transaction_ids_keep_the_existing_400(): void
    {
        // --- step 1 - Prepare an approvable seller transaction - start ---
        $seller = User::factory()->create();
        $transaction = $this->createTransaction($seller);

        $this->actingAs($seller);
        // --- step 1 - Prepare an approvable seller transaction - end ---

        // --- step 2 - Reject each empty identifier - start ---
        $emptyIdentifiers = [
            [],
            ['transaction_user_id' => null],
            ['transaction_user_id' => ''],
            ['transaction_user_id' => '   '],
            ['transaction_user_id' => '0'],
            ['transaction_user_id' => false],
            ['transaction_user_id' => 0],
            ['transaction_user_id' => []],
        ];

        foreach ($emptyIdentifiers as $identifier) {
            $this->postJson(self::APPROVAL_ENDPOINT, ['user_type' => 'seller', ...$identifier])
                ->assertBadRequest()
                ->assertExactJson(['status' => 'error', 'message' => self::EMPTY_ID_MESSAGE]);

            $this->assertTransactionWasNotApproved($transaction);
        }
        // --- step 2 - Reject each empty identifier - end ---
    }

    /**
     * Rejects nonempty identifiers that are not strings before the transaction query runs.
     *
     * @return void Arrays, numbers, and booleans receive 422 without changing the transaction.
     */
    public function test_non_string_transaction_ids_are_rejected_with_422(): void
    {
        // --- step 1 - Prepare an approvable seller transaction - start ---
        $seller = User::factory()->create();
        $transaction = $this->createTransaction($seller);

        $this->actingAs($seller);
        // --- step 1 - Prepare an approvable seller transaction - end ---

        // --- step 2 - Reject each non-string identifier - start ---
        foreach ([[$transaction->id], 123, 1.5, true] as $identifier) {
            $this->postJson(self::APPROVAL_ENDPOINT, [
                'user_type' => 'seller',
                'transaction_user_id' => $identifier,
            ])
                ->assertUnprocessable()
                ->assertExactJson(['status' => 'error', 'message' => self::NON_STRING_ID_MESSAGE]);

            $this->assertTransactionWasNotApproved($transaction);
        }
        // --- step 2 - Reject each non-string identifier - end ---
    }

    /**
     * Keeps the existing 404 response for unknown, foreign, and already approved transactions.
     *
     * @return void None of the requests changes a transaction or credits a balance.
     */
    public function test_unavailable_transactions_keep_the_existing_404(): void
    {
        // --- step 1 - Prepare foreign and completed transactions - start ---
        $seller = User::factory()->create();
        $otherSeller = User::factory()->create();
        $foreignTransaction = $this->createTransaction($otherSeller);
        $completedTransaction = $this->createTransaction($seller, 'done');

        $this->actingAs($seller);
        // --- step 1 - Prepare foreign and completed transactions - end ---

        // --- step 2 - Reject each unavailable transaction - start ---
        $unknownId = '00000000-0000-4000-8000-000000000000';
        $unavailableIds = [$unknownId, $foreignTransaction->id, $completedTransaction->id];

        foreach ($unavailableIds as $transactionId) {
            $this->postJson(self::APPROVAL_ENDPOINT, [
                'user_type' => 'seller',
                'transaction_user_id' => $transactionId,
            ])
                ->assertNotFound()
                ->assertExactJson(['status' => 'error', 'message' => 'Transaksi Tidak Ditemukan']);
        }
        // --- step 2 - Reject each unavailable transaction - end ---

        // --- step 3 - Check that no row changed - start ---
        $this->assertTransactionWasNotApproved($foreignTransaction);
        $this->assertSame('done', $completedTransaction->fresh()?->status);
        // --- step 3 - Check that no row changed - end ---
    }

    /**
     * Rejects approval while the buyer has not paid, including a row without an invoice.
     *
     * @return void Each request receives 400 before the status or balance changes.
     */
    public function test_unpaid_transactions_are_rejected_with_400(): void
    {
        // --- step 1 - Prepare unpaid seller transactions - start ---
        $seller = User::factory()->create();
        $pendingInvoiceTransaction = $this->createTransaction($seller, invoiceStatus: 'pending');
        $transactionWithoutInvoice = $this->createTransaction($seller, invoiceStatus: null);

        $this->actingAs($seller);
        // --- step 1 - Prepare unpaid seller transactions - end ---

        // --- step 2 - Reject each unpaid transaction - start ---
        foreach ([$pendingInvoiceTransaction, $transactionWithoutInvoice] as $transaction) {
            $this->postJson(self::APPROVAL_ENDPOINT, [
                'user_type' => 'seller',
                'transaction_user_id' => $transaction->id,
            ])
                ->assertBadRequest()
                ->assertExactJson(['status' => 'error', 'message' => self::UNPAID_MESSAGE]);

            $this->assertTransactionWasNotApproved($transaction);
        }
        // --- step 2 - Reject each unpaid transaction - end ---
    }

    /**
     * Rolls the approval back when the income credit fails, so the seller can approve again.
     *
     * @return void The failed request keeps the row waiting; the retry credits exactly once.
     */
    public function test_failed_credit_rolls_back_the_approval(): void
    {
        // --- step 1 - Prepare an approvable seller transaction - start ---
        $seller = User::factory()->create();
        $transaction = $this->createTransaction($seller);
        $payload = ['user_type' => 'seller', 'transaction_user_id' => $transaction->id];

        $this->actingAs($seller);
        // --- step 1 - Prepare an approvable seller transaction - end ---

        // --- step 2 - Make only the first history write fail - start ---
        // The history row is written after the status and the balance were saved, so the failure
        // proves both are rolled back together.
        $historyWriteFailed = false;

        SaldoHistory::creating(function () use (&$historyWriteFailed): void {
            if ($historyWriteFailed) {
                return;
            }

            $historyWriteFailed = true;

            throw new RuntimeException('History write failed.');
        });
        // --- step 2 - Make only the first history write fail - end ---

        // --- step 3 - Request the approval while the credit fails - start ---
        $failedResponse = $this->postJson(self::APPROVAL_ENDPOINT, $payload);
        // --- step 3 - Request the approval while the credit fails - end ---

        // --- step 4 - Check the failed approval left no change - start ---
        $failedResponse->assertInternalServerError();

        $this->assertTransactionWasNotApproved($transaction);
        // --- step 4 - Check the failed approval left no change - end ---

        // --- step 5 - Retry the approval - start ---
        $retryResponse = $this->postJson(self::APPROVAL_ENDPOINT, $payload);
        // --- step 5 - Retry the approval - end ---

        // --- step 6 - Check the retry credited once - start ---
        $retryResponse->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame('done', $transaction->fresh()?->status);
        $this->assertDatabaseHas('saldo_users', [
            'user_id' => $seller->id,
            'saldo_income' => self::PRODUCT_PRICE,
        ]);
        $this->assertDatabaseCount('saldo_histories', 1);
        // --- step 6 - Check the retry credited once - end ---
    }

    /**
     * Confirms the approval reads the transaction row with FOR UPDATE on PostgreSQL.
     *
     * @return void SQLite drops the lock clause, so the check only runs on PostgreSQL.
     */
    public function test_approval_reads_the_transaction_row_with_a_row_lock(): void
    {
        // --- step 1 - Skip drivers without row locks - start ---
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The FOR UPDATE clause is verified on isolated PostgreSQL.');
        }
        // --- step 1 - Skip drivers without row locks - end ---

        // --- step 2 - Prepare an approvable seller transaction - start ---
        $seller = User::factory()->create();
        $transaction = $this->createTransaction($seller);

        $this->actingAs($seller);
        // --- step 2 - Prepare an approvable seller transaction - end ---

        // --- step 3 - Record the approval queries - start ---
        DB::enableQueryLog();

        $this->postJson(self::APPROVAL_ENDPOINT, [
            'user_type' => 'seller',
            'transaction_user_id' => $transaction->id,
        ])->assertOk();

        $queries = array_column(DB::getQueryLog(), 'query');

        DB::disableQueryLog();
        // --- step 3 - Record the approval queries - end ---

        // --- step 4 - Check the locked read - start ---
        // The refreshed transaction list also reads transaction_users, but without a lock.
        $lockedReads = array_filter(
            $queries,
            fn (string $query): bool => str_contains($query, 'from "transaction_users"')
                && str_ends_with($query, 'for update'),
        );

        $this->assertCount(1, $lockedReads);
        // --- step 4 - Check the locked read - end ---
    }

    /**
     * Creates the minimal seller transaction row read by the approval endpoint.
     *
     * @param  User  $seller  Seller who owns the transaction and would receive the income.
     * @param  string  $status  Starting status; only `approved_seller` can be approved.
     * @param  string|null  $invoiceStatus  Parent invoice status; null creates no invoice.
     *
     * @return TransactionUser Persisted transaction with a known product price.
     */
    private function createTransaction(
        User $seller,
        string $status = 'approved_seller',
        ?string $invoiceStatus = self::PAID_INVOICE_STATUS,
    ): TransactionUser {
        // --- step 1 - Create the buyer invoice - start ---
        // Approval only credits a transaction whose invoice the buyer already paid.
        $buyer = User::factory()->create();
        $invoiceId = null;

        if ($invoiceStatus !== null) {
            $invoiceId = TransactionInvoice::create([
                'user_id_buyer' => $buyer->id,
                'price' => self::PRODUCT_PRICE,
                'status' => $invoiceStatus,
            ])->id;
        }
        // --- step 1 - Create the buyer invoice - end ---

        // --- step 2 - Create the seller transaction - start ---
        $transaction = TransactionUser::create([
            'user_id_seller' => $seller->id,
            'user_id_buyer' => $buyer->id,
            'transaction_invoice_id' => $invoiceId,
            'transaction_number' => 'TRX-TEST-'.uniqid(),
            'product_price' => self::PRODUCT_PRICE,
            'status' => $status,
        ]);
        // --- step 2 - Create the seller transaction - end ---

        return $transaction;
    }

    /**
     * Asserts that a rejected request left the transaction and every balance table untouched.
     *
     * @param  TransactionUser  $transaction  Transaction that must still wait for seller approval.
     *
     * @return void Fails the test when the status or balance changed.
     */
    private function assertTransactionWasNotApproved(TransactionUser $transaction): void
    {
        $this->assertSame('approved_seller', $transaction->fresh()?->status);
        $this->assertDatabaseCount('saldo_users', 0);
        $this->assertDatabaseCount('saldo_histories', 0);
    }
}
