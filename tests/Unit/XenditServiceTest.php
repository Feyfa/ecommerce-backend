<?php

namespace Tests\Unit;

use App\Services\XenditService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stringable;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Throwable;

class XenditServiceTest extends TestCase
{
    // Distinct from the production defaults so the tests prove the configured values are sent.
    private const CONNECT_TIMEOUT_SECONDS = 2.5;

    private const REQUEST_TIMEOUT_SECONDS = 7.5;

    private const VIRTUAL_ACCOUNT_URL = 'https://api.xendit.co/callback_virtual_accounts';

    private const VIRTUAL_ACCOUNT_OPERATIONS = [
        'create_virtual_account',
        'simulate_virtual_account_pool',
        'simulate_virtual_account_fixed',
    ];

    private const EXTERNAL_ID = 'dis-bca-01jtestexternalid';

    // Shape of the Sandbox answer seen in the TOK-88 probe, with the bank fields left out.
    private const PENDING_DISBURSEMENT_BODY = '{"id":"disb-1","external_id":"'
        .self::EXTERNAL_ID.'","amount":10000,"status":"PENDING"}';

    private const PENDING_DISBURSEMENT_SUMMARY = [
        'id' => 'disb-1',
        'status' => 'PENDING',
        'failure_code' => null,
    ];

    private const DUPLICATE_KEY_BODY = '{"error_code":"DUPLICATE_TRANSACTION_ERROR",'
        .'"message":"Request failed because idempotency key has been seen before"}';

    private const NOT_FOUND_BODY = '{"error_code":"DIRECT_DISBURSEMENT_NOT_FOUND_ERROR",'
        .'"message":"Direct disbursement not found"}';

    /**
     * Locks the result keys, status spelling, and unmodified provider payload.
     *
     * @return void Checks the successful envelope of every operation.
     *
     * @throws \JsonException If the fixture cannot be encoded.
     */
    public function test_success_preserves_decoded_payloads(): void
    {
        // --- step 1 - Supply successful responses for every operation - start ---
        $account = ['account_number' => '001234', 'external_id' => 'va-fixture'];
        $service = $this->serviceWithResponses(
            new Response(HttpStatus::HTTP_OK, [], json_encode($account, JSON_THROW_ON_ERROR)),
            new Response(HttpStatus::HTTP_OK, [], 'true'),
            new Response(HttpStatus::HTTP_OK, [], 'null'),
            new Response(HttpStatus::HTTP_OK, [], self::PENDING_DISBURSEMENT_BODY),
        );
        // --- step 1 - Supply successful responses for every operation - end ---

        // --- step 2 - Check the successful envelopes - start ---
        $this->assertSame(
            ['status' => 'suceess', 'data' => $account],
            $service->createVirtualAccount(),
        );
        $this->assertSame(
            ['status' => 'success', 'data' => true],
            $service->simulateVirtualAccountPool(),
        );
        $this->assertSame(
            ['status' => 'success', 'data' => null],
            $service->simulateVirtualAccountFixed(),
        );
        $this->assertSame(
            ['status' => 'success', 'disbursement' => self::PENDING_DISBURSEMENT_SUMMARY],
            $service->disbursement(),
        );
        // --- step 2 - Check the successful envelopes - end ---
    }

    /**
     * Exercises the real Guzzle error path for each provider operation.
     *
     * @param  string  $body  Body supplied by the fake provider.
     * @param  string  $message  Text expected by API callers.
     *
     * @return void Verifies that every operation returns the same message, and that a
     *              disbursement rejection also carries the Xendit error code.
     */
    #[DataProvider('errorBodies')]
    public function test_client_errors_return_a_string_message(string $body, string $message): void
    {
        // --- step 1 - Supply provider rejections - start ---
        $service = $this->serviceWithResponses(
            new Response(HttpStatus::HTTP_BAD_REQUEST, [], $body),
            new Response(HttpStatus::HTTP_BAD_REQUEST, [], $body),
            new Response(HttpStatus::HTTP_BAD_REQUEST, [], $body),
            new Response(HttpStatus::HTTP_BAD_REQUEST, [], $body),
        );
        $expected = ['status' => 'error', 'message' => $message];
        // --- step 1 - Supply provider rejections - end ---

        // --- step 2 - Check the virtual account error envelopes - start ---
        $this->assertSame($expected, $service->createVirtualAccount());
        $this->assertSame($expected, $service->simulateVirtualAccountPool());
        $this->assertSame($expected, $service->simulateVirtualAccountFixed());
        // --- step 2 - Check the virtual account error envelopes - end ---

        // --- step 3 - Check the disbursement error envelope - start ---
        // None of these bodies carries an error code, so the code stays null.
        $this->assertSame([...$expected, 'error_code' => null], $service->disbursement());
        // --- step 3 - Check the disbursement error envelope - end ---
    }

