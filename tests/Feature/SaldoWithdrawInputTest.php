<?php

namespace Tests\Feature;

use App\Enums\WithdrawalStatus;
use App\Http\Middleware\AuthenticateApiRequest;
use App\Models\PaymentList;
use App\Models\PaymentUser;
use App\Models\SaldoUser;
use App\Models\SaldoWithdrawal;
use App\Models\User;
use App\Services\XenditService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Tests\TestCase;
use Throwable;

/**
 * Verifies withdrawal input parsing, the locked balance hold before the Xendit disbursement, and
 * how each Xendit answer leaves the withdrawal and the balance.
 */
class SaldoWithdrawInputTest extends TestCase
{
    use RefreshDatabase;

    private const WITHDRAW_ENDPOINT = '/api/saldo-withdraw';

    private const ACCOUNT_NUMBER = '001234';

    // Enough income for every successful withdrawal in this file, including the maximum.
    private const STARTING_INCOME = 2000000;

    private const MAXIMUM_AMOUNT = 1000000;

    private const EMPTY_ACCOUNT_MESSAGE = 'Rekening Bank Tidak Boleh Kosong';

    private const NON_STRING_ACCOUNT_MESSAGE = 'Rekening Bank Harus Berupa Teks';

    private const EMPTY_AMOUNT_MESSAGE = 'Nominal Tidak Boleh Kosong';

    private const INVALID_AMOUNT_TYPE_MESSAGE = 'Nominal Harus Berupa Angka Atau Teks';

    private const INVALID_FORMAT_MESSAGE = 'Format Nominal Tidak Valid';

    private const MINIMUM_MESSAGE = 'Nominal Minimal Rp1';

    private const MAXIMUM_MESSAGE = 'Nominal Tidak Boleh Lebih Dari Rp1.000.000';

    private const WITHDRAWAL_AMOUNT = 100000;

    private User $user;

    /** @var array<int, array{request: RequestInterface}> Requests the mocked Xendit client sent. */
    private array $providerRequests = [];

    /**
     * Creates a user with balance and one withdrawal account, and records every Xendit request.
     *
     * @return void Each test starts with the same balance and an observable provider client.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // --- step 1 - Bypass Clerk transport authentication - start ---
        // Request normalization middleware stays active, so blank text becomes null as in
        // production.
        $this->withoutMiddleware(AuthenticateApiRequest::class);
        // --- step 1 - Bypass Clerk transport authentication - end ---

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

        PaymentUser::create([
            'user_id' => $this->user->id,
            'payment_id' => $bank->id,
            'account' => self::ACCOUNT_NUMBER,
            'name' => 'Jidan',
        ]);
        // --- step 3 - Create the withdrawal account - end ---

        // --- step 4 - Bind an observable Xendit client - start ---
        // One accepted response is queued so an unexpected disbursement is recorded and
        // fails the provider request count instead of hiding behind an empty mock queue.
        $this->useProviderResponses(1);
        // --- step 4 - Bind an observable Xendit client - end ---
    }

    /**
     * Confirms both amount shapes sent by the frontend reach Xendit and the balance as integers.
     *
     * @return void Covers the minimum, a typed amount, and the maximum in one balance sequence.
     */
    public function test_json_number_and_digit_text_amounts_withdraw_as_integers(): void
    {
        // --- step 1 - Queue one accepted disbursement per withdrawal - start ---
        // The frontend sends the JSON number 1 until the user types, then digit text.
        $withdrawals = [1, '100000', self::MAXIMUM_AMOUNT];

        $this->useProviderResponses(count($withdrawals));
        $this->actingAs($this->user);
        // --- step 1 - Queue one accepted disbursement per withdrawal - end ---

        // --- step 2 - Withdraw each amount - start ---
        $expectedIncome = self::STARTING_INCOME;

        foreach ($withdrawals as $index => $amount) {
            // --- step 2.1 - Request the withdrawal - start ---
            $expectedAmount = (int) $amount;
            $expectedIncome -= $expectedAmount;

            $this->postJson(self::WITHDRAW_ENDPOINT, $this->amountPayload($amount))
                ->assertOk()
                ->assertJsonPath('status', 'success')
                ->assertJsonPath('saldoHistory.type', 'withdrawal');
            // --- step 2.1 - Request the withdrawal - end ---

            // --- step 2.2 - Check the provider payload - start ---
            // Same rupiah format as the controller: no decimals, dot as thousands separator.
            $providerPayload = $this->providerPayload($index);
            $formattedAmount = number_format($expectedAmount, 0, ',', '.');

            $this->assertSame($expectedAmount, $providerPayload['amount']);
            $this->assertSame(self::ACCOUNT_NUMBER, $providerPayload['account_number']);
            $this->assertSame(
                "Transfer Rekening BCA Sebesar Rp$formattedAmount",
                $providerPayload['description'],
            );
            // --- step 2.2 - Check the provider payload - end ---

            // --- step 2.3 - Check the remaining income - start ---
            $this->assertDatabaseHas('saldo_users', [
                'user_id' => $this->user->id,
                'saldo_income' => $expectedIncome,
            ]);
            // --- step 2.3 - Check the remaining income - end ---
        }
        // --- step 2 - Withdraw each amount - end ---

        // --- step 3 - Check one provider call and history row per withdrawal - start ---
        $this->assertCount(count($withdrawals), $this->providerRequests);
        $this->assertDatabaseCount('saldo_histories', count($withdrawals));
        // --- step 3 - Check one provider call and history row per withdrawal - end ---
    }

