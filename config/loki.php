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
    | memory_buffer_size: Number of log entries to buffer in memory before
    |                     flushing to cache (default: 100, cannot be higher than Loki buffer size, reduces cache writes)
    | memory_flush_interval: Maximum seconds to wait before flushing memory
    |                        buffer to cache (default: 1.0, cannot be higher than Loki flush interval)
    | buffer_size: Number of log entries to buffer in cache before flushing
    |              to the job queue
    | flush_interval: Maximum seconds to wait before flushing cache buffer
    |
    */
    'memory_buffer_size' => env('LOKI_MEMORY_BUFFER_SIZE', 100),
    'memory_flush_interval' => env('LOKI_MEMORY_FLUSH_INTERVAL', 1.0),
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
    | Enable debug mode to report what the package is doing - the streams it is
    | about to send, and so on - to the diagnostics channel below. Useful for
    | troubleshooting.
    |
    | Failures are reported whether or not this is enabled: a push that never
    | arrived is not a debugging detail.
    |
    */
    'debug' => env('LOKI_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Debug Log Channel
    |--------------------------------------------------------------------------
    |
    | The log channel the package reports its own failures and diagnostics to.
    |
    | Leave this null and everything goes to the process error log via error_log()
    | - stderr in a container, which the log driver is already collecting. That is
    | the safe default and needs no configuration.
    |
    | Set it to a channel to get the package's diagnostics into a destination you
    | already read. It must not resolve to the Loki driver: reporting a Loki
    | failure into Loki buffers it, dispatches another push job, fails again and
    | reports that the same way, so every outage amplifies itself. A channel that
    | does resolve there - directly, or through a stack, including the one the
    | application's default channel points at - is refused and the error log is
    | used instead, but do not rely on that: point this at a loop-safe channel
    | such as 'stderr', 'single' or 'daily'.
    |
    | Note that this is NOT the application's default channel. Defaulting to it
    | would put the package's failures back into the Loki channel in the very
    | setup that needs them elsewhere.
    |
    */
    'debug_channel' => env('LOKI_DEBUG_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | GZIP Compression
    |--------------------------------------------------------------------------
    |
    | Enable GZIP compression for data sent to Loki. This reduces payload size
    | and allows more data to be sent per request, helping to maximize usage
    | of Grafana's request size limits.
    |
    */
    'gzip_compression' => env('LOKI_GZIP_COMPRESSION', true),

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

    /*
    |--------------------------------------------------------------------------
    | Labels Prefix
    |--------------------------------------------------------------------------
    |
    | Configure a prefix for extracting labels from log context.
    | Default is 'label_' to provide clear separation from structured metadata.
    | Context fields starting with this prefix will be extracted and included
    | as labels in the Loki stream (with prefix removed).
    |
    | Example: With default 'label_', fields like 'label_user_id' and
    | 'label_request_id' will be included as labels, while others won't.
    |
    | Set to empty string ('') to use the traditional 'labels' key approach.
    |
    | Note: Labels with null or empty values are automatically excluded.
    | Important: Use a different prefix than structured_metadata_prefix to
    | avoid fields being added as both labels and structured metadata.
    |
    */
    'labels_prefix' => env('LOKI_LABELS_PREFIX', 'label_'),
];
