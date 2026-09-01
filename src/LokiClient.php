<?php

namespace Omniboost\LaravelLoggingLoki;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Omniboost\LaravelLoggingLoki\DTOs\LokiPayload;
use Omniboost\LaravelLoggingLoki\Exceptions\LokiConnectionException;
use Omniboost\LaravelLoggingLoki\Exceptions\LokiPayloadException;
use Omniboost\LaravelLoggingLoki\Exceptions\LokiResponseException;

class LokiClient
{
    private Client $httpClient;
    private string $url;
    private ?string $username;
    private ?string $password;
    private bool $gzipCompression;

    public function __construct(string $url, ?string $username = null, ?string $password = null, bool $gzipCompression = true)
    {
        $this->url = rtrim($url, '/');
        $this->username = $username;
        $this->password = $password;
        $this->gzipCompression = $gzipCompression;

        $this->httpClient = new Client([
            'timeout' => 5,
            'connect_timeout' => 3,
        ]);
    }

    /**
     * Push logs to Loki.
     *
     * @param array<\Omniboost\LaravelLoggingLoki\DTOs\LokiStream> $streams Array of log streams in Loki format
     * @return bool true when Loki acknowledged the push (HTTP 204)
     *
     * The caller (the queue job) lets these propagate so the failure is retried
     * and recorded instead of silently dropped. Every one of them implements
     * {@see \Omniboost\LaravelLoggingLoki\Exceptions\LokiException} and extends
     * \RuntimeException, so callers can catch this package's failures
     * specifically without breaking existing \RuntimeException handling.
     *
     * @throws LokiPayloadException when the payload could not be encoded or
     *         compressed locally — nothing was sent.
     * @throws LokiResponseException when Loki answered without acknowledging the
     *         push; carries the HTTP status and response body (auth, bad
     *         request, rate limit, ...).
     * @throws LokiConnectionException when Loki could not be reached at all
     *         (DNS, refused, TLS, timeout).
     */
    public function push(array $streams): bool
    {
        if (empty($streams)) {
            return true;
        }

        $jsonPayload = json_encode(['streams' => $streams]);

        if ($jsonPayload === false) {
            throw LokiPayloadException::encodingFailed(json_last_error_msg());
        }

        $options = [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ];

        // Apply GZIP compression if enabled
        if ($this->gzipCompression) {
            $compressedPayload = gzencode($jsonPayload);

            if ($compressedPayload === false) {
                throw LokiPayloadException::compressionFailed();
            }

            $options['body'] = $compressedPayload;
            $options['headers']['Content-Encoding'] = 'gzip';
        } else {
            $options['body'] = $jsonPayload;
        }

        // Add basic auth if credentials are provided
        if ($this->username && $this->password) {
            $options['auth'] = [$this->username, $this->password];
        }

        try {
            $response = $this->httpClient->post($this->url . '/loki/api/v1/push', $options);
        } catch (RequestException $e) {
            // Loki returned an error status (401, 404, 429, 5xx, ...). Surface the
            // status and body it sent back rather than a generic failure.
            if ($e->getResponse() !== null) {
                throw new LokiResponseException(
                    $e->getResponse()->getStatusCode(),
                    trim((string) $e->getResponse()->getBody()),
                    $e
                );
            }

            throw LokiConnectionException::forUrl($this->url, $e);
        } catch (GuzzleException $e) {
            // Connection/timeout/DNS failure — there is no response to report.
            throw LokiConnectionException::forUrl($this->url, $e);
        }

        // Guzzle's http_errors only throws on 4xx/5xx, so a response reaching here
        // still has to be checked: Loki acknowledges a push with 204 (200 from
        // some proxies). Anything else is not an acknowledgement, so fail loudly
        // and let the job's retry/error path handle it instead of dropping logs.
        $statusCode = $response->getStatusCode();

        if ($statusCode !== 204 && $statusCode !== 200) {
            throw new LokiResponseException($statusCode, trim((string) $response->getBody()));
        }

        return true;
    }
}