    /**
     * Keeps the Xendit error code of a disbursement rejection for the withdrawal record.
     *
     * @return void Checks a 400 and a 429, which both mean Xendit did not accept the request.
     */
    public function test_disbursement_rejections_return_the_error_code(): void
    {
        // --- step 1 - Supply a validation error and a rate limit - start ---
        $service = $this->serviceWithResponses(
            new Response(
                HttpStatus::HTTP_BAD_REQUEST,
                [],
                '{"error_code":"API_VALIDATION_ERROR","message":"Invalid bank code"}',
            ),
            new Response(
                HttpStatus::HTTP_TOO_MANY_REQUESTS,
                [],
                '{"error_code":"RATE_LIMIT_EXCEEDED","message":"Too many requests"}',
            ),
        );
        // --- step 1 - Supply a validation error and a rate limit - end ---

        // --- step 2 - Check both rejections - start ---
        $this->assertSame(
            [
                'status' => 'error',
                'message' => 'Invalid bank code',
                'error_code' => 'API_VALIDATION_ERROR',
            ],
            $service->disbursement(),
        );
        $this->assertSame(
            [
                'status' => 'error',
                'message' => 'Too many requests',
                'error_code' => 'RATE_LIMIT_EXCEEDED',
            ],
            $service->disbursement(),
        );
        // --- step 2 - Check both rejections - end ---
    }

    /**
     * Covers bodies that must preserve text or fall back to an error string.
     *
     * @return array<string, array{string, string}> Provider bodies and expected messages.
     */
    public static function errorBodies(): array
    {
        // Callers accept an empty string today, so preserve it as well as nonempty text.
        return [
            'text' => ['{"message":"Bank unavailable"}', 'Bank unavailable'],
            'empty text' => ['{"message":""}', ''],
            'missing message' => ['{}', 'Unknown error'],
            'null message' => ['{"message":null}', 'Unknown error'],
            'array message' => ['{"message":["invalid"]}', 'Unknown error'],
            'numeric message' => ['{"message":42}', 'Unknown error'],
            'non-JSON body' => ['<html>Error</html>', 'Unknown error'],
            'empty body' => ['', 'Unknown error'],
        ];
    }

    /**
     * Confirms every virtual account request carries the configured connect and total limits.
     *
     * @return void Checks the options Guzzle received for each virtual account operation.
     */
    public function test_virtual_account_requests_send_the_configured_timeouts(): void
    {
        // --- step 1 - Supply successful responses - start ---
        $handler = new MockHandler([
            new Response(HttpStatus::HTTP_OK, [], '{}'),
            new Response(HttpStatus::HTTP_OK, [], '{}'),
            new Response(HttpStatus::HTTP_OK, [], '{}'),
        ]);
        $service = $this->serviceWithHandler($handler, new NullLogger());
        // --- step 1 - Supply successful responses - end ---

        // --- step 2 - Send each operation - start ---
        $operations = [
            'create' => fn () => $service->createVirtualAccount(),
            'simulate pool' => fn () => $service->simulateVirtualAccountPool(),
            'simulate fixed' => fn () => $service->simulateVirtualAccountFixed(),
        ];
        $sentOptions = [];

        foreach ($operations as $name => $operation) {
            $operation();

            $sentOptions[$name] = $handler->getLastOptions();
        }
        // --- step 2 - Send each operation - end ---

        // --- step 3 - Check the timeouts each request carried - start ---
        foreach ($sentOptions as $name => $options) {
            $this->assertSame(
                self::CONNECT_TIMEOUT_SECONDS,
                $options[RequestOptions::CONNECT_TIMEOUT] ?? null,
                $name,
            );
            $this->assertSame(
                self::REQUEST_TIMEOUT_SECONDS,
                $options[RequestOptions::TIMEOUT] ?? null,
                $name,
            );
        }
        // --- step 3 - Check the timeouts each request carried - end ---
    }

