<?php

namespace Tests\Integration;

use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Verifies the first income credit waits for another connection creating the same balance row.
 *
 * A second connection plays a concurrent approval that inserted the seller's first balance row
 * and has not committed yet. Before the fix the credit read nothing, skipped the lock, and its
 * plain INSERT later failed on the unique `user_id`. The application connection gives up after a
 * short lock timeout, so the test can see which query waited.
 */
class PostgresSaldoFirstCreditLockingTest extends TestCase
{
    use DatabaseMigrations;

    private const HOLDER_CONNECTION = 'saldo_first_row_holder';

    // Short enough to fail fast; a query that does not wait returns long before it expires.
    private const LOCK_TIMEOUT = '1s';

    // PostgreSQL SQLSTATE lock_not_available, raised when lock_timeout expires.
    private const LOCK_NOT_AVAILABLE = '55P03';

    private const HOLDER_INCOME = 70000;

    private const CREDIT_AMOUNT = 50000;

    private User $seller;

    private ?Connection $holder = null;

    /**
     * Creates a seller without a balance row, inserts that row from an open second connection,
     * and limits lock waits.
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
            $this->markTestSkipped('PostgreSQL first credit test requires DB_CONNECTION=pgsql.');
        }

        $database = basename((string) DB::connection()->getDatabaseName());

        if (preg_match('/(?:^|[_\-.])test(?:ing)?(?:$|[_\-.])/i', $database) !== 1) {
            throw new RuntimeException(
                'PostgreSQL first credit test requires a database name containing test/testing.'
            );
        }
        // --- step 1 - Refuse a non-disposable PostgreSQL database - end ---

        // --- step 2 - Create the committed seller - start ---
        // DatabaseMigrations commits rows, so both connections see the seller but no balance.
        $this->seller = User::factory()->create();
        // --- step 2 - Create the committed seller - end ---

        // --- step 3 - Insert the first balance row without committing - start ---
        // Without the timeout, a correct wait would hang the application connection until
        // PHPUnit or the shell stops the run.
        $holderConfiguration = config('database.connections.pgsql');

        config(['database.connections.'.self::HOLDER_CONNECTION => $holderConfiguration]);

        $timestamp = now();

        $this->holder = DB::connection(self::HOLDER_CONNECTION);
        $this->holder->beginTransaction();
        $this->holder->table('saldo_users')->insert([
            'id' => (string) Str::orderedUuid(),
            'user_id' => $this->seller->id,
            'saldo_income' => self::HOLDER_INCOME,
            'saldo_refund' => 0,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::statement("SET lock_timeout = '".self::LOCK_TIMEOUT."'");
        // --- step 3 - Insert the first balance row without committing - end ---
    }

    /**
     * Releases the second connection before DatabaseMigrations rolls the schema back.
     *
     * @return void The uncommitted balance row never blocks the schema rollback.
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
     * Confirms the first credit waits on the idempotent insert instead of racing past the lock.
     *
     * @return void No balance row or history is left once both connections roll back.
     */
    public function test_first_credit_waits_for_a_concurrent_first_row(): void
    {
        // --- step 1 - Credit the seller while the first row is uncommitted - start ---
        $transactionService = app(TransactionService::class);

        $exception = $this->captureQueryException(
            fn () => $transactionService->transferSaldo(
                user_id: $this->seller->id,
                transaction_user_id: (string) Str::uuid(),
                price: self::CREDIT_AMOUNT,
                type: 'incoming',
            ),
        );
        // --- step 1 - Credit the seller while the first row is uncommitted - end ---

        // --- step 2 - Check the wait happened on the idempotent insert - start ---
        // The old code waited on a plain INSERT that would fail on the unique `user_id` once the
        // other connection committed.
        $this->assertNotNull($exception);
        $this->assertSame(self::LOCK_NOT_AVAILABLE, $exception->getCode());
        $this->assertStringStartsWith('insert into "saldo_users"', $exception->getSql());
        $this->assertStringEndsWith('on conflict do nothing', $exception->getSql());
        // --- step 2 - Check the wait happened on the idempotent insert - end ---

        // --- step 3 - Check nothing was left behind - start ---
        // The holder row is uncommitted and invisible here, so any visible row would be the
        // credit's own write surviving its rollback.
        $this->assertDatabaseMissing('saldo_users', ['user_id' => $this->seller->id]);
        $this->assertDatabaseCount('saldo_histories', 0);
        // --- step 3 - Check nothing was left behind - end ---
    }

    /**
     * Runs an action that is expected to fail with a database error.
     *
     * @param  callable(): mixed  $action  Service call made while the first row is uncommitted.
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
}
