# Laravel Loki Logging Library

A Laravel logging library that sends logs to Grafana Loki using buffered, non-blocking background jobs.

## Features

- ✅ **Buffered Logging**: Collects logs in memory before sending to reduce API calls
- ✅ **Non-blocking**: Uses Laravel Jobs to send logs in the background
- ✅ **Automatic Flushing**: Flushes based on buffer size or time interval
- ✅ **Thread-Safe**: Race-condition protected buffer management ensures no log loss under concurrent access
- ✅ **Configurable**: Extensive configuration options for buffer size, intervals, labels, etc.
- ✅ **Resilient**: Automatic retries on failure with exponential backoff
- ✅ **Label Support**: Add custom labels for better log organization in Grafana
- ✅ **Debug Mode**: Optional debug logging for troubleshooting

## Installation

1. Add the package to your Laravel application (if not already in your repository):

```bash
composer require omniboost/laravel-logging-loki
```

2. Publish the configuration file:

```bash
php artisan vendor:publish --tag=loki-config
```

3. Configure your `.env` file:

```env
LOKI_URL=http://localhost:3100
LOKI_USERNAME=
LOKI_PASSWORD=
LOKI_BUFFER_SIZE=100
LOKI_FLUSH_INTERVAL=5.0
LOKI_QUEUE=default
LOKI_LOG_LEVEL=debug
LOKI_DEBUG=false
LOKI_STRUCTURED_METADATA_PREFIX=
```

## Configuration

### Basic Setup

Add the Loki channel to your `config/logging.php`:

```php
'channels' => [
    // ... other channels

    'loki' => [
        'driver' => 'loki',
        'url' => env('LOKI_URL', 'http://localhost:3100'),
        'level' => env('LOKI_LOG_LEVEL', 'debug'),
        'buffer_size' => env('LOKI_BUFFER_SIZE', 100),
        'flush_interval' => env('LOKI_FLUSH_INTERVAL', 5.0),
        'username' => env('LOKI_USERNAME'),
        'password' => env('LOKI_PASSWORD'),
        'structured_metadata_prefix' => env('LOKI_STRUCTURED_METADATA_PREFIX', ''),
        'labels' => [
            'app' => env('APP_NAME', 'laravel'),
            'env' => env('APP_ENV', 'production'),
            'server' => gethostname(),
        ],
    ],

    // Stack multiple channels together
    'stack' => [
        'driver' => 'stack',
        'channels' => ['single', 'loki'],
        'ignore_exceptions' => false,
    ],
],
```

### Using Multiple Channels

You can use Loki alongside your existing logging:

```php
'channels' => [
    'default' => [
        'driver' => 'stack',
        'channels' => ['daily', 'loki'],
    ],
],
```

## Usage

### Basic Logging

Once configured, use Laravel's Log facade as normal:

```php
use Illuminate\Support\Facades\Log;

Log::info('User logged in', ['user_id' => 123]);
Log::error('Payment failed', ['order_id' => 456, 'amount' => 99.99]);
Log::warning('High memory usage detected');
```

### Adding Custom Labels

You can add custom labels to individual log entries:

```php
Log::info('API request completed', [
    'labels' => [
        'endpoint' => '/api/users',
        'method' => 'GET',
        'status' => 200,
    ],
    'duration_ms' => 145,
    'user_id' => 789,
]);
```

### Adding Structured Metadata

You can include additional context data as structured metadata in your logs. The `structured_metadata_prefix` configuration controls how structured metadata is extracted from the log context:

#### Include All Context as Structured Metadata (Default)

When `structured_metadata_prefix` is empty (default), all context data (except `labels`) is added as structured metadata:

```php
Log::info('User action', [
    'user_id' => 123,
    'action' => 'login',
    'ip_address' => '192.168.1.1',
]);
// All three fields (user_id, action, ip_address) will be included as structured metadata
```

#### Selective Structured Metadata with Prefix

Set a prefix to only include specific context fields as structured metadata:

```php
// In .env or config
LOKI_STRUCTURED_METADATA_PREFIX=meta_

// In your code
Log::info('Payment processed', [
    'meta_user_id' => 456,
    'meta_order_id' => 789,
    'meta_amount' => 99.99,
    'internal_flag' => true,  // Not included (no prefix)
]);
// Only user_id, order_id, and amount will be included as structured metadata (prefix removed)
```

**Common Use Cases:**
- User identifiers: `meta_user_id`, `meta_username`
- Request tracking: `meta_request_id`, `meta_session_id`
- Business context: `meta_order_id`, `meta_transaction_id`
- Performance metrics: `meta_duration_ms`, `meta_memory_mb`

### Channel-specific Logging

Log only to Loki:

```php
Log::channel('loki')->info('This goes only to Loki');
```

Log to multiple specific channels:

```php
Log::stack(['loki', 'slack'])->critical('Critical error occurred!');
```

## How It Works

1. **Buffering**: When you log something, it's added to an in-memory buffer
2. **Flushing**: The buffer is automatically flushed when:
   - It reaches the configured `buffer_size` (default: 100 logs)
   - The `flush_interval` time passes (default: 5 seconds)
   - The application terminates
3. **Job Dispatching**: When flushed, logs are dispatched to a Laravel Job
4. **Background Processing**: The job sends logs to Loki in the background
5. **Retries**: If sending fails, the job retries up to 3 times with backoff

## Configuration Options

