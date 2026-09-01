<?php

namespace Omniboost\LaravelLoggingLoki\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Omniboost\LaravelLoggingLoki\DTOs\LokiLogEntry;
use Omniboost\LaravelLoggingLoki\DTOs\LokiStream;
use Omniboost\LaravelLoggingLoki\Exceptions\LokiPayloadException;
use Omniboost\LaravelLoggingLoki\Exceptions\LokiPushException;
use Omniboost\LaravelLoggingLoki\Exceptions\LokiResponseException;
use Omniboost\LaravelLoggingLoki\LokiClient;
use stdClass;

class SendLogsToLoki implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    private array $entries;
    private string $url;
    private ?string $username;
    private ?string $password;
    private bool $gzipCompression;

    /**
     * Create a new job instance.
     *
     * @param array<LokiLogEntry>: $entries Loki Push API payload
     * @param string $url Loki URL (e.g., http://loki:3100)
     * @param string|null $username Optional basic auth username
     * @param string|null $password Optional basic auth password
     * @param bool $gzipCompression Whether to use GZIP compression
     *
     * Payload structure:
     * [
     *   'streams' => [
     *     [
     *       'stream' => ['level' => 'info', 'channel' => 'app'],  // Labels
     *       'values' => [
     *         ['1702512000000000000', 'Log message 1'],          // [timestamp_ns, message]
     *         ['1702512001000000000', 'Log message 2']
     *       ]
     *     ]
     *   ]
     * ]
     */
    public function __construct(array $entries, string $url, ?string $username = null, ?string $password = null, bool $gzipCompression = true)
    {
        $this->entries = $entries;
        $this->url = $url;
        $this->username = $username;
        $this->password = $password;
        $this->gzipCompression = $gzipCompression;

        // Use the configured queue connection for loki
        $this->onQueue(config('loki.queue', 'default'));
    }

    /**
     * Execute the job.
     *
     * Sends the buffered logs to Loki's Push API endpoint
     */
    public function handle(): void
    {
        // check if the payload array is empty
        if (empty($this->entries ?? [])) {
            return;
        }

        // create client
        $client = $this->makeClient();

        // Batch logs by labels into streams (already done, just pass through)
        $streams = $this->prepareStreams($this->entries);

        if (config('loki.debug', false)) {
            Log::channel(config('loki.debug_channel'))->debug('SendLogsToLoki job sending logs to Loki', [
                'streams' => json_encode($streams),
                'url' => $this->url,
            ]);
        }

        // Send to Loki. push() throws a descriptive exception on failure (the
        // HTTP status + body, or the connection error).
        //
        // A transient failure — Loki unreachable, rate limited, a 5xx — is left
        // to propagate so the queue retries it (per $tries/$backoff) and records
        // the real reason if the retries run out.
        //
        // A permanent one is not worth retrying: a payload that cannot be
        // encoded, or a rejection like 400/401/404, fails identically on every
        // attempt. Retrying only delays the failed_jobs entry by $tries *
        // $backoff seconds while the buffer behind it keeps growing, so fail
        // immediately instead.
        try {
            $client->push($streams);
        } catch (LokiPayloadException $e) {
            $this->failWithoutRetrying($e);
        } catch (LokiResponseException $e) {
            if ($e->isRetryable()) {
                throw $e;
            }

            $this->failWithoutRetrying($e);
        }
    }

    /**
     * Build the client used to push, as a seam tests can replace.
     */
    protected function makeClient(): LokiClient
    {
        return new LokiClient($this->url, $this->username, $this->password, $this->gzipCompression);
    }

    /**
     * Mark this job failed for a failure no retry can fix.
     *
     * This runs failed() and drops the job without consuming the remaining
     * attempts.
     */
    private function failWithoutRetrying(LokiPushException $e): void
    {
        // Without a queue job instance — dispatchSync, or handle() called
        // directly — fail() has nothing to mark and would silently swallow the
        // exception, so surface it instead.
        if ($this->job === null) {
            throw $e;
        }

        $this->fail($e);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        // Log failure if debug is enabled
        if (config('loki.debug', false)) {
            $streams = $this->prepareStreams($this->entries);
            $totalEntries = count($this->entries);

            Log::channel(config('loki.debug_channel'))->error('SendLogsToLoki job failed', [
                'exception' => get_class($exception),
                'error' => $exception->getMessage(),
                'stream_count' => count($streams),
                'total_entries' => $totalEntries,
                'url' => $this->url,
            ]);
        }
    }

    /**
     * Prepare logs into LokiStream format
     *
     * @param array<LokiLogEntry> $logs Array of logs in LokiLogEntry format
     * @return array<LokiStream>
     */
    public function prepareStreams(array $entries): array
    {
        /** @var object<string, array<LokiStream>> $streams */
        $streamsById = new stdClass();

        // Prepare log entries into streams
        foreach ($entries as $logEntry) {
            // create stream
            $stream = new LokiStream(
                $logEntry->stream,
                []
            );

            // get stream id
            $streamId = $stream->getStreamId();

            // check if stream already exists
            // If not, create it
            if (!isset($streamsById->{$streamId})) {
                $streamsById->{$streamId} = $stream;
            }

            // Build the log entry value array for Loki
            // Format: [timestamp, line, structuredMetadata (optional)]
            $value = [$logEntry->timestamp, $logEntry->entry];
            
            // Add structured metadata as third element if present
            if (!empty($logEntry->structuredMetadata)) {
                $value[] = $logEntry->structuredMetadata;
            }

            // Add values to the existing stream
            $streamsById->{$streamId}->add([$value]);
        }

        // Convert streams object to array and return it
        return array_values((array)$streamsById);;
    }
}
