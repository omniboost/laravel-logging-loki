<?php

namespace Omniboost\LaravelLoggingLoki;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Omniboost\LaravelLoggingLoki\DTOs\LokiPayload;

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
     * @param array<LokiStream> $streams Array of log streams in Loki format
     * @return bool true when Loki acknowledged the push (HTTP 204)
     *
     * @throws \RuntimeException with the real reason when the push fails — the
     *         HTTP status and response body for a rejected request (auth, bad
     *         request, rate limit, ...), or the transport error when Loki could
     *         not be reached. The caller (the queue job) lets this propagate so
     *         the failure is retried and recorded instead of silently dropped.
     */
    public function push(array $streams): bool
    {
        if (empty($streams)) {
            return true;
        }

        $jsonPayload = json_encode(['streams' => $streams]);

        if ($jsonPayload === false) {
            throw new \RuntimeException('Failed to encode payload to JSON');
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
                throw new \RuntimeException('Failed to compress payload');
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
            $this->httpClient->post($this->url . '/loki/api/v1/push', $options);
        } catch (RequestException $e) {
            // Loki returned an error status (401, 404, 429, 5xx, ...). Surface the
            // status and body it sent back rather than a generic failure.
            if ($e->getResponse() !== null) {
                throw new \RuntimeException(sprintf(
                    'Loki rejected the push with HTTP %d: %s',
                    $e->getResponse()->getStatusCode(),
                    trim((string) $e->getResponse()->getBody())
                ), 0, $e);
            }

            throw new \RuntimeException('Could not reach Loki at ' . $this->url . ': ' . $e->getMessage(), 0, $e);
        } catch (GuzzleException $e) {
            // Connection/timeout/DNS failure — there is no response to report.
            throw new \RuntimeException('Could not reach Loki at ' . $this->url . ': ' . $e->getMessage(), 0, $e);
        }

        // No exception means a 2xx/3xx response (Guzzle's http_errors throws on
        // 4xx/5xx), so the push was accepted.
        return true;
    }
}
