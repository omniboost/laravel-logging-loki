<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Loki Server URL
    |--------------------------------------------------------------------------
    |
    | The URL of your Grafana Loki instance. This should include the protocol
    | and port if different from default.
    |
    | Example: https://loki.example.com:3100
    |
    */
    'url' => env('LOKI_URL', 'http://localhost:3100'),

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | If your Loki instance requires basic authentication, set the username
    | and password here.
    |
    */
    'username' => env('LOKI_USERNAME'),
    'password' => env('LOKI_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Buffer Settings
    |--------------------------------------------------------------------------
    |
    | Configure how logs are buffered before being sent to Loki.
    |
    | buffer_size: Number of log entries to buffer before flushing
    | flush_interval: Maximum seconds to wait before flushing buffer
    |
    */
    'buffer_size' => env('LOKI_BUFFER_SIZE', 100),
    'flush_interval' => env('LOKI_FLUSH_INTERVAL', 5.0),

    /*
    |--------------------------------------------------------------------------
    | Queue Settings
    |--------------------------------------------------------------------------
    |
    | Configure which queue connection and queue name to use for sending
    | logs to Loki in the background.
    |
    */
    'queue' => env('LOKI_QUEUE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Default Labels
    |--------------------------------------------------------------------------
    |
    | Labels that will be attached to all log entries. These help with
    | filtering and organizing logs in Grafana.
    |
    */
    'labels' => [
        'app' => env('APP_NAME', 'laravel'),
        'env' => env('APP_ENV', 'production'),
        'server' => gethostname(),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Level
    |--------------------------------------------------------------------------
    |
    | The minimum log level to send to Loki. Available levels:
    | debug, info, notice, warning, error, critical, alert, emergency
    |
    */
    'level' => env('LOKI_LOG_LEVEL', 'debug'),

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | Enable debug mode to log Loki connection errors to your default
    | Laravel log channel. Useful for troubleshooting.
    |
    */
    'debug' => env('LOKI_DEBUG', true),

    /*
    |--------------------------------------------------------------------------
    | Structured Metadata Prefix
    |--------------------------------------------------------------------------
    |
    | Configure a prefix for extracting structured metadata from log context.
    | If left empty (default), all context data is added as structured metadata.
    | If set, only context fields starting with this prefix will be extracted
    | and included as structured metadata in the Loki payload.
    |
    | Example: Setting 'meta_' means context fields like 'meta_user_id'
    | and 'meta_request_id' will be included as structured metadata, while others won't.
    |
    */
    'structured_metadata_prefix' => env('LOKI_STRUCTURED_METADATA_PREFIX', ''),
];