    /**
     * Keeps the existing 401 response for a missing identity and for a deleted local user.
     *
     * @return void Neither request reaches Xendit or the balance.
     */
    public function test_missing_and_deleted_users_receive_401_without_withdrawal(): void
    {
        // --- step 1 - Request a withdrawal without an identity - start ---
        $this->postJson(self::WITHDRAW_ENDPOINT, $this->amountPayload('100000'))
            ->assertUnauthorized()
            ->assertExactJson(['status' => 'error', 'message' => 'Unauthorized']);

        $this->assertNothingWasWithdrawn();
        // --- step 1 - Request a withdrawal without an identity - end ---

        // --- step 2 - Request a withdrawal after the user is deleted - start ---
        $this->actingAs($this->user);
        $this->user->delete();

        $this->postJson(self::WITHDRAW_ENDPOINT, $this->amountPayload('100000'))
            ->assertUnauthorized()
            ->assertExactJson(['status' => 'error', 'message' => 'Unauthorized']);

        $this->assertNothingWasWithdrawn();
        // --- step 2 - Request a withdrawal after the user is deleted - end ---
    }

    /**
     * Keeps the existing 400 response for every empty account value.
     *
     * @return void Falsy values are normalized to an empty account before any lookup.
     */
    public function test_empty_accounts_keep_the_existing_400(): void
    {
        // A missing key and every falsy value share the message the endpoint returned before.
        $payloads = [
            ['wihtdrawPrice' => '100000'],
            ...$this->accountPayloads([null, '', '   ', false, 0, '0', []]),
        ];

        $this->assertPayloadsAreRejected(
            $payloads,
            HttpStatus::HTTP_BAD_REQUEST,
            self::EMPTY_ACCOUNT_MESSAGE,
        );
    }

    /**
     * Rejects nonempty account values that are not text with 422.
     *
     * @return void Arrays previously made trim() throw and ended as HTTP 500.
     */
    public function test_non_string_accounts_receive_422(): void
    {
        // Numbers were previously coerced to text and searched as an account number.
        $this->assertPayloadsAreRejected(
            $this->accountPayloads([1234, 1.5, true, [self::ACCOUNT_NUMBER]]),
            HttpStatus::HTTP_UNPROCESSABLE_ENTITY,
            self::NON_STRING_ACCOUNT_MESSAGE,
        );
    }

