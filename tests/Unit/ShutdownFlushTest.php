<?php

namespace Omniboost\LaravelLoggingLoki\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Monolog\Level;
use Monolog\LogRecord;
use Omniboost\LaravelLoggingLoki\LokiServiceProvider;
use Omniboost\LaravelLoggingLoki\Services\LokiBufferedHandler;
use Orchestra\Testbench\TestCase;
use ReflectionClass;
use WeakReference;

/**
 * Unit Tests for Shutdown Flushing
 *
 * The handler registers a shutdown function that flushes the memory buffer of
 * every instance it created. That callback runs after the application has
 * terminated, so these tests cover what happens when the container it needs is
 * no longer usable, and make sure the instance registry does not retain handlers.
 */
class ShutdownFlushTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        fwrite(STDERR, "\n");

        $this->clearHandlerInstances();
    }

    protected function tearDown(): void
    {
        $this->clearHandlerInstances();

        parent::tearDown();
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
     * Test: flushing without an application does not throw
     *
     * Regression test: the shutdown function used to call into the container
     * after the application had been flushed, which threw "Target class [config]
     * does not exist" and wrote one error log line per handler instance.
     */
    public function testFlushMemoryBufferIsANoOpWithoutAnApplication()
    {
        fwrite(STDERR, "  → Testing flushMemoryBuffer() without an application...\n");

        $handler = $this->createHandler();
        $handler->write($this->createRecord('buffered before teardown'));

        $this->assertCount(1, $this->getMemoryBuffer($handler));

        $this->withoutApplication(function () use ($handler) {
            LokiBufferedHandler::flushAllMemoryBuffers();
        });

        // The buffer is kept: it could not be written anywhere, so dropping it
        // would lose logs that a later flush (with a live application) can still send
        $this->assertCount(1, $this->getMemoryBuffer($handler));

        fwrite(STDERR, "    ✓ Shutdown flush without an application is a no-op\n");
    }

    /**
     * Test: flush() without an application does not throw either
     */
    public function testFlushIsANoOpWithoutAnApplication()
    {
        fwrite(STDERR, "  → Testing flush() without an application...\n");

        $handler = $this->createHandler();

        $this->withoutApplication(function () use ($handler) {
            $handler->flush();
        });

        $this->assertTrue(true);

        fwrite(STDERR, "    ✓ Cache flush without an application is a no-op\n");
    }

    /**
     * Test: the instance registry does not keep handlers alive
     *
     * Every handler used to be stored by strong reference, so a process that
     * builds handlers repeatedly (queue workers, Octane, test suites) leaked all
     * of them and flushed all of them at shutdown.
     */
    public function testInstanceRegistryDoesNotRetainHandlers()
    {
        fwrite(STDERR, "  → Testing handler instances are weakly referenced...\n");

        $handler = $this->createHandler();
        $reference = WeakReference::create($handler);

        $this->assertNotEmpty($this->getHandlerInstances());

        unset($handler);

        $this->assertNull($reference->get(), 'The registry is keeping the handler alive');

        fwrite(STDERR, "    ✓ Handlers are released once the application drops them\n");
    }

    /**
     * Test: dead instances are pruned from the registry on flush
     */
    public function testDeadInstancesArePrunedFromTheRegistry()
    {
        fwrite(STDERR, "  → Testing dead instances are pruned...\n");

        $handler = $this->createHandler();
        unset($handler);

        $this->assertCount(1, $this->getHandlerInstances());

        LokiBufferedHandler::flushAllMemoryBuffers();

        $this->assertCount(0, $this->getHandlerInstances());

        fwrite(STDERR, "    ✓ Garbage collected handlers are dropped from the registry\n");
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
            datetime: new \Monolog\DateTimeImmutable(true),
            channel: 'test',
            level: Level::Info,
            message: $message,
            context: [],
            extra: []
        );
    }

    /**
     * @return array<int, mixed>
     */
    private function getMemoryBuffer(LokiBufferedHandler $handler): array
    {
        $property = (new ReflectionClass(LokiBufferedHandler::class))->getProperty('memoryBuffer');
        $property->setAccessible(true);

        return $property->getValue($handler);
    }

    /**
     * @return array<int, mixed>
     */
    private function getHandlerInstances(): array
    {
        $property = (new ReflectionClass(LokiBufferedHandler::class))->getProperty('handlerInstances');
        $property->setAccessible(true);

        return $property->getValue();
    }

    private function clearHandlerInstances(): void
    {
        $property = (new ReflectionClass(LokiBufferedHandler::class))->getProperty('handlerInstances');
        $property->setAccessible(true);
        $property->setValue(null, []);
    }
}
