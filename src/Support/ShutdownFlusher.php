<?php

namespace Omniboost\LaravelLoggingLoki\Support;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Omniboost\LaravelLoggingLoki\Services\LokiBufferedHandler;
use WeakReference;

/**
 * Flushes the memory buffer of every live handler when the process shuts down
 *
 * Logs sitting in a handler's in-memory buffer are lost if the process ends
 * before the buffer reaches its size or time threshold, so every handler
 * registers itself here and a single shutdown function flushes them all.
 */
class ShutdownFlusher
{
    /**
     * Registered handlers, keyed by object id
     *
     * Handlers are held by weak reference: this registry must not be what keeps
     * a handler alive. Strong references leak every handler ever built for the
     * lifetime of the process, which adds up in long-running workers and in test
     * suites that rebuild the application for each test.
     *
     * @var array<int, WeakReference<LokiBufferedHandler>>
     */
    private static array $handlers = [];

    private static bool $shutdownRegistered = false;

    /**
     * Register a handler to be flushed at shutdown
     */
    public static function register(LokiBufferedHandler $handler): void
    {
        self::$handlers[spl_object_id($handler)] = WeakReference::create($handler);

        // Register the shutdown function once per process
        if (self::$shutdownRegistered) {
            return;
        }

        register_shutdown_function(static function () {
            self::flushAll();
        });

        self::$shutdownRegistered = true;
    }

    /**
     * Flush the memory buffer of every handler still alive in this process
     *
     * Handlers that have already been garbage collected are dropped from the
     * registry as they are encountered.
     */
    public static function flushAll(): void
    {
        foreach (self::$handlers as $id => $reference) {
            $handler = $reference->get();

            if ($handler === null) {
                // The handler is gone; stop tracking it
                unset(self::$handlers[$id]);
                continue;
            }

            $handler->flushMemoryBuffer();
        }
    }

    /**
     * Check whether the shutdown function has been registered for this process
     */
    public static function isShutdownRegistered(): bool
    {
        return self::$shutdownRegistered;
    }

    /**
     * Count the handlers still alive in the registry
     *
     * Dead references are pruned first, so this reports handlers that would
     * actually be flushed at shutdown.
     */
    public static function registeredHandlerCount(): int
    {
        foreach (self::$handlers as $id => $reference) {
            if ($reference->get() === null) {
                unset(self::$handlers[$id]);
            }
        }

        return count(self::$handlers);
    }

    /**
     * Check whether the Laravel application is still usable
     *
     * Flushing reaches into the container (config, cache, queue), so it can only
     * run while an application with those bindings is available. That is not a
     * given for the callers that run late in the process lifecycle:
     *
     * - the shutdown function runs after the application has terminated, and in
     *   test suites or Octane-style workers the container has been flushed (or
     *   replaced) by then;
     * - a handler's destructor can run at any point during shutdown, for the
     *   same reason.
     *
     * Without this check every such call throws "Target class [config] does not
     * exist", which the handler catches and writes to the error log once per
     * instance - noise that hides real flush failures.
     */
    public static function applicationIsAvailable(): bool
    {
        if (Facade::getFacadeApplication() === null) {
            return false;
        }

        return Container::getInstance()->bound('config');
    }
}