    /**
     * Keeps the existing 400 response for every empty amount value.
     *
     * @return void Falsy values are normalized to an empty amount before parsing.
     */
    public function test_empty_amounts_keep_the_existing_400(): void
    {
        // The JSON number 0 is falsy, so it keeps the empty message instead of the minimum.
        $payloads = [
            ['paymentAccount' => self::ACCOUNT_NUMBER],
            ...$this->amountPayloads([null, '', '   ', false, 0, '0', []]),
        ];

        $this->assertPayloadsAreRejected(
            $payloads,
            HttpStatus::HTTP_BAD_REQUEST,
            self::EMPTY_AMOUNT_MESSAGE,
        );
    }

    /**
     * Rejects amount values that are neither a JSON number nor text with 422.
     *
     * @return void Booleans, lists, and objects never reach the amount parser.
     */
    public function test_non_numeric_amount_types_receive_422(): void
    {
        // A JSON object arrives as an associative array and is rejected like a list.
        $this->assertPayloadsAreRejected(
            $this->amountPayloads([true, ['100000'], ['value' => 100000]]),
            HttpStatus::HTTP_UNPROCESSABLE_ENTITY,
            self::INVALID_AMOUNT_TYPE_MESSAGE,
        );
    }

    /**
     * Rejects amounts that are not whole rupiah before they can be truncated or rounded.
     *
     * @return void Decimal, exponent, signed, grouped, and fractional JSON values get 400.
     */
    public function test_non_integer_amounts_receive_the_format_400(): void
    {
        // json_encode() writes 100.0 as 100, so only a real fraction reaches the parser as float.
        $amounts = ['150000.5', '1e5', '+5000', '1.000', '1 000', '0x1A', 'abc', 150000.5];

        $this->assertPayloadsAreRejected(
            $this->amountPayloads($amounts),
            HttpStatus::HTTP_BAD_REQUEST,
            self::INVALID_FORMAT_MESSAGE,
        );
    }

    /**
     * Rejects amounts below one rupiah, including negatives that could otherwise raise the balance.
     *
     * @return void Text beyond the integer range is clamped by the cast and still rejected.
     */
    public function test_amounts_below_the_minimum_receive_400(): void
    {
        // "00" is not falsy and matches the digit pattern, so only the minimum check stops it.
        $this->assertPayloadsAreRejected(
            $this->amountPayloads([-5000, '-5000', '00', '-99999999999999999999']),
            HttpStatus::HTTP_BAD_REQUEST,
            self::MINIMUM_MESSAGE,
        );
    }

    /**
     * Keeps the existing maximum message for amounts above one million rupiah.
     *
     * @return void Text beyond the integer range is clamped by the cast and still rejected.
     */
    public function test_amounts_above_the_maximum_keep_the_existing_400(): void
    {
        // JSON number and digit text share the limit after parsing.
        $this->assertPayloadsAreRejected(
            $this->amountPayloads([1000001, '1000001', '99999999999999999999']),
            HttpStatus::HTTP_BAD_REQUEST,
            self::MAXIMUM_MESSAGE,
        );
    }

    /**
     * Keeps the insufficient balance message and formats it from the parsed integer amount.
     *
     * @return void The request stops before Xendit and leaves the smaller balance unchanged.
     */
    public function test_insufficient_balance_keeps_the_existing_message(): void
    {
        // --- step 1 - Lower the balance below the requested amount - start ---
        $smallIncome = 50000;

        SaldoUser::where('user_id', $this->user->id)->update(['saldo_income' => $smallIncome]);
        $this->actingAs($this->user);
        // --- step 1 - Lower the balance below the requested amount - end ---

        // --- step 2 - Request more than the balance - start ---
        $message = 'Saldo Anda Hanya Rp50.000, Tidak Bisa Tarik Saldo Sebesar 100.000';

        $this->postJson(self::WITHDRAW_ENDPOINT, $this->amountPayload('100000'))
            ->assertBadRequest()
            ->assertExactJson(['status' => 'error', 'message' => $message]);

        $this->assertNothingWasWithdrawn($smallIncome);
        // --- step 2 - Request more than the balance - end ---
    }