    /**
     * Confirms both disbursement requests carry the limits and the create call its idempotency key.
     *
     * @return void Checks the options of the create and lookup requests.
     */
    public function test_disbursement_requests_send_the_timeouts_and_idempotency_key(): void
    {
        // --- step 1 - Create and look up a disbursement - start ---
        $handler = new MockHandler([
            new Response(HttpStatus::HTTP_OK, [], self::PENDING_DISBURSEMENT_BODY),
            new Response(HttpStatus::HTTP_OK, [], '['.self::PENDING_DISBURSEMENT_BODY.']'),
        ]);
        $service = $this->serviceWithHandler($handler, new NullLogger());

        // Guzzle moves headers and the query into the request, so those are read from it.
        $service->disbursement(external_id: self::EXTERNAL_ID);
        $createOptions = $handler->getLastOptions();
        $createRequest = $handler->getLastRequest();

        $service->findDisbursementByExternalId(self::EXTERNAL_ID);
        $lookupOptions = $handler->getLastOptions();
        $lookupRequest = $handler->getLastRequest();
        // --- step 1 - Create and look up a disbursement - end ---

        // --- step 2 - Check the timeouts of both requests - start ---
        // Withdrawals are held before the call, so a bounded wait can no longer lose money.
        foreach (['create' => $createOptions, 'lookup' => $lookupOptions] as $name => $options) {
            $this->assertSame(
                self::CONNECT_TIMEOUT_SECONDS,
                $options[RequestOptions::CONNECT_TIMEOUT] ?? null,
                $name,
            );
            $this->assertSame(
                self::REQUEST_TIMEOUT_SECONDS,
                $options[RequestOptions::TIMEOUT] ?? null,
                $name,
            );
        }
        // --- step 2 - Check the timeouts of both requests - end ---

        // --- step 3 - Check the idempotency key and lookup query - start ---
        $this->assertSame(self::EXTERNAL_ID, $createRequest?->getHeaderLine('X-IDEMPOTENCY-KEY'));
        $this->assertSame('external_id='.self::EXTERNAL_ID, $lookupRequest?->getUri()->getQuery());
        // --- step 3 - Check the idempotency key and lookup query - end ---
    }

    /**
     * Treats every disbursement answer that does not prove a rejection as an unknown result.
     *
     * @param  Response|Throwable  $failure  What the fake provider returns or throws.
     * @param  int|null  $httpStatus  Status expected in the log, or null without a response.
     * @param  int|null  $curlErrno  cURL code expected in the log, or null without one.
     *
     * @return void Checks the result and the single warning entry.
     */
    #[DataProvider('unansweredDisbursements')]
    public function test_unanswered_disbursements_are_unknown(
        Response|Throwable $failure,
        ?int $httpStatus,
        ?int $curlErrno,
    ): void {
        // --- step 1 - Capture the warning log entry - start ---
        $contexts = [];
        $logger = $this->warningRecorder($contexts, 1);
        // --- step 1 - Capture the warning log entry - end ---

        // --- step 2 - Send the disbursement - start ---
        $service = $this->serviceWithHandler(new MockHandler([$failure]), $logger);

        $result = $service->disbursement(external_id: self::EXTERNAL_ID);
        // --- step 2 - Send the disbursement - end ---

        // --- step 3 - Check the result and the logged fields - start ---
        // An error result would return the balance while the money may already be on its way.
        $this->assertSame(['status' => 'unknown'], $result);
        $this->assertSame('create_disbursement', $contexts[0]['operation']);
        $this->assertSame($httpStatus, $contexts[0]['http_status']);
        $this->assertSame($curlErrno, $contexts[0]['curl_errno']);
        // --- step 3 - Check the result and the logged fields - end ---
    }

    /**
     * Covers the lost answers of the virtual account test plus a reused idempotency key.
     *
     * @return array<string, array{Response|Throwable, int|null, int|null}> Failure and log fields.
     */
    public static function unansweredDisbursements(): array
    {
        // Xendit answers a reused key with 400, so only the error code tells it from a rejection.
        $duplicateKey = new Response(HttpStatus::HTTP_BAD_REQUEST, [], self::DUPLICATE_KEY_BODY);

        return [
            ...self::unansweredFailures(),
            'duplicate idempotency key' => [$duplicateKey, HttpStatus::HTTP_BAD_REQUEST, null],
        ];
    }

