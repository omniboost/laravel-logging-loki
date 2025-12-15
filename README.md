# Laravel Loki Logging Library

A Laravel logging library that sends logs to Grafana Loki using buffered, non-blocking background jobs.

## Features

- ✅ **Dual-Layer Buffering**: In-memory buffer → cache buffer → Loki (optimized performance)
- ✅ **Non-blocking**: Uses Laravel Jobs to send logs in the background
- ✅ **Automatic Flushing**: Flushes based on buffer size or time interval (both layers)
- ✅ **Thread-Safe**: Race-condition protected buffer management ensures no log loss under concurrent access
- ✅ **Reliable Persistence**: Shutdown handlers ensure logs are flushed on process termination
- ✅ **Configurable**: Extensive configuration options for buffer sizes, intervals, labels, etc.
- ✅ **Resilient**: Automatic retries on failure with fixed delay between attempts
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
LOKI_MEMORY_BUFFER_SIZE=10
LOKI_MEMORY_FLUSH_INTERVAL=1.0
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
        'memory_buffer_size' => env('LOKI_MEMORY_BUFFER_SIZE', 10),
        'memory_flush_interval' => env('LOKI_MEMORY_FLUSH_INTERVAL', 1.0),
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

### Dual-Layer Buffering Architecture

This library uses a two-tier buffering system for optimal performance:

1. **In-Memory Buffer** (First Layer - Fast)
   - Logs are first added to an in-memory array
   - Minimal overhead, no I/O operations
   - Flushes to cache when:
     - Memory buffer size threshold reached (default: 10 logs)
     - Memory flush interval elapsed (default: 1.0 seconds)
     - Process termination (`register_shutdown_function`)
     - Handler destructor called

2. **Cache Buffer** (Second Layer - Persistent)
   - In-memory logs are batched and written to cache (Redis/Laravel Cache)
   - Thread-safe with locks for concurrent access
   - Flushes to queue when:
     - Cache buffer size threshold reached (default: 100 logs)
     - Cache flush interval elapsed (default: 5.0 seconds)

3. **Job Dispatching**: Cache buffer dispatches logs to Laravel Job for async processing

4. **Background Processing**: The job sends logs to Loki via HTTP in the background

5. **Retries**: If sending fails, the job retries up to 3 times with a 10-second fixed delay

### The SendLogsToLoki Job

The `SendLogsToLoki` job is the core component that handles asynchronous log transmission to Grafana Loki. When the cache buffer reaches its threshold or flush interval, logs are dispatched to this Laravel queue job for background processing.

**Key Features:**
- **Implements `ShouldQueue`**: Runs asynchronously in the background via Laravel's queue system
- **Automatic Retries**: Configures 3 retry attempts with a 10-second fixed delay between retries
- **Batch Processing**: Groups multiple log entries by labels into Loki streams for efficient transmission
- **Structured Metadata Support**: Automatically includes structured metadata (if present) as the third element in Loki's values array
- **Error Handling**: Logs failures to your default Laravel log channel when debug mode is enabled

**Job Configuration:**
```php
public int $tries = 3;        // Retry up to 3 times on failure
public int $backoff = 10;     // Wait 10 seconds between retries (fixed delay)
```

**Queue Selection:**
The job uses the queue specified in your `config/loki.php` configuration:
```php
$this->onQueue(config('loki.queue', 'default'));
```

**Stream Preparation:**
The job intelligently groups logs with identical labels into a single Loki stream, reducing the number of API calls and improving efficiency. Each log entry is formatted according to Loki's Push API format:
- Without structured metadata: `[timestamp, message]`
- With structured metadata: `[timestamp, message, structuredMetadata]`

### Benefits of Dual-Layer Buffering

- **Better Performance**: Reduces cache write operations by batching logs in memory first
- **No Log Loss**: Shutdown handlers ensure memory buffer is flushed on process end
- **Lower Latency**: In-memory operations are much faster than cache I/O
- **Reduced Infrastructure Load**: Fewer writes to Redis/cache layer

## Configuration Options

| Option | Description | Default |
|--------|-------------|---------|
| `url` | Loki server URL | `http://localhost:3100` |
| `username` | Basic auth username (optional) | `null` |
| `password` | Basic auth password (optional) | `null` |
| `memory_buffer_size` | Logs to buffer in memory before cache write | `10` |
| `memory_flush_interval` | Seconds before flushing memory buffer | `1.0` |
| `buffer_size` | Logs to buffer in cache before queue dispatch | `100` |
| `flush_interval` | Seconds before flushing cache buffer | `5.0` |
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

### Monitoring Queue Jobs

