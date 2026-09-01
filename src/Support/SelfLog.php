<?php

namespace Omniboost\LaravelLoggingLoki\Support;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Log;
use Omniboost\LaravelLoggingLoki\Logging\LokiBufferedLogger;
use Throwable;

/**
 * The package's own diagnostics sink.
 *
 * A log destination must never report its own failures through itself. When the
 * Loki channel is part of the application's default channel - the common setup,
 * a 'stack' channel in config/logging.php listing both stderr and
 * omniboost:loki - a Loki failure written with Log::error() is buffered for
 * Loki, dispatches another push job, fails again, and reports that failure the
 * same way. Nothing recurses infinitely because the push is queued, but every
 * outage amplifies itself until the buffer or the queue gives out.
 *
 * Every logging library keeps a separate, out-of-band sink for exactly this
 * reason - log4j2's StatusLogger, logback's StatusManager, Serilog's SelfLog,
 * Python's Handler.handleError(), Fluentd's reserved @FLUENT_LOG label - and all
 * of them default to the process's standard error rather than the configured
 * pipeline. This is that sink.
 *
 * error_log() is the guaranteed destination: it needs no container, no config
 * and no cache, so it works in a destructor, in a shutdown function and before
 * the application has booted. In a container it lands on stderr, which is where
 * the log driver is already looking.
 *
 * A loop-safe Laravel channel can be configured on top of that with
 * loki.debug_channel (LOKI_DEBUG_CHANNEL), which is verified not to resolve back
 * into the Loki driver before anything is written to it.
 */
final class SelfLog
{
    /**
     * Resolved diagnostics channel
     *
     * false means "not resolved yet", null means "no usable channel, use
     * error_log()". Resolving walks the logging config, so the result is cached
     * for the process; reset() clears it.
     */
    private static string|null|false $channel = false;

    /**
     * Report a Loki failure
     *
     * Unconditional: a push that never arrived is worth knowing about whether or
     * not loki.debug is on.
     */
    public static function error(string $message, array $context = [], ?Throwable $exception = null): void
    {
        self::send('error', $message, $context, $exception);
    }

    /**
     * Report a diagnostic detail
     *
     * Only called from paths already gated on loki.debug.
     */
    public static function debug(string $message, array $context = []): void
    {
        self::send('debug', $message, $context);
    }

    /**
     * Write straight to the process error log, bypassing Laravel entirely
     *
     * For callers that cannot rely on the container being there: destructors,
     * shutdown functions, and the recursion guard itself.
     */
    public static function write(string $message, ?Throwable $exception = null, string $level = 'error'): void
    {
        error_log(self::format($level, $message, [], $exception));
    }

    /**
     * The configured diagnostics channel, or null when there is none to use
     *
     * A channel that resolves back into the Loki driver is refused - honouring
     * it is what the whole class exists to prevent - as is one that is not
     * defined, since Log::channel() would only throw on it.
     */
    public static function channel(): ?string
    {
        if (self::$channel !== false) {
            return self::$channel;
        }

        if (!self::applicationIsAvailable()) {
            // Not cached: the application may well be available on the next call.
            return null;
        }

        $configured = config('loki.debug_channel');

        if (!is_string($configured) || trim($configured) === '') {
            return self::$channel = null;
        }

        $configured = trim($configured);
        $channels = config('logging.channels', []);

        if (!isset($channels[$configured])) {
            self::write(sprintf(
                'loki.debug_channel "%s" is not a defined logging channel; using the error log instead.',
                $configured
            ));

            return self::$channel = null;
        }

        if (self::reachesLoki($configured, $channels)) {
            self::write(sprintf(
                'loki.debug_channel "%s" resolves to the Loki driver, which would feed Loki failures back '
                . 'into Loki; using the error log instead. Point it at a loop-safe channel such as stderr.',
                $configured
            ));

            return self::$channel = null;
        }

        return self::$channel = $configured;
    }

    /**
     * Forget the resolved channel
     *
     * The logging config does not change within a process, so this is only for
     * tests that rewrite it between cases.
     */
    public static function reset(): void
    {
        self::$channel = false;
    }