    /**
     * Maps a lookup answer to found, not_found, or unknown.
     *
     * @param  Response|Throwable  $answer  What the fake provider returns or throws.
     * @param  array<string, mixed>  $expected  Lookup result the caller must receive.
     * @param  int  $warnings  Number of warning entries the lookup must write.
     *
     * @return void Checks the result and the number of warnings.
     */
    #[DataProvider('lookupAnswers')]
    public function test_disbursement_lookup_classifies_the_answer(
        Response|Throwable $answer,
        array $expected,
        int $warnings,
    ): void {
        // --- step 1 - Look the disbursement up - start ---
        $contexts = [];
        $logger = $this->warningRecorder($contexts, $warnings);
        $service = $this->serviceWithHandler(new MockHandler([$answer]), $logger);

        $result = $service->findDisbursementByExternalId(self::EXTERNAL_ID);
        // --- step 1 - Look the disbursement up - end ---

        // --- step 2 - Check the result - start ---
        $this->assertSame($expected, $result);
        // --- step 2 - Check the result - end ---
    }

    /**
     * Covers a found disbursement, the Xendit not-found answer, and answers that prove nothing.
     *
     * Each case holds the provider answer, the expected lookup result, and the warning count.
     *
     * @return array<string, array{Response|Throwable, array<string, mixed>, int}> Named cases.
     */
    public static function lookupAnswers(): array
    {
        // --- step 1 - Prepare the answers that settle the lookup - start ---
        $pending = new Response(HttpStatus::HTTP_OK, [], '['.self::PENDING_DISBURSEMENT_BODY.']');
        $failed = new Response(
            HttpStatus::HTTP_OK,
            [],
            '[{"id":"disb-2","status":"FAILED","failure_code":"INVALID_DESTINATION"}]',
        );
        $notFound = new Response(HttpStatus::HTTP_NOT_FOUND, [], self::NOT_FOUND_BODY);

        $pendingResult = [
            'status' => 'found',
            'disbursements' => [self::PENDING_DISBURSEMENT_SUMMARY],
        ];
        $failedResult = [
            'status' => 'found',
            'disbursements' => [
                ['id' => 'disb-2', 'status' => 'FAILED', 'failure_code' => 'INVALID_DESTINATION'],
            ],
        ];
        // --- step 1 - Prepare the answers that settle the lookup - end ---

        // --- step 2 - Prepare the answers that prove nothing - start ---
        // Only the documented not-found code may lead to a refund; anything else stays unknown.
        $bareNotFound = new Response(HttpStatus::HTTP_NOT_FOUND, [], '{}');
        $unauthorized = new Response(
            HttpStatus::HTTP_UNAUTHORIZED,
            [],
            '{"error_code":"INVALID_API_KEY"}',
        );
        $emptyList = new Response(HttpStatus::HTTP_OK, [], '[]');
        $object = new Response(HttpStatus::HTTP_OK, [], self::PENDING_DISBURSEMENT_BODY);
        $timeout = self::unansweredFailures()['timeout'][0];
        $unknown = ['status' => 'unknown'];
        // --- step 2 - Prepare the answers that prove nothing - end ---

        return [
            'pending' => [$pending, $pendingResult, 0],
            'failed with failure code' => [$failed, $failedResult, 0],
            'not found' => [$notFound, ['status' => 'not_found'], 0],
            '404 without the not-found code' => [$bareNotFound, $unknown, 1],
            'unauthorized' => [$unauthorized, $unknown, 1],
            'empty list' => [$emptyList, $unknown, 1],
            'object instead of list' => [$object, $unknown, 1],
            'timeout' => [$timeout, $unknown, 1],
        ];
    }

