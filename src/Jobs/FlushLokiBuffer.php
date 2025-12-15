<?php

namespace Omniboost\LaravelLoggingLoki\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Omniboost\LaravelLoggingLoki\DTOs\LokiLogEntry;

/**
 * Job to manually flush the Loki log buffer
 *
 * This job can be scheduled to periodically flush buffered logs to Loki.
 * It uses distributed locking to prevent concurrent flushes.
 *
 * Example usage in App\Console\Kernel:
 *
 * protected function schedule(Schedule $schedule)
 * {
 *     $schedule->job(new \Omniboost\LaravelLoggingLoki\Jobs\FlushLokiBuffer())
 *              ->everyMinute()
 *              ->withoutOverlapping();
 * }
 */
class FlushLokiBuffer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const BUFFER_KEY = 'loki:log:buffer';
    private const BUFFER_LOCK_KEY = 'loki:log:buffer:lock';
    private const FLUSH_LOCK_KEY = 'loki:log:flush:lock';
    private const FLUSH_LAST_TIME_KEY = 'loki:log:flush:time';

    public int $tries = 1;
    public int $timeout = 30;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        // Use the configured queue connection for loki
        $this->onQueue(config('loki.queue', 'default'));
    }

    /**
     * Execute the job.
     *
     * Flushes the Loki buffer to the queue using distributed locking
     * to prevent concurrent flushes.
     */
    public function handle(): void
    {
        // Try to acquire flush lock to prevent concurrent flush operations
        $flushLock = Cache::lock(self::FLUSH_LOCK_KEY, 30);

        if (!$flushLock->get()) {
            // Another process is already flushing, skip this execution
            if (config('loki.debug', false)) {
                Log::channel('single')->debug('FlushLokiBuffer job skipped - flush already in progress');
            }
            return;
        }

        try {
            // Check if Redis is available for atomic operations
            if ($this->isRedisAvailable()) {
                $this->flushRedis();
            } else {
                $this->flushWithLock();
            }

            // Update last flush time
            Cache::put(self::FLUSH_LAST_TIME_KEY, time());

            if (config('loki.debug', false)) {
                Log::channel('single')->debug('FlushLokiBuffer job completed successfully');
            }
        } finally {
            $flushLock->release();
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
     * Flush buffer using Redis atomic operations
     */
    private function flushRedis(): void
    {
        // Check buffer size before flushing
        $bufferSize = Redis::llen(self::BUFFER_KEY);

        if ($bufferSize === 0) {
            return;
        }

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
                Log::channel('single')->error('FlushLokiBuffer Redis error', [
                    'error' => $e->getMessage(),
                ]);
            }
            return;
        } catch (\Exception $e) {
            // Catch any other Redis-related exceptions for safety
            if (config('loki.debug', false)) {
                Log::channel('single')->error('FlushLokiBuffer Redis error', [
                    'error' => $e->getMessage(),
                ]);
            }
            return;
        }

        if (empty($buffer)) {
            return;
        }

        // Decode JSON entries
        $decodedBuffer = array_map(fn($item) => LokiLogEntry::fromArray(json_decode($item, true)), $buffer);

        // Get Loki configuration
        $url = config('loki.url');
        $username = config('loki.username');
        $password = config('loki.password');

        // Dispatch job to send logs to Loki
        SendLogsToLoki::dispatch($decodedBuffer, $url, $username, $password);
    }

    /**
     * Flush with lock (for non-Redis cache drivers)
     */
    private function flushWithLock(): void
    {
        // Acquire BUFFER_LOCK_KEY to atomically read and clear the buffer
        $bufferLock = Cache::lock(self::BUFFER_LOCK_KEY, 5);

        try {
            if (!$bufferLock->get()) {
                // Buffer is locked by another process
                return;
            }

            // Atomically extract and clear the buffer within the lock
            $buffer = Cache::get(self::BUFFER_KEY, []);

            if (empty($buffer)) {
                $bufferLock->release();
                return;
            }

            // Clear the buffer BEFORE dispatching the job
            Cache::forget(self::BUFFER_KEY);

            // Convert buffer to LokiLogEntry objects
            $decodedBuffer = array_map(fn($item) => LokiLogEntry::fromArray($item), $buffer);

            // Release buffer lock before dispatching job
            $bufferLock->release();

            // Get Loki configuration
            $url = config('loki.url');
            $username = config('loki.username');
            $password = config('loki.password');

            // Dispatch job to send logs to Loki
            SendLogsToLoki::dispatch($decodedBuffer, $url, $username, $password);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            // If we can't get the buffer lock, another process is using the buffer
            if (config('loki.debug', false)) {
                Log::channel('single')->debug('FlushLokiBuffer could not acquire buffer lock');
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        if (config('loki.debug', false)) {
            Log::channel('single')->error('FlushLokiBuffer job failed', [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);
        }
    }
}
