# Laravel Loki Logging Library

A Laravel logging library that sends logs to Grafana Loki using buffered, non-blocking background jobs.

## Features

- ✅ **Dual-Layer Buffering**: In-memory buffer → cache buffer → Loki (optimized performance)
- ✅ **Non-blocking**: Uses Laravel Jobs to send logs in the background
- ✅ **Automatic Flushing**: Flushes based on buffer size or time interval (both layers)
- ✅ **Thread-Safe**: Race-condition protected buffer management ensures no log loss under concurrent access
- ✅ **Reliable Persistence**: Shutdown handlers ensure logs are flushed on process termination
- ✅ **GZIP Compression**: Reduces payload size to send more data per request
- ✅ **Configurable**: Extensive configuration options for buffer sizes, intervals, labels, etc.
- ✅ **Resilient**: Automatic retries on failure with exponential backoff
- ✅ **Label Support**: Add custom labels for better log organization in Grafana
- ✅ **Debug Mode**: Optional debug logging for troubleshooting
- ✅ **Typed Exceptions**: `LokiClient::push()` reports the failures it classifies as a `LokiException`, so consumers can handle Loki push problems specifically

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
LOKI_GZIP_COMPRESSION=true
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
        'gzip_compression' => env('LOKI_GZIP_COMPRESSION', true),
        'structured_metadata_prefix' => env('LOKI_STRUCTURED_METADATA_PREFIX', ''),
        'labels_prefix' => env('LOKI_LABELS_PREFIX', 'label_'),
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

You can add custom labels to individual log entries. Labels are indexed by Loki and enable fast filtering and querying in Grafana.

Labels are extracted from **both** the log's `context` and `extra` fields that start with the configured prefix (default: `label_`):

```php
// Default behavior (LOKI_LABELS_PREFIX=label_)
Log::info('Payment processed', [
    'label_user_id' => 456,
    'label_endpoint' => '/api/payment',
    'label_method' => 'POST',
    'internal_flag' => true,  // Not included (no prefix)
]);
// user_id, endpoint, and method will be added as labels (prefix removed)
```

**Key Features:**
- Labels are extracted from **both** `context` and `extra` arrays  
- If the same label key exists in both, the `extra` value takes precedence
- Labels with `null` or empty string values are automatically excluded
- The prefix is removed from the label name in Loki
- Labels are indexed and enable fast queries like `{endpoint="/api/payment"}`

**Important:** Use a different prefix for labels (`label_` by default) than for structured metadata (empty by default) to avoid fields being added as both labels and structured metadata.

**Common Use Cases:**
- HTTP request metadata: `label_endpoint`, `label_method`, `label_status_code`
- Application components: `label_service`, `label_component`, `label_module`
- Environment context: `label_datacenter`, `label_region`, `label_instance`

### Adding Structured Metadata

You can include additional context data as structured metadata in your logs. The `structured_metadata_prefix` configuration controls how structured metadata is extracted from the log context and extra fields:

**Key Features:**
- Structured metadata is extracted from **both** the log's `context` and `extra` arrays
- If the same key exists in both, the `extra` value takes precedence
- Values with `null` or empty strings are automatically excluded from structured metadata

#### Include All Context as Structured Metadata (Default)

When `structured_metadata_prefix` is empty (default), all context and extra data (except `labels`) is added as structured metadata:

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

#### Using Context and Extra Fields

Both structured metadata and labels support extraction from the log's `context` and `extra` arrays. This is particularly useful when working with Laravel's logging system or Monolog processors that add data to the `extra` field.

```php
// Example: Using context (standard logging)
Log::info('User logged in', [
    'user_id' => 123,
    'label_endpoint' => '/login',
]);

// Example: Extra fields (added by processors or internally)
// When using Monolog processors, data may be added to 'extra'
// This library automatically merges both context and extra for extraction
```

**Precedence:** If the same key exists in both `context` and `extra`, the value from `extra` takes precedence.

### GZIP Compression

This library supports GZIP compression for data sent to Grafana Loki, which significantly reduces payload size and allows more data to be sent per request. This helps maximize usage of Grafana's request size limits.

#### Benefits

- **Reduced Payload Size**: GZIP compression typically reduces JSON payloads by 80-95%
- **More Data Per Request**: Send more log entries within Grafana's size limits
- **Reduced Network Overhead**: Smaller payloads mean faster transmission
- **Lower Bandwidth Costs**: Especially beneficial for high-volume logging

#### Configuration

GZIP compression is **enabled by default**. You can control it via configuration:

```env
# Enable compression (default)
LOKI_GZIP_COMPRESSION=true

# Disable compression if needed
LOKI_GZIP_COMPRESSION=false
```