    /**
     * Turns failures without a usable answer into a safe message and a log without body or URL.
     *
     * @param  Response|Throwable  $failure  What the fake provider returns or throws.
     * @param  int|null  $httpStatus  Status expected in the log, or null without a response.
     * @param  int|null  $curlErrno  cURL code expected in the log, or null without one.
     *
     * @return void Checks the result of every virtual account operation and its log entry.
     *
     * @throws \JsonException If the captured log context cannot be encoded.
     */
    #[DataProvider('unansweredFailures')]
    public function test_unanswered_virtual_account_requests_return_a_safe_message(
        Response|Throwable $failure,
        ?int $httpStatus,
        ?int $curlErrno,
    ): void {
        // --- step 1 - Capture the warning log entries - start ---
        $contexts = [];
        $logger = $this->warningRecorder($contexts, count(self::VIRTUAL_ACCOUNT_OPERATIONS));
        // --- step 1 - Capture the warning log entries - end ---

        // --- step 2 - Supply the same failure for every operation - start ---
        $handler = new MockHandler([$failure, $failure, $failure]);
        $service = $this->serviceWithHandler($handler, $logger);
        // --- step 2 - Supply the same failure for every operation - end ---

        // --- step 3 - Call every virtual account operation - start ---
        $results = [
            $service->createVirtualAccount(),
            $service->simulateVirtualAccountPool(),
            $service->simulateVirtualAccountFixed(),
        ];
        // --- step 3 - Call every virtual account operation - end ---

        // --- step 4 - Check the buyer-facing results - start ---
        $expected = [
            'status' => 'error',
            'message' => XenditService::PROVIDER_UNAVAILABLE_MESSAGE,
        ];

        foreach ($results as $result) {
            $this->assertSame($expected, $result);
        }
        // --- step 4 - Check the buyer-facing results - end ---

        // --- step 5 - Check the logged fields - start ---
        $this->assertSame(self::VIRTUAL_ACCOUNT_OPERATIONS, array_column($contexts, 'operation'));
        $this->assertSame([$httpStatus], array_unique(array_column($contexts, 'http_status')));
        $this->assertSame([$curlErrno], array_unique(array_column($contexts, 'curl_errno')));
        // --- step 5 - Check the logged fields - end ---

        // --- step 6 - Check the log leaves out the URL and body - start ---
        // The URL can carry an external ID and a 5xx message copies the body; neither belongs here.
        $loggedText = json_encode($contexts, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('api.xendit.co', $loggedText);
        $this->assertStringNotContainsString('Service Unavailable', $loggedText);
        // --- step 6 - Check the log leaves out the URL and body - end ---
    }

    /**
     * Covers a timeout, a 5xx answer, and a connection dropped before any response.
     *
     * @return array<string, array{Response|Throwable, int|null, int|null}> Failure and log fields.
     */
    public static function unansweredFailures(): array
    {
        // Messages mimic Guzzle by ending with the URL; cURL 28 is a timeout, 56 a receive error.
        $request = new Request('POST', self::VIRTUAL_ACCOUNT_URL);
        $timeout = new ConnectException(
            'cURL error 28: Operation timed out for '.self::VIRTUAL_ACCOUNT_URL,
            $request,
            null,
            ['errno' => 28, 'error' => 'Operation timed out'],
        );
        $connectionReset = new RequestException(
            'cURL error 56: Recv failure: Connection reset by peer for '.self::VIRTUAL_ACCOUNT_URL,
            $request,
            null,
            null,
            ['errno' => 56, 'error' => 'Recv failure: Connection reset by peer'],
        );
        $serverError = new Response(
            HttpStatus::HTTP_SERVICE_UNAVAILABLE,
            [],
            '{"message":"Service Unavailable"}',
        );

        return [
            'timeout' => [$timeout, null, 28],
            'server error' => [$serverError, HttpStatus::HTTP_SERVICE_UNAVAILABLE, null],
            'connection reset' => [$connectionReset, null, 56],
        ];
    }

    /**
     * Builds a logger mock that expects a number of warnings and keeps each context.
     *
     * @param  list<array<string, mixed>>  $contexts  Receives the context of every warning.
     * @param  int  $expectedWarnings  Exact number of warnings the code under test must write.
     *
     * @return LoggerInterface Logger that fails the test on a different warning count.
     */
    private function warningRecorder(array &$contexts, int $expectedWarnings): LoggerInterface
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly($expectedWarnings))
            ->method('warning')
            ->willReturnCallback(
                function (string|Stringable $message, array $context) use (&$contexts): void {
                    $contexts[] = $context;
                },
            );

        return $logger;
    }

    /**
     * Routes every HTTP request to a local response queue under Guzzle's normal middleware.
     *
     * @param  Response  ...$responses  Responses in request order.
     *
     * @return XenditService Adapter isolated from network and real credentials.
     */
    private function serviceWithResponses(Response ...$responses): XenditService
    {
        // Use the normal middleware so a 400 is converted into a real ClientException.
        return $this->serviceWithHandler(new MockHandler($responses), new NullLogger());
    }

    /**
     * Builds the adapter on a mock handler with the test timeouts and a chosen logger.
     *
     * @param  MockHandler  $handler  Queue of responses or failures, kept for option checks.
     * @param  LoggerInterface  $logger  Receives provider failure entries.
     *
     * @return XenditService Adapter isolated from network and real credentials.
     */
    private function serviceWithHandler(
        MockHandler $handler,
        LoggerInterface $logger,
    ): XenditService {
        // Normal middleware turns a 4xx into ClientException and a 5xx into ServerException.
        $client = new Client(['handler' => HandlerStack::create($handler)]);

        return new XenditService(
            'test-key',
            $client,
            self::CONNECT_TIMEOUT_SECONDS,
            self::REQUEST_TIMEOUT_SECONDS,
            $logger,
        );
    }
}
