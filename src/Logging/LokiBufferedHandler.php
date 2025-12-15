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
    private string $structuredMetadataPrefix;
    
    // In-memory buffer properties
    private array $memoryBuffer = [];
    private int $memoryBufferSize;
    private float $memoryFlushInterval;
    private float $memoryBufferLastFlush;
    private static bool $shutdownRegistered = false;

    /**
     * @param string $url Loki URL
     * @param int $level The minimum logging level
     * @param int $bufferSize Number of logs to buffer in cache before flushing to queue
     * @param float $flushInterval Max seconds to wait before flushing cache buffer
     * @param array $defaultLabels Default labels to apply to all logs
     * @param string|null $username Optional basic auth username
     * @param string|null $password Optional basic auth password
     * @param string $structuredMetadataPrefix Prefix for extracting structured metadata from context
     * @param bool $bubble Whether to bubble the record to the next handler
     * @param int $memoryBufferSize Number of logs to buffer in memory before flushing to cache (default: 10)
     * @param float $memoryFlushInterval Max seconds to wait before flushing memory buffer (default: 1.0)
     */
    public function __construct(
        string $url,
        int $level = \Monolog\Level::Debug->value,
        int $bufferSize = 100,
        float $flushInterval = 5.0,
        array $defaultLabels = [],
        ?string $username = null,
        ?string $password = null,
        string $structuredMetadataPrefix = '',
        bool $bubble = true,
        int $memoryBufferSize = 10,
        float $memoryFlushInterval = 1.0
    ) {
        parent::__construct($level, $bubble);

        $this->url = $url;
        $this->bufferSize = $bufferSize;
        $this->flushInterval = $flushInterval;
        $this->username = $username;
        $this->password = $password;
        $this->defaultLabels = $defaultLabels;
        $this->structuredMetadataPrefix = $structuredMetadataPrefix;
        
        // Initialize in-memory buffer settings
        $this->memoryBufferSize = max(1, $memoryBufferSize);
        $this->memoryFlushInterval = max(0.1, $memoryFlushInterval);
        $this->memoryBufferLastFlush = microtime(true);
        
        // Register shutdown function once per process to flush memory buffer
        if (!self::$shutdownRegistered) {
            register_shutdown_function(function () {
                $this->flushMemoryBuffer();
            });
            self::$shutdownRegistered = true;
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function write(LogRecord $record): void
    {
        $logEntry = $this->prepareLogEntry($record);
        $this->addToMemoryBuffer($logEntry);
    }
    
    /**
     * Add log entry to in-memory buffer
     * Flushes to cache buffer when threshold is reached or interval elapsed
     */
    private function addToMemoryBuffer(LokiLogEntry $logEntry): void
    {
        $this->memoryBuffer[] = $logEntry;
        
        // Check if we should flush memory buffer to cache
        if ($this->shouldFlushMemoryBuffer()) {
            $this->flushMemoryBuffer();
        }
    }
    
    /**
     * Check if memory buffer should be flushed to cache
     */
    private function shouldFlushMemoryBuffer(): bool
    {
        // Flush if memory buffer size threshold reached
        if (count($this->memoryBuffer) >= $this->memoryBufferSize) {
            return true;
        }
        
        // Flush if time interval elapsed
        $elapsed = microtime(true) - $this->memoryBufferLastFlush;
        return $elapsed >= $this->memoryFlushInterval;
    }
    
    /**
     * Flush in-memory buffer to cache buffer
     * This moves logs from memory to the persistent cache layer
     */
    private function flushMemoryBuffer(): void
    {
        if (empty($this->memoryBuffer)) {
            return;
        }
        
        // Get all buffered logs and clear memory buffer atomically
        $logsToFlush = $this->memoryBuffer;
        $this->memoryBuffer = [];
        $this->memoryBufferLastFlush = microtime(true);
        
        // Add each log to the cache buffer
        // Wrap in try-catch to prevent errors in destructor or shutdown
        try {
            foreach ($logsToFlush as $logEntry) {
                $this->bufferLog($logEntry);
            }
        } catch (\Throwable $e) {
            // Silently fail to avoid breaking application
            // In production, logs would already be in memory and would be
            // attempted again on next flush or process end
        }
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
        // Extract structured metadata from context based on prefix configuration
        $structuredMetadata = $this->extractStructuredMetadata($record->context);

        $logEntry = new LokiLogEntry(
            // Labels will be set later
            [],

            // Use formatted message if available, else raw message
            ($record->formatted ?? $record->message),

            // Timestamp in nanoseconds
            (string)($record->datetime->getTimestamp() * 1000000000),

            // Structured metadata
            $structuredMetadata
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
     * Extract structured metadata from context based on prefix configuration
     *
     * @param array $context Log context array
     * @return array<string, string>
     */
    private function extractStructuredMetadata(array $context): array
    {
        // If context is empty, return empty array
        if (empty($context)) {
            return [];
        }

        // Remove 'labels' key from structured metadata as it's handled separately
        $contextWithoutLabels = array_filter(
            $context,
            fn($key) => $key !== 'labels',
            ARRAY_FILTER_USE_KEY
        );

        // If no prefix is configured, include all context as structured metadata
        if (empty($this->structuredMetadataPrefix)) {
            return $this->sanitizeStructuredMetadata($contextWithoutLabels);
        }

        // Extract only fields that start with the prefix
        $structuredMetadata = [];
        $prefixLength = strlen($this->structuredMetadataPrefix);

        foreach ($contextWithoutLabels as $key => $value) {
            if (str_starts_with($key, $this->structuredMetadataPrefix)) {
                // Remove the prefix from the key
                $cleanKey = substr($key, $prefixLength);

                // Skip if the clean key is empty (key was exactly the prefix)
                if ($cleanKey !== '') {
                    $structuredMetadata[$cleanKey] = $value;
                }
            }
        }

        return $this->sanitizeStructuredMetadata($structuredMetadata);
    }

    /**
     * Sanitize structured metadata to comply with Loki requirements
     * - Only string values (no null, objects, or arrays)
     * - Converts scalars to strings
     * - JSON encodes complex types
     *
     * @param array $metadata
     * @return array<string, string>
     */
    private function sanitizeStructuredMetadata(array $metadata): array
    {
        $sanitized = [];

        foreach ($metadata as $key => $value) {
            // Skip null values - Loki doesn't accept them
            if ($value === null) {
                continue;
            }

            // JSON encode arrays and objects - Loki only accepts flat key-value pairs
            if (is_array($value) || is_object($value)) {
                $sanitized[$key] = json_encode($value);
                continue;
            }

            // Convert boolean to string
            if (is_bool($value)) {
                $sanitized[$key] = $value ? 'true' : 'false';
                continue;
            }

            // Convert scalar values to strings
            $sanitized[$key] = (string) $value;
        }

        return $sanitized;
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
        $this->flushMemoryBuffer();
        $this->flush();
        parent::close();
    }

    /**
     * Destructor - ensure memory buffer is flushed
     */
    public function __destruct()
    {
        $this->flushMemoryBuffer();
    }
}
