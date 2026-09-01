<?php

namespace Omniboost\LaravelLoggingLoki\Tests\Unit;

use Illuminate\Contracts\Queue\Job as JobContract;
use Mockery;
use Omniboost\LaravelLoggingLoki\DTOs\LokiLogEntry;
use Omniboost\LaravelLoggingLoki\Exceptions\LokiConnectionException;
use Omniboost\LaravelLoggingLoki\Exceptions\LokiPayloadException;
use Omniboost\LaravelLoggingLoki\Exceptions\LokiResponseException;
use Omniboost\LaravelLoggingLoki\Jobs\SendLogsToLoki;
use Omniboost\LaravelLoggingLoki\LokiClient;
use Omniboost\LaravelLoggingLoki\LokiServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Unit tests for how SendLogsToLoki treats a failed push.
 *
 * A transient failure (unreachable Loki, 429, 5xx) propagates so the queue
 * retries it. A permanent one (an unencodable payload, a 400/401/404) fails the
 * job straight away: every retry would produce the same rejection, and waiting
 * $tries * $backoff seconds only delays the failed_jobs entry while the buffer
 * behind it keeps growing.
 */
class PushFailureRetryTest extends TestCase
{
    private const LOKI_URL = 'http://localhost:3100';

    protected function setUp(): void
    {
        parent::setUp();
        fwrite(STDERR, "\n");
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [LokiServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('loki.queue', 'sync');
        $app['config']->set('loki.debug', false);
    }

    /**
     * A job that pushes through the given client instead of a real one.
     *
     * @param array<LokiLogEntry> $entries
     */
    private function jobWithClient(LokiClient $client, array $entries): SendLogsToLoki
    {
        $job = new class($entries, self::LOKI_URL) extends SendLogsToLoki {
            public ?LokiClient $fakeClient = null;

            protected function makeClient(): LokiClient
            {
                return $this->fakeClient;
            }
        };
        $job->fakeClient = $client;

        return $job;
    }

    /**
     * A single log entry, enough for the job to have something to push.
     */
    private function anEntry(): LokiLogEntry
    {
        return new LokiLogEntry(['level' => 'info'], 'message', '1234567890000000000');
    }

    /**
     * A job whose push() always throws the given exception, with a fake queue
     * job instance so fail() has something to mark.
     *
     * @return array{0: SendLogsToLoki, 1: \Mockery\MockInterface}
     */
    private function jobThatFailsWith(\Throwable $failure, bool $expectFail): array
    {
        $client = Mockery::mock(LokiClient::class);
        $client->shouldReceive('push')->once()->andThrow($failure);

        $job = $this->jobWithClient($client, [$this->anEntry()]);

        $queueJob = Mockery::mock(JobContract::class);

        if ($expectFail) {
            $queueJob->shouldReceive('fail')->once()->with($failure);
        } else {
            $queueJob->shouldNotReceive('fail');
        }

        $queueJob->shouldNotReceive('release');
        $job->job = $queueJob;

        return [$job, $queueJob];
    }

    public function testUnencodablePayloadFailsWithoutRetrying()
    {
        fwrite(STDERR, "  → Testing a payload failure fails the job immediately...\n");

        $failure = LokiPayloadException::compressionFailed();
        [$job] = $this->jobThatFailsWith($failure, expectFail: true);

        // No exception escapes: the job is marked failed instead of thrown back
        // to the worker, which would have retried it.
        $job->handle();

        $this->assertTrue(true, 'handle() returned without throwing, so the worker will not retry');

        fwrite(STDERR, "    ✓ LokiPayloadException marks the job failed, no retry\n");
    }

    public function testRejectionThatCannotSucceedFailsWithoutRetrying()
    {
        fwrite(STDERR, "  → Testing a 401 fails the job immediately...\n");

        $failure = new LokiResponseException(401, 'authentication error');
        [$job] = $this->jobThatFailsWith($failure, expectFail: true);

        $job->handle();

        $this->assertFalse($failure->isRetryable());

        fwrite(STDERR, "    ✓ a non-retryable LokiResponseException marks the job failed, no retry\n");
    }

    public function testBadRequestFailsWithoutRetrying()
    {
        fwrite(STDERR, "  → Testing a 400 fails the job immediately...\n");

        $failure = new LokiResponseException(400, 'entry out of order');
        [$job] = $this->jobThatFailsWith($failure, expectFail: true);

        $job->handle();

        $this->assertFalse($failure->isRetryable());

        fwrite(STDERR, "    ✓ a 400 marks the job failed, no retry\n");
    }

    public function testRateLimitPropagatesSoTheQueueRetries()
    {
        fwrite(STDERR, "  → Testing a 429 still propagates for a retry...\n");

        $failure = new LokiResponseException(429, 'rate limited');
        [$job] = $this->jobThatFailsWith($failure, expectFail: false);

        try {
            $job->handle();
            $this->fail('Expected a 429 to propagate so the queue retries it');
        } catch (LokiResponseException $e) {
            $this->assertSame($failure, $e);
            $this->assertTrue($e->isRetryable());
        }

        fwrite(STDERR, "    ✓ a 429 propagates; the queue retries it per \$tries/\$backoff\n");
    }

    public function testServerErrorPropagatesSoTheQueueRetries()
    {
        fwrite(STDERR, "  → Testing a 503 still propagates for a retry...\n");

        $failure = new LokiResponseException(503, 'service unavailable');
        [$job] = $this->jobThatFailsWith($failure, expectFail: false);

        try {
            $job->handle();
            $this->fail('Expected a 503 to propagate so the queue retries it');
        } catch (LokiResponseException $e) {
            $this->assertSame($failure, $e);
        }

        fwrite(STDERR, "    ✓ a 5xx propagates; the queue retries it\n");
    }

    public function testConnectionFailurePropagatesSoTheQueueRetries()
    {
        fwrite(STDERR, "  → Testing an unreachable Loki still propagates for a retry...\n");

        $failure = new LokiConnectionException(self::LOKI_URL, 'Could not reach Loki');
        [$job] = $this->jobThatFailsWith($failure, expectFail: false);

        try {
            $job->handle();
            $this->fail('Expected a connection failure to propagate so the queue retries it');
        } catch (LokiConnectionException $e) {
            $this->assertSame($failure, $e);
        }

        fwrite(STDERR, "    ✓ a connection failure propagates; the queue retries it\n");
    }

    public function testPermanentFailurePropagatesWhenThereIsNoQueueJob()
    {
        fwrite(STDERR, "  → Testing a permanent failure outside the queue still surfaces...\n");

        // dispatchSync, or handle() called directly: there is no job instance to
        // mark failed, and fail() would silently swallow the exception.
        $client = Mockery::mock(LokiClient::class);
        $client->shouldReceive('push')->once()->andThrow(new LokiResponseException(401, 'nope'));

        $job = $this->jobWithClient($client, [$this->anEntry()]);

        $this->assertNull($job->job, 'No queue job instance is set');

        $this->expectException(LokiResponseException::class);

        $job->handle();

        fwrite(STDERR, "    ✓ the exception is not swallowed without a queue job\n");
    }

    public function testSuccessfulPushIsUnchanged()
    {
        fwrite(STDERR, "  → Testing a successful push neither fails nor throws...\n");

        $client = Mockery::mock(LokiClient::class);
        $client->shouldReceive('push')->once()->andReturn(true);

        $job = $this->jobWithClient($client, [$this->anEntry()]);

        $queueJob = Mockery::mock(JobContract::class);
        $queueJob->shouldNotReceive('fail');
        $queueJob->shouldNotReceive('release');
        $job->job = $queueJob;

        $job->handle();

        $this->assertTrue(true, 'handle() returned without failing or releasing the job');

        fwrite(STDERR, "    ✓ a successful push is untouched\n");
    }

    public function testEmptyEntriesNeverTouchTheClient()
    {
        fwrite(STDERR, "  → Testing an empty batch is a no-op...\n");

        $client = Mockery::mock(LokiClient::class);
        $client->shouldNotReceive('push');

        $job = $this->jobWithClient($client, []);

        $job->handle();

        $this->assertTrue(true, 'handle() returned before building a payload');

        fwrite(STDERR, "    ✓ no push is attempted for an empty batch\n");
    }
}