Or in your `config/logging.php`:

```php
'loki' => [
    'driver' => 'loki',
    'url' => env('LOKI_URL'),
    'gzip_compression' => env('LOKI_GZIP_COMPRESSION', true),
    // ... other options
],
```

#### When to Disable Compression

You might want to disable GZIP compression in these scenarios:

- **Debugging**: To inspect raw payloads in network monitoring tools
- **Compatibility**: If using a proxy or gateway that doesn't support gzip encoding
- **Very Small Payloads**: Compression overhead might not be worth it for tiny log volumes (rare)

**Note**: Most Grafana Loki instances support and recommend GZIP compression. The library automatically sets the appropriate `Content-Encoding: gzip` header when compression is enabled.

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

5. **Retries**: If sending fails, the job retries up to 3 times with exponential backoff

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
| `gzip_compression` | Enable GZIP compression for data sent to Loki | `true` |
| `memory_buffer_size` | Logs to buffer in memory before cache write | `10` |
| `memory_flush_interval` | Seconds before flushing memory buffer | `1.0` |
| `buffer_size` | Logs to buffer in cache before queue dispatch | `100` |
| `flush_interval` | Seconds before flushing cache buffer | `5.0` |
| `queue` | Queue to use for background jobs | `default` |
| `level` | Minimum log level | `debug` |
| `debug` | Enable debug logging | `false` |
| `labels` | Default labels for all logs | `['app', 'env', 'server']` |
| `structured_metadata_prefix` | Prefix for extracting structured metadata from context | `''` (empty = all context) |
| `labels_prefix` | Prefix for extracting labels from context | `'label_'` |

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

## Manual Log Flushing

### Flush Command

The package provides a command to manually flush all buffered Loki logs to ensure they are sent immediately. This is useful in scenarios where you need to guarantee logs are sent before an application shutdown or deployment.

#### Running the Flush Command

```bash
php artisan omniboost:loki:flush
```

This command will:
1. Discover all logging channels configured with the `omniboost:loki` driver
2. Locate all `LokiBufferedLogger` handlers in those channels
3. Flush both the in-memory buffer and cache buffer for each handler
4. Dispatch jobs immediately to send logs to Loki

**When to use this command:**
- Before application deployment or shutdown
- After critical log events that must be sent immediately
- When debugging to ensure recent logs are visible in Grafana
- In testing environments to verify log delivery

**Example output:**
```
[loki] Flushing...
[api-loki] Flushing...
```

### Scheduling the Flush Command

You can schedule the flush command to run periodically using Laravel's Task Scheduler. This ensures logs are sent to Loki at regular intervals, even if buffer thresholds aren't reached.

#### Basic Scheduling

Add the following to your `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule): void
{
    // Flush Loki logs every minute
    $schedule->command('omniboost:loki:flush')
             ->everyMinute();
}
```

#### Advanced Scheduling Examples

**Flush every 5 minutes:**
```php
$schedule->command('omniboost:loki:flush')
         ->everyFiveMinutes();
```

**Flush every hour:**
```php
$schedule->command('omniboost:loki:flush')
         ->hourly();
```

**Flush at specific times:**
```php
// Flush every day at midnight
$schedule->command('omniboost:loki:flush')
         ->dailyAt('00:00');
```

**Flush only during business hours:**
```php
$schedule->command('omniboost:loki:flush')
         ->everyFiveMinutes()
         ->between('8:00', '17:00')
         ->weekdays();
```

**Flush with output logging:**
```php
$schedule->command('omniboost:loki:flush')
         ->everyMinute()
         ->appendOutputTo(storage_path('logs/loki-flush.log'));
```

**Prevent overlapping flushes:**
```php
$schedule->command('omniboost:loki:flush')
         ->everyMinute()
         ->withoutOverlapping()
         ->runInBackground();
```

#### When to Schedule Flushes

**High-frequency scenarios (every 1-5 minutes):**
- Production environments with critical logging requirements
- When real-time log visibility is important
- Applications with unpredictable traffic patterns
- When buffer thresholds may not be reached regularly

**Low-frequency scenarios (hourly or daily):**
- Development environments
- Applications with consistent high traffic (buffers fill naturally)
- When queue workers handle flushing adequately
- Cost-sensitive environments (fewer API calls to Loki)

**Considerations:**
- Scheduled flushes work alongside automatic buffer-based flushing
- More frequent flushes = more real-time logs but more queue jobs
- Less frequent flushes = reduced overhead but potential log delay
- Monitor your queue depth to find the right balance

### Programmatic Flushing

You can also flush logs programmatically in your application code:

```php
use Illuminate\Support\Facades\Artisan;

// Flush all Loki logs
Artisan::call('omniboost:loki:flush');
```

