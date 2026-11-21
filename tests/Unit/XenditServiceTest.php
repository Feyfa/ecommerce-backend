<?php

namespace Tests\Unit;

use App\Services\XenditService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class XenditServiceTest extends TestCase
{
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
     * Routes every HTTP request to a local response queue under Guzzle's normal middleware.
     *
     * @param  Response  ...$responses  Responses in request order.
     *
     * @return XenditService Adapter isolated from network and real credentials.
     */
    private function serviceWithResponses(Response ...$responses): XenditService
    {
        // Use the normal middleware so a 400 is converted into a real ClientException.
        $handler = HandlerStack::create(new MockHandler($responses));
        $client = new Client(['handler' => $handler]);

        return new XenditService('test-key', $client);
    }
}
