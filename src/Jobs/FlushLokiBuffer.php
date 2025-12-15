<?php

namespace Omniboost\LaravelLoggingLoki\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Omniboost\LaravelLoggingLoki\Services\BufferFlusher;

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

    private const FLUSH_LOCK_KEY = 'loki:log:flush:lock';

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
            // Get Loki configuration
            $url = config('loki.url');
            $username = config('loki.username');
            $password = config('loki.password');

            // Create buffer flusher instance
            $flusher = new BufferFlusher($url, $username, $password);

            // Flush buffer (without acquiring flush lock again since we already have it)
            $flushed = $flusher->flush(acquireFlushLock: false);

            if ($flushed && config('loki.debug', false)) {
                Log::channel('single')->debug('FlushLokiBuffer job completed successfully');
            }
        } finally {
            $flushLock->release();
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
