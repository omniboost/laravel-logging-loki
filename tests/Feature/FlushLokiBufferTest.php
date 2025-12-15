<?php

namespace Omniboost\LaravelLoggingLoki\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Omniboost\LaravelLoggingLoki\Jobs\FlushLokiBuffer;
use Omniboost\LaravelLoggingLoki\Jobs\SendLogsToLoki;
use Orchestra\Testbench\TestCase;

/**
 * Feature Tests for FlushLokiBuffer Job
 *
 * These tests verify that the FlushLokiBuffer job correctly flushes
 * the buffer and uses distributed locking to prevent concurrent execution.
 */
class FlushLokiBufferTest extends TestCase
{
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

    /**
     * Define environment setup for tests
     */
    protected function defineEnvironment($app)
    {
        // Use array cache driver by default
        $app['config']->set('cache.default', 'array');
        
        // Set loki configuration
        $app['config']->set('loki.url', 'http://localhost:3100');
        $app['config']->set('loki.username', null);
        $app['config']->set('loki.password', null);
        $app['config']->set('loki.queue', 'default');
        $app['config']->set('loki.debug', false);
    }

    /**
     * Get package providers
     */
    protected function getPackageProviders($app)
    {
        return [];
    }

    /**
     * Test: Job skips execution when flush lock is held
     *
     * This test verifies that if another process is flushing,
     * the job will skip execution gracefully.
     */
    public function testJobSkipsWhenFlushLockIsHeld()
    {
        fwrite(STDERR, "  → Testing job skips when flush lock is held...\n");

        config(['cache.default' => 'array']);

        // Mock flush lock that cannot be acquired
        Cache::shouldReceive('lock')
            ->with('loki:log:flush:lock', 30)
            ->once()
            ->andReturnUsing(function () {
                $lock = Mockery::mock();
                $lock->shouldReceive('get')->andReturn(false);
                return $lock;
            });

        $job = new FlushLokiBuffer();
        $job->handle();

        // Job should complete without errors even when lock cannot be acquired
        $this->assertTrue(true);

        fwrite(STDERR, "    ✓ Job correctly skipped when flush lock is held\n");
    }

    /**
     * Test: Job flushes buffer with non-Redis cache driver
     *
     * This test verifies that the job correctly flushes the buffer
     * using lock-based approach for non-Redis cache drivers.
     */
    public function testJobFlushesBufferWithNonRedisDriver()
    {
        fwrite(STDERR, "  → Testing job flushes buffer with non-Redis driver...\n");

        config(['cache.default' => 'array']);

        // Expect SendLogsToLoki job to be dispatched - fake BEFORE mocking
        Queue::fake();

        $testBuffer = [
            ['stream' => ['level' => 'info'], 'entry' => 'Test log 1', 'timestamp' => '1000000000', 'structuredMetadata' => []],
            ['stream' => ['level' => 'info'], 'entry' => 'Test log 2', 'timestamp' => '2000000000', 'structuredMetadata' => []],
        ];

        // Mock flush lock
        Cache::shouldReceive('lock')
            ->with('loki:log:flush:lock', 30)
            ->once()
            ->andReturnUsing(function () {
                $lock = Mockery::mock();
                $lock->shouldReceive('get')->andReturn(true);
                $lock->shouldReceive('release')->andReturn(true);
                return $lock;
            });

        // Mock buffer lock
        Cache::shouldReceive('lock')
            ->with('loki:log:buffer:lock', 5)
            ->once()
            ->andReturnUsing(function () {
                $lock = Mockery::mock();
                $lock->shouldReceive('get')->andReturn(true);
                $lock->shouldReceive('release')->andReturn(true);
                return $lock;
            });

        // Mock buffer operations
        Cache::shouldReceive('get')
            ->with('loki:log:buffer', [])
            ->once()
            ->andReturn($testBuffer);

        Cache::shouldReceive('forget')
            ->with('loki:log:buffer')
            ->once()
            ->andReturn(true);

        Cache::shouldReceive('put')
            ->with('loki:log:flush:time', Mockery::type('int'))
            ->once()
            ->andReturn(true);

        $job = new FlushLokiBuffer();
        $job->handle();

        // Verify SendLogsToLoki job was dispatched
        Queue::assertPushed(SendLogsToLoki::class);

        fwrite(STDERR, "    ✓ Job successfully flushed buffer with non-Redis driver\n");
    }

    /**
     * Test: Job handles empty buffer gracefully
     *
     * This test verifies that the job handles an empty buffer without errors.
     */
    public function testJobHandlesEmptyBuffer()
    {
        fwrite(STDERR, "  → Testing job handles empty buffer...\n");

        config(['cache.default' => 'array']);

        // Mock flush lock
        Cache::shouldReceive('lock')
            ->with('loki:log:flush:lock', 30)
            ->once()
            ->andReturnUsing(function () {
                $lock = Mockery::mock();
                $lock->shouldReceive('get')->andReturn(true);
                $lock->shouldReceive('release')->andReturn(true);
                return $lock;
            });

        // Mock buffer lock
        Cache::shouldReceive('lock')
            ->with('loki:log:buffer:lock', 5)
            ->once()
            ->andReturnUsing(function () {
                $lock = Mockery::mock();
                $lock->shouldReceive('get')->andReturn(true);
                $lock->shouldReceive('release')->andReturn(true);
                return $lock;
            });

        // Mock buffer operations - empty buffer
        Cache::shouldReceive('get')
            ->with('loki:log:buffer', [])
            ->once()
            ->andReturn([]);

        Cache::shouldReceive('put')
            ->with('loki:log:flush:time', Mockery::type('int'))
            ->once()
            ->andReturn(true);

        // Expect NO SendLogsToLoki job to be dispatched
        Queue::fake();

        $job = new FlushLokiBuffer();
        $job->handle();

        // Verify SendLogsToLoki job was NOT dispatched
        Queue::assertNotPushed(SendLogsToLoki::class);

        fwrite(STDERR, "    ✓ Job correctly handled empty buffer\n");
    }

