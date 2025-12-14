# Laravel Loki Logging Library

A Laravel logging library that sends logs to Grafana Loki using buffered, non-blocking background jobs.

## Features

- ✅ **Buffered Logging**: Collects logs in memory before sending to reduce API calls
- ✅ **Non-blocking**: Uses Laravel Jobs to send logs in the background
- ✅ **Automatic Flushing**: Flushes based on buffer size or time interval
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

### Contributing

Contributions are welcome! Please follow these guidelines:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

This library is open-source software.

## Credits

- [Bart Versluijs](https://github.com/bartversluijs)
- [Omniboost](https://github.com/omniboost)

## Support

For issues, questions, or contributions, please use the [GitHub issue tracker](https://github.com/omniboost/laravel-logging-loki/issues).