Monitor the `SendLogsToLoki` jobs in your queue to ensure logs are being processed:

**View all queued jobs:**
```bash
# For database queue driver
php artisan queue:monitor

# Or use Laravel Horizon (if installed)
php artisan horizon:list
```

**Check failed jobs:**
```bash
php artisan queue:failed
```

**Retry failed jobs:**
```bash
# Retry all failed jobs
php artisan queue:retry all

# Retry a specific job by ID
php artisan queue:retry 5
```

**View job statistics:**
```bash
# For Laravel Horizon users
php artisan horizon:stats
```

**Debug job execution:**
Enable debug mode in your `.env` to see detailed logs about job execution:
```env
LOKI_DEBUG=true
```

When debug mode is enabled, the `SendLogsToLoki` job logs:
- The number of streams being sent
- The total number of log entries
- The Loki URL being used
- Any errors or failures during transmission

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

Adjust buffer sizes to reduce memory footprint:

```env
LOKI_MEMORY_BUFFER_SIZE=5
LOKI_MEMORY_FLUSH_INTERVAL=0.5
LOKI_BUFFER_SIZE=50
LOKI_FLUSH_INTERVAL=3.0
```

**Note**: The memory buffer is typically very small (10 logs default), so memory usage is minimal. If you're experiencing high memory, it's more likely from the cache buffer or other application code.

### Job failures

The `SendLogsToLoki` job may fail for several reasons. Here's how to diagnose and resolve them:

**Common failure reasons:**
1. **Loki server is unreachable** - Network issues or incorrect URL
2. **Authentication failure** - Wrong username/password
3. **Invalid log format** - Malformed log entries
4. **Loki API errors** - Server-side errors (500, 503, etc.)

**Check failed jobs:**

```bash
php artisan queue:failed
```

**View detailed failure information:**
Enable debug mode to get detailed error logs:
```env
LOKI_DEBUG=true
```

The job's `failed()` method logs comprehensive error information including:
- Error message
- Number of streams being sent
- Total number of log entries
- Loki URL

**Retry failed jobs:**

```bash
# Retry all failed jobs
php artisan queue:retry all

# Retry a specific job by ID
php artisan queue:retry 5

# Flush all failed jobs (delete them)
php artisan queue:flush
```

**Prevent job failures:**
1. Ensure Loki server is running and accessible
2. Verify authentication credentials are correct
3. Use appropriate buffer sizes to avoid overwhelming Loki
4. Monitor your queue worker's health with Supervisor or similar tools
5. Consider using Laravel Horizon for better job monitoring in production

### Queue worker not processing jobs

If logs are not appearing in Loki, the queue worker might not be running:

**Check if queue worker is running:**
```bash
# Check running processes
ps aux | grep "queue:work"

# For Horizon users
php artisan horizon:status
```

**Start the queue worker:**
```bash
# Basic queue worker
php artisan queue:work

# With specific options
php artisan queue:work --queue=default --tries=3 --timeout=90

# For Horizon
php artisan horizon
```

**Common issues:**
1. **Worker not running** - Start it with `php artisan queue:work`
2. **Wrong queue name** - Ensure the worker listens to the queue specified in `LOKI_QUEUE`
3. **Worker crashed** - Check logs and restart with a process monitor (Supervisor)
4. **Jobs stuck in queue** - Restart the queue worker to process pending jobs

**Best practices:**
- Always use a process monitor (Supervisor, systemd) in production
- Monitor queue worker health and restart automatically on failure
- Use `--tries=3` to retry failed jobs automatically
- Consider using Laravel Horizon for Redis queues with advanced monitoring

## Architecture

