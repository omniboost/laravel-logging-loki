<?php

namespace Omniboost\LaravelLoggingLoki\DTOs;

/**
 * Loki Push API Payload
 *
 * Represents the structure sent to Loki's /loki/api/v1/push endpoint
 *
 * @see https://grafana.com/docs/loki/latest/api/#push-log-entries-to-loki
 */
class LokiPayload
{
    /**
     * @param array<int, LokiStream> $streams Array of log streams grouped by labels
     */
    public function __construct(
        public readonly array $streams
    ) {}

    /**
     * Convert to array format expected by Loki API
     *
     * @return array{streams: array<int, array{stream: array<string, string>, values: array<int, array{0: string, 1: string}>}>}
     */
    public function toArray(): array
    {
        return [
            'streams' => array_map(
                fn(LokiStream $stream) => $stream->toArray(),
                $this->streams
            )
        ];
    }

    /**
     * Create from array
     *
     * @param array{streams: array<int, array{stream: array<string, string>, values: array<int, array{0: string, 1: string}>}>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            array_map(
                fn(array $stream) => LokiStream::fromArray($stream),
                $data['streams'] ?? []
            )
        );
    }
}
