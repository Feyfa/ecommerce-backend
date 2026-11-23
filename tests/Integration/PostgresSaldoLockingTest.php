<?php

namespace Tests\Integration;

use App\Http\Middleware\AuthenticateApiRequest;
use App\Models\PaymentList;
use App\Models\PaymentUser;
use App\Models\SaldoUser;
use App\Models\User;
use App\Services\TransactionService;
use App\Services\XenditService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Tests\TestCase;

/**
 * Verifies saldo writers wait for a balance row locked by another PostgreSQL connection.
 *
 * A second connection plays a withdrawal that locked the row and has not committed yet. The
 * application connection gives up after a short lock timeout, so each test can see which query
 * waited. An unlocked read would return at once and fail later on the UPDATE instead.
 */
class PostgresSaldoLockingTest extends TestCase
{
    use DatabaseMigrations;

    private const HOLDER_CONNECTION = 'saldo_lock_holder';

    // Short enough to fail fast; an unlocked read returns long before it expires.
    private const LOCK_TIMEOUT = '1s';

    // PostgreSQL SQLSTATE lock_not_available, raised when lock_timeout expires.
    private const LOCK_NOT_AVAILABLE = '55P03';

    private const STARTING_INCOME = 500000;

    private const WITHDRAWAL_AMOUNT = 100000;

    private const ACCOUNT_NUMBER = '001234';

    private User $user;

    private ?Connection $holder = null;

    /**
     * Creates a committed balance, locks it from a second connection, and limits lock waits.
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
            $this->markTestSkipped('PostgreSQL saldo locking test requires DB_CONNECTION=pgsql.');
        }

        $database = basename((string) DB::connection()->getDatabaseName());

        if (preg_match('/(?:^|[_\-.])test(?:ing)?(?:$|[_\-.])/i', $database) !== 1) {
            throw new RuntimeException(
                'PostgreSQL saldo locking test requires a database name containing test/testing.'
            );
        }
        // --- step 1 - Refuse a non-disposable PostgreSQL database - end ---

        // --- step 2 - Create the committed balance - start ---
        // DatabaseMigrations commits rows, so the second connection can see and lock them.
        $this->user = User::factory()->create();

        SaldoUser::create([
            'user_id' => $this->user->id,
            'saldo_income' => self::STARTING_INCOME,
            'saldo_refund' => 0,
        ]);
        // --- step 2 - Create the committed balance - end ---

        // --- step 3 - Hold the balance row lock with a bounded wait - start ---
        // Without the timeout, a correct lock would hang the application connection until
        // PHPUnit or the shell stops the run.
        $holderConfiguration = config('database.connections.pgsql');

        config(['database.connections.'.self::HOLDER_CONNECTION => $holderConfiguration]);

        $this->holder = DB::connection(self::HOLDER_CONNECTION);
        $this->holder->beginTransaction();
        $this->holder->table('saldo_users')
            ->where('user_id', $this->user->id)
            ->lockForUpdate()
            ->first();

        DB::statement("SET lock_timeout = '".self::LOCK_TIMEOUT."'");
        // --- step 3 - Hold the balance row lock with a bounded wait - end ---
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
     * Confirms a withdrawal waits on the locked balance read and never reaches Xendit meanwhile.
     *
     * @return void The balance and history stay unchanged after the lock timeout.
     */
    public function test_withdrawal_waits_for_the_balance_lock_before_xendit(): void
    {
        // --- step 1 - Prepare the withdrawal fixtures - start ---
        $providerRequests = [];

        $this->createWithdrawalAccount();
        $this->useAcceptingProvider($providerRequests);
        $this->withoutMiddleware(AuthenticateApiRequest::class);
        $this->withoutExceptionHandling();
        $this->actingAs($this->user);
        // --- step 1 - Prepare the withdrawal fixtures - end ---

        // --- step 2 - Request the withdrawal while the row is locked - start ---
        $payload = [
            'paymentAccount' => self::ACCOUNT_NUMBER,
            'wihtdrawPrice' => (string) self::WITHDRAWAL_AMOUNT,
        ];

        $exception = $this->captureQueryException(
            fn () => $this->postJson('/api/saldo-withdraw', $payload),
        );
        // --- step 2 - Request the withdrawal while the row is locked - end ---

        // --- step 3 - Check the wait happened before Xendit - start ---
        // The old unlocked read let the request reach Xendit and wait only on the UPDATE.
        $this->assertWaitedOnLockedRead($exception);
        $this->assertCount(0, $providerRequests);
        $this->assertBalanceUnchanged();
        // --- step 3 - Check the wait happened before Xendit - end ---
    }

