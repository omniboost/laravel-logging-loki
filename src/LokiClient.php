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

    public function __construct(string $url, ?string $username = null, ?string $password = null)
    {
        $this->url = rtrim($url, '/');
        $this->username = $username;
        $this->password = $password;

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
            $options = [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ];

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
