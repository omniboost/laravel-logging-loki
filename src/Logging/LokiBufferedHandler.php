<?php

namespace Omniboost\LaravelLoggingLoki\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Omniboost\LaravelLoggingLoki\Jobs\SendLogsToLoki;
use Omniboost\LaravelLoggingLoki\DTOs\LokiLogEntry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class LokiBufferedHandler extends AbstractProcessingHandler
{
    private const BUFFER_KEY = 'loki:log:buffer';
    private const BUFFER_LOCK_KEY = 'loki:log:buffer:lock';
    private const FLUSH_LOCK_KEY = 'loki:log:flush:lock';
    private const FLUSH_LAST_TIME_KEY = 'loki:log:flush:time';

    private int $bufferSize;
    private float $flushInterval;
    private string $url;
    private ?string $username;
    private ?string $password;
    private array $defaultLabels;
    private string $extraPrefix;

    /**
     * @param string $url Loki URL
     * @param int $level The minimum logging level
     * @param int $bufferSize Number of logs to buffer before flushing
     * @param float $flushInterval Max seconds to wait before flushing
     * @param array $defaultLabels Default labels to apply to all logs
     * @param string|null $username Optional basic auth username
     * @param string|null $password Optional basic auth password
     * @param string $extraPrefix Prefix for extracting extra metadata from context
     * @param bool $bubble Whether to bubble the record to the next handler
     */
    public function __construct(
        string $url,
        int $level = \Monolog\Level::Debug->value,
        int $bufferSize = 100,
        float $flushInterval = 5.0,
        array $defaultLabels = [],
        ?string $username = null,
        ?string $password = null,
        string $extraPrefix = '',
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);

        $this->url = $url;
        $this->bufferSize = $bufferSize;
        $this->flushInterval = $flushInterval;
        $this->username = $username;
        $this->password = $password;
        $this->defaultLabels = $defaultLabels;
        $this->extraPrefix = $extraPrefix;
    }

    /**
     * {@inheritdoc}
     */
    protected function write(LogRecord $record): void
    {
        $logEntry = $this->prepareLogEntry($record);
        $this->bufferLog($logEntry);
    }

    /**
     * Buffer log entry with race-condition protection
     */
    protected function bufferLog(LokiLogEntry $logEntry): void
    {
        // Use Redis atomic operations if available for better performance
        if ($this->isRedisAvailable()) {
            $this->bufferLogWithRedis($logEntry);
            return;
        }

        $this->bufferLogWithLock($logEntry);
    }

    /**
     * Check if Redis is available as cache driver
     */
    private function isRedisAvailable(): bool
    {
        return strtolower(config('cache.default')) === 'redis';
    }

    /**
     * Buffer log using Redis atomic operations (fastest, no locks needed)
     */
    private function bufferLogWithRedis(LokiLogEntry $logEntry): void
    {
        // Atomic append to list
        Redis::rpush(self::BUFFER_KEY, json_encode($logEntry->toArray()));

        // Get buffer size atomically
        $bufferSize = Redis::llen(self::BUFFER_KEY);

        // Check if we should flush - only if we're the one who hit the threshold
        if ($this->shouldFlush($bufferSize)) {
            $this->flush();
        }
    }

    /**
     * Buffer log using cache locks (fallback for non-Redis drivers)
     */
    private function bufferLogWithLock(LokiLogEntry $logEntry): void
    {
        // Use cache lock to prevent race conditions
        $lock = Cache::lock(self::BUFFER_LOCK_KEY, 5);
        $shouldFlush = false;

        try {
            // Try to acquire lock with 5 second timeout
            $lock->block(5, function () use ($logEntry, &$shouldFlush) {
                // Add log entry to buffer
                $buffer = Cache::get(self::BUFFER_KEY, []);
                $buffer[] = $logEntry->toArray();
                Cache::put(self::BUFFER_KEY, $buffer);

                // Check if we should flush (but don't flush while holding the buffer lock)
                $shouldFlush = $this->shouldFlush(count($buffer));
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            // If we can't get the lock, just skip this log entry
            // This prevents blocking the application
            // Alternative: could log to a fallback channel
        } finally {
            optional($lock)->release();
        }

        // Trigger flush AFTER releasing the buffer lock to avoid holding multiple locks
        if ($shouldFlush) {
            $this->flush();
        }
    }

    /**
     * Check if buffer should be flushed
     */
    private function shouldFlush(int $bufferSize): bool
    {
        // When the buffer size exceeds limit, flush
        if ($bufferSize >= $this->bufferSize) {
            return true;
        }

        // Check time since last flush
        $lastFlush = Cache::get(self::FLUSH_LAST_TIME_KEY, 0);
        return (time() - $lastFlush) >= $this->flushInterval;
    }

    /**
     * Flush buffer to queue
     *
     * Takes buffered log entries and groups them by labels into streams,
     * then dispatches to SendLogsToLoki job for async processing.
     *
     * Example payload structure:
     * [
     *   'streams' => [
     *     [
     *       'stream' => ['level' => 'info', 'channel' => 'app'],
     *       'values' => [
     *         ['1702512000000000000', 'Log message 1'],
     *         ['1702512001000000000', 'Log message 2']
     *       ]
     *     ]
     *   ]
     * ]
     *
     * @param array<LokiLogEntry> $buffer Array of LokiLogEntry
     */
    protected function flushBuffer(array $buffer): void
    {
        // Dispatch job to send logs to Loki asynchronously
        // Note: Buffer should already be cleared atomically before this is called
        SendLogsToLoki::dispatch($buffer, $this->url, $this->username, $this->password);
    }

    /**
     * Prepare log entry in Loki format
     *
     * Converts a Monolog LogRecord into a LokiLogEntry that will be buffered
     * and later sent to Loki.
     *
     * @param LogRecord $record Monolog log record
     * @return LokiLogEntry
     */
    private function prepareLogEntry(LogRecord $record): LokiLogEntry
    {
        // Extract extras from context based on prefix configuration
        $extras = $this->extractExtras($record->context);

        $logEntry = new LokiLogEntry(
            // Labels will be set later
            [],

            // Use formatted message if available, else raw message
            ($record->formatted ?? $record->message),

            // Timestamp in nanoseconds
            (string)($record->datetime->getTimestamp() * 1000000000),

            // Extras
            $extras
        );

        // Combine default labels with standard labels
        $labels = array_merge($this->defaultLabels);

        // Add extra context labels if available
        if (!empty($record->context['labels'])) {
            $labels = array_merge($labels, $record->context['labels']);
        }

        // Overwrite labels
        $logEntry->stream = $labels;

        return $logEntry;
    }

    /**
     * Extract extras from context based on prefix configuration
     *
     * @param array $context Log context array
     * @return array<string, mixed>
     */
    private function extractExtras(array $context): array
    {
        // If context is empty, return empty array
        if (empty($context)) {
            return [];
        }

        // Remove 'labels' key from extras as it's handled separately
        $contextWithoutLabels = array_filter(
            $context,
            fn($key) => $key !== 'labels',
            ARRAY_FILTER_USE_KEY
        );

        // If no prefix is configured, include all context as extras
        if (empty($this->extraPrefix)) {
            return $contextWithoutLabels;
        }

        // Extract only fields that start with the prefix
        $extras = [];
        $prefixLength = strlen($this->extraPrefix);

        foreach ($contextWithoutLabels as $key => $value) {
            if (strpos($key, $this->extraPrefix) === 0) {
                // Remove the prefix from the key
                $cleanKey = substr($key, $prefixLength);
                $extras[$cleanKey] = $value;
            }
        }

        return $extras;
    }

    /**
     * Flush buffered logs to the job queue
     * Public method to allow manual flushing
     */
    public function flush(): void
    {
        if ($this->isRedisAvailable()) {
            // Flush via Redis
            $this->flushRedis();

            // Set last flush time
            Cache::put(self::FLUSH_LAST_TIME_KEY, time());

            return;
        }

        // Flush via cache with lock
        $this->flushWithLock();

        // Set last flush time
        Cache::put(self::FLUSH_LAST_TIME_KEY, time());
    }

    /**
     * Flush buffer using Redis atomic operations
     */
    private function flushRedis(): void
    {
        // Try to acquire flush lock to prevent double-flushing
        $lock = Cache::lock(self::FLUSH_LOCK_KEY, 5);

        if (!$lock->get()) {
            return; // Another process is already flushing
        }

        try {
            // Check buffer size before flushing
            $bufferSize = Redis::llen(self::BUFFER_KEY);

            if ($bufferSize === 0) {
                return;
            }

            // Atomically read and remove items from the buffer
            // Using LRANGE to read items, then LTRIM to remove them
            // Note: Pipeline is not a transaction, but the FLUSH_LOCK_KEY ensures only
            // one flush operation runs at a time, preventing concurrent access
            // New logs added during pipeline will remain in the list (no data loss)
            // If bufferSize equals list length and no new items added, LTRIM clears the list
            try {
                // Use pipeline to batch read and trim operations
                $results = Redis::pipeline(function ($pipe) use ($bufferSize) {
                    // Get all current items (0 to bufferSize-1)
                    $pipe->lrange(self::BUFFER_KEY, 0, $bufferSize - 1);
                    // Trim to keep only items from bufferSize onwards (removes what we just read)
                    $pipe->ltrim(self::BUFFER_KEY, $bufferSize, -1);
                });

                $buffer = $results[0] ?? [];
            } catch (\RedisException | \Predis\Response\ServerException $e) {
                // Redis errors (connection, permissions, etc.)
                return;
            } catch (\Exception $e) {
                // Catch any other Redis-related exceptions for safety
                return;
            }

            if (empty($buffer)) {
                return;
            }

            // Decode JSON entries
            $decodedBuffer = array_map(fn($item) => LokiLogEntry::fromArray(json_decode($item, true)), $buffer);

            // Flush buffer
            $this->flushBuffer($decodedBuffer);
        } finally {
            $lock->release();
        }
    }

    /**
     * Flush with lock (for non-Redis cache drivers)
     */
    private function flushWithLock(): void
    {
        // First, acquire FLUSH_LOCK_KEY to prevent multiple concurrent flush operations
        $flushLock = Cache::lock(self::FLUSH_LOCK_KEY, 5);
        
        if (!$flushLock->get()) {
            return; // Another process is already flushing
        }

        try {
            // Now acquire BUFFER_LOCK_KEY to atomically read and clear the buffer
            // This prevents logs from being added between reading and clearing
            $bufferLock = Cache::lock(self::BUFFER_LOCK_KEY, 5);
            $bufferLockAcquired = false;

            try {
                // Try to get the buffer lock
                if ($bufferLock->get()) {
                    $bufferLockAcquired = true;
                    
                    // Atomically extract and clear the buffer within the lock
                    $buffer = Cache::get(self::BUFFER_KEY, []);
                    
                    if (empty($buffer)) {
                        // Release buffer lock before returning
                        $bufferLock->release();
                        $bufferLockAcquired = false;
                        return;
                    }

                    // Clear the buffer BEFORE dispatching the job
                    // This prevents race condition where new logs arrive during job dispatch
                    Cache::forget(self::BUFFER_KEY);

                    // Convert buffer to LokiLogEntry objects
                    $decodedBuffer = array_map(fn($item) => LokiLogEntry::fromArray($item), $buffer);

                    // Release buffer lock before dispatching job to avoid blocking buffer operations
                    $bufferLock->release();
                    $bufferLockAcquired = false;
                    
                    // Flush buffer (dispatch job) - still holding flush lock
                    $this->flushBuffer($decodedBuffer);
                }
            } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
                // If we can't get the buffer lock, another process is using the buffer
            } finally {
                // Only release buffer lock if still acquired
                if ($bufferLockAcquired) {
                    $bufferLock->release();
                }
            }
        } finally {
            // Always release the flush lock
            $flushLock->release();
        }
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
     * Destructor - ensure logs are flushed
     */
    public function __destruct()
    {
        // Note: Destructor flush might not always work reliably
        // Consider using a scheduled task for guaranteed flushing
    }
}