    /**
     * Holds an accepted withdrawal as processing and sends its stored external ID as the key.
     *
     * @return void Xendit's PENDING answer keeps the balance deducted until a final status.
     */
    public function test_accepted_withdrawal_is_held_as_processing(): void
    {
        // --- step 1 - Request the withdrawal - start ---
        $this->actingAs($this->user);

        $this->postJson(self::WITHDRAW_ENDPOINT, $this->amountPayload(self::WITHDRAWAL_AMOUNT))
            ->assertOk()
            ->assertJsonPath('saldoHistory.type', 'withdrawal')
            ->assertJsonPath('saldoHistory.withdrawal_status', 'processing');
        // --- step 1 - Request the withdrawal - end ---

        // --- step 2 - Check the stored withdrawal - start ---
        $withdrawal = SaldoWithdrawal::sole();

        $this->assertSame(WithdrawalStatus::PROCESSING, $withdrawal->status);
        $this->assertSame('disb-test', $withdrawal->provider_disbursement_id);
        $this->assertSame(self::WITHDRAWAL_AMOUNT, $withdrawal->amount);
        $this->assertSame(0, $withdrawal->refund_portion);
        $this->assertSame(self::WITHDRAWAL_AMOUNT, $withdrawal->income_portion);
        // --- step 2 - Check the stored withdrawal - end ---

        // --- step 3 - Check the external ID sent to Xendit - start ---
        // The same stored value is the body field and the idempotency key, so a lost answer can
        // be looked up and a repeated send cannot pay twice.
        $request = $this->providerRequests[0]['request'];

        // A lowercase ULID after the bank slug, unique across environments like Xendit requires.
        $externalIdPattern = '/\Adis-bca-[0-9a-z]{26}\z/';

        $this->assertMatchesRegularExpression($externalIdPattern, $withdrawal->external_id);
        $this->assertSame($withdrawal->external_id, $this->providerPayload(0)['external_id']);
        $this->assertSame($withdrawal->external_id, $request->getHeaderLine('X-IDEMPOTENCY-KEY'));
        // --- step 3 - Check the external ID sent to Xendit - end ---

        // --- step 4 - Check the held balance - start ---
        $this->assertDatabaseHas('saldo_users', [
            'user_id' => $this->user->id,
            'saldo_income' => self::STARTING_INCOME - self::WITHDRAWAL_AMOUNT,
        ]);
        // --- step 4 - Check the held balance - end ---
    }

    /**
     * Sends the disbursement only after the balance transaction has committed.
     *
     * @return void Xendit sees the same transaction depth as the test itself.
     */
    public function test_xendit_is_called_after_the_balance_transaction_ends(): void
    {
        // --- step 1 - Record the transaction depth at send time - start ---
        // RefreshDatabase wraps the test in one transaction; a deeper level would mean the
        // balance row lock is still held while Xendit answers.
        $testDepth = DB::transactionLevel();
        $sendDepths = [];

        $this->useProviderAnswers(
            [$this->acceptedResponse()],
            function () use (&$sendDepths): void {
                $sendDepths[] = DB::transactionLevel();
            },
        );
        $this->actingAs($this->user);
        // --- step 1 - Record the transaction depth at send time - end ---

        // --- step 2 - Request the withdrawal - start ---
        $this->postJson(self::WITHDRAW_ENDPOINT, $this->amountPayload(self::WITHDRAWAL_AMOUNT))
            ->assertOk();
        // --- step 2 - Request the withdrawal - end ---

        // --- step 3 - Check the send happened outside the balance transaction - start ---
        $this->assertSame([$testDepth], $sendDepths);
        // --- step 3 - Check the send happened outside the balance transaction - end ---
    }

