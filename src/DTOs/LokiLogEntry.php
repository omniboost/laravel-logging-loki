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
     * @param array<string, string> $labels Key-value pairs of labels (e.g., ['level' => 'info', 'channel' => 'application'])
     * @param string $entry The actual log message/content
     * @param string $timestamp Unix timestamp in nanoseconds (string to prevent precision loss)
     */
    public function __construct(
        public array $stream,
        public string $entry,
        public string $timestamp
    ) {}

    /**
     * Convert to array format
     *
     * @return array{entry: string, labels: array<string, string>, timestamp: string}
     */
    public function toArray(): array
    {
        return [
            'stream' => $this->stream,
            'entry' => $this->entry,
            'timestamp' => $this->timestamp
        ];
    }

    /**
     * Create from array
     *
     * @param array{entry: string, labels: array<string, string>, timestamp: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['stream'] ?? [],
            $data['entry'] ?? '',
            $data['timestamp'] ?? (string)(time() * 1000000000)
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
