<?php

namespace Omniboost\LaravelLoggingLoki;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
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
     * Push logs to Loki
     *
     * @param array<LokiStream> $streams Array of log streams in Loki format
     * @return bool
     */
    public function push(array $streams): bool
    {
        if (empty($streams)) {
            return true;
        }

        $payload = [
            'streams' => $streams,
        ];

        try {
            // Encode payload to JSON
            $jsonPayload = json_encode($payload);
            
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

            $response = $this->httpClient->post(
                $this->url . '/loki/api/v1/push',
                $options
            );

            return $response->getStatusCode() === 204;
        } catch (GuzzleException $e) {
            // Log the error but don't throw - logging should be resilient
            if (config('loki.debug', false)) {
                Log::channel('single')->error('Failed to push logs to Loki', [
                    'error' => $e->getMessage(),
                    'url' => $this->url,
                ]);
            }
            return false;
        }
    }
}