    /**
     * Keeps the balance held when Xendit's answer is lost.
     *
     * @return void The user sees a processing withdrawal instead of a rejection.
     */
    public function test_unanswered_withdrawal_keeps_the_balance_held(): void
    {
        // --- step 1 - Make the provider time out - start ---
        // Before TOK-88 this became a 400 without a deduction while the money might be sent.
        $timeout = new ConnectException(
            'cURL error 28: Operation timed out',
            new Request('POST', 'https://api.xendit.co/disbursements'),
            null,
            ['errno' => 28, 'error' => 'Operation timed out'],
        );

        $this->useProviderAnswers([$timeout]);
        $this->actingAs($this->user);
        // --- step 1 - Make the provider time out - end ---

        // --- step 2 - Request the withdrawal - start ---
        $this->postJson(self::WITHDRAW_ENDPOINT, $this->amountPayload(self::WITHDRAWAL_AMOUNT))
            ->assertOk()
            ->assertJsonPath('saldoHistory.withdrawal_status', 'processing');
        // --- step 2 - Request the withdrawal - end ---

        // --- step 3 - Check the held withdrawal - start ---
        $withdrawal = SaldoWithdrawal::sole();

        $this->assertSame(WithdrawalStatus::PROCESSING, $withdrawal->status);
        $this->assertNull($withdrawal->provider_disbursement_id);
        $this->assertDatabaseCount('saldo_histories', 1);
        $this->assertDatabaseHas('saldo_users', [
            'user_id' => $this->user->id,
            'saldo_income' => self::STARTING_INCOME - self::WITHDRAWAL_AMOUNT,
        ]);
        // --- step 3 - Check the held withdrawal - end ---
    }

    /**
     * Returns the balance and Xendit's message when Xendit rejects the disbursement.
     *
     * @return void History keeps the failed withdrawal and adds a refund row.
     */
    public function test_rejected_withdrawal_returns_the_balance(): void
    {
        // --- step 1 - Make the provider reject the request - start ---
        $rejection = new Response(
            HttpStatus::HTTP_BAD_REQUEST,
            [],
            '{"error_code":"API_VALIDATION_ERROR","message":"Invalid account"}',
        );

        $this->useProviderAnswers([$rejection]);
        $this->actingAs($this->user);
        // --- step 1 - Make the provider reject the request - end ---

        // --- step 2 - Request the withdrawal - start ---
        $this->postJson(self::WITHDRAW_ENDPOINT, $this->amountPayload(self::WITHDRAWAL_AMOUNT))
            ->assertBadRequest()
            ->assertExactJson(['status' => 'error', 'message' => 'Invalid account']);
        // --- step 2 - Request the withdrawal - end ---

        // --- step 3 - Check the failed withdrawal - start ---
        $withdrawal = SaldoWithdrawal::sole();

        $this->assertSame(WithdrawalStatus::FAILED, $withdrawal->status);
        $this->assertSame('API_VALIDATION_ERROR', $withdrawal->failure_code);
        $this->assertNotNull($withdrawal->settled_at);
        // --- step 3 - Check the failed withdrawal - end ---

        // --- step 4 - Check the returned balance - start ---
        $this->assertDatabaseHas('saldo_users', [
            'user_id' => $this->user->id,
            'saldo_income' => self::STARTING_INCOME,
        ]);
        $this->assertDatabaseHas('saldo_histories', [
            'id' => $withdrawal->refund_saldo_history_id,
            'type' => 'withdrawal_refund',
            'price' => self::WITHDRAWAL_AMOUNT,
        ]);
        $this->assertDatabaseCount('saldo_histories', 2);
        // --- step 4 - Check the returned balance - end ---
    }

