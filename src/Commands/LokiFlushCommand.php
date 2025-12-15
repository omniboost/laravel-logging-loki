<?php

namespace Omniboost\LaravelLoggingLoki\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Omniboost\LaravelLoggingLoki\Logging\LokiBufferedLogger;

class LokiFlushCommand extends Command
{
  /**
   * {@inheritdoc}
   */
  protected $signature = 'omniboost:loki:flush';

  /**
   * {@inheritdoc}
   */
  protected $description = 'This command puts the Shiji integrations into a Queue to process the Full Revenue';

  /**
   * {@inheritdoc}
   */
  public function __construct()
  {
    return parent::__construct();
  }

  public function handle(): void {
    // Get all loggers
    $loggers = $this->getLoggers();

    // Gather all handlers
    $handlers = $this->getHandlers($loggers);

    // Flush all handlers
    foreach ($handlers as $channelName => $handler) {
      $this->info("[$channelName] Flushing...");
      $handler->flush();
    }
  }

  /**
   * Get all registered loggers
   */
  public function getLoggers(): array
  {
    // Initiate loggers
    $loggers = [];

    // Get all channels
    $channels = config('logging.channels', []);

    // Loop through all channels and find those using the LokiBufferedLogger
    foreach ($channels as $channelName => $channelConfig) {
      // Ensure 'driver' is set
      if (!isset($channelConfig['driver'])) {
        continue;
      }

      // Check if the driver is 'omniboost:loki'
      if ($channelConfig['driver'] !== 'omniboost:loki') {
        continue;
      }

      $loggers[$channelName] = Log::channel($channelName)->getLogger();
    }

    return $loggers;
  }

  /**
   * Get all registered loggers
   */
  public function getHandlers(array $loggers): array
  {
    // Gather all handlers
    $handlers = [];

    // Loop through loggers
    foreach ($loggers as $channelName => $logger) {
      // Get the logger handlers
      $loggerHandlers = $logger->getHandlers();

      // Loop through every handler and flush if it's a LokiBufferedLogger
      foreach ($loggerHandlers as $handler) {
        // Check if it's a LokiBufferedLogger
        if ($handler instanceof LokiBufferedLogger === false) {
          continue;
        }

        // Store the handler
        $handlers[$channelName] = $handler;
      }
    }

    return $handlers;
  }
}

?>