    /**
     * Confirms an income credit waits on the locked balance read instead of reading stale income.
     *
     * @return void The balance and history stay unchanged after the lock timeout.
     */
    public function test_income_credit_waits_for_the_balance_lock_before_reading(): void
    {
        // --- step 1 - Credit the seller while the row is locked - start ---
        $transactionService = app(TransactionService::class);

        $exception = $this->captureQueryException(
            fn () => $transactionService->transferSaldo(
                user_id: $this->user->id,
                transaction_user_id: (string) Str::uuid(),
                price: self::WITHDRAWAL_AMOUNT,
                type: 'incoming',
            ),
        );
        // --- step 1 - Credit the seller while the row is locked - end ---

        // --- step 2 - Check the wait happened on the read - start ---
        // The old unlocked read returned stale income and waited only on the UPDATE.
        $this->assertWaitedOnLockedRead($exception);
        $this->assertBalanceUnchanged();
        // --- step 2 - Check the wait happened on the read - end ---
    }

    /**
     * Creates the user's saved BCA withdrawal account.
     *
     * @return void The withdrawal request can resolve the account before locking the balance.
     */
    private function createWithdrawalAccount(): void
    {
        $bank = PaymentList::create(['type' => 'withdrawal', 'slug' => 'bca', 'name' => 'BCA']);

        PaymentUser::create([
            'user_id' => $this->user->id,
            'payment_id' => $bank->id,
            'account' => self::ACCOUNT_NUMBER,
            'name' => 'Jidan',
        ]);
    }

    /**
     * Replaces the Xendit client with one accepted disbursement and a request history.
     *
     * @param  array<int, mixed>  $providerRequests  Receives every request sent to the provider.
     *
     * @return void The container resolves the controller's XenditService to this client.
     */
    private function useAcceptingProvider(array &$providerRequests): void
    {
        $accepted = new Response(HttpStatus::HTTP_OK, [], '{"id":"disb-test","status":"PENDING"}');

        $handler = HandlerStack::create(new MockHandler([$accepted]));
        $handler->push(Middleware::history($providerRequests));

        $client = new Client(['handler' => $handler]);
        $this->instance(XenditService::class, new XenditService('test-key', $client));
    }

    /**
     * Runs an action that is expected to fail with a database error.
     *
     * @param  callable(): mixed  $action  Request or service call made while the row is locked.
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
     * Confirms the lock timeout was raised by a read that asked for the row lock.
     *
     * @param  QueryException|null  $exception  Error captured while the row was locked.
     *
     * @return void Fails when nothing waited or when a later UPDATE was the query that waited.
     */
    private function assertWaitedOnLockedRead(?QueryException $exception): void
    {
        $this->assertNotNull($exception);
        $this->assertSame(self::LOCK_NOT_AVAILABLE, $exception->getCode());
        $this->assertStringEndsWith('for update', $exception->getSql());
    }

    /**
     * Confirms the committed balance and the empty history survived the failed write.
     *
     * @return void Fails when the timed-out write left a change behind.
     */
    private function assertBalanceUnchanged(): void
    {
        $this->assertDatabaseHas('saldo_users', [
            'user_id' => $this->user->id,
            'saldo_income' => self::STARTING_INCOME,
        ]);
        $this->assertDatabaseCount('saldo_histories', 0);
    }
}
