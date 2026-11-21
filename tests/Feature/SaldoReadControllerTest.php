<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateApiRequest;
use App\Models\SaldoHistory;
use App\Models\SaldoUser;
use App\Models\User;
use App\Services\SaldoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class SaldoReadControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SUMMARY_ENDPOINT = '/api/saldo';

    private const HISTORY_ENDPOINT = '/api/saldo-history';

    private const INCOME = 125_000;

    private const REFUND = 25_000;

    /**
     * Keeps the API request flow local while exercising Laravel's request user resolver.
     *
     * @return void Other request middleware remains available to normalize the filters.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(AuthenticateApiRequest::class);
    }

    /**
     * Confirms the balance response before and after the signed-in user has a balance row.
     *
     * @return void The endpoint uses zero defaults and returns the user's income and refund.
     */
    public function test_summary_returns_zero_then_the_users_balance(): void
    {
        // --- step 1 - verify the balance defaults - start ---
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->getJson(self::SUMMARY_ENDPOINT)->assertOk()->assertExactJson([
            'status' => 'success',
            'saldoIncome' => 0,
            'saldoRefund' => 0,
            'saldoTotal' => 0,
        ]);
        // --- step 1 - verify the balance defaults - end ---

        // --- step 2 - insert a balance for the signed-in user - start ---
        SaldoUser::create([
            'user_id' => $user->id,
            'saldo_income' => self::INCOME,
            'saldo_refund' => self::REFUND,
        ]);
        // --- step 2 - insert a balance for the signed-in user - end ---

        // --- step 3 - verify the populated balance - start ---
        $this->getJson(self::SUMMARY_ENDPOINT)->assertOk()->assertExactJson([
            'status' => 'success',
            'saldoIncome' => self::INCOME,
            'saldoRefund' => self::REFUND,
            'saldoTotal' => self::INCOME + self::REFUND,
        ]);
        // --- step 3 - verify the populated balance - end ---
    }

    /**
     * Exercises the query string sent by the balance page against the real history query.
     *
     * @return void The range, displayed IDs, and user scope together select the expected row.
     */
    public function test_history_filters_return_only_the_requested_users_unseen_rows(): void
    {
        // --- step 1 - insert distinguishable history rows - start ---
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($owner);

        $included = $this->recordHistory($owner, '2026-11-20 12:00:00');
        $displayed = $this->recordHistory($owner, '2026-11-21 12:00:00');
        $this->recordHistory($owner, '2026-11-19 12:00:00');
        $this->recordHistory($other, '2026-11-20 12:00:00');
        // --- step 1 - insert distinguishable history rows - end ---

        // --- step 2 - build the frontend query string - start ---
        $query = http_build_query([
            'startDate' => '2026-11-20',
            'endDate' => '2026-11-21',
            'saldo_history_current_ids' => json_encode([$displayed->id], JSON_THROW_ON_ERROR),
        ]);
        // --- step 2 - build the frontend query string - end ---

        // --- step 3 - verify the matching history row - start ---
        $this->getJson(self::HISTORY_ENDPOINT.'?'.$query)
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(1, 'saldoHistory')
            ->assertJsonPath('saldoHistory.0.id', $included->id);
        // --- step 3 - verify the matching history row - end ---
    }

    /**
     * Keeps all supported empty filter representations compatible with existing clients.
     *
     * @return void Missing, null, blank, and an empty list produce the same history response.
     */
    public function test_empty_optional_filters_keep_the_existing_defaults(): void
    {
        // --- step 1 - define empty request fields - start ---
        $this->actingAs(User::factory()->create());

        $fields = ['startDate', 'endDate', 'saldo_history_current_ids'];
        $emptyFilters = [
            [],
            array_fill_keys($fields, null),
            array_fill_keys($fields, ''),
            ['saldo_history_current_ids' => '[]'],
        ];
        // --- step 1 - define empty request fields - end ---

        // --- step 2 - verify default response - start ---
        foreach ($emptyFilters as $filters) {
            $this->json('GET', self::HISTORY_ENDPOINT, $filters)
                ->assertOk()
                ->assertExactJson(['status' => 'success', 'saldoHistory' => []]);
        }
        // --- step 2 - verify default response - end ---
    }

    /**
     * Protects the history query from invalid request types and decoded ID values.
     *
     * @return void Rejected inputs receive a 422 with the text message expected by the UI.
     */
    public function test_invalid_filters_are_rejected_before_the_service_is_called(): void
    {
        // --- step 1 - forbid service calls for malformed filters - start ---
        $this->actingAs(User::factory()->create());

        $this->mock(SaldoService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('getSaldoHistory');
        });
        // --- step 1 - forbid service calls for malformed filters - end ---

        // --- step 2 - define invalid filter inputs - start ---
        $invalidFilters = [
            ['startDate' => ['2026-11-20']],
            ['endDate' => false],
            ['saldo_history_current_ids' => ['not-json']],
            ['saldo_history_current_ids' => '['],
            ['saldo_history_current_ids' => '{}'],
            ['saldo_history_current_ids' => 'null'],
            ['saldo_history_current_ids' => '0'],
            ['saldo_history_current_ids' => '[true]'],
            ['saldo_history_current_ids' => '[["nested"]]'],
            ['saldo_history_current_ids' => '["not-a-uuid"]'],
        ];
        // --- step 2 - define invalid filter inputs - end ---

        // --- step 3 - verify validation responses - start ---
        foreach ($invalidFilters as $filters) {
            $response = $this->json('GET', self::HISTORY_ENDPOINT, $filters)
                ->assertUnprocessable()
                ->assertJsonPath('status', 'error');

            $this->assertIsString($response->json('message'));
        }
        // --- step 3 - verify validation responses - end ---
    }

    /**
     * Checks authorization before any balance query or filter validation can succeed.
     *
     * @return void Missing and deleted users both receive the existing 401 response.
     */
    public function test_balance_reads_require_an_existing_authenticated_user(): void
    {
        // Establish the guest response before testing a stale authenticated user.
        $this->assertBalanceReadsUnauthorized();

        // --- step 1 - delete the signed-in test user - start ---
        $user = User::factory()->create();
        $this->actingAs($user);
        $user->delete();

        $this->assertBalanceReadsUnauthorized();
        // --- step 1 - delete the signed-in test user - end ---
    }

    /**
     * Creates a dated history row for a specific local test user.
     *
     * @param  User  $user  Owner used to verify the query's user scope.
     * @param  string  $createdAt  Timestamp used to verify the date range.
     *
     * @return SaldoHistory Row whose UUID can be included in the excluded-ID filter.
     */
    private function recordHistory(User $user, string $createdAt): SaldoHistory
    {
        // Match against the database timestamp instead of the test run date.
        return SaldoHistory::forceCreate([
            'user_id' => $user->id,
            'type' => 'incoming',
            'price' => self::INCOME,
            'created_at' => $createdAt,
        ]);
    }

    /**
     * Verifies the status and response shape for unauthorized balance requests.
     *
     * @return void Malformed history filters still receive the authentication failure first.
     */
    private function assertBalanceReadsUnauthorized(): void
    {
        // Send a malformed history filter to prove authentication is checked first.
        $error = ['status' => 'error', 'message' => 'Unauthorized'];

        $this->getJson(self::SUMMARY_ENDPOINT)->assertUnauthorized()->assertExactJson($error);
        $this->json('GET', self::HISTORY_ENDPOINT, ['startDate' => ['invalid']])
            ->assertUnauthorized()
            ->assertExactJson($error);
    }
}
