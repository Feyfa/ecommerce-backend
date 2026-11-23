<?php

namespace Tests\Integration;

use App\Http\Middleware\AuthenticateApiRequest;
use App\Models\TransactionInvoice;
use App\Models\TransactionUser;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Verifies seller approval waits for a transaction row locked by another PostgreSQL connection.
 *
 * A second connection plays an approval of the same transaction that locked the row and has not
 * committed yet. The application connection gives up after a short lock timeout, so the test can
 * see which query waited. An unlocked read would return at once and credit the income again.
 */
class PostgresTransactionApprovalLockingTest extends TestCase
{
    use DatabaseMigrations;

    private const HOLDER_CONNECTION = 'transaction_lock_holder';

    // Short enough to fail fast; an unlocked read returns long before it expires.
    private const LOCK_TIMEOUT = '1s';

    // PostgreSQL SQLSTATE lock_not_available, raised when lock_timeout expires.
    private const LOCK_NOT_AVAILABLE = '55P03';

    private const PRODUCT_PRICE = 150000;

    private User $seller;

    private TransactionUser $transaction;

    private ?Connection $holder = null;

    /**
     * Creates a committed paid transaction, locks it from a second connection, and limits waits.
     *
     * @return void Skips on drivers other than PostgreSQL and refuses non-test databases.
     *
     * @throws RuntimeException When the PostgreSQL database name does not look disposable.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // --- step 1 - Refuse a non-disposable PostgreSQL database - start ---
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL approval lock test requires DB_CONNECTION=pgsql.');
        }

        $database = basename((string) DB::connection()->getDatabaseName());

        if (preg_match('/(?:^|[_\-.])test(?:ing)?(?:$|[_\-.])/i', $database) !== 1) {
            throw new RuntimeException(
                'PostgreSQL approval lock test requires a database name containing test/testing.'
            );
        }
        // --- step 1 - Refuse a non-disposable PostgreSQL database - end ---

        // --- step 2 - Create the paid buyer invoice - start ---
        // DatabaseMigrations commits rows, so the second connection can see and lock them.
        $buyer = User::factory()->create();

        $invoice = TransactionInvoice::create([
            'user_id_buyer' => $buyer->id,
            'price' => self::PRODUCT_PRICE,
            'status' => 'done',
        ]);
        // --- step 2 - Create the paid buyer invoice - end ---

        // --- step 3 - Create the seller transaction waiting for approval - start ---
        $this->seller = User::factory()->create();

        $this->transaction = TransactionUser::create([
            'user_id_seller' => $this->seller->id,
            'user_id_buyer' => $buyer->id,
            'transaction_invoice_id' => $invoice->id,
            'transaction_number' => 'TRX-LOCK-'.uniqid(),
            'product_price' => self::PRODUCT_PRICE,
            'status' => 'approved_seller',
        ]);
        // --- step 3 - Create the seller transaction waiting for approval - end ---

        // --- step 4 - Hold the transaction row lock with a bounded wait - start ---
        // Without the timeout, a correct lock would hang the application connection until
        // PHPUnit or the shell stops the run.
        $holderConfiguration = config('database.connections.pgsql');

        config(['database.connections.'.self::HOLDER_CONNECTION => $holderConfiguration]);

        $this->holder = DB::connection(self::HOLDER_CONNECTION);
        $this->holder->beginTransaction();
        $this->holder->table('transaction_users')
            ->where('id', $this->transaction->id)
            ->lockForUpdate()
            ->first();

        DB::statement("SET lock_timeout = '".self::LOCK_TIMEOUT."'");
        // --- step 4 - Hold the transaction row lock with a bounded wait - end ---
    }

    /**
     * Releases the second connection before DatabaseMigrations rolls the schema back.
     *
     * @return void The held row lock never blocks the schema rollback.
     */
    protected function tearDown(): void
    {
        if ($this->holder !== null) {
            $this->holder->rollBack();
            DB::purge(self::HOLDER_CONNECTION);
        }

        parent::tearDown();
    }

    /**
     * Confirms an approval waits on the locked transaction read before changing anything.
     *
     * @return void The status and the balance stay unchanged after the lock timeout.
     */
    public function test_approval_waits_for_the_transaction_row_lock(): void
    {
        // --- step 1 - Prepare the seller request - start ---
        $this->withoutMiddleware(AuthenticateApiRequest::class);
        $this->withoutExceptionHandling();
        $this->actingAs($this->seller);
        // --- step 1 - Prepare the seller request - end ---

        // --- step 2 - Request the approval while the row is locked - start ---
        $payload = [
            'user_type' => 'seller',
            'transaction_user_id' => $this->transaction->id,
        ];

        $exception = $this->captureQueryException(
            fn () => $this->postJson('/api/transaction/approved', $payload),
        );
        // --- step 2 - Request the approval while the row is locked - end ---

        // --- step 3 - Check the wait happened on the locked read - start ---
        // The old unlocked read returned at once, so a concurrent approval credited again.
        $this->assertWaitedOnLockedTransactionRead($exception);
        // --- step 3 - Check the wait happened on the locked read - end ---

        // --- step 4 - Check the approval left no change - start ---
        $this->assertSame('approved_seller', $this->transaction->fresh()?->status);
        $this->assertDatabaseCount('saldo_users', 0);
        $this->assertDatabaseCount('saldo_histories', 0);
        // --- step 4 - Check the approval left no change - end ---
    }

    /**
     * Runs an action that is expected to fail with a database error.
     *
     * @param  callable(): mixed  $action  Request made while the transaction row is locked.
     *
     * @return QueryException|null The database error, or null when the action finished.
     */
    private function captureQueryException(callable $action): ?QueryException
    {
        try {
            $action();
        } catch (QueryException $exception) {
            return $exception;
        }

        return null;
    }

    /**
     * Confirms the lock timeout was raised by the locked read of the transaction row.
     *
     * @param  QueryException|null  $exception  Error captured while the row was locked.
     *
     * @return void Fails when nothing waited or when another query was the one that waited.
     */
    private function assertWaitedOnLockedTransactionRead(?QueryException $exception): void
    {
        $this->assertNotNull($exception);
        $this->assertSame(self::LOCK_NOT_AVAILABLE, $exception->getCode());
        $this->assertStringContainsString('from "transaction_users"', $exception->getSql());
        $this->assertStringEndsWith('for update', $exception->getSql());
    }
}
