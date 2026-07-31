<?php

namespace Omniboost\LaravelLoggingLoki\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use Monolog\DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use Omniboost\LaravelLoggingLoki\Jobs\SendLogsToLoki;
use Omniboost\LaravelLoggingLoki\LokiServiceProvider;
use Omniboost\LaravelLoggingLoki\Services\LokiBufferedHandler;
use Omniboost\LaravelLoggingLoki\Support\ShutdownFlusher;
use Orchestra\Testbench\TestCase;
use WeakReference;

/**
 * Unit Tests for Shutdown Flushing
 *
 * ShutdownFlusher flushes the memory buffer of every live handler when the
 * process ends. That callback runs after the application has terminated, so
 * these tests cover what happens when the container it needs is no longer
 * usable, and make sure the instance registry does not retain handlers.
 */
class ShutdownFlushTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        fwrite(STDERR, "\n");
    }

    /**
     * Get package providers
     */
    protected function getPackageProviders($app): array
    {
        return [LokiServiceProvider::class];
    }

    /**
     * Configure environment for testing
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('loki.url', 'http://localhost:3100');
    }

    /**
     * Test: flushing without an application does not throw and keeps the buffer
     *
     * Regression test: the shutdown function used to call into the container
     * after the application had been flushed, which threw "Target class [config]
     * does not exist" and wrote one error log line per handler instance.
     */
    public function testShutdownFlushWithoutAnApplicationKeepsTheBuffer()
    {
        fwrite(STDERR, "  → Testing shutdown flush without an application...\n");

        Queue::fake();

        $handler = $this->createHandler();
        $handler->write($this->createRecord('buffered before teardown'));

        $this->withoutApplication(static function () {
            ShutdownFlusher::flushAll();
        });

        Queue::assertNothingPushed();

        // The buffer was kept rather than dropped, so it can still be flushed
        // once an application is available again
        $handler->flushMemoryBuffer();

        Queue::assertPushed(SendLogsToLoki::class);

        fwrite(STDERR, "    ✓ Shutdown flush without an application is a no-op\n");
    }

    /**
     * Test: flush() without an application does not throw either
     */
    public function testCacheFlushWithoutAnApplicationIsANoOp()
    {
        fwrite(STDERR, "  → Testing flush() without an application...\n");

        Queue::fake();

        $handler = $this->createHandler();

        $this->withoutApplication(static function () use ($handler) {
            $handler->flush();
        });

        Queue::assertNothingPushed();

        fwrite(STDERR, "    ✓ Cache flush without an application is a no-op\n");
    }

    /**
     * Test: the instance registry does not keep handlers alive
     *
     * Every handler used to be stored by strong reference, so a process that
     * builds handlers repeatedly (queue workers, Octane, test suites) leaked all
     * of them and flushed all of them at shutdown.
     */
    public function testRegistryDoesNotRetainHandlers()
    {
        fwrite(STDERR, "  → Testing handler instances are weakly referenced...\n");

        $handler = $this->createHandler();
        $reference = WeakReference::create($handler);

        unset($handler);

        $this->assertNull($reference->get(), 'The registry is keeping the handler alive');

        // Collected handlers are pruned instead of tripping over a dead reference
        ShutdownFlusher::flushAll();

        $this->assertNull($reference->get());

        fwrite(STDERR, "    ✓ Handlers are released once the application drops them\n");
    }

    /**
     * Run a callback with no application bound, as at shutdown
     */
    private function withoutApplication(callable $callback): void
    {
        $app = Container::getInstance();
        $facadeApp = Facade::getFacadeApplication();

        Container::setInstance(new Container());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        try {
            $callback();
        } finally {
            Container::setInstance($app);
            Facade::setFacadeApplication($facadeApp);
        }
    }

    private function createHandler(): LokiBufferedHandler
    {
        return new LokiBufferedHandler(
            url: 'http://localhost:3100',
            bufferSize: 100,
            flushInterval: 5.0,
            defaultLabels: ['app' => 'test'],
            memoryBufferSize: 10,
            memoryFlushInterval: 60.0
        );
    }

    private function createRecord(string $message): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable(true),
            channel: 'test',
            level: Level::Info,
            message: $message,
            context: [],
            extra: []
        );
    }
}