```
┌─────────────────────┐
│    Your Laravel     │
│    Application      │
└──────┬──────────────┘
       │ Log::info('message', ['context'])
       ▼
┌──────────────────────────────────────────────┐
│         LokiBufferedHandler                  │
│                                              │
│  ┌──────────────────────────────────────┐   │
│  │     In-Memory Buffer (Layer 1)       │   │
│  │  • Fast array in PHP process memory  │   │
│  │  • Size: 10 logs (configurable)      │   │
│  │  • Interval: 1.0s (configurable)     │   │
│  │  • Shutdown handler ensures flush    │   │
│  └──────┬───────────────────────────────┘   │
│         │ Memory threshold reached/elapsed   │
│         ▼                                    │
│  ┌──────────────────────────────────────┐   │
│  │     Cache Buffer (Layer 2)           │   │
│  │  • Persistent storage (Redis/Cache)  │   │
│  │  • Size: 100 logs (configurable)     │   │
│  │  • Interval: 5.0s (configurable)     │   │
│  │  • Thread-safe with locks            │   │
│  └──────┬───────────────────────────────┘   │
└─────────┼────────────────────────────────────┘
          │ Cache threshold reached/elapsed
          │ Dispatch job to queue
          ▼
┌──────────────────────────────────────────────┐
│         Laravel Queue System                 │
│  ┌────────────────────────────────────────┐ │
│  │      SendLogsToLoki Job                │ │
│  │                                        │ │
│  │  • Implements ShouldQueue             │ │
│  │  • Retries: 3 attempts                │ │
│  │  • Backoff: 10s fixed delay         │ │
│  │  • Groups logs by labels into streams │ │
│  │  • Formats for Loki Push API          │ │
│  └────────┬───────────────────────────────┘ │
└───────────┼──────────────────────────────────┘
            │ Background worker processes job
            ▼
┌──────────────────────────────────────────────┐
│              LokiClient                      │
│  • Sends HTTP POST to Loki Push API         │
│  • Handles basic authentication             │
│  • Returns success/failure status           │
└──────┬───────────────────────────────────────┘
       │ HTTP POST /loki/api/v1/push
       ▼
┌──────────────────────┐
│   Grafana Loki       │
│   Server             │
└──────────────────────┘
```

**Flow Summary:**
1. **Application logs** → In-Memory Buffer (fast, no I/O)
2. **Memory buffer flushes** → Cache Buffer (persistent, Redis/Laravel Cache)
3. **Cache buffer flushes** → Dispatches `SendLogsToLoki` job to queue
4. **Queue worker picks up job** → Processes asynchronously in background
5. **Job sends logs** → HTTP POST to Loki Push API via `LokiClient`
6. **Retry on failure** → Up to 3 attempts with 10s fixed delay between retries

## Performance Considerations

### Dual-Layer Buffer Tuning

- **Memory Buffer**: Small, fast, in-process (default: 10 logs, 1s)
  - Increase size for better batching: `LOKI_MEMORY_BUFFER_SIZE=20`
  - Decrease interval for more real-time: `LOKI_MEMORY_FLUSH_INTERVAL=0.5`
  
- **Cache Buffer**: Larger, persistent, cross-process (default: 100 logs, 5s)
  - Increase size for fewer queue jobs: `LOKI_BUFFER_SIZE=200`
  - Decrease interval for more frequent flushing: `LOKI_FLUSH_INTERVAL=3.0`

- **Trade-offs**:
  - Larger buffers = fewer writes to cache/queue but slightly higher memory usage
  - Shorter intervals = more real-time logs but more overhead
  - Dual buffering reduces cache writes by ~10x (default: 10 logs batched per cache write)

- **Queue**: Use Redis or database queue for reliability in production
- **Non-blocking**: All Loki communication happens in background jobs

## Thread Safety and Concurrency

This library is designed to handle high-concurrency scenarios safely:

### In-Memory Buffer (First Layer)
- **Process-Local**: Each PHP process has its own in-memory buffer (no cross-process conflicts)
- **No Locking Needed**: Since it's in-process, no locks are required
- **Reliable Flushing**: Shutdown handlers and destructors ensure buffer is flushed on process termination

### Cache Buffer (Second Layer)
- **Atomic Buffer Operations (Redis)**: When using Redis as the cache driver, atomic extraction and clearing of the buffer is guaranteed using Redis operations. For non-Redis cache drivers, exclusive locks are used to provide thread safety.
- **No Log Loss (Redis)**: When using Redis as the cache driver, logs arriving during a flush operation are safely stored in a fresh buffer and are not lost, thanks to atomic operations.
- **Caveat for Non-Redis Cache Drivers**: With non-Redis cache drivers, while exclusive locks ensure thread-safe buffer access, there is a minimal risk of log loss under extreme concurrency scenarios. For strict "no log loss" guarantees, use Redis as your cache driver.
- **Lock-based Coordination**: Distributed locks prevent multiple processes from accessing the cache buffer simultaneously
- **Redis Optimized**: When using Redis as the cache driver, atomic operations provide better performance without lock contention

**Best Practices for High-Traffic Applications:**
1. Use Redis as your cache driver for better performance under high concurrency
2. Configure appropriate buffer sizes to balance between API calls and memory usage
3. Monitor your queue workers to ensure logs are processed timely
4. Consider using multiple queue workers to handle high log volumes

## Requirements

- PHP 8.2 or higher
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

This project uses GitHub Actions for continuous integration. The test suite automatically runs on all pull requests against multiple PHP versions (8.2, 8.3) and Laravel versions (10.x, 11.x).

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
