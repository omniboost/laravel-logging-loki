<?php

namespace Omniboost\LaravelLoggingLoki\DTOs;

/**
 * Loki Stream
 *
 * Represents a single log stream in Loki with consistent labels
 * All log entries in a stream share the same set of labels
 */
class LokiStream
{
    /**
     * @param array<string, string> $stream Key-value pairs of labels (e.g., ['level' => 'info', 'channel' => 'application'])
     * @param array<int, array{0: string, 1: string}> $values Array of [timestamp_nanoseconds, log_entry] tuples
     */
    public function __construct(
        public array $stream,
        public array $values
    ) {}

    /**
     * Add values to the stream
     */
    public function add(array $newValues): void
    {
        $this->values = array_merge($this->values, $newValues);
    }

    /**
     * Convert to array format expected by Loki API
     *
     * @return array{stream: array<string, string>, values: array<int, array{0: string, 1: string}>}
     */
    public function toArray(): array
    {
        return [
            'stream' => $this->stream,
            'values' => $this->values
        ];
    }

    /**
     * Create from array
     *
     * @param array{stream: array<string, string>, values: array<int, array{0: string, 1: string}>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['stream'] ?? [],
            $data['values'] ?? []
        );
    }

    /**
     * Get the number of log entries in this stream
     */
    public function count(): int
    {
        return count($this->values);
    }

    /**
     * Get the unique identifier string of the stream based on its labels
     */
    public function getStreamId(): string
    {
        // sort stream keys and join them to create a unique identifier
        ksort($this->stream);
        return http_build_query($this->stream);
    }
}
