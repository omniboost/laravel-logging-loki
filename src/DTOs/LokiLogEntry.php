<?php

namespace Omniboost\LaravelLoggingLoki\DTOs;

/**
 * Loki Log Entry
 *
 * Represents a single log entry before it's grouped into streams
 */
class LokiLogEntry
{
    /**
     * @param array<string, string> $stream Key-value pairs of labels (e.g., ['level' => 'info', 'channel' => 'application'])
     * @param string $entry The actual log message/content
     * @param string $timestamp Unix timestamp in nanoseconds (string to prevent precision loss)
     * @param array<string, mixed> $structuredMetadata Additional structured metadata to include in the log entry
     */
    public function __construct(
        public array $stream,
        public string $entry,
        public string $timestamp,
        public array $structuredMetadata = []
    ) {}

    /**
     * Convert to array format
     *
     * @return array{stream: array<string, string>, entry: string, timestamp: string, structuredMetadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'stream' => $this->stream,
            'entry' => $this->entry,
            'timestamp' => $this->timestamp,
            'structuredMetadata' => $this->structuredMetadata
        ];
    }

    /**
     * Create from array
     *
     * @param array{stream: array<string, string>, entry: string, timestamp: string, structuredMetadata?: array<string, mixed>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['stream'] ?? [],
            $data['entry'] ?? '',
            $data['timestamp'] ?? (string)(time() * 1000000000),
            $data['structuredMetadata'] ?? []
        );
    }

    /**
     * Get all labels
     *
     * @return array<string, string>
     */
    public function getStream(): array
    {
        return $this->stream;
    }
}