Or flush a specific channel:

```php
use Illuminate\Support\Facades\Log;

// Get the logger and flush directly
$logger = Log::channel('loki')->getLogger();
foreach ($logger->getHandlers() as $handler) {
    if ($handler instanceof \Omniboost\LaravelLoggingLoki\Logging\LokiBufferedLogger) {
        $handler->flush();
    }
}
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

## Exception Handling

The failures `LokiClient::push()` classifies — a payload it could not encode, a
response Loki did not acknowledge with, a Loki it could not reach — are thrown as
exceptions implementing
`Omniboost\LaravelLoggingLoki\Exceptions\LokiException`, so you can single out
a failed push instead of catching `\Exception` or matching on messages:

```php
use Omniboost\LaravelLoggingLoki\Exceptions\LokiException;

try {
    $client->push($streams);
} catch (LokiException $e) {
    // Any push failure this client classifies
}
```

### The hierarchy

| Exception | Thrown when |
|-----------|-------------|
| `LokiException` (interface) | Marker implemented by all of the below |
| `LokiPushException` | Base class for any failed push |
| `LokiPayloadException` | The payload could not be JSON-encoded or GZIP-compressed locally — nothing was sent |
| `LokiResponseException` | Loki answered but did not acknowledge the push (401, 404, 429, 5xx, or any non-204/200) |
| `LokiConnectionException` | Loki could not be reached at all (DNS, connection refused, TLS, timeout) |

All of them extend `\RuntimeException`, so existing `catch (\RuntimeException $e)`
code around a push keeps working.

The guarantee is scoped to the `LokiClient::push()` API boundary and to those
three cases. Other code paths in the package are not wrapped: the buffered
handler and the shutdown flusher swallow or report their own errors rather than
throwing, and a native error raised somewhere else — a cache or Redis driver
failure, a `\TypeError`, an `\Error` from PHP itself — is *not* converted into a
`LokiException`. Catch `LokiException` to handle a failed push; keep a broader
`\Throwable` handler for everything else.

### Reacting per failure type

`LokiResponseException` carries the status and body Loki returned, plus
`isRetryable()` (true for 429 and 5xx). `LokiConnectionException` exposes the URL
it tried:

```php
use Omniboost\LaravelLoggingLoki\Exceptions\LokiConnectionException;
use Omniboost\LaravelLoggingLoki\Exceptions\LokiPayloadException;
use Omniboost\LaravelLoggingLoki\Exceptions\LokiResponseException;

try {
    $client->push($streams);
} catch (LokiResponseException $e) {
    if ($e->getStatusCode() === 429) {
        // Rate limited — back off
    } elseif ($e->getStatusCode() === 401) {
        // Credentials are wrong — alert, retrying will not help
    }

    report($e->getResponseBody());
} catch (LokiConnectionException $e) {
    // Loki is unreachable at $e->getUrl()
} catch (LokiPayloadException $e) {
    // A log line could not be encoded — retrying the same payload will fail again
}
```

The original Guzzle exception, when there is one, is available through
`$e->getPrevious()`.

### In the queue job

`SendLogsToLoki` decides whether a failed push is worth retrying, using the same
distinction `isRetryable()` makes:

- **Transient** — Loki unreachable, a 429, a 5xx — the exception propagates, so
  the queue retries the push (3 tries, 10s backoff) and records the real reason
  in `failed_jobs` if the retries run out.
- **Permanent** — a payload that could not be encoded, or a rejection like 400,
  401 or 404 — the job is failed immediately. Every attempt would hit the same
  rejection, and retrying would only delay the `failed_jobs` entry by
  `tries × backoff` seconds while the buffer behind it keeps growing.

Either way the job's `failed()` handler runs, so the failure is reported. To
handle these centrally, match on the exception class in your application's
exception handler or in a queue `JobFailed` listener.

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
┌──────────────────────┐
│   In-Memory Buffer   │ ◄─── Fast: 10 logs (1s interval)
└──────┬───────────────┘
       │ Memory buffer full or time elapsed
       ▼
┌──────────────────────┐
│    Cache Buffer      │ ◄─── Persistent: Redis/Cache (100 logs, 5s interval)
│  (Redis/Laravel)     │
└──────┬───────────────┘
       │ Cache buffer full or time elapsed
       ▼
┌──────────────────────┐
│  SendLogsToLoki Job  │ ◄─── Laravel Job (queued)
└──────┬───────────────┘
       │ Background processing
       ▼
┌──────────────────────┐
│     LokiClient       │ ◄─── HTTP client
└──────┬───────────────┘
       │ HTTP POST
       ▼
┌──────────────────────┐
│   Grafana Loki       │
└──────────────────────┘
```

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
