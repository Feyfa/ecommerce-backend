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
            new Response(HttpStatus::HTTP_OK, [], '{"status":"PENDING"}'),
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
            ['status' => 'success', 'data' => ['status' => 'PENDING']],
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
     * @return void Verifies that every operation returns the same error shape.
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

        // --- step 2 - Check the error envelopes - start ---
        $this->assertSame($expected, $service->createVirtualAccount());
        $this->assertSame($expected, $service->simulateVirtualAccountPool());
        $this->assertSame($expected, $service->simulateVirtualAccountFixed());
        $this->assertSame($expected, $service->disbursement());
        // --- step 2 - Check the error envelopes - end ---
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
     * Keeps disbursement without a timeout until withdrawals can stay pending after a lost answer.
     *
     * @return void Checks that the disbursement request carries no connect or total limit.
     */
    public function test_disbursement_is_sent_without_a_timeout(): void
    {
        // --- step 1 - Send a disbursement - start ---
        $handler = new MockHandler([new Response(HttpStatus::HTTP_OK, [], '{}')]);
        $service = $this->serviceWithHandler($handler, new NullLogger());

        $service->disbursement();
        // --- step 1 - Send a disbursement - end ---

        // --- step 2 - Check the request options - start ---
        // A timeout here would treat a lost answer as a rejection while the money may be sent.
        $options = $handler->getLastOptions();
        $this->assertArrayNotHasKey(RequestOptions::CONNECT_TIMEOUT, $options);
        $this->assertArrayNotHasKey(RequestOptions::TIMEOUT, $options);
        // --- step 2 - Check the request options - end ---
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
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(count(self::VIRTUAL_ACCOUNT_OPERATIONS)))
            ->method('warning')
            ->willReturnCallback(
                function (string|Stringable $message, array $context) use (&$contexts): void {
                    $contexts[] = $context;
                },
            );
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
