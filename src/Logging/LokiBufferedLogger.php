<?php

namespace Omniboost\LaravelLoggingLoki\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Omniboost\LaravelLoggingLoki\Services\LokiBufferedHandler;

class LokiBufferedLogger extends AbstractProcessingHandler
{
    // Logger handler
    private ?LokiBufferedHandler $handler = null;

    /**
     * @param string $url Loki URL
     * @param int $level The minimum logging level
     * @param bool $bubble Whether to bubble the record to the next handler
     *
     * @param LokiBufferedHandler $handler The buffered handler instance
     */
    public function __construct(
        int $level = \Monolog\Level::Debug->value,
        bool $bubble = true,

        LokiBufferedHandler $handler,
    ) {
        parent::__construct($level, $bubble);

        // Create handler
        //
        // No shutdown handling is registered here: LokiBufferedHandler already
        // registers every instance it creates. Doing it here as well flushes the
        // same buffers twice and keeps every logger ever built alive for the
        // lifetime of the process.
        $this->handler = $handler;
    }

    /**
     * {@inheritdoc}
     */
    protected function write(LogRecord $record): void
    {
        $this->getHandler()->write($record);
    }

    /**
     * Get the underlying LokiBufferedHandler instance
     *
     * Provides access to the internal handler for advanced operations
     * such as manual flushing or configuration inspection.
     *
     * @return LokiBufferedHandler The buffered handler instance
     */
    public function getHandler(): LokiBufferedHandler
    {
        return $this->handler;
    }

    /**
     * Get the command name for flushing this logger
     *
     * @deprecated since version 1.1, will be removed in version 2.0. This method is not used and serves no purpose.
     * @return string The command name
     */
    public function getCommand(): string
    {
        return 'loki:flush';
    }

    /**
     * Flush both memory buffer and persistent cache buffer
     *
     * This method performs a complete flush of all buffered logs:
     * 1. Flushes the in-memory buffer to the cache layer
     * 2. Flushes the cache buffer to the queue (dispatches SendLogsToLoki job)
     *
     * Use cases:
     * - Called by the LokiFlushCommand to flush all logs
     * - Can be called programmatically to force immediate log delivery
     * - Useful before application shutdown or deployment
     * - Helpful when debugging to ensure recent logs are visible in Grafana
     *
     * Example:
     * ```php
     * $logger = Log::channel('loki')->getLogger();
     * foreach ($logger->getHandlers() as $handler) {
     *     if ($handler instanceof LokiBufferedLogger) {
     *         $handler->flush();
     *     }
     * }
     * ```
     *
     * @return void
     */
    public function flush(): void
    {
        $this->getHandler()->flushMemoryBuffer();
        $this->getHandler()->flush();
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
        $this->flush();
        parent::close();
    }

    /**
     * Destructor - ensure memory buffer is flushed
     */
    public function __destruct()
    {
        $this->getHandler()->flushMemoryBuffer();
    }
}