    /**
     * Does this channel end up writing to Loki?
     *
     * Follows stack channels down to their leaves. Laravel accepts a
     * comma-separated string for a stack's channels (LogManager::createStackDriver
     * explodes it), so that shape is followed as well.
     *
     * A stack that contains itself is reported as reaching Loki. $seen has to
     * break the cycle to terminate, but the branch it breaks cannot be shown to
     * be Loki-free, and the whole class errs towards the error log when it cannot
     * tell. Laravel would recurse into such a stack until the process died
     * anyway, so it is not a channel to hand diagnostics to.
     *
     * Three shapes reach the Loki handler: this package's own driver, and
     * Laravel's 'monolog' driver pointed at LokiBufferedLogger (the handler the
     * driver builds), either directly or as one of a 'custom' driver's options.
     * A 'custom' driver whose factory builds the handler itself is invisible from
     * the config, which is why this is a guard rail and not a promise.
     */
    private static function reachesLoki(string $name, array $channels, array $seen = []): bool
    {
        if (isset($seen[$name])) {
            return true;
        }

        $seen[$name] = true;
        $config = $channels[$name] ?? null;

        if (!is_array($config)) {
            return false;
        }

        $driver = $config['driver'] ?? null;

        if ($driver === 'omniboost:loki') {
            return true;
        }

        if (self::wrapsLokiHandler($config)) {
            return true;
        }

        if ($driver !== 'stack') {
            return false;
        }

        $nested = $config['channels'] ?? [];
        $nested = is_string($nested) ? explode(',', $nested) : $nested;

        foreach ($nested as $child) {
            if (!is_string($child)) {
                continue;
            }

            if (self::reachesLoki(trim($child), $channels, $seen)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this channel config built around the package's Monolog handler?
     *
     * Covers `'driver' => 'monolog', 'handler' => LokiBufferedLogger::class` and
     * the same handler named in a channel's 'with'/'handler_with' options.
     */
    private static function wrapsLokiHandler(array $config): bool
    {
        $candidates = [$config['handler'] ?? null];

        foreach (['with', 'handler_with'] as $key) {
            $options = $config[$key] ?? [];

            if (is_array($options)) {
                $candidates[] = $options['handler'] ?? null;
            }
        }

        foreach ($candidates as $candidate) {
            if (is_object($candidate)) {
                $candidate = $candidate::class;
            }

            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            if (is_a($candidate, LokiBufferedLogger::class, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Send a record to the diagnostics channel, falling back to the error log
     */
    private static function send(string $level, string $message, array $context, ?Throwable $exception = null): void
    {
        $channel = self::channel();

        if ($channel === null) {
            error_log(self::format($level, $message, $context, $exception));

            return;
        }

        if ($exception !== null) {
            $context['exception_message'] = $exception->getMessage();
            $context['exception_origin'] = $exception->getFile() . ':' . $exception->getLine();
        }

        try {
            Log::channel($channel)->{$level}('[loki] ' . $message, $context);
        } catch (Throwable $loggingFailure) {
            // The diagnostics channel itself is broken; the error log always works.
            error_log(self::format($level, $message, $context, $exception));
            error_log(self::format(
                'error',
                sprintf('diagnostics channel "%s" could not be written to.', $channel),
                [],
                $loggingFailure
            ));
        }
    }

    /**
     * Render a record as a single error-log line
     */
    private static function format(string $level, string $message, array $context, ?Throwable $exception = null): string
    {
        $line = sprintf('[loki:%s] %s', $level, $message);

        if ($exception !== null) {
            $line .= sprintf(
                ' | %s: %s in %s:%d',
                $exception::class,
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine()
            );
        }

        if ($context !== []) {
            $line .= ' | ' . (json_encode($context) ?: '<context could not be encoded>');
        }

        return $line;
    }

    /**
     * Is there an application to read config from and log through?
     *
     * Same reasoning as ShutdownFlusher::applicationIsAvailable(): this class is
     * called from destructors and shutdown functions, where the container may
     * already be gone.
     */
    private static function applicationIsAvailable(): bool
    {
        if (!ShutdownFlusher::applicationIsAvailable()) {
            return false;
        }

        return Container::getInstance()->bound('log');
    }
}
