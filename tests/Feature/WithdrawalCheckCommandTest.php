<?php

namespace Tests\Feature;

use App\Enums\WithdrawalStatus;
use App\Models\PaymentList;
use App\Models\PaymentUser;
use App\Models\SaldoUser;
use App\Models\SaldoWithdrawal;
use App\Models\User;
use App\Services\WithdrawalService;
use App\Services\XenditService;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Tests\TestCase;
use Throwable;

/**
 * Verifies `withdrawal:check`: which withdrawals it picks, how each Xendit answer settles them,
 * and when the held balance is returned.
 */
class WithdrawalCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    private const STARTING_INCOME = 1000000;

    private const WITHDRAWAL_AMOUNT = 100000;

    // Fixed start so every schedule assertion compares exact times.
    private const START_TIME = '2026-11-24 10:00:00';

    private User $user;

    private string $paymentUserId;

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> Lookups sent. */
    private array $lookups = [];

    /**
     * Freezes time and creates a user with balance and one withdrawal account.
     *
     * @return void Each test holds its withdrawals from the same balance.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // --- step 1 - Freeze the clock - start ---
        $this->travelTo(CarbonImmutable::parse(self::START_TIME));
        // --- step 1 - Freeze the clock - end ---

        // --- step 2 - Create the user balance - start ---
        $this->user = User::factory()->create();

        SaldoUser::create([
            'user_id' => $this->user->id,
            'saldo_income' => self::STARTING_INCOME,
            'saldo_refund' => 0,
        ]);
        // --- step 2 - Create the user balance - end ---

        // --- step 3 - Create the withdrawal account - start ---
        $bank = PaymentList::create(['type' => 'withdrawal', 'slug' => 'bca', 'name' => 'BCA']);
        $account = PaymentUser::create([
            'user_id' => $this->user->id,
            'payment_id' => $bank->id,
            'account' => '001234',
            'name' => 'Jidan',
        ]);

        $this->paymentUserId = $account->id;
        // --- step 3 - Create the withdrawal account - end ---

        // --- step 4 - Start without provider answers - start ---
        // An unexpected lookup then fails on the empty queue instead of reaching Xendit.
        $this->useLookupAnswers([]);
        // --- step 4 - Start without provider answers - end ---
    }

    /**
     * Completes a withdrawal once Xendit reports the transfer as COMPLETED.
     *
     * @return void The balance stays deducted and the Xendit ID is stored.
     */
    public function test_completed_disbursement_settles_the_withdrawal(): void
    {
        // --- step 1 - Check a due withdrawal - start ---
        $withdrawal = $this->holdWithdrawal();

        $this->useLookupAnswers([$this->lookupResponse('COMPLETED')]);
        $this->travel(1)->minutes();
        $this->runCheck();
        // --- step 1 - Check a due withdrawal - end ---

        // --- step 2 - Check the completed withdrawal - start ---
        $withdrawal->refresh();

        $this->assertSame(WithdrawalStatus::COMPLETED, $withdrawal->status);
        $this->assertSame('disb-check', $withdrawal->provider_disbursement_id);
        $this->assertNotNull($withdrawal->settled_at);
        $this->assertIncome(self::STARTING_INCOME - self::WITHDRAWAL_AMOUNT);
        // --- step 2 - Check the completed withdrawal - end ---
    }

    /**
     * Returns the balance once Xendit reports the transfer as FAILED.
     *
     * @return void The failure code is stored and a refund row restores the income.
     */
    public function test_failed_disbursement_returns_the_balance(): void
    {
        // --- step 1 - Check a due withdrawal - start ---
        $withdrawal = $this->holdWithdrawal();

        $this->useLookupAnswers([$this->lookupResponse('FAILED', 'INVALID_DESTINATION')]);
        $this->travel(1)->minutes();
        $this->runCheck();
        // --- step 1 - Check a due withdrawal - end ---

        // --- step 2 - Check the failed withdrawal and refund - start ---
        $withdrawal->refresh();

        $this->assertSame(WithdrawalStatus::FAILED, $withdrawal->status);
        $this->assertSame('INVALID_DESTINATION', $withdrawal->failure_code);
        $this->assertNotNull($withdrawal->refund_saldo_history_id);
        $this->assertIncome(self::STARTING_INCOME);
        // --- step 2 - Check the failed withdrawal and refund - end ---
    }

    /**
     * Keeps a PENDING withdrawal processing and spaces its checks 1, 2, 5, 15, 60, 60 minutes.
     *
     * @return void Each check moves the next check by the configured delay.
     */
    public function test_pending_disbursement_is_checked_with_growing_delays(): void
    {
        // --- step 1 - Check the first scheduled check - start ---
        $withdrawal = $this->holdWithdrawal();

        $this->assertEquals(
            CarbonImmutable::parse(self::START_TIME)->addMinute(),
            $withdrawal->next_check_at,
        );
        // --- step 1 - Check the first scheduled check - end ---

        // --- step 2 - Check the delay after every pending answer - start ---
        $expectedDelays = [2, 5, 15, 60, 60];
        $answers = array_map(fn (): Response => $this->lookupResponse('PENDING'), $expectedDelays);

        $this->useLookupAnswers($answers);

        foreach ($expectedDelays as $attempt => $delayMinutes) {
            // --- step 2.1 - Run the check when the withdrawal is due - start ---
            $dueAt = $withdrawal->next_check_at;

            $this->assertNotNull($dueAt);
            $this->travelTo($dueAt);
            $this->runCheck();
            $withdrawal->refresh();
            // --- step 2.1 - Run the check when the withdrawal is due - end ---

            // --- step 2.2 - Check the next scheduled check - start ---
            $this->assertSame(WithdrawalStatus::PROCESSING, $withdrawal->status);
            $this->assertSame($attempt + 1, $withdrawal->check_attempts);
            $this->assertEquals($dueAt->addMinutes($delayMinutes), $withdrawal->next_check_at);
            // --- step 2.2 - Check the next scheduled check - end ---
        }
        // --- step 2 - Check the delay after every pending answer - end ---

        // --- step 3 - Check the balance is still held - start ---
        $this->assertIncome(self::STARTING_INCOME - self::WITHDRAWAL_AMOUNT);
        // --- step 3 - Check the balance is still held - end ---
    }

    /**
     * Treats not-found as a failure only after the grace period.
     *
     * @return void The first lookup keeps the balance held; the later one returns it.
     */
    public function test_not_found_returns_the_balance_only_after_the_grace_period(): void
    {
        // --- step 1 - Look the withdrawal up inside the grace period - start ---
        $withdrawal = $this->holdWithdrawal();

        $this->useLookupAnswers([$this->notFoundResponse(), $this->notFoundResponse()]);
        $this->travel(1)->minutes();
        $this->runCheck();
        // --- step 1 - Look the withdrawal up inside the grace period - end ---

        // --- step 2 - Check the balance is still held - start ---
        // Xendit may not have stored a request it received seconds ago.
        $this->assertSame(WithdrawalStatus::PROCESSING, $withdrawal->refresh()->status);
        $this->assertIncome(self::STARTING_INCOME - self::WITHDRAWAL_AMOUNT);
        // --- step 2 - Check the balance is still held - end ---

        // --- step 3 - Look it up again after the grace period - start ---
        // One minute past the grace period; the second check was already due at minute three.
        $graceMinutes = config('withdrawal.not_found_grace_minutes');
        $afterGrace = CarbonImmutable::parse(self::START_TIME)->addMinutes($graceMinutes + 1);

        $this->travelTo($afterGrace);
        $this->runCheck();
        $withdrawal->refresh();
        // --- step 3 - Look it up again after the grace period - end ---

        // --- step 4 - Check the failed withdrawal and refund - start ---
        $this->assertSame(WithdrawalStatus::FAILED, $withdrawal->status);
        $this->assertSame(WithdrawalService::NOT_FOUND_FAILURE_CODE, $withdrawal->failure_code);
        $this->assertIncome(self::STARTING_INCOME);
        // --- step 4 - Check the failed withdrawal and refund - end ---
    }

    /**
     * Keeps the balance held when the lookup itself gets no usable answer.
     *
     * @return void A timeout is never read as a failed transfer.
     */
    public function test_unanswered_lookup_keeps_the_withdrawal_processing(): void
    {
        // --- step 1 - Make the lookup time out - start ---
        $withdrawal = $this->holdWithdrawal();
        $timeout = new ConnectException(
            'cURL error 28: Operation timed out',
            new Request('GET', 'https://api.xendit.co/disbursements'),
            null,
            ['errno' => 28, 'error' => 'Operation timed out'],
        );

        $this->useLookupAnswers([$timeout]);
        $this->travel(1)->minutes();
        $this->runCheck();
        // --- step 1 - Make the lookup time out - end ---

        // --- step 2 - Check the held withdrawal - start ---
        $this->assertSame(WithdrawalStatus::PROCESSING, $withdrawal->refresh()->status);
        $this->assertSame(1, $withdrawal->check_attempts);
        $this->assertIncome(self::STARTING_INCOME - self::WITHDRAWAL_AMOUNT);
        // --- step 2 - Check the held withdrawal - end ---
    }

    /**
     * Holds the balance and logs critical when one external ID matches several disbursements.
     *
     * @return void The withdrawal stays processing for a manual check.
     */
    public function test_several_disbursements_keep_the_balance_held(): void
    {
        // --- step 1 - Return two disbursements for one external ID - start ---
        $withdrawal = $this->holdWithdrawal();
        $twoDisbursements = new Response(
            HttpStatus::HTTP_OK,
            [],
            '[{"id":"disb-a","status":"COMPLETED"},{"id":"disb-b","status":"FAILED"}]',
        );

        Log::spy();
        $this->useLookupAnswers([$twoDisbursements]);
        $this->travel(1)->minutes();
        $this->runCheck();
        // --- step 1 - Return two disbursements for one external ID - end ---

        // --- step 2 - Check the held withdrawal and the log - start ---
        $this->assertSame(WithdrawalStatus::PROCESSING, $withdrawal->refresh()->status);
        $this->assertIncome(self::STARTING_INCOME - self::WITHDRAWAL_AMOUNT);

        Log::shouldHaveReceived('critical')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $context['withdrawal_id']
                === $withdrawal->id);
        // --- step 2 - Check the held withdrawal and the log - end ---
    }

    /**
     * Leaves withdrawals that are not due yet or already settled alone.
     *
     * @return void No lookup is sent and nothing changes.
     */
    public function test_only_due_processing_withdrawals_are_checked(): void
    {
        // --- step 1 - Hold one withdrawal that is not due yet - start ---
        $notDue = $this->holdWithdrawal();
        // --- step 1 - Hold one withdrawal that is not due yet - end ---

        // --- step 2 - Settle one withdrawal whose check time has passed - start ---
        $settled = $this->holdWithdrawal();

        $settled->forceFill([
            'status' => WithdrawalStatus::COMPLETED,
            'next_check_at' => CarbonImmutable::parse(self::START_TIME)->subHour(),
        ])->save();
        // --- step 2 - Settle one withdrawal whose check time has passed - end ---

        // --- step 3 - Run the check - start ---
        $this->artisan('withdrawal:check')
            ->expectsOutputToContain('claimed=0')
            ->assertSuccessful();
        // --- step 3 - Run the check - end ---

        // --- step 4 - Check nothing was looked up or changed - start ---
        $this->assertCount(0, $this->lookups);
        $this->assertSame(0, $notDue->refresh()->check_attempts);
        $this->assertSame(0, $settled->refresh()->check_attempts);
        // --- step 4 - Check nothing was looked up or changed - end ---
    }

    /**
     * Checks no more withdrawals than the limit in one run.
     *
     * @return void The withdrawal beyond the limit waits for the next run.
     */
    public function test_one_run_checks_at_most_the_limit(): void
    {
        // --- step 1 - Hold three due withdrawals - start ---
        $withdrawals = [$this->holdWithdrawal(), $this->holdWithdrawal(), $this->holdWithdrawal()];

        $this->useLookupAnswers([
            $this->lookupResponse('PENDING'),
            $this->lookupResponse('PENDING'),
        ]);
        $this->travel(1)->minutes();
        // --- step 1 - Hold three due withdrawals - end ---

        // --- step 2 - Run with a limit of two - start ---
        $this->runCheck(['--limit' => 2]);
        // --- step 2 - Run with a limit of two - end ---

        // --- step 3 - Check only two were looked up - start ---
        $attempts = array_map(
            fn (SaldoWithdrawal $withdrawal): int => $withdrawal->refresh()->check_attempts,
            $withdrawals,
        );

        $this->assertCount(2, $this->lookups);
        $this->assertSame([1, 1, 0], $attempts);
        // --- step 3 - Check only two were looked up - end ---
    }

    /**
     * Logs a withdrawal that is still processing after 24 hours exactly once.
     *
     * @return void The balance stays held; time alone never returns it.
     */
    public function test_overdue_withdrawal_is_logged_once_and_stays_held(): void
    {
        // --- step 1 - Check an overdue withdrawal twice - start ---
        $withdrawal = $this->holdWithdrawal();
        $overdueHours = config('withdrawal.overdue_hours');

        Log::spy();
        $this->useLookupAnswers([
            $this->lookupResponse('PENDING'),
            $this->lookupResponse('PENDING'),
        ]);
        $this->travel($overdueHours)->hours();
        $this->travel(1)->minutes();
        $this->runCheck();

        $this->travelTo($withdrawal->refresh()->next_check_at);
        $this->runCheck();
        // --- step 1 - Check an overdue withdrawal twice - end ---

        // --- step 2 - Check the single critical log - start ---
        Log::shouldHaveReceived('critical')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $context['withdrawal_id']
                === $withdrawal->id);
        // --- step 2 - Check the single critical log - end ---

        // --- step 3 - Check the balance is still held - start ---
        $withdrawal->refresh();

        $this->assertSame(WithdrawalStatus::PROCESSING, $withdrawal->status);
        $this->assertNotNull($withdrawal->overdue_logged_at);
        $this->assertIncome(self::STARTING_INCOME - self::WITHDRAWAL_AMOUNT);
        // --- step 3 - Check the balance is still held - end ---
    }

    /**
     * Returns the balance only once, however often a final status is applied.
     *
     * @return void A second failure and a late completion leave the first result in place.
     */
    public function test_settling_twice_returns_the_balance_once(): void
    {
        // --- step 1 - Fail the same withdrawal twice, then try to complete it - start ---
        // The endpoint and withdrawal:check can both reach a final answer for one withdrawal.
        $withdrawal = $this->holdWithdrawal();
        $withdrawalService = app(WithdrawalService::class);
        $completed = ['id' => 'disb-check', 'status' => 'COMPLETED', 'failure_code' => null];

        $first = $withdrawalService->markFailed($withdrawal, 'INVALID_DESTINATION');
        $second = $withdrawalService->markFailed($withdrawal, 'INVALID_DESTINATION');
        $late = $withdrawalService->applyDisbursementState($withdrawal, $completed);
        // --- step 1 - Fail the same withdrawal twice, then try to complete it - end ---

        // --- step 2 - Check the first result was kept - start ---
        $this->assertSame(
            [WithdrawalStatus::FAILED, WithdrawalStatus::FAILED, WithdrawalStatus::FAILED],
            [$first, $second, $late],
        );
        $this->assertSame(WithdrawalStatus::FAILED, $withdrawal->refresh()->status);
        // --- step 2 - Check the first result was kept - end ---

        // --- step 3 - Check one refund only - start ---
        $this->assertIncome(self::STARTING_INCOME);
        $this->assertDatabaseCount('saldo_histories', 2);
        // --- step 3 - Check one refund only - end ---
    }

    /**
     * Shows counts per status and lists only the withdrawals processing past the overdue limit.
     *
     * @return void The output names the overdue withdrawal without any bank account data.
     */
    public function test_status_command_lists_counts_and_overdue_withdrawals(): void
    {
        // --- step 1 - Hold an old, a new, and a completed withdrawal - start ---
        $overdue = $this->holdWithdrawal();

        $this->travel(config('withdrawal.overdue_hours'))->hours();
        $this->travel(1)->minutes();

        $recent = $this->holdWithdrawal();
        $completed = $this->holdWithdrawal();

        $completed->forceFill(['status' => WithdrawalStatus::COMPLETED])->save();
        // --- step 1 - Hold an old, a new, and a completed withdrawal - end ---

        // --- step 2 - Show the status - start ---
        $this->artisan('withdrawal:status')
            ->expectsTable(['Status', 'Count'], [
                ['processing', 2],
                ['completed', 1],
                ['failed', 0],
            ])
            ->expectsOutputToContain('Processing longer than 24 hours: 1')
            ->expectsOutputToContain($overdue->external_id)
            ->doesntExpectOutputToContain($recent->external_id)
            ->doesntExpectOutputToContain('001234')
            ->assertSuccessful();
        // --- step 2 - Show the status - end ---
    }

    /**
     * Rejects a limit that is not a positive integer before any withdrawal is claimed.
     *
     * @return void The command exits with INVALID.
     */
    public function test_invalid_limit_is_rejected(): void
    {
        // A zero limit would claim nothing forever while looking like a successful run.
        $this->artisan('withdrawal:check', ['--limit' => '0'])
            ->assertExitCode(Command::INVALID);
    }

    /**
     * Confirms the scheduler runs the check every minute without overlapping runs.
     *
     * @return void A slow run must not start a second one that checks the same withdrawals.
     */
    public function test_scheduler_runs_the_check_every_minute_without_overlap(): void
    {
        // --- step 1 - Find the scheduled check - start ---
        $events = app(Schedule::class)->events();
        $checks = array_filter(
            $events,
            fn (Event $event): bool => str_contains((string) $event->command, 'withdrawal:check'),
        );
        // --- step 1 - Find the scheduled check - end ---

        // --- step 2 - Check its frequency and overlap guard - start ---
        $this->assertCount(1, $checks);

        $check = array_values($checks)[0];

        $this->assertSame('* * * * *', $check->expression);
        $this->assertTrue($check->withoutOverlapping);
        // --- step 2 - Check its frequency and overlap guard - end ---
    }

    /**
     * Holds one withdrawal through the real service, as the withdrawal endpoint does.
     *
     * @return SaldoWithdrawal Processing withdrawal due one minute after the current time.
     */
    private function holdWithdrawal(): SaldoWithdrawal
    {
        // The real hold writes the same balance, history, and schedule as the endpoint.
        $externalId = 'dis-bca-'.Str::lower((string) Str::ulid());
        $hold = app(WithdrawalService::class)->hold(
            $this->user->id,
            $this->paymentUserId,
            self::WITHDRAWAL_AMOUNT,
            $externalId,
        );

        $this->assertSame('success', $hold['status']);

        return $hold['withdrawal'];
    }

    /**
     * Replaces the Xendit client with queued lookup answers and records each lookup.
     *
     * @param  list<Response|Throwable>  $answers  Responses or failures in request order.
     *
     * @return void The container resolves XenditService to this client.
     */
    private function useLookupAnswers(array $answers): void
    {
        // --- step 1 - Build the mock transport - start ---
        $this->lookups = [];

        $handler = HandlerStack::create(new MockHandler($answers));
        $handler->push(Middleware::history($this->lookups));
        // --- step 1 - Build the mock transport - end ---

        // --- step 2 - Bind the adapter - start ---
        // The command resolves its services on every run, so each run receives this adapter.
        $client = new Client(['handler' => $handler]);

        $this->instance(XenditService::class, new XenditService('test-key', $client));
        // --- step 2 - Bind the adapter - end ---
    }

    /**
     * Builds a lookup answer with one disbursement in the given status.
     *
     * @param  string  $status  Xendit disbursement status, for example `PENDING`.
     * @param  string|null  $failureCode  Failure code for a FAILED disbursement.
     *
     * @return Response HTTP 200 with a one-item list, as seen in the TOK-88 probe.
     */
    private function lookupResponse(string $status, ?string $failureCode = null): Response
    {
        $disbursement = ['id' => 'disb-check', 'status' => $status];

        if ($failureCode !== null) {
            $disbursement['failure_code'] = $failureCode;
        }

        return new Response(HttpStatus::HTTP_OK, [], (string) json_encode([$disbursement]));
    }

    /**
     * Builds the answer Xendit gives for an unknown external ID.
     *
     * @return Response HTTP 404 with the not-found error code seen in the TOK-88 probe.
     */
    private function notFoundResponse(): Response
    {
        return new Response(
            HttpStatus::HTTP_NOT_FOUND,
            [],
            '{"error_code":"DIRECT_DISBURSEMENT_NOT_FOUND_ERROR","message":"not found"}',
        );
    }

    /**
     * Runs `withdrawal:check` and requires a successful exit.
     *
     * @param  array<string, mixed>  $options  Command options, for example `--limit`.
     *
     * @return void Fails when the command exits with an error.
     */
    private function runCheck(array $options = []): void
    {
        $this->artisan('withdrawal:check', $options)->assertSuccessful();
    }

    /**
     * Checks the user's income balance.
     *
     * @param  int  $income  Expected income in whole rupiah.
     *
     * @return void Fails when the balance differs.
     */
    private function assertIncome(int $income): void
    {
        $this->assertDatabaseHas('saldo_users', [
            'user_id' => $this->user->id,
            'saldo_income' => $income,
        ]);
    }
}
