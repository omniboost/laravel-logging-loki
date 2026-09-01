<?php

namespace Omniboost\LaravelLoggingLoki\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Omniboost\LaravelLoggingLoki\DTOs\LokiStream;
use Omniboost\LaravelLoggingLoki\Exceptions\LokiConnectionException;
use Omniboost\LaravelLoggingLoki\Exceptions\LokiException;
use Omniboost\LaravelLoggingLoki\Exceptions\LokiPushException;
use Omniboost\LaravelLoggingLoki\Exceptions\LokiResponseException;
use Omniboost\LaravelLoggingLoki\LokiClient;
use Orchestra\Testbench\TestCase;

/**
 * Unit tests for the error reporting in LokiClient::push().
 *
 * A successful push returns true; a failed push throws with the real reason —
 * the HTTP status + body Loki returned, or the transport error — so callers no
 * longer get a generic "Failed to send logs to Loki" that hides whether it was
 * auth, the endpoint, or the network.
 */
class PushErrorReportingTest extends TestCase
{
    private const LOKI_URL = 'http://localhost:3100';

    /**
     * Build a LokiClient whose HTTP client is backed by the given mock handler.
     *
     * @param array<\GuzzleHttp\Psr7\Response|\Throwable> $queue
     */
    private function clientWithResponses(array $queue): LokiClient
    {
        $reflection = new \ReflectionClass(LokiClient::class);
        $instance = $reflection->newInstanceArgs([self::LOKI_URL, null, null, true]);

        $httpClient = new Client(['handler' => HandlerStack::create(new MockHandler($queue))]);
        $property = $reflection->getProperty('httpClient');
        $property->setAccessible(true);
        $property->setValue($instance, $httpClient);

        return $instance;
    }

    private function sampleStreams(): array
    {
        return [
            new LokiStream(
                ['level' => 'info', 'app' => 'test'],
                [['1234567890000000000', 'Test log message']]
            ),
        ];
    }

    public function testSuccessfulPushReturnsTrue()
    {
        fwrite(STDERR, "\n  → Testing successful push returns true...\n");

        $client = $this->clientWithResponses([new Response(204)]);

        $this->assertTrue($client->push($this->sampleStreams()));

        fwrite(STDERR, "    ✓ push() returns true on HTTP 204\n");
    }

    public function testAuthFailureThrowsWithStatusAndBody()
    {
        fwrite(STDERR, "\n  → Testing 401 throws with status and body...\n");

        $client = $this->clientWithResponses([
            new Response(401, [], 'authentication error: invalid scope requested'),
        ]);

        try {
            $client->push($this->sampleStreams());
            $this->fail('Expected push() to throw on a 401 response');
        } catch (LokiResponseException $e) {
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
            $this->assertStringContainsString('invalid scope', $e->getMessage());
            $this->assertSame(401, $e->getStatusCode());
            $this->assertStringContainsString('invalid scope', $e->getResponseBody());
            $this->assertFalse($e->isRetryable());
        }

        fwrite(STDERR, "    ✓ exception message contains the 401 status and response body\n");
    }

    public function testConnectionFailureThrowsWithTransportError()
    {
        fwrite(STDERR, "\n  → Testing connection failure throws transport error...\n");

        $client = $this->clientWithResponses([
            new \GuzzleHttp\Exception\ConnectException(
                'cURL error 28: Connection timed out',
                new \GuzzleHttp\Psr7\Request('POST', self::LOKI_URL . '/loki/api/v1/push')
            ),
        ]);

        try {
            $client->push($this->sampleStreams());
            $this->fail('Expected push() to throw on a connection failure');
        } catch (LokiConnectionException $e) {
            $this->assertStringContainsString('Could not reach Loki', $e->getMessage());
            $this->assertStringContainsString('Connection timed out', $e->getMessage());
            $this->assertSame(self::LOKI_URL, $e->getUrl());
        }

        fwrite(STDERR, "    ✓ exception names the transport error, not a response\n");
    }

