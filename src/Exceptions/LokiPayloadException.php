<?php

namespace Omniboost\LaravelLoggingLoki\Exceptions;

/**
 * The payload could not be prepared locally — JSON encoding or GZIP compression
 * failed. Nothing was sent, and retrying the same payload will fail again.
 */
class LokiPayloadException extends LokiPushException
{
    public static function encodingFailed(?string $reason = null): self
    {
        return new self('Failed to encode payload to JSON' . ($reason !== null ? ': ' . $reason : ''));
    }

    public static function compressionFailed(): self
    {
        return new self('Failed to compress payload');
    }
}
