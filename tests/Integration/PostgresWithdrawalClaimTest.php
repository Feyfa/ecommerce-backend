<?php

namespace Tests\Integration;

use App\Models\PaymentList;
use App\Models\PaymentUser;
use App\Models\SaldoUser;
use App\Models\SaldoWithdrawal;
use App\Models\User;
use App\Services\WithdrawalService;
use App\Services\XenditService;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Tests\TestCase;

/**
 * Verifies `withdrawal:check` skips a withdrawal row locked by another PostgreSQL connection.
 *
 * A second connection plays a run that already claimed one withdrawal and has not committed.
 * The application connection gives up after a short lock timeout, so a claim that waited instead
 * of skipping would fail the command rather than hang the test.
 */
class PostgresWithdrawalClaimTest extends TestCase
{
    use DatabaseMigrations;

    private const HOLDER_CONNECTION = 'withdrawal_claim_holder';

    // Short enough to fail fast; SKIP LOCKED returns long before it expires.
    private const LOCK_TIMEOUT = '1s';

    private const WITHDRAWAL_AMOUNT = 100000;

    private ?Connection $holder = null;

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> Lookups sent. */
    private array $lookups = [];

    /**
     * Skips on drivers other than PostgreSQL and refuses non-test databases.
     *
     * @return void The test continues only on a disposable PostgreSQL database.
     *
     * @throws RuntimeException When the PostgreSQL database name does not look disposable.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'PostgreSQL withdrawal claim test requires DB_CONNECTION=pgsql.'
            );
        }

        $database = basename((string) DB::connection()->getDatabaseName());

        if (preg_match('/(?:^|[_\-.])test(?:ing)?(?:$|[_\-.])/i', $database) !== 1) {
            throw new RuntimeException(
                'PostgreSQL withdrawal claim test requires a database name containing test/testing.'
            );
        }
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
     * Confirms a run claims the free due withdrawal and skips the one another run is holding.
     *
     * @return void Only the free withdrawal is looked up, and the locked one stays unchecked.
     */
    public function test_check_skips_a_withdrawal_locked_by_another_run(): void
    {
        // --- step 1 - Hold two committed due withdrawals - start ---
        $this->travelTo(CarbonImmutable::parse('2026-11-24 10:00:00'));

        [$locked, $free] = $this->holdTwoWithdrawals();

        $this->travel(1)->minutes();
        // --- step 1 - Hold two committed due withdrawals - end ---

        // --- step 2 - Lock the first withdrawal from a second connection - start ---
        // Without the timeout, a waiting claim would hang until PHPUnit or the shell stops it.
        config([
            'database.connections.'.self::HOLDER_CONNECTION => config('database.connections.pgsql'),
        ]);

        $this->holder = DB::connection(self::HOLDER_CONNECTION);
        $this->holder->beginTransaction();
        $this->holder->table('saldo_withdrawals')
            ->where('id', $locked->id)
            ->lockForUpdate()
            ->first();

        DB::statement("SET lock_timeout = '".self::LOCK_TIMEOUT."'");
        // --- step 2 - Lock the first withdrawal from a second connection - end ---

        // --- step 3 - Run the check - start ---
        $this->useLookupAnswers([
            new Response(HttpStatus::HTTP_OK, [], '[{"id":"disb-free","status":"PENDING"}]'),
        ]);

        $this->artisan('withdrawal:check')
            ->expectsOutputToContain('claimed=1')
            ->assertSuccessful();
        // --- step 3 - Run the check - end ---

        // --- step 4 - Check only the free withdrawal was looked up - start ---
        $this->assertCount(1, $this->lookups);
        $this->assertSame(
            'external_id='.$free->external_id,
            $this->lookups[0]['request']->getUri()->getQuery(),
        );
        $this->assertSame(0, $locked->refresh()->check_attempts);
        $this->assertSame(1, $free->refresh()->check_attempts);
        // --- step 4 - Check only the free withdrawal was looked up - end ---
    }

    /**
     * Creates a user with balance and holds two withdrawals through the real service.
     *
     * @return array{SaldoWithdrawal, SaldoWithdrawal} Two committed processing withdrawals.
     */
    private function holdTwoWithdrawals(): array
    {
        // --- step 1 - Create the user, balance, and account - start ---
        $user = User::factory()->create();

        SaldoUser::create([
            'user_id' => $user->id,
            'saldo_income' => self::WITHDRAWAL_AMOUNT * 2,
            'saldo_refund' => 0,
        ]);

        $bank = PaymentList::create(['type' => 'withdrawal', 'slug' => 'bca', 'name' => 'BCA']);
        $account = PaymentUser::create([
            'user_id' => $user->id,
            'payment_id' => $bank->id,
            'account' => '001234',
            'name' => 'Jidan',
        ]);
        // --- step 1 - Create the user, balance, and account - end ---

        // --- step 2 - Hold the two withdrawals - start ---
        $withdrawals = [];

        foreach (['a', 'b'] as $suffix) {
            $hold = app(WithdrawalService::class)->hold(
                $user->id,
                $account->id,
                self::WITHDRAWAL_AMOUNT,
                "dis-bca-claim-{$suffix}-".Str::lower((string) Str::ulid()),
            );

            $this->assertSame('success', $hold['status']);

            $withdrawals[] = $hold['withdrawal'];
        }
        // --- step 2 - Hold the two withdrawals - end ---

        return [$withdrawals[0], $withdrawals[1]];
    }

    /**
     * Replaces the Xendit client with queued lookup answers and records each lookup.
     *
     * @param  list<Response>  $answers  Responses in request order.
     *
     * @return void The container resolves XenditService to this client.
     */
    private function useLookupAnswers(array $answers): void
    {
        $handler = HandlerStack::create(new MockHandler($answers));
        $handler->push(Middleware::history($this->lookups));

        $client = new Client(['handler' => $handler]);
        $this->instance(XenditService::class, new XenditService('test-key', $client));
    }
}
