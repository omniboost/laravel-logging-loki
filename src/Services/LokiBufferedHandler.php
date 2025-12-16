<?php

namespace Omniboost\LaravelLoggingLoki\Services;

use Monolog\LogRecord;
use Omniboost\LaravelLoggingLoki\Jobs\SendLogsToLoki;
use Omniboost\LaravelLoggingLoki\DTOs\LokiLogEntry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class LokiBufferedHandler
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
    private bool $gzipCompression;
    private array $defaultLabels;
    private string $structuredMetadataPrefix;

    // In-memory buffer properties
    private array $memoryBuffer = [];
    private int $memoryBufferSize;
    private float $memoryFlushInterval;
    private float $memoryBufferLastFlush;

    // Static registry for shutdown handlers
    private static bool $shutdownRegistered = false;
    private static array $handlerInstances = [];

    /**
     * @param string $url Loki URL
     * @param int $level The minimum logging level
     * @param int $bufferSize Number of logs to buffer in cache before flushing to queue
     * @param float $flushInterval Max seconds to wait before flushing cache buffer
     * @param array $defaultLabels Default labels to apply to all logs
     * @param string|null $username Optional basic auth username
     * @param string|null $password Optional basic auth password
     * @param bool $gzipCompression Whether to use GZIP compression
     * @param string $structuredMetadataPrefix Prefix for extracting structured metadata from context
     * @param bool $bubble Whether to bubble the record to the next handler
     * @param int $memoryBufferSize Number of logs to buffer in memory before flushing to cache (default: 10)
     * @param float $memoryFlushInterval Max seconds to wait before flushing memory buffer (default: 1.0)
     */
    public function __construct(
        string $url,
        int $bufferSize = 100,
        float $flushInterval = 5.0,
        array $defaultLabels = [],
        ?string $username = null,
        ?string $password = null,
        bool $gzipCompression = true,
        string $structuredMetadataPrefix = '',
        int $memoryBufferSize = 100,
        float $memoryFlushInterval = 1.0
    ) {
        $this->url = $url;
        $this->bufferSize = $bufferSize;
        $this->flushInterval = $flushInterval;
        $this->username = $username;
        $this->password = $password;
        $this->gzipCompression = $gzipCompression;
        $this->defaultLabels = $defaultLabels;
        $this->structuredMetadataPrefix = $structuredMetadataPrefix;

        // Initialize in-memory buffer settings
        // Memory buffer size cannot be lower than 1 or higher than cache buffer size
        $this->memoryBufferSize = min(
            max(1, $memoryBufferSize),
            $this->bufferSize
        );

        // Memory flush interval cannot be lower than 0.1s or higher than cache flush interval
        $this->memoryFlushInterval = min(
            max(0.1, $memoryFlushInterval),
            $this->flushInterval
        );

        $this->memoryBufferLastFlush = microtime(true);

        // Register this instance for shutdown handling
        self::$handlerInstances[] = $this;

        // Register shutdown function once per process to flush all handler instances
        if (!self::$shutdownRegistered) {
            register_shutdown_function(function () {
                foreach (self::$handlerInstances as $handler) {
                    if ($handler instanceof self) {
                        $handler->flushMemoryBuffer();
                    }
                }
            });
            self::$shutdownRegistered = true;
        }
    }

    /**
     * Write logs into the buffered handler.
     */
    public function write(LogRecord $record): void
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
     *
     * This method moves logs from the in-memory buffer to the persistent cache layer.
     * It is called automatically when:
     * - Memory buffer size threshold is reached
     * - Memory flush interval has elapsed
     * - Process shutdown (via register_shutdown_function)
     * - Handler destructor is called
     *
     * The method is also exposed publicly to allow manual flushing via:
     * - LokiFlushCommand (artisan omniboost:loki:flush)
     * - Programmatic calls in application code
     *
     * Thread-safe: Uses atomic operations (Redis) or locks (other cache drivers)
     * to prevent race conditions during concurrent access.
     *
     * @return void
     */
    public function flushMemoryBuffer(): void
    {
        if (empty($this->memoryBuffer)) {
            return;
        }

        // Get all buffered logs and clear memory buffer atomically
        $logsToFlush = $this->memoryBuffer;
        $this->memoryBuffer = [];
        $this->memoryBufferLastFlush = microtime(true);

        // Add all logs to the cache buffer in a single batch operation
        // This reduces lock contention by acquiring lock once instead of per log
        // Wrap in try-catch to prevent errors in destructor or shutdown
        try {
            $this->bufferLogs($logsToFlush);
        } catch (\Throwable $e) {
            // Log error to PHP error log to aid debugging
            // We can't use Laravel Log here as it might cause recursion
            error_log(sprintf(
                'LokiBufferedHandler: Failed to flush memory buffer: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }
    }

    /**
     * Check if Redis is available as cache driver
     */
    private function isRedisAvailable(): bool
    {
        return strtolower(config('cache.default')) === 'redis';
    }

    /**
     * Buffer log entry with race-condition protection
     *
     * @param array<LokiLogEntry> $logEntries
     */
    protected function bufferLogs(array $logEntries): void
    {
        if (empty($logEntries)) {
            return;
        }

        // Use Redis atomic operations if available for better performance
        if ($this->isRedisAvailable()) {
            $this->bufferLogsWithRedis($logEntries);
            return;
        }

        $this->bufferLogsWithLock($logEntries);
    }

    /**
     * Buffer log using Redis atomic operations (fastest, no locks needed)
     *
     * @param array<LokiLogEntry> $logEntries
     */
    private function bufferLogsWithRedis(array $logEntries): void
    {
        // Use Redis pipeline to push all entries atomically
        Redis::pipeline(function ($pipe) use ($logEntries) {
            foreach ($logEntries as $logEntry) {
                $pipe->rpush(self::BUFFER_KEY, json_encode($logEntry->toArray()));
            }
        });

        // Get buffer size atomically
        $bufferSize = Redis::llen(self::BUFFER_KEY);

        // Check if we should flush - only if we're the one who hit the threshold
        if ($this->shouldFlush($bufferSize)) {
            $this->flush();
        }
    }

    /**
     * Buffer log using cache locks (fallback for non-Redis drivers)
     *
     * @param array<LokiLogEntry> $logEntries
     */
    private function bufferLogsWithLock(array $logEntries): void
    {
        // Use cache lock to prevent race conditions
        $lock = Cache::lock(self::BUFFER_LOCK_KEY, 5);
        $shouldFlush = false;

        try {
            // Try to acquire lock with 5 second timeout
            $lock->block(5, function () use ($logEntries, &$shouldFlush) {
                // Get existing buffer
                $buffer = Cache::get(self::BUFFER_KEY, []);

                // Add all log entries to buffer in one operation
                foreach ($logEntries as $logEntry) {
                    $buffer[] = $logEntry->toArray();
                }

                // Write back to cache once
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
        SendLogsToLoki::dispatch($buffer, $this->url, $this->username, $this->password, $this->gzipCompression);
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
     * @return array<string, string|array> Structured metadata with string values or nested arrays
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
     * Convert a value to Loki-compatible format
     * 
     * Loki structured metadata requirements:
     * - Primitives (scalar values, null) are allowed
     * - Associative arrays (key-value pairs) are treated as objects
     * - Objects can contain only primitives or nested objects recursively
     * - Indexed/list arrays are not allowed and will be skipped
     *
     * @param mixed $value The value to convert
     * @return mixed Loki-compatible value or null if not compatible
     */
    private function toLokiCompatible($value)
    {
        // Primitives: return as-is
        if (is_scalar($value) || is_null($value)) {
            return $value;
        }

        // Objects: cast to array and process
        if (is_object($value)) {
            $value = (array)$value;
        }

        // Arrays: distinguish between associative (objects) and indexed (lists)
        if (is_array($value)) {
            // Empty arrays are treated as indexed (not compatible)
            if (empty($value)) {
                return null;
            }

            // Check if array is associative (has string keys or non-sequential numeric keys)
            $keys = array_keys($value);
            $isAssociative = $keys !== range(0, count($value) - 1);

            if ($isAssociative) {
                // Associative array: recursively process each value
                $result = [];
                foreach ($value as $k => $v) {
                    $compVal = $this->toLokiCompatible($v);
                    
                    // Include the value if:
                    // 1. It's Loki-compatible (compVal is not null), OR
                    // 2. The original value was explicitly null (we want to preserve null keys)
                    // Skip if compVal is null AND original value was not null (incompatible value like indexed array)
                    $shouldInclude = $compVal !== null || $v === null;
                    
                    if ($shouldInclude) {
                        $result[$k] = $compVal;
                    }
                }
                return $result;
            } else {
                // Indexed array: not allowed in Loki structured metadata
                // Return null to indicate this value should be skipped
                return null;
            }
        }

        // Unknown type: skip
        return null;
    }

    /**
     * Sanitize structured metadata to comply with Loki requirements
     * - Converts values to Loki-compatible format
     * - Recursively handles nested objects/associative arrays
     * - Skips indexed arrays (lists)
     * - Converts primitives to strings for final output
     *
     * @param array $metadata
     * @return array<string, string|array>
     */
    private function sanitizeStructuredMetadata(array $metadata): array
    {
        $sanitized = [];

        foreach ($metadata as $key => $value) {
            // Convert to Loki-compatible format
            $compatible = $this->toLokiCompatible($value);

            // Skip null values - either incompatible (indexed arrays) or explicitly null
            // Loki doesn't accept null values in structured metadata
            if ($compatible === null) {
                continue;
            }

            // Convert booleans to string representation
            if (is_bool($compatible)) {
                $sanitized[$key] = $compatible ? 'true' : 'false';
                continue;
            }

            // If it's an array (associative), recursively sanitize it
            if (is_array($compatible)) {
                $sanitizedArray = $this->sanitizeStructuredMetadataRecursive($compatible);
                if (!empty($sanitizedArray)) {
                    $sanitized[$key] = $sanitizedArray;
                }
                continue;
            }

            // Convert scalar values to strings
            $sanitized[$key] = (string) $compatible;
        }

        return $sanitized;
    }

    /**
     * Recursively sanitize nested arrays/objects to strings
     *
     * @param array $data
     * @return array<string, string|array>
     */
    private function sanitizeStructuredMetadataRecursive(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            // Skip null values
            if ($value === null) {
                continue;
            }

            // Convert boolean to string
            if (is_bool($value)) {
                $result[$key] = $value ? 'true' : 'false';
                continue;
            }

            // Recursively process nested arrays
            if (is_array($value)) {
                $nested = $this->sanitizeStructuredMetadataRecursive($value);
                if (!empty($nested)) {
                    $result[$key] = $nested;
                }
                continue;
            }

            // Convert scalar to string
            $result[$key] = (string) $value;
        }

        return $result;
    }

    /**
     * Flush buffered logs from cache to the job queue
     *
     * This method extracts all buffered logs from the cache layer and dispatches
     * them to the SendLogsToLoki job for asynchronous processing.
     *
     * The flush operation is triggered when:
     * - Cache buffer size threshold is reached
     * - Cache flush interval has elapsed
     * - Manual flush is requested (via LokiFlushCommand or programmatic call)
     *
     * Thread-safety:
     * - Uses Redis atomic operations (LRANGE + LTRIM) when Redis is the cache driver
     * - Uses distributed locks for non-Redis cache drivers
     * - Prevents double-flushing via FLUSH_LOCK_KEY
     * - Ensures no log loss during concurrent access
     *
     * Process:
     * 1. Acquires flush lock to prevent concurrent flush operations
     * 2. Atomically reads and clears the cache buffer
     * 3. Decodes buffer entries to LokiLogEntry objects
     * 4. Dispatches SendLogsToLoki job with the entries
     * 5. Releases lock
     *
     * @return void
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
}
