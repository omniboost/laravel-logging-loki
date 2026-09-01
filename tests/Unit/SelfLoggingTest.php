<?php

namespace Omniboost\LaravelLoggingLoki\Tests\Unit;

use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Logger;
use Omniboost\LaravelLoggingLoki\Jobs\SendLogsToLoki;
use Omniboost\LaravelLoggingLoki\Logging\LokiBufferedLogger;
use Omniboost\LaravelLoggingLoki\LokiServiceProvider;
use Omniboost\LaravelLoggingLoki\Services\LokiBufferedHandler;
use Omniboost\LaravelLoggingLoki\Support\SelfLog;
use Orchestra\Testbench\TestCase;
use ReflectionClass;

/**
 * Tests for the package's out-of-band diagnostics sink.
 *
 * The rule these cover: nothing this package reports about itself may travel
 * through the Loki channel. A Loki failure written to a channel that stacks the
 * Loki driver is buffered for Loki, dispatches another push job, fails again and
 * reports that failure the same way - so every outage feeds itself.
 */
class SelfLoggingTest extends TestCase
{
    private const LOKI_URL = 'http://localhost:3100';

    private string $errorLog;
    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        parent::setUp();

        // error_log() is the guaranteed sink, so point it at a file we can read.
        $this->errorLog = tempnam(sys_get_temp_dir(), 'loki-self-log-');
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->errorLog);

        SelfLog::reset();
        $this->resetRecursionGuard();
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        @unlink($this->errorLog);

        SelfLog::reset();
        $this->resetRecursionGuard();

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [LokiServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('loki.url', self::LOKI_URL);
        $app['config']->set('loki.queue', 'sync');
        $app['config']->set('loki.debug', false);

        // The setup this all exists for: the default channel stacks Loki.
        $app['config']->set('logging.channels.omniboost:loki', [
            'driver' => 'omniboost:loki',
            'url' => self::LOKI_URL,
        ]);
        $app['config']->set('logging.channels.default', [
            'driver' => 'stack',
            'channels' => 'stderr,omniboost:loki',
        ]);
    }

    private function errorLogContents(): string
    {
        return (string) file_get_contents($this->errorLog);
    }

    private function resetRecursionGuard(): void
    {
        $property = (new ReflectionClass(LokiBufferedHandler::class))->getProperty('writing');
        $property->setAccessible(true);
        $property->setValue(null, false);
    }

    private function handler(): LokiBufferedHandler
    {
        return new LokiBufferedHandler(url: self::LOKI_URL);
    }

    private function record(string $message = 'a message'): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Error,
            message: $message,
            context: [],
            extra: []
        );
    }

    /**
     * A record prepareLogEntry() cannot turn into a LokiLogEntry
     *
     * LokiLogEntry::$entry is typed string and takes the record's formatted
     * output, so a formatter that produced something else throws a TypeError from
     * inside the recursion guard.
     */
    private function unwritableRecord(): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Error,
            message: 'a message',
            context: [],
            extra: [],
            formatted: ['not a string']
        );
    }

    public function test_no_configured_channel_means_the_error_log(): void
    {
        config()->set('loki.debug_channel', null);

        $this->assertNull(SelfLog::channel());

        SelfLog::error('something broke');

        $this->assertStringContainsString('[loki:error] something broke', $this->errorLogContents());
    }

    public function test_a_loop_safe_channel_is_used(): void
    {
        config()->set('loki.debug_channel', 'stderr');

        $this->assertSame('stderr', SelfLog::channel());

        $log = Log::spy();
        SelfLog::error('something broke');

        $log->shouldHaveReceived('channel')->with('stderr');
    }

    public function test_a_channel_that_is_the_loki_driver_is_refused(): void
    {
        config()->set('loki.debug_channel', 'omniboost:loki');

        $this->assertNull(SelfLog::channel());
        $this->assertStringContainsString('resolves to the Loki driver', $this->errorLogContents());
    }

    public function test_a_stack_that_reaches_loki_is_refused(): void
    {
        // The datahub shape: a named stack whose channels list includes Loki.
        config()->set('loki.debug_channel', 'default');

        $this->assertNull(SelfLog::channel());
        $this->assertStringContainsString('resolves to the Loki driver', $this->errorLogContents());
    }

    public function test_a_nested_stack_that_reaches_loki_is_refused(): void
    {
        config()->set('logging.channels.outer', [
            'driver' => 'stack',
            'channels' => ['daily', 'default'],
        ]);
        config()->set('loki.debug_channel', 'outer');

        $this->assertNull(SelfLog::channel());
        $this->assertStringContainsString('resolves to the Loki driver', $this->errorLogContents());
    }

    public function test_a_stack_that_contains_itself_is_refused_without_hanging(): void
    {
        config()->set('logging.channels.recursive', [
            'driver' => 'stack',
            'channels' => ['recursive', 'daily'],
        ]);
        config()->set('loki.debug_channel', 'recursive');

        // The cycle cannot be walked to its leaves, so it cannot be shown to be
        // Loki-free - and Laravel would recurse into it until the process died.
        $this->assertNull(SelfLog::channel());
        $this->assertStringContainsString('resolves to the Loki driver', $this->errorLogContents());
    }

    public function test_a_monolog_driver_wrapping_the_loki_handler_is_refused(): void
    {
        // Laravel's own 'monolog' driver pointed straight at our handler reaches
        // Loki without ever naming the omniboost:loki driver.
        config()->set('logging.channels.wrapped', [
            'driver' => 'monolog',
            'handler' => LokiBufferedLogger::class,
        ]);
        config()->set('loki.debug_channel', 'wrapped');

        $this->assertNull(SelfLog::channel());
        $this->assertStringContainsString('resolves to the Loki driver', $this->errorLogContents());
    }

    public function test_a_custom_driver_naming_the_loki_handler_in_its_options_is_refused(): void
    {
        config()->set('logging.channels.wrapped', [
            'driver' => 'custom',
            'via' => 'App\\Logging\\SomeFactory',
            'with' => ['handler' => LokiBufferedLogger::class],
        ]);
        config()->set('loki.debug_channel', 'wrapped');

        $this->assertNull(SelfLog::channel());
        $this->assertStringContainsString('resolves to the Loki driver', $this->errorLogContents());
    }

    public function test_a_stack_reaching_a_wrapped_loki_handler_is_refused(): void
    {
        config()->set('logging.channels.wrapped', [
            'driver' => 'monolog',
            'handler' => LokiBufferedLogger::class,
        ]);
        config()->set('logging.channels.wrapping-stack', [
            'driver' => 'stack',
            'channels' => ['stderr', 'wrapped'],
        ]);
        config()->set('loki.debug_channel', 'wrapping-stack');

        $this->assertNull(SelfLog::channel());
        $this->assertStringContainsString('resolves to the Loki driver', $this->errorLogContents());
    }

    public function test_a_monolog_driver_wrapping_an_unrelated_handler_is_allowed(): void
    {
        config()->set('logging.channels.wrapped', [
            'driver' => 'monolog',
            'handler' => \Monolog\Handler\StreamHandler::class,
            'with' => ['stream' => 'php://stderr'],
        ]);
        config()->set('loki.debug_channel', 'wrapped');

        $this->assertSame('wrapped', SelfLog::channel());
    }

    public function test_an_undefined_channel_is_refused(): void
    {
        config()->set('loki.debug_channel', 'does-not-exist');

        $this->assertNull(SelfLog::channel());
        $this->assertStringContainsString('is not a defined logging channel', $this->errorLogContents());
    }

    public function test_a_broken_diagnostics_channel_falls_back_to_the_error_log(): void
    {
        config()->set('loki.debug_channel', 'stderr');

        Log::shouldReceive('channel')->andThrow(new \RuntimeException('channel is broken'));

        SelfLog::error('something broke');

        $contents = $this->errorLogContents();
        $this->assertStringContainsString('[loki:error] something broke', $contents);
        $this->assertStringContainsString('could not be written to', $contents);
    }

    public function test_a_record_emitted_while_writing_is_dropped(): void
    {
        config()->set('loki.debug_channel', null);

        $handler = $this->handler();

        // Stand in for a record produced by something the write path called.
        $property = (new ReflectionClass(LokiBufferedHandler::class))->getProperty('writing');
        $property->setAccessible(true);
        $property->setValue(null, true);

        $handler->write($this->record('a record emitted from inside the write path'));

        $buffer = (new ReflectionClass(LokiBufferedHandler::class))->getProperty('memoryBuffer');
        $buffer->setAccessible(true);

        $this->assertSame([], $buffer->getValue($handler), 'the nested record must not be buffered');
        $this->assertStringContainsString('recursion guard', $this->errorLogContents());
    }

    public function test_the_guard_is_released_after_a_normal_write(): void
    {
        $handler = $this->handler();
        $handler->write($this->record());

        $property = (new ReflectionClass(LokiBufferedHandler::class))->getProperty('writing');
        $property->setAccessible(true);

        $this->assertFalse($property->getValue(), 'the guard must not stay closed after a successful write');
        $this->assertStringNotContainsString('recursion guard', $this->errorLogContents());
    }

    public function test_the_guard_is_released_after_a_failed_write(): void
    {
        $handler = $this->handler();

        $property = (new ReflectionClass(LokiBufferedHandler::class))->getProperty('writing');
        $property->setAccessible(true);

        // prepareLogEntry() runs inside the guard and passes the formatted record
        // to LokiLogEntry, which is typed string - so a non-string formatter
        // output throws from inside the guard. Whatever the cause, a write that
        // throws must leave the guard open for the next record.
        $this->expectException(\TypeError::class);

        try {
            $handler->write($this->unwritableRecord());
        } finally {
            $this->assertFalse($property->getValue(), 'a throwing write must not leave the guard closed');
        }
    }

    public function test_the_guard_reopens_for_the_record_after_a_failed_write(): void
    {
        $handler = $this->handler();

        try {
            $handler->write($this->unwritableRecord());
        } catch (\TypeError) {
            // The point of the test is what happens next.
        }

        $handler->write($this->record('the next record'));

        $buffer = (new ReflectionClass(LokiBufferedHandler::class))->getProperty('memoryBuffer');
        $buffer->setAccessible(true);

        $this->assertCount(1, $buffer->getValue($handler), 'the next record must still be buffered');
        $this->assertStringNotContainsString('recursion guard', $this->errorLogContents());
    }

    public function test_a_throwing_handler_does_not_reach_the_caller(): void
    {
        config()->set('loki.debug_channel', null);
        config()->set('logging.channels.throwing-loki', [
            'driver' => 'omniboost:loki',
            'url' => self::LOKI_URL,
        ]);

        /** @var Logger $logger */
        $logger = Log::channel('throwing-loki')->getLogger();
        $logger->setHandlers([new ThrowingHandler()]);

        // Would otherwise surface from the line that called Log::error().
        $logger->error('an application log line');

        $contents = $this->errorLogContents();
        $this->assertStringContainsString('handler failed; the record was dropped', $contents);
        $this->assertStringContainsString('the handler cannot write', $contents);
    }

    public function test_a_failed_push_job_reports_without_debug_enabled(): void
    {
        config()->set('loki.debug', false);
        config()->set('loki.debug_channel', null);

        $job = new SendLogsToLoki([], self::LOKI_URL);
        $job->failed(new \RuntimeException('Loki is unreachable'));

        $contents = $this->errorLogContents();
        $this->assertStringContainsString('push job failed', $contents);
        $this->assertStringContainsString('Loki is unreachable', $contents);
        $this->assertStringContainsString('"total_entries":0', $contents);
    }

    public function test_a_failed_push_job_never_throws_out_of_failed(): void
    {
        // failed() runs in the worker, which reports anything escaping it through
        // Laravel's exception handler - the default channel, which is Loki here.
        // So even a broken diagnostics channel must not produce an exception.
        config()->set('loki.debug_channel', 'stderr');

        Log::shouldReceive('channel')->andThrow(new \RuntimeException('channel is broken'));

        $job = new SendLogsToLoki([], self::LOKI_URL);
        $job->failed(new \RuntimeException('Loki is unreachable'));

        $contents = $this->errorLogContents();
        $this->assertStringContainsString('push job failed', $contents);
        $this->assertStringContainsString('could not be written to', $contents);
    }

    public function test_debug_is_off_in_the_shipped_config(): void
    {
        // Failures are reported regardless; debug only adds the per-push detail,
        // and shipping that on writes every batch's streams to the error log.
        $shipped = require __DIR__ . '/../../config/loki.php';

        $this->assertFalse($shipped['debug']);
        $this->assertNull($shipped['debug_channel']);
    }
}

/**
 * A handler that cannot write, standing in for Loki being unreachable.
 */
class ThrowingHandler extends AbstractProcessingHandler
{
    protected function write(LogRecord $record): void
    {
        throw new \RuntimeException('the handler cannot write');
    }
}
