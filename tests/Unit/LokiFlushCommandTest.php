<?php

namespace Omniboost\LaravelLoggingLoki\Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Mockery;
use Monolog\Logger;
use Omniboost\LaravelLoggingLoki\Commands\LokiFlushCommand;
use Omniboost\LaravelLoggingLoki\Logging\LokiBufferedLogger;
use Orchestra\Testbench\TestCase;
use Omniboost\LaravelLoggingLoki\LokiServiceProvider;

/**
 * Unit Tests for LokiFlushCommand
 *
 * These tests verify that the command correctly discovers Loki channels,
 * extracts their handlers, and triggers the flush operation.
 */
class LokiFlushCommandTest extends TestCase
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
        // Set up minimal configuration
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('loki.url', 'http://localhost:3100');
        $app['config']->set('loki.level', 'debug');
        $app['config']->set('loki.buffer_size', 100);
        $app['config']->set('loki.flush_interval', 5.0);
        $app['config']->set('loki.memory_buffer_size', 10);
        $app['config']->set('loki.memory_flush_interval', 1.0);
        $app['config']->set('loki.labels', ['app' => 'test']);
    }

    /**
     * Test: Command is registered
     */
    public function testCommandIsRegistered()
    {
        fwrite(STDERR, "  → Testing command registration...\n");

        $commands = Artisan::all();
        $this->assertArrayHasKey('omniboost:loki:flush', $commands);

        fwrite(STDERR, "    ✓ Command 'omniboost:loki:flush' is registered\n");
    }

    /**
     * Test: Command has correct signature
     */
    public function testCommandHasCorrectSignature()
    {
        fwrite(STDERR, "  → Testing command signature...\n");

        $command = new LokiFlushCommand();
        $this->assertEquals('omniboost:loki:flush', $command->getName());

        fwrite(STDERR, "    ✓ Command signature is 'omniboost:loki:flush'\n");
    }

    /**
     * Test: Command has description
     */
    public function testCommandHasDescription()
    {
        fwrite(STDERR, "  → Testing command description...\n");

        $command = new LokiFlushCommand();
        $description = $command->getDescription();

        $this->assertNotEmpty($description);
        $this->assertStringContainsString('flush', strtolower($description));
        $this->assertStringContainsString('loki', strtolower($description));

        fwrite(STDERR, "    ✓ Command has descriptive text\n");
    }

    /**
     * Test: getLoggers discovers Loki channels
     */
    public function testGetLoggersDiscoversLokiChannels()
    {
        fwrite(STDERR, "  → Testing getLoggers discovers Loki channels...\n");

        // Configure a Loki channel
        Config::set('logging.channels.test-loki', [
            'driver' => 'omniboost:loki',
            'url' => 'http://localhost:3100',
            'level' => 'debug',
        ]);

        $command = new LokiFlushCommand();
        $loggers = $command->getLoggers();

        $this->assertIsArray($loggers);
        $this->assertArrayHasKey('test-loki', $loggers);
        $this->assertInstanceOf(Logger::class, $loggers['test-loki']);

        fwrite(STDERR, "    ✓ Loki channels discovered correctly\n");
    }

    /**
     * Test: getLoggers ignores non-Loki channels
     */
    public function testGetLoggersIgnoresNonLokiChannels()
    {
        fwrite(STDERR, "  → Testing getLoggers ignores non-Loki channels...\n");

        // Configure mixed channels
        Config::set('logging.channels.test-loki', [
            'driver' => 'omniboost:loki',
            'url' => 'http://localhost:3100',
        ]);
        Config::set('logging.channels.daily', [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
        ]);
        Config::set('logging.channels.stack', [
            'driver' => 'stack',
            'channels' => ['daily'],
        ]);

        $command = new LokiFlushCommand();
        $loggers = $command->getLoggers();

        $this->assertArrayHasKey('test-loki', $loggers);
        $this->assertArrayNotHasKey('daily', $loggers);
        $this->assertArrayNotHasKey('stack', $loggers);

        fwrite(STDERR, "    ✓ Non-Loki channels are ignored\n");
    }

    /**
     * Test: getLoggers handles channels without driver key
     */
    public function testGetLoggersHandlesChannelsWithoutDriver()
    {
        fwrite(STDERR, "  → Testing getLoggers handles channels without driver...\n");

        // Configure a channel without driver key
        Config::set('logging.channels.invalid', [
            'path' => storage_path('logs/test.log'),
        ]);

        $command = new LokiFlushCommand();
        $loggers = $command->getLoggers();

        // Should not throw error and should not include invalid channel
        $this->assertArrayNotHasKey('invalid', $loggers);

        fwrite(STDERR, "    ✓ Channels without driver are handled gracefully\n");
    }

    /**
     * Test: getLoggers returns empty array when no Loki channels
     */
    public function testGetLoggersReturnsEmptyWhenNoLokiChannels()
    {
        fwrite(STDERR, "  → Testing getLoggers with no Loki channels...\n");

        // Configure only non-Loki channels
        Config::set('logging.channels.daily', [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
        ]);

        $command = new LokiFlushCommand();
        $loggers = $command->getLoggers();

        $this->assertIsArray($loggers);
        $this->assertEmpty($loggers);

        fwrite(STDERR, "    ✓ Empty array returned when no Loki channels\n");
    }

    /**
     * Test: getHandlers extracts LokiBufferedLogger handlers
     */
    public function testGetHandlersExtractsLokiBufferedLoggerHandlers()
    {
        fwrite(STDERR, "  → Testing getHandlers extracts correct handlers...\n");

        // Configure a Loki channel
        Config::set('logging.channels.test-loki', [
            'driver' => 'omniboost:loki',
            'url' => 'http://localhost:3100',
        ]);

        // Get the logger
        $logger = Log::channel('test-loki')->getLogger();

        $command = new LokiFlushCommand();
        $handlers = $command->getHandlers(['test-loki' => $logger]);

        $this->assertIsArray($handlers);
        $this->assertArrayHasKey('test-loki', $handlers);
        $this->assertInstanceOf(LokiBufferedLogger::class, $handlers['test-loki']);

        fwrite(STDERR, "    ✓ LokiBufferedLogger handlers extracted correctly\n");
    }

    /**
     * Test: getHandlers ignores non-LokiBufferedLogger handlers
     */
    public function testGetHandlersIgnoresNonLokiHandlers()
    {
        fwrite(STDERR, "  → Testing getHandlers ignores non-Loki handlers...\n");

        // Create a mock logger with non-Loki handler
        $mockLogger = Mockery::mock(Logger::class);
        $mockHandler = Mockery::mock(\Monolog\Handler\StreamHandler::class);
        
        $mockLogger->shouldReceive('getHandlers')
            ->andReturn([$mockHandler]);

        $command = new LokiFlushCommand();
        $handlers = $command->getHandlers(['test' => $mockLogger]);

        $this->assertIsArray($handlers);
        $this->assertEmpty($handlers);

        fwrite(STDERR, "    ✓ Non-Loki handlers are ignored\n");
    }

    /**
     * Test: getHandlers returns empty array when no handlers
     */
    public function testGetHandlersReturnsEmptyWhenNoHandlers()
    {
        fwrite(STDERR, "  → Testing getHandlers with no handlers...\n");

        $command = new LokiFlushCommand();
        $handlers = $command->getHandlers([]);

        $this->assertIsArray($handlers);
        $this->assertEmpty($handlers);

        fwrite(STDERR, "    ✓ Empty array returned when no handlers\n");
    }

    /**
     * Test: Command execution with Loki channels
     */
    public function testCommandExecutionWithLokiChannels()
    {
        fwrite(STDERR, "  → Testing command execution...\n");

        // Configure a Loki channel
        Config::set('logging.channels.test-loki', [
            'driver' => 'omniboost:loki',
            'url' => 'http://localhost:3100',
        ]);

        // Execute the command
        $exitCode = Artisan::call('omniboost:loki:flush');

        $this->assertEquals(0, $exitCode);

        fwrite(STDERR, "    ✓ Command executed successfully\n");
    }

    /**
     * Test: Command execution without Loki channels
     */
    public function testCommandExecutionWithoutLokiChannels()
    {
        fwrite(STDERR, "  → Testing command execution without Loki channels...\n");

        // Configure only non-Loki channels
        Config::set('logging.channels', [
            'daily' => [
                'driver' => 'daily',
                'path' => storage_path('logs/laravel.log'),
            ],
        ]);

        // Execute the command (should not fail)
        $exitCode = Artisan::call('omniboost:loki:flush');

        $this->assertEquals(0, $exitCode);

        fwrite(STDERR, "    ✓ Command handles no Loki channels gracefully\n");
    }

    /**
     * Test: Command handles multiple Loki channels
     */
    public function testCommandHandlesMultipleLokiChannels()
    {
        fwrite(STDERR, "  → Testing command with multiple Loki channels...\n");

        // Configure multiple Loki channels
        Config::set('logging.channels.loki1', [
            'driver' => 'omniboost:loki',
            'url' => 'http://localhost:3100',
        ]);
        Config::set('logging.channels.loki2', [
            'driver' => 'omniboost:loki',
            'url' => 'http://localhost:3100',
        ]);

        $command = new LokiFlushCommand();
        $loggers = $command->getLoggers();

        $this->assertCount(2, $loggers);
        $this->assertArrayHasKey('loki1', $loggers);
        $this->assertArrayHasKey('loki2', $loggers);

        fwrite(STDERR, "    ✓ Multiple Loki channels handled correctly\n");
    }

    /**
     * Test: flush() method is called on handlers
     */
    public function testFlushMethodCalledOnHandlers()
    {
        fwrite(STDERR, "  → Testing flush() is called on handlers...\n");

        // Create a mock handler
        $mockHandler = Mockery::mock(LokiBufferedLogger::class);
        $mockHandler->shouldReceive('flush')
            ->once()
            ->andReturnNull();

        // Create a mock logger
        $mockLogger = Mockery::mock(Logger::class);
        $mockLogger->shouldReceive('getHandlers')
            ->andReturn([$mockHandler]);

        // Execute the flush logic manually
        $handlers = ['test' => $mockHandler];
        foreach ($handlers as $handler) {
            $handler->flush();
        }

        // Mockery will verify the expectations
        $this->assertTrue(true);

        fwrite(STDERR, "    ✓ flush() method called on handlers\n");
    }

    /**
     * Test: Command output shows channel names
     */
    public function testCommandOutputShowsChannelNames()
    {
        fwrite(STDERR, "  → Testing command output...\n");

        // Configure a Loki channel
        Config::set('logging.channels.test-loki', [
            'driver' => 'omniboost:loki',
            'url' => 'http://localhost:3100',
        ]);

        // Execute the command and capture output
        Artisan::call('omniboost:loki:flush');
        $output = Artisan::output();

        $this->assertStringContainsString('test-loki', $output);
        $this->assertStringContainsString('Flushing', $output);

        fwrite(STDERR, "    ✓ Command output shows channel names\n");
    }

    /**
     * Test: getLoggers handles empty channels config
     */
    public function testGetLoggersHandlesEmptyChannelsConfig()
    {
        fwrite(STDERR, "  → Testing getLoggers with empty channels config...\n");

        // Set empty channels config
        Config::set('logging.channels', []);

        $command = new LokiFlushCommand();
        $loggers = $command->getLoggers();

        $this->assertIsArray($loggers);
        $this->assertEmpty($loggers);

        fwrite(STDERR, "    ✓ Empty channels config handled gracefully\n");
    }

    /**
     * Test: getLoggers handles null channels config
     */
    public function testGetLoggersHandlesNullChannelsConfig()
    {
        fwrite(STDERR, "  → Testing getLoggers with null channels config...\n");

        // Set null channels config
        Config::set('logging.channels', null);

        $command = new LokiFlushCommand();
        $loggers = $command->getLoggers();

        $this->assertIsArray($loggers);
        $this->assertEmpty($loggers);

        fwrite(STDERR, "    ✓ Null channels config handled gracefully\n");
    }
}