    public function testRateLimitThrowsWithStatus()
    {
        fwrite(STDERR, "\n  → Testing 429 throws with status...\n");

        $client = $this->clientWithResponses([new Response(429, [], 'rate limited')]);

        try {
            $client->push($this->sampleStreams());
            $this->fail('Expected push() to throw on a 429 response');
        } catch (LokiResponseException $e) {
            $this->assertStringContainsString('HTTP 429', $e->getMessage());
            $this->assertSame(429, $e->getStatusCode());
            $this->assertTrue($e->isRetryable(), 'A rate limit is worth retrying');
        }

        fwrite(STDERR, "    ✓ 429 throws a retryable LokiResponseException\n");
    }

    public function testUnexpectedSuccessStatusThrowsInsteadOfSilentlySucceeding()
    {
        fwrite(STDERR, "\n  → Testing an unacknowledged 2xx throws...\n");

        // Guzzle only throws on 4xx/5xx, so an odd 2xx (a proxy, a misrouted
        // endpoint) used to be reported as a successful push and the logs were
        // dropped. It has to fail so the job retries instead.
        $client = $this->clientWithResponses([new Response(260, [], 'unexpected')]);

        try {
            $client->push($this->sampleStreams());
            $this->fail('Expected a LokiResponseException for a non-204 response');
        } catch (LokiResponseException $e) {
            $this->assertStringContainsString('HTTP 260', $e->getMessage());
            $this->assertStringContainsString('unexpected', $e->getMessage());
        }

        fwrite(STDERR, "    ✓ push() throws on a 2xx status Loki never acknowledges with\n");
    }

    public function testPushAcceptedOnHttp200()
    {
        fwrite(STDERR, "\n  → Testing HTTP 200 is accepted...\n");

        // Loki answers 204, but proxies in front of it commonly rewrite that to
        // 200 — that still means the push landed.
        $client = $this->clientWithResponses([new Response(200)]);

        $this->assertTrue($client->push($this->sampleStreams()));

        fwrite(STDERR, "    ✓ push() returns true on HTTP 200\n");
    }

    public function testPushFailuresAreTargetableAsLokiExceptions()
    {
        fwrite(STDERR, "\n  → Testing failures can be caught as a Loki exception...\n");

        // The point of the hierarchy: consumers can single out this package's
        // failures — via the marker interface or the push base class — instead of
        // catching \RuntimeException and matching on the message.
        $client = $this->clientWithResponses([new Response(500, [], 'boom')]);

        try {
            $client->push($this->sampleStreams());
            $this->fail('Expected push() to throw on a 500 response');
        } catch (LokiException $e) {
            $this->assertInstanceOf(LokiPushException::class, $e);
            $this->assertInstanceOf(LokiResponseException::class, $e);
            $this->assertTrue($e->isRetryable(), 'A server error is worth retrying');
        }

        fwrite(STDERR, "    ✓ a push failure is catchable as LokiException / LokiPushException\n");
    }

    public function testPushFailuresRemainRuntimeExceptionsForExistingCallers()
    {
        fwrite(STDERR, "\n  → Testing backwards compatibility with \\RuntimeException...\n");

        // Code written against the previous behaviour must keep working.
        $client = $this->clientWithResponses([new Response(400, [], 'bad request')]);

        try {
            $client->push($this->sampleStreams());
            $this->fail('Expected push() to throw on a 400 response');
        } catch (\RuntimeException $e) {
            $this->assertInstanceOf(LokiException::class, $e);
        }

        fwrite(STDERR, "    ✓ Loki exceptions still extend \\RuntimeException\n");
    }

    public function testRejectionKeepsTheGuzzleExceptionAsPrevious()
    {
        fwrite(STDERR, "\n  → Testing the underlying Guzzle exception is preserved...\n");

        $client = $this->clientWithResponses([new Response(401, [], 'nope')]);

        try {
            $client->push($this->sampleStreams());
            $this->fail('Expected push() to throw on a 401 response');
        } catch (LokiResponseException $e) {
            $this->assertInstanceOf(\GuzzleHttp\Exception\RequestException::class, $e->getPrevious());
        }

        fwrite(STDERR, "    ✓ getPrevious() exposes the original Guzzle exception\n");
    }
}