    /**
     * Keeps the withdrawal processing when its outcome cannot be saved after Xendit answered.
     *
     * @return void The user gets a processing answer and the error log names the withdrawal.
     */
    public function test_unsaved_outcome_keeps_the_withdrawal_processing(): void
    {
        // --- step 1 - Make the outcome write fail - start ---
        // The hold is committed before this write, so the balance stays safely held.
        SaldoWithdrawal::updating(function (): never {
            throw new RuntimeException('Outcome write failed.');
        });
        Log::spy();
        $this->actingAs($this->user);
        // --- step 1 - Make the outcome write fail - end ---

        // --- step 2 - Request the withdrawal - start ---
        $this->postJson(self::WITHDRAW_ENDPOINT, $this->amountPayload(self::WITHDRAWAL_AMOUNT))
            ->assertOk()
            ->assertJsonPath('saldoHistory.withdrawal_status', 'processing');
        // --- step 2 - Request the withdrawal - end ---

        // --- step 3 - Check the held withdrawal - start ---
        $withdrawal = SaldoWithdrawal::sole();

        $this->assertSame(WithdrawalStatus::PROCESSING, $withdrawal->status);
        $this->assertDatabaseHas('saldo_users', [
            'user_id' => $this->user->id,
            'saldo_income' => self::STARTING_INCOME - self::WITHDRAWAL_AMOUNT,
        ]);
        // --- step 3 - Check the held withdrawal - end ---

        // --- step 4 - Check the error log - start ---
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($withdrawal): bool {
                $namesWithdrawal = $context['withdrawal_id'] === $withdrawal->id
                    && $context['external_id'] === $withdrawal->external_id;

                return $namesWithdrawal && ! in_array(self::ACCOUNT_NUMBER, $context, true);
            });
        // --- step 4 - Check the error log - end ---
    }

    /**
     * Confirms every balance read during a withdrawal uses FOR UPDATE on PostgreSQL.
     *
     * @return void SQLite drops the lock clause, so the check only runs on PostgreSQL.
     */
    public function test_withdrawal_reads_the_balance_only_with_a_row_lock(): void
    {
        // --- step 1 - Skip drivers without row locks - start ---
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The FOR UPDATE clause is verified on isolated PostgreSQL.');
        }
        // --- step 1 - Skip drivers without row locks - end ---

        // --- step 2 - Record the withdrawal queries - start ---
        $this->actingAs($this->user);
        DB::enableQueryLog();

        $this->postJson(self::WITHDRAW_ENDPOINT, $this->amountPayload(self::WITHDRAWAL_AMOUNT))
            ->assertOk();

        $queries = array_column(DB::getQueryLog(), 'query');

        DB::disableQueryLog();
        // --- step 2 - Record the withdrawal queries - end ---

        // --- step 3 - Check every balance read - start ---
        // An unlocked read before Xendit would let a parallel withdrawal pass the same check.
        $balanceReads = array_filter(
            $queries,
            fn (string $query): bool => str_starts_with($query, 'select')
                && str_contains($query, 'from "saldo_users"'),
        );

        $this->assertNotEmpty($balanceReads);

        foreach ($balanceReads as $query) {
            $this->assertStringEndsWith('for update', $query);
        }
        // --- step 3 - Check every balance read - end ---
    }

    /**
     * Sends each payload as the user and checks the exact rejection without side effects.
     *
     * @param  list<array<string, mixed>>  $payloads  Request bodies that must all be rejected.
     * @param  int  $status  Expected HTTP status for every payload.
     * @param  string  $message  Expected error message for every payload.
     *
     * @return void Fails on the first payload that is accepted or rejected differently.
     */
    private function assertPayloadsAreRejected(array $payloads, int $status, string $message): void
    {
        $this->actingAs($this->user);

        foreach ($payloads as $payload) {
            $this->postJson(self::WITHDRAW_ENDPOINT, $payload)
                ->assertStatus($status)
                ->assertExactJson(['status' => 'error', 'message' => $message]);

            $this->assertNothingWasWithdrawn();
        }
    }

    /**
     * Confirms no disbursement request, balance change, or history row happened.
     *
     * @param  int  $expectedIncome  Income the user had before the rejected request.
     *
     * @return void Fails when a rejected request reached Xendit or the balance write.
     */
    private function assertNothingWasWithdrawn(int $expectedIncome = self::STARTING_INCOME): void
    {
        $this->assertCount(0, $this->providerRequests);
        $this->assertDatabaseHas('saldo_users', [
            'user_id' => $this->user->id,
            'saldo_income' => $expectedIncome,
        ]);
        $this->assertDatabaseCount('saldo_histories', 0);
        $this->assertDatabaseCount('saldo_withdrawals', 0);
    }

    /**
     * Builds one valid-account request body per amount value.
     *
     * @param  list<mixed>  $amounts  Raw `wihtdrawPrice` values to send as JSON.
     *
     * @return list<array<string, mixed>> Request bodies in the same order as the amounts.
     */
    private function amountPayloads(array $amounts): array
    {
        return array_map(fn (mixed $amount): array => $this->amountPayload($amount), $amounts);
    }

    /**
     * Builds a request body for the user's saved account and the given amount.
     *
     * @param  mixed  $amount  Raw `wihtdrawPrice` value to send as JSON.
     *
     * @return array<string, mixed> Request body with a valid account.
     */
    private function amountPayload(mixed $amount): array
    {
        return ['paymentAccount' => self::ACCOUNT_NUMBER, 'wihtdrawPrice' => $amount];
    }

    /**
     * Builds one valid-amount request body per account value.
     *
     * @param  list<mixed>  $accounts  Raw `paymentAccount` values to send as JSON.
     *
     * @return list<array<string, mixed>> Request bodies in the same order as the accounts.
     */
    private function accountPayloads(array $accounts): array
    {
        return array_map(
            fn (mixed $account): array => [
                'paymentAccount' => $account,
                'wihtdrawPrice' => '100000',
            ],
            $accounts,
        );
    }

    /**
     * Replaces the Xendit client with accepted disbursements and a request history.
     *
     * @param  int  $responseCount  Number of disbursements the mock provider accepts.
     *
     * @return void The container resolves the controller's XenditService to this client.
     */
    private function useProviderResponses(int $responseCount): void
    {
        // Xendit answers a new disbursement with PENDING, as seen in the TOK-88 Sandbox probe.
        $responses = [];

        for ($index = 0; $index < $responseCount; $index++) {
            $responses[] = $this->acceptedResponse();
        }

        $this->useProviderAnswers($responses);
    }

    /**
     * Replaces the Xendit client with queued answers and records each request it sends.
     *
     * @param  list<Response|Throwable>  $answers  Responses or failures in request order.
     * @param  (callable(): void)|null  $beforeSend  Runs just before each request is sent.
     *
     * @return void The container resolves the controller's XenditService to this client.
     */
    private function useProviderAnswers(array $answers, ?callable $beforeSend = null): void
    {
        // --- step 1 - Build the mock transport - start ---
        $this->providerRequests = [];

        $handler = HandlerStack::create(new MockHandler($answers));
        $handler->push(Middleware::history($this->providerRequests));

        if ($beforeSend !== null) {
            $handler->push(Middleware::tap($beforeSend));
        }
        // --- step 1 - Build the mock transport - end ---

        // --- step 2 - Bind the adapter to the mock transport - start ---
        $client = new Client(['handler' => $handler]);
        $this->instance(XenditService::class, new XenditService('test-key', $client));
        // --- step 2 - Bind the adapter to the mock transport - end ---
    }

    /**
     * Builds the answer Xendit gives to a new disbursement.
     *
     * @return Response HTTP 200 with a PENDING disbursement.
     */
    private function acceptedResponse(): Response
    {
        return new Response(HttpStatus::HTTP_OK, [], '{"id":"disb-test","status":"PENDING"}');
    }

    /**
     * Decodes the JSON body of one recorded disbursement request.
     *
     * @param  int  $index  Position of the request in the provider history.
     *
     * @return array<string, mixed> Disbursement fields sent to Xendit.
     */
    private function providerPayload(int $index): array
    {
        $body = (string) $this->providerRequests[$index]['request']->getBody();

        /** @var array<string, mixed> $payload */
        $payload = json_decode($body, true);

        return $payload;
    }
}