    /**
     * Test: Job handles buffer lock timeout
     *
     * This test verifies that if the buffer lock cannot be acquired,
     * the job handles it gracefully.
     */
    public function testJobHandlesBufferLockTimeout()
    {
        fwrite(STDERR, "  → Testing job handles buffer lock timeout...\n");

        config(['cache.default' => 'array', 'loki.debug' => false]);

        // Mock flush lock
        Cache::shouldReceive('lock')
            ->with('loki:log:flush:lock', 30)
            ->once()
            ->andReturnUsing(function () {
                $lock = Mockery::mock();
                $lock->shouldReceive('get')->andReturn(true);
                $lock->shouldReceive('release')->andReturn(true);
                return $lock;
            });

        // Mock buffer lock that cannot be acquired
        Cache::shouldReceive('lock')
            ->with('loki:log:buffer:lock', 5)
            ->once()
            ->andReturnUsing(function () {
                $lock = Mockery::mock();
                $lock->shouldReceive('get')->andReturn(false);
                $lock->shouldReceive('release')->andReturn(true);
                return $lock;
            });

        Cache::shouldReceive('put')
            ->with('loki:log:flush:time', Mockery::type('int'))
            ->once()
            ->andReturn(true);

        // Expect NO SendLogsToLoki job to be dispatched
        Queue::fake();

        $job = new FlushLokiBuffer();
        $job->handle();

        // Verify SendLogsToLoki job was NOT dispatched
        Queue::assertNotPushed(SendLogsToLoki::class);

        fwrite(STDERR, "    ✓ Job correctly handled buffer lock timeout\n");
    }

    /**
     * Test: Job flushes buffer with Redis driver
     *
     * This test verifies that the job correctly flushes the buffer
     * using Redis atomic operations.
     */
    public function testJobFlushesBufferWithRedisDriver()
    {
        fwrite(STDERR, "  → Testing job flushes buffer with Redis driver...\n");

        config(['cache.default' => 'redis']);

        // Expect SendLogsToLoki job to be dispatched - fake BEFORE mocking
        Queue::fake();

        $testBuffer = [
            json_encode(['stream' => ['level' => 'info'], 'entry' => 'Test log 1', 'timestamp' => '1000000000', 'structuredMetadata' => []]),
            json_encode(['stream' => ['level' => 'info'], 'entry' => 'Test log 2', 'timestamp' => '2000000000', 'structuredMetadata' => []]),
        ];

        // Mock flush lock
        Cache::shouldReceive('lock')
            ->with('loki:log:flush:lock', 30)
            ->once()
            ->andReturnUsing(function () {
                $lock = Mockery::mock();
                $lock->shouldReceive('get')->andReturn(true);
                $lock->shouldReceive('release')->andReturn(true);
                return $lock;
            });

        // Mock Redis operations
        Redis::shouldReceive('llen')
            ->with('loki:log:buffer')
            ->once()
            ->andReturn(2);

        Redis::shouldReceive('pipeline')
            ->once()
            ->andReturnUsing(function ($callback) use ($testBuffer) {
                // Simulate pipeline execution
                return [$testBuffer, true];
            });

        Cache::shouldReceive('put')
            ->with('loki:log:flush:time', Mockery::type('int'))
            ->once()
            ->andReturn(true);

        $job = new FlushLokiBuffer();
        $job->handle();

        // Verify SendLogsToLoki job was dispatched
        Queue::assertPushed(SendLogsToLoki::class);

        fwrite(STDERR, "    ✓ Job successfully flushed buffer with Redis driver\n");
    }

    /**
     * Test: Job handles Redis errors gracefully
     *
     * This test verifies that Redis errors are handled without crashing.
     */
    public function testJobHandlesRedisErrors()
    {
        fwrite(STDERR, "  → Testing job handles Redis errors...\n");

        config(['cache.default' => 'redis', 'loki.debug' => false]);

        // Mock flush lock
        Cache::shouldReceive('lock')
            ->with('loki:log:flush:lock', 30)
            ->once()
            ->andReturnUsing(function () {
                $lock = Mockery::mock();
                $lock->shouldReceive('get')->andReturn(true);
                $lock->shouldReceive('release')->andReturn(true);
                return $lock;
            });

        // Mock Redis operations that throw exception
        Redis::shouldReceive('llen')
            ->with('loki:log:buffer')
            ->once()
            ->andReturn(5);

        Redis::shouldReceive('pipeline')
            ->once()
            ->andThrow(new \RedisException('Connection failed'));

        Cache::shouldReceive('put')
            ->with('loki:log:flush:time', Mockery::type('int'))
            ->once()
            ->andReturn(true);

        // Expect NO SendLogsToLoki job to be dispatched
        Queue::fake();

        $job = new FlushLokiBuffer();
        $job->handle();

        // Verify SendLogsToLoki job was NOT dispatched
        Queue::assertNotPushed(SendLogsToLoki::class);

        fwrite(STDERR, "    ✓ Job correctly handled Redis errors\n");
    }

    /**
     * Test: Job can be dispatched to queue
     *
     * This test verifies that the job can be dispatched to the queue.
     */
    public function testJobCanBeDispatchedToQueue()
    {
        fwrite(STDERR, "  → Testing job can be dispatched to queue...\n");

        Queue::fake();

        FlushLokiBuffer::dispatch();

        Queue::assertPushed(FlushLokiBuffer::class);

        fwrite(STDERR, "    ✓ Job successfully dispatched to queue\n");
    }
}
