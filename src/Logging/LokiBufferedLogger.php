<?php

namespace Omniboost\LaravelLoggingLoki\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Omniboost\LaravelLoggingLoki\Services\LokiBufferedHandler;

class LokiBufferedLogger extends AbstractProcessingHandler
{
    // Logger handler
    private ?LokiBufferedHandler $handler = null;

    // Static registry for shutdown handlers
    private static bool $shutdownRegistered = false;
    private static array $handlerInstances = [];

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
        $this->handler = $handler;

        // Register this instance for shutdown handling
        self::$handlerInstances[] = $this;

        // Register shutdown function once per process to flush all handler instances
        if (!self::$shutdownRegistered) {
            register_shutdown_function(function () {
                foreach (self::$handlerInstances as $handler) {
                    if ($handler instanceof self) {
                        $handler->getHandler()->flushMemoryBuffer();
                    }
                }
            });
            self::$shutdownRegistered = true;
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function write(LogRecord $record): void
    {
        $this->getHandler()->write($record);
    }

    public function getHandler(): LokiBufferedHandler
    {
        return $this->handler;
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
        $this->getHandler()->flushMemoryBuffer();
        $this->getHandler()->flush();
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
