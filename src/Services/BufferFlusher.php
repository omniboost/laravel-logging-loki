<?php

namespace Omniboost\LaravelLoggingLoki\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Omniboost\LaravelLoggingLoki\DTOs\LokiLogEntry;
use Omniboost\LaravelLoggingLoki\Jobs\SendLogsToLoki;

/**
 * Service for flushing buffered Loki logs
 *
 * This service provides shared buffer flushing logic used by both
 * LokiBufferedHandler and FlushLokiBuffer job to avoid code duplication.
 */
class BufferFlusher
{
    private const BUFFER_KEY = 'loki:log:buffer';
    private const BUFFER_LOCK_KEY = 'loki:log:buffer:lock';
    private const FLUSH_LOCK_KEY = 'loki:log:flush:lock';
    private const FLUSH_LAST_TIME_KEY = 'loki:log:flush:time';

    private string $url;
    private ?string $username;
    private ?string $password;

    public function __construct(string $url, ?string $username = null, ?string $password = null)
    {
        $this->url = $url;
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * Flush buffer using Redis atomic operations
     *
     * @param bool $acquireFlushLock Whether to acquire the flush lock (default: true)
     * @return bool True if flushed successfully, false otherwise
     */
    public function flushRedis(bool $acquireFlushLock = true): bool
    {
        $lock = null;

        if ($acquireFlushLock) {
            // Try to acquire flush lock to prevent double-flushing
            $lock = Cache::lock(self::FLUSH_LOCK_KEY, 5);

            if (!$lock->get()) {
                return false; // Another process is already flushing
            }
        }

        try {
            // Check buffer size before flushing
            $bufferSize = Redis::llen(self::BUFFER_KEY);

            if ($bufferSize === 0) {
                return false;
            }

            // Atomically read and remove items from the buffer
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
                if (config('loki.debug', false)) {
                    Log::channel('single')->error('BufferFlusher Redis error', [
                        'error' => $e->getMessage(),
                    ]);
                }
                return false;
            } catch (\Exception $e) {
                // Catch any other Redis-related exceptions for safety
                if (config('loki.debug', false)) {
                    Log::channel('single')->error('BufferFlusher Redis error', [
                        'error' => $e->getMessage(),
                    ]);
                }
                return false;
            }

            if (empty($buffer)) {
                return false;
            }

            // Decode JSON entries with null check
            $decodedBuffer = [];
            foreach ($buffer as $item) {
                $decoded = json_decode($item, true);
                if ($decoded !== null && is_array($decoded)) {
                    $decodedBuffer[] = LokiLogEntry::fromArray($decoded);
                } elseif (config('loki.debug', false)) {
                    Log::channel('single')->warning('BufferFlusher skipped malformed JSON entry', [
                        'item' => $item,
                    ]);
                }
            }

            if (empty($decodedBuffer)) {
                return false;
            }

            // Dispatch job to send logs to Loki
            SendLogsToLoki::dispatch($decodedBuffer, $this->url, $this->username, $this->password);

            return true;
        } finally {
            if ($lock) {
                $lock->release();
            }
        }
    }

    /**
     * Flush with lock (for non-Redis cache drivers)
     *
     * @param bool $acquireFlushLock Whether to acquire the flush lock (default: true)
     * @return bool True if flushed successfully, false otherwise
     */
    public function flushWithLock(bool $acquireFlushLock = true): bool
    {
        $flushLock = null;

        if ($acquireFlushLock) {
            // First, acquire FLUSH_LOCK_KEY to prevent multiple concurrent flush operations
            $flushLock = Cache::lock(self::FLUSH_LOCK_KEY, 5);

            if (!$flushLock->get()) {
                return false; // Another process is already flushing
            }
        }

        try {
            // Now acquire BUFFER_LOCK_KEY to atomically read and clear the buffer
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
                        return false;
                    }

                    // Clear the buffer BEFORE dispatching the job
                    Cache::forget(self::BUFFER_KEY);

                    // Convert buffer to LokiLogEntry objects
                    $decodedBuffer = array_map(fn($item) => LokiLogEntry::fromArray($item), $buffer);

                    // Release buffer lock before dispatching job to avoid blocking buffer operations
                    $bufferLock->release();
                    $bufferLockAcquired = false;

                    // Dispatch job to send logs to Loki
                    SendLogsToLoki::dispatch($decodedBuffer, $this->url, $this->username, $this->password);

                    return true;
                }
            } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
                // If we can't get the buffer lock, another process is using the buffer
                if (config('loki.debug', false)) {
                    Log::channel('single')->debug('BufferFlusher could not acquire buffer lock');
                }
            } finally {
                // Only release buffer lock if still acquired
                if ($bufferLockAcquired) {
                    $bufferLock->release();
                }
            }

            return false;
        } finally {
            // Always release the flush lock
            if ($flushLock) {
                $flushLock->release();
            }
        }
    }

    /**
     * Check if Redis is available as cache driver
     */
    public function isRedisAvailable(): bool
    {
        return strtolower(config('cache.default')) === 'redis';
    }

    /**
     * Flush buffer to queue
     *
     * @param bool $acquireFlushLock Whether to acquire the flush lock (default: true)
     * @return bool True if flushed successfully, false otherwise
     */
    public function flush(bool $acquireFlushLock = true): bool
    {
        $flushed = false;

        if ($this->isRedisAvailable()) {
            // Flush via Redis
            $flushed = $this->flushRedis($acquireFlushLock);
        } else {
            // Flush via cache with lock
            $flushed = $this->flushWithLock($acquireFlushLock);
        }

        if ($flushed) {
            // Set last flush time
            Cache::put(self::FLUSH_LAST_TIME_KEY, time());
        }

        return $flushed;
    }
}
