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

    /**
     * Create a new job instance.
     *
     * @param array<LokiLogEntry>: $entries Loki Push API payload
     * @param string $url Loki URL (e.g., http://loki:3100)
     * @param string|null $username Optional basic auth username
     * @param string|null $password Optional basic auth password
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
    public function __construct(array $entries, string $url, ?string $username = null, ?string $password = null)
    {
        $this->entries = $entries;
        $this->url = $url;
        $this->username = $username;
        $this->password = $password;

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
        $client = new LokiClient($this->url, $this->username, $this->password);

        // Batch logs by labels into streams (already done, just pass through)
        $streams = $this->prepareStreams($this->entries);

        Log::channel('single')->debug('SendLogsToLoki job sending logs to Loki', [
            'streams' => json_encode($streams),
            'url' => $this->url,
        ]);

        // Send to Loki
        $success = $client->push($streams);

        if (!$success && $this->attempts() < $this->tries) {
            // Re-throw to trigger retry
            throw new \RuntimeException('Failed to send logs to Loki');
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        // Log failure if debug is enabled
        if (config('loki.debug', false)) {
            $streamCount = count($this->payload['streams'] ?? []);
            $totalEntries = array_sum(array_map(fn($s) => count($s['values'] ?? []), $this->payload['streams'] ?? []));

            Log::channel('single')->error('SendLogsToLoki job failed', [
                'error' => $exception->getMessage(),
                'stream_count' => $streamCount,
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

            // Add values to the existing stream
            $streamsById->{$streamId}->add([
                [
                    $logEntry->timestamp,
                    $logEntry->entry
                ]
            ]);
        }

        // Convert streams object to array and return it
        return array_values((array)$streamsById);;
    }
}
