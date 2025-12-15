<?php

namespace Omniboost\LaravelLoggingLoki;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Monolog\Logger;
use Omniboost\LaravelLoggingLoki\Logging\LokiBufferedHandler;
use Omniboost\LaravelLoggingLoki\Formatters\LokiFormatter;

class LokiServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/loki.php', 'loki'
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish config file
        $this->publishes([
            __DIR__ . '/../config/loki.php' => config_path('loki.php'),
        ], 'loki-config');

        // Register custom logging driver
        Log::extend('omniboost:loki', function ($app, array $config) {
            $handler = new LokiBufferedHandler(
                url: $config['url'] ?? config('loki.url'),
                level: Logger::toMonologLevel($config['level'] ?? config('loki.level', 'debug'))->value,
                bufferSize: $config['buffer_size'] ?? config('loki.buffer_size', 100),
                flushInterval: $config['flush_interval'] ?? config('loki.flush_interval', 5.0),
                defaultLabels: $config['labels'] ?? config('loki.labels', []),
                username: $config['username'] ?? config('loki.username'),
                password: $config['password'] ?? config('loki.password'),
                structuredMetadataPrefix: $config['structured_metadata_prefix'] ?? config('loki.structured_metadata_prefix', ''),
                bubble: $config['bubble'] ?? true,
                memoryBufferSize: $config['memory_buffer_size'] ?? config('loki.memory_buffer_size', 10),
                memoryFlushInterval: $config['memory_flush_interval'] ?? config('loki.memory_flush_interval', 1.0)
            );

            // Optionally set custom formatter
            if (!empty($config['formatter'])) {
                $handler->setFormatter(new $config['formatter']());
            } else {
                $handler->setFormatter(new LokiFormatter());
            }

            return new Logger($config['name'] ?? 'loki', [$handler]);
        });
    }
}
