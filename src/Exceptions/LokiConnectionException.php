<?php

namespace Omniboost\LaravelLoggingLoki\Exceptions;

/**
 * Loki could not be reached at all — DNS, connection refused, TLS or timeout.
 * There is no response to inspect; the push may safely be retried.
 */
class LokiConnectionException extends LokiPushException
{
    private string $url;

    public function __construct(string $url, string $message, ?\Throwable $previous = null)
    {
        $this->url = $url;

        parent::__construct($message, 0, $previous);
    }

    public static function forUrl(string $url, \Throwable $previous): self
    {
        return new self($url, 'Could not reach Loki at ' . $url . ': ' . $previous->getMessage(), $previous);
    }

    /**
     * The Loki base URL that could not be reached.
     */
    public function getUrl(): string
    {
        return $this->url;
    }
}
