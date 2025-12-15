<?php

namespace Omniboost\LaravelLoggingLoki\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Omniboost\LaravelLoggingLoki\Logging\LokiBufferedLogger;

/**
 * Command to flush all buffered Loki logs immediately
 *
 * This command discovers all logging channels configured with the 'omniboost:loki' driver
 * and flushes their buffers to ensure logs are sent to Loki without waiting for
 * buffer size or time thresholds to be reached.
 *
 * Use cases:
 * - Before application deployment or shutdown
 * - After critical log events that must be sent immediately
 * - When debugging to ensure recent logs are visible in Grafana
 * - In testing environments to verify log delivery
 *
 * The command can be scheduled using Laravel's Task Scheduler:
 * $schedule->command('omniboost:loki:flush')->everyMinute();
 *
 * @package Omniboost\LaravelLoggingLoki\Commands
 */
class LokiFlushCommand extends Command
{
  /**
   * The name and signature of the console command
   *
   * @var string
   */
  protected $signature = 'omniboost:loki:flush';

  /**
   * The console command description
   *
   * @var string
   */
  protected $description = 'Flush all buffered Loki logs to ensure they are sent to Loki immediately';

  /**
   * Create a new command instance
   *
   * @return void
   */
  public function __construct()
  {
    parent::__construct();
  }

  /**
   * Execute the console command
   *
   * This method performs the following steps:
   * 1. Discovers all Loki logging channels from config/logging.php
   * 2. Retrieves logger instances for each Loki channel
   * 3. Extracts LokiBufferedLogger handlers from each logger
   * 4. Flushes both memory and cache buffers for each handler
   *
   * @return void
   */
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
   * Get all registered Loki loggers from configured channels
   *
   * Scans all logging channels in config/logging.php and returns logger instances
   * for channels using the 'omniboost:loki' driver.
   *
   * @return array<string, \Monolog\Logger> Associative array of channel name => Logger instance
   */
  public function getLoggers(): array
  {
    // Initiate loggers
    $loggers = [];

    // Get all channels
    $channels = config('logging.channels', []);

    // Handle null or non-array config
    if (!is_array($channels)) {
      return $loggers;
    }

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
   * Extract LokiBufferedLogger handlers from logger instances
   *
   * Iterates through each logger and extracts handlers that are instances of
   * LokiBufferedLogger, which are the handlers that can be flushed.
   *
   * @param array<string, \Monolog\Logger> $loggers Array of logger instances keyed by channel name
   * @return array<string, LokiBufferedLogger> Associative array of channel name => LokiBufferedLogger handler
   */
  public function getHandlers(array $loggers): array
  {
    // Gather all handlers
    $handlers = [];

    // Loop through loggers
    foreach ($loggers as $channelName => $logger) {
      // Skip null or invalid loggers
      if ($logger === null || !is_object($logger)) {
        continue;
      }

      // Check if logger has getHandlers method
      if (!method_exists($logger, 'getHandlers')) {
        continue;
      }

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
