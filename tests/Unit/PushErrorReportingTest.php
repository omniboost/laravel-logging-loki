<?php

namespace Omniboost\LaravelLoggingLoki\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Omniboost\LaravelLoggingLoki\DTOs\LokiStream;
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
    /**
     * Build a LokiClient whose HTTP client is backed by the given mock handler.
     *
     * @param array<\GuzzleHttp\Psr7\Response|\Throwable> $queue
     */
    private function clientWithResponses(array $queue): LokiClient
    {
        $reflection = new \ReflectionClass(LokiClient::class);
        $instance = $reflection->newInstanceArgs(['http://localhost:3100', null, null, true]);

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
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
            $this->assertStringContainsString('invalid scope', $e->getMessage());
        }

        fwrite(STDERR, "    ✓ exception message contains the 401 status and response body\n");
    }

    public function testConnectionFailureThrowsWithTransportError()
    {
        fwrite(STDERR, "\n  → Testing connection failure throws transport error...\n");

        $client = $this->clientWithResponses([
            new \GuzzleHttp\Exception\ConnectException(
                'cURL error 28: Connection timed out',
                new \GuzzleHttp\Psr7\Request('POST', 'http://localhost:3100/loki/api/v1/push')
            ),
        ]);

        try {
            $client->push($this->sampleStreams());
            $this->fail('Expected push() to throw on a connection failure');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Could not reach Loki', $e->getMessage());
            $this->assertStringContainsString('Connection timed out', $e->getMessage());
        }

        fwrite(STDERR, "    ✓ exception names the transport error, not a response\n");
    }

    public function testRateLimitThrowsWithStatus()
    {
        fwrite(STDERR, "\n  → Testing 429 throws with status...\n");

        $client = $this->clientWithResponses([new Response(429, [], 'rate limited')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 429');

        $client->push($this->sampleStreams());
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
            $this->fail('Expected a RuntimeException for a non-204 response');
        } catch (\RuntimeException $e) {
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
}