| Option | Description | Default |
|--------|-------------|---------|
| `url` | Loki server URL | `http://localhost:3100` |
| `username` | Basic auth username (optional) | `null` |
| `password` | Basic auth password (optional) | `null` |
| `buffer_size` | Number of logs before flushing | `100` |
| `flush_interval` | Seconds before auto-flush | `5.0` |
| `queue` | Queue to use for background jobs | `default` |
| `level` | Minimum log level | `debug` |
| `debug` | Enable debug logging | `false` |
| `labels` | Default labels for all logs | `['app', 'env', 'server']` |
| `structured_metadata_prefix` | Prefix for extracting structured metadata from context | `''` (empty = all context) |

## Queue Configuration

Ensure your queue worker is running to process the log jobs:

```bash
php artisan queue:work
```

For production, use a process manager like Supervisor:

```ini
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/artisan queue:work --sleep=3 --tries=3
autostart=true
autorestart=true
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/worker.log
```

## Viewing Logs in Grafana

1. Open Grafana and navigate to Explore
2. Select your Loki datasource
3. Use LogQL to query your logs:

```logql
{app="laravel", env="production"}
```

Filter by log level:

```logql
{app="laravel"} |= "level=error"
```

Filter by custom labels:

```logql
{app="laravel", endpoint="/api/users"}
```

## Troubleshooting

### Logs not appearing in Loki

1. Check queue is running: `php artisan queue:work`
2. Enable debug mode: `LOKI_DEBUG=true`
3. Check Laravel logs for errors
4. Verify Loki URL is accessible: `curl http://your-loki-url:3100/ready`

### High memory usage

Reduce buffer size:

```env
LOKI_BUFFER_SIZE=50
LOKI_FLUSH_INTERVAL=3.0
```

### Job failures

Check failed jobs:

```bash
php artisan queue:failed
```

Retry failed jobs:

```bash
php artisan queue:retry all
```

## Architecture

```
┌─────────────┐
│   Your App  │
└──────┬──────┘
       │ Log::info()
       ▼
┌──────────────────┐
│ LokiBufferedHandler │ ◄─── Buffers logs in memory
└──────┬──────────┘
       │ Buffer full or time elapsed
       ▼
┌──────────────────┐
│ SendLogsToLoki   │ ◄─── Laravel Job (queued)
│      Job         │
└──────┬──────────┘
       │ Background processing
       ▼
┌──────────────────┐
│   LokiClient     │ ◄─── HTTP client
└──────┬──────────┘
       │ HTTP POST
       ▼
┌──────────────────┐
│  Grafana Loki    │
└──────────────────┘
```

## Performance Considerations

- **Buffer Size**: Larger buffers = fewer API calls but more memory usage
- **Flush Interval**: Shorter intervals = more real-time but more API calls
- **Queue**: Use Redis or database queue for reliability in production
- **Non-blocking**: All Loki communication happens in background jobs

## Thread Safety and Concurrency

This library is designed to handle high-concurrency scenarios safely:

- **Atomic Buffer Operations (Redis)**: When using Redis as the cache driver, atomic extraction and clearing of the buffer is guaranteed using Redis RENAME. For non-Redis cache drivers, exclusive locks are used to provide thread safety.
- **No Log Loss (Redis)**: When using Redis as the cache driver, logs arriving during a flush operation are safely stored in a fresh buffer and are not lost, thanks to atomic operations.
- **Caveat for Non-Redis Cache Drivers**: With non-Redis cache drivers, while exclusive locks ensure thread-safe buffer access, there is a minimal risk of log loss under extreme concurrency scenarios. For strict "no log loss" guarantees, use Redis as your cache driver.
- **Lock-based Coordination**: Distributed locks prevent multiple processes from accessing the buffer simultaneously
- **Redis Optimized**: When using Redis as the cache driver, atomic operations provide better performance without lock contention

**Best Practices for High-Traffic Applications:**
1. Use Redis as your cache driver for better performance under high concurrency
2. Configure appropriate buffer sizes to balance between API calls and memory usage
3. Monitor your queue workers to ensure logs are processed timely
4. Consider using multiple queue workers to handle high log volumes

## Requirements

- PHP 8.1 or higher
- Laravel 10.x or 11.x
- A running Grafana Loki instance
- Laravel Queue configured (database, Redis, etc.)

## Development

### Running Tests

```bash
composer install
./vendor/bin/phpunit
```

Or use the composer script:

```bash
composer test
```

### Continuous Integration

This project uses GitHub Actions for continuous integration. The test suite automatically runs on all pull requests against multiple PHP versions (8.1, 8.2, 8.3) and Laravel versions (10.x, 11.x).

**Status checks are required to pass before merging pull requests.**

The CI workflow:
- Tests against all supported PHP and Laravel version combinations
- Installs dependencies and runs the full PHPUnit test suite
- Caches dependencies for faster builds
- Runs automatically on every pull request and push to main, master, or develop branches

You can view the workflow configuration in `.github/workflows/tests.yml`.

### Contributing

Contributions are welcome! Please follow these guidelines:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

**Note**: All pull requests must pass the automated test suite before they can be merged.

## License

This library is open-source software.

## Credits

- [Bart Versluijs](https://github.com/bartversluijs)
- [Omniboost](https://github.com/omniboost)

## Support

For issues, questions, or contributions, please use the [GitHub issue tracker](https://github.com/omniboost/laravel-logging-loki/issues).
