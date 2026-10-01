<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateApiRequest;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Verifies transaction filter validation before the read service receives request values.
 */
class TransactionControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bypasses only Clerk transport authentication while retaining request normalization middleware.
     *
     * @return void The API tests use Laravel's local authenticated user without external calls.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(AuthenticateApiRequest::class);
    }

    /**
     * Rejects array and object filters before the service can read any transactions.
     *
     * @return void Every supported filter must return a field-specific 422 error for compound values.
     */
    public function test_compound_filters_are_rejected_before_the_service_is_called(): void
    {
        // --- step 1 - start - authenticate a local user and forbid transaction service calls
        $this->actingAs(User::factory()->create());
        $this->mock(TransactionService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('getTransaction');
        });
        // --- step 1 - end - authenticate a local user and forbid transaction service calls

        // --- step 2 - start - reject each filter with array and object input
        foreach (['user_type', 'status_filter', 'search', 'sort', 'page', 'per_page', 'date_from', 'date_to'] as $field) {
            foreach ([['unexpected'], (object) ['unexpected' => 'value']] as $value) {
                $this->json('GET', '/api/transaction', array_replace(['user_type' => 'buyer'], [$field => $value]))
                    ->assertUnprocessable()
                    ->assertJsonPath('status', 'error')
                    ->assertJsonValidationErrors([$field], 'message');
            }
        }
        // --- step 2 - end - reject each filter with array and object input
    }

    /**
     * Rejects scalar values that do not meet string or numeric filter contracts.
     *
     * @return void Text fields reject booleans and numbers; pagination rejects booleans and nonnumeric text.
     */
    public function test_incorrect_scalar_types_are_rejected_before_the_service_is_called(): void
    {
        // --- step 1 - start - authenticate and forbid service calls for malformed filters
        $this->actingAs(User::factory()->create());
        $this->mock(TransactionService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('getTransaction');
        });
        // --- step 1 - end - authenticate and forbid service calls for malformed filters

        // --- step 2 - start - reject non-string scalar text filters
        foreach (['user_type', 'status_filter', 'search', 'sort', 'date_from', 'date_to'] as $field) {
            foreach ([true, false, 123, 1.5] as $value) {
                $this->json('GET', '/api/transaction', array_replace(['user_type' => 'buyer'], [$field => $value]))
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors([$field], 'message');
            }
        }
        // --- step 2 - end - reject non-string scalar text filters

        // --- step 3 - start - reject nonnumeric pagination filters
        foreach (['page', 'per_page'] as $field) {
            foreach ([true, false, 'not-a-number'] as $value) {
                $this->json('GET', '/api/transaction', ['user_type' => 'buyer', $field => $value])
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors([$field], 'message');
            }
        }
        // --- step 3 - end - reject nonnumeric pagination filters
    }

    /**
     * Keeps authentication failure ahead of malformed filter validation.
     *
     * @return void An unauthenticated request receives the existing 401 response without calling the service.
     */
    public function test_unauthenticated_requests_are_rejected_before_filter_validation(): void
    {
        $this->mock(TransactionService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('getTransaction');
        });

        $this->json('GET', '/api/transaction', ['search' => ['invalid']])
            ->assertUnauthorized()
            ->assertExactJson(['status' => 'error', 'message' => 'Unauthorized']);
    }

    /**
     * Keeps the database existence check even when the request contains a User instance.
     *
     * @return void A user deleted after authentication receives 401 before malformed filter validation.
     */
    public function test_a_deleted_authenticated_user_is_rejected_before_filter_validation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $user->delete();
        $this->mock(TransactionService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('getTransaction');
        });

        $this->json('GET', '/api/transaction', ['search' => ['invalid']])
            ->assertUnauthorized();
    }

    /**
     * Preserves service validation for missing, null, empty, and unknown string user perspectives.
     *
     * @return void Type-valid but unsupported perspectives retain the existing business error and HTTP 400.
     */
    public function test_invalid_string_perspectives_keep_the_existing_business_error(): void
    {
        $this->actingAs(User::factory()->create());

        foreach ([[], ['user_type' => null], ['user_type' => ''], ['user_type' => 'admin']] as $payload) {
            $this->json('GET', '/api/transaction', $payload)
                ->assertStatus(400)
                ->assertExactJson([
                    'status' => 'error',
                    'message' => 'user type cannot be empty and must be seller or buyer',
                ]);
        }
    }

    /**
     * Maps omitted, null, and empty optional filters to the same service defaults.
     *
     * @return void All three requests pass the expected default filter values to the read service.
     */
    public function test_optional_filters_keep_the_existing_defaults(): void
    {
        // --- step 1 - start - define the service defaults and expected calls
        $user = User::factory()->create();
        $this->actingAs($user);
        $defaults = [
            'status' => 'all',
            'search' => '',
            'sort' => 'newest',
            'page' => 1,
            'per_page' => 5,
            'date_from' => '',
            'date_to' => '',
        ];
        $this->mock(TransactionService::class, function (MockInterface $mock) use ($user, $defaults): void {
            $mock->shouldReceive('getTransaction')
                ->with($user->id, 'buyer', $defaults)
                ->times(3)
                ->andReturn(['status' => 'success', 'transactions' => [], 'counts' => [], 'pagination' => []]);
        });
        // --- step 1 - end - define the service defaults and expected calls

        // --- step 2 - start - verify omitted, null, and empty request values
        $fields = ['status_filter', 'search', 'sort', 'page', 'per_page', 'date_from', 'date_to'];
        foreach ([[], array_fill_keys($fields, null), array_fill_keys($fields, '')] as $filters) {
            $this->json('GET', '/api/transaction', ['user_type' => 'buyer', ...$filters])
                ->assertOk()
                ->assertJsonPath('status', 'success');
        }
        // --- step 2 - end - verify omitted, null, and empty request values
    }

    /**
     * Accepts the buyer and seller query parameter shapes used by the frontend.
     *
     * @return void String filters and numeric strings or decimals reach the service without new enum or date restrictions.
     */
    public function test_valid_frontend_queries_and_numeric_values_reach_the_service(): void
    {
        // --- step 1 - start - define the accepted frontend and numeric request contracts
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->mock(TransactionService::class, function (MockInterface $mock) use ($user): void {
            foreach (['buyer', 'seller'] as $perspective) {
                $mock->shouldReceive('getTransaction')
                    ->with($user->id, $perspective, [
                        'status' => 'paid',
                        'search' => 'Produk',
                        'sort' => 'oldest',
                        'page' => '1',
                        'per_page' => '5',
                        'date_from' => '2026-09-01',
                        'date_to' => '2026-09-30',
                    ])
                    ->once()
                    ->andReturn(['status' => 'success']);
            }
            $mock->shouldReceive('getTransaction')
                ->with($user->id, 'buyer', [
                    'status' => 'unknown',
                    'search' => '',
                    'sort' => 'unknown',
                    'page' => -1.5,
                    'per_page' => 30.5,
                    'date_from' => 'invalid-date',
                    'date_to' => '',
                ])
                ->once()
                ->andReturn(['status' => 'success']);
        });
        // --- step 1 - end - define the accepted frontend and numeric request contracts

        // --- step 2 - start - send actual buyer and seller query strings
        foreach (['buyer', 'seller'] as $perspective) {
            $query = http_build_query([
                'user_type' => $perspective,
                'status_filter' => 'paid',
                'search' => 'Produk',
                'sort' => 'oldest',
                'page' => 1,
                'per_page' => 5,
                'date_from' => '2026-09-01',
                'date_to' => '2026-09-30',
            ]);
            $this->getJson('/api/transaction?'.$query)->assertOk();
        }
        // --- step 2 - end - send actual buyer and seller query strings

        // --- step 3 - start - retain decimal pagination and unsupported string filter handling
        $this->json('GET', '/api/transaction', [
            'user_type' => 'buyer',
            'status_filter' => 'unknown',
            'sort' => 'unknown',
            'page' => -1.5,
            'per_page' => 30.5,
            'date_from' => 'invalid-date',
        ])->assertOk();
        // --- step 3 - end - retain decimal pagination and unsupported string filter handling
    }
}
