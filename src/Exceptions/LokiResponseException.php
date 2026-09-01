<?php

namespace Omniboost\LaravelLoggingLoki\Exceptions;

/**
 * Loki answered, but did not acknowledge the push: an error status (401, 404,
 * 429, 5xx, ...) or any status other than the 204/200 that means "accepted".
 *
 * The status and body are exposed so callers can react per case, e.g. back off
 * on 429 and alert on 401:
 *
 *     catch (LokiResponseException $e) {
 *         if ($e->getStatusCode() === 429) { ... }
 *     }
 */
class LokiResponseException extends LokiPushException
{
    private int $statusCode;
    private string $responseBody;

    public function __construct(int $statusCode, string $responseBody, ?\Throwable $previous = null)
    {
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;

        parent::__construct(
            sprintf('Loki rejected the push with HTTP %d: %s', $statusCode, $responseBody),
            0,
            $previous
        );
    }

    /**
     * The HTTP status Loki responded with.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * The (trimmed) response body Loki sent back, often naming the real reason.
     */
    public function getResponseBody(): string
    {
        return $this->responseBody;
    }

    /**
     * Whether retrying the push could succeed — rate limits and server errors
     * are transient; a 400 or 401 will fail again until something changes.
     */
    public function isRetryable(): bool
    {
        return $this->statusCode === 429 || $this->statusCode >= 500;
    }
}
