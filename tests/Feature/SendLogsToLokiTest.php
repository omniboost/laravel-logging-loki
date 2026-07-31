<?php

namespace Omniboost\LaravelLoggingLoki\Tests\Feature;

use Omniboost\LaravelLoggingLoki\DTOs\LokiLogEntry;
use Omniboost\LaravelLoggingLoki\Jobs\SendLogsToLoki;
use Omniboost\LaravelLoggingLoki\LokiServiceProvider;
use Omniboost\LaravelLoggingLoki\Support\ShutdownFlusher;
use Orchestra\Testbench\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Feature Tests for Loki Stream Preparation with Structured Metadata
 * 
 * These tests verify that log entries are correctly formatted into Loki streams
 * with structured metadata as the optional third element in the values array.
 */
class SendLogsToLokiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        fwrite(STDERR, "\n");
    }

    protected function tearDown(): void
    {
        // Flush all handler instances while the application is still available.
        // Anything left behind is skipped by the shutdown flusher rather than
        // failing, since it no longer touches the container once the application
        // is gone.
        ShutdownFlusher::flushAll();

        parent::tearDown();
    }

    /**
     * Get package providers
     */
    protected function getPackageProviders($app): array
    {
        return [LokiServiceProvider::class];
    }

    /**
     * Configure environment for testing
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('loki.url', 'http://localhost:3100');
        $app['config']->set('loki.queue', 'sync');
        $app['config']->set('loki.debug', false);
    }

    /**
     * Create a partial mock of SendLogsToLoki that doesn't call the constructor
     */
    private function createJobMock(): SendLogsToLoki
    {
        // Create instance without calling constructor to avoid config() call
        $reflection = new ReflectionClass(SendLogsToLoki::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        
        return $instance;
    }

    private function invokePrivateMethod($object, $methodName, array $parameters = [])
    {
        $reflection = new ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }

    /**
     * Test: Log entries without structured metadata use 2-element format
     * 
     * When a log entry has no structured metadata, the Loki values array
     * should contain only [timestamp, line] (2 elements).
     */
    public function testPrepareStreamsWithoutStructuredMetadata()
    {
        fwrite(STDERR, "  → Testing stream without structured metadata (2-element format)...\n");
        $entries = [
            new LokiLogEntry(
                stream: ['level' => 'info', 'app' => 'test'],
                entry: 'Simple log message',
                timestamp: '1234567890000000000',
                structuredMetadata: []
            ),
        ];

        $job = $this->createJobMock();
        $streams = $this->invokePrivateMethod($job, 'prepareStreams', [$entries]);

        $this->assertIsArray($streams);
        $this->assertCount(1, $streams);
        $this->assertArrayHasKey('stream', $streams[0]->toArray());
        $this->assertArrayHasKey('values', $streams[0]->toArray());
        
        $values = $streams[0]->toArray()['values'];
        $this->assertCount(1, $values);
        $this->assertCount(2, $values[0]); // Only timestamp and message
        $this->assertEquals('1234567890000000000', $values[0][0]);
        $this->assertEquals('Simple log message', $values[0][1]);
        
        fwrite(STDERR, "    ✓ Stream prepared with 2-element values array [timestamp, line]\n");
    }

    /**
     * Test: Log entries with structured metadata use 3-element format
     * 
     * When a log entry has structured metadata, the Loki values array
     * should contain [timestamp, line, structuredMetadata] (3 elements).
     */
    public function testPrepareStreamsWithStructuredMetadata()
    {
        fwrite(STDERR, "  → Testing stream with structured metadata (3-element format)...\n");
        $entries = [
            new LokiLogEntry(
                stream: ['level' => 'info', 'app' => 'test'],
                entry: 'Log with metadata',
                timestamp: '1234567890000000001',
                structuredMetadata: ['user_id' => '123', 'request_id' => 'abc-123']
            ),
        ];

        $job = $this->createJobMock();
        $streams = $this->invokePrivateMethod($job, 'prepareStreams', [$entries]);

        $this->assertIsArray($streams);
        $this->assertCount(1, $streams);
        
        $values = $streams[0]->toArray()['values'];
        $this->assertCount(1, $values);
        $this->assertCount(3, $values[0]); // Timestamp, message, and structured metadata
        $this->assertEquals('1234567890000000001', $values[0][0]);
        $this->assertEquals('Log with metadata', $values[0][1]);
        $this->assertIsArray($values[0][2]);
        $this->assertArrayHasKey('user_id', $values[0][2]);
        $this->assertArrayHasKey('request_id', $values[0][2]);
        $this->assertEquals('123', $values[0][2]['user_id']);
        $this->assertEquals('abc-123', $values[0][2]['request_id']);
        
        fwrite(STDERR, "    ✓ Stream prepared with 3-element values array [timestamp, line, metadata]\n");
    }

    /**
     * Test: Mixed entries with and without metadata in same stream
     * 
     * A single stream can contain both 2-element entries (no metadata) and
     * 3-element entries (with metadata) based on individual log entries.
     */
    public function testPrepareStreamsMixedEntriesWithAndWithoutMetadata()
    {
        fwrite(STDERR, "  → Testing mixed entries (some with, some without metadata)...\n");
        $entries = [
            new LokiLogEntry(
                stream: ['level' => 'info'],
                entry: 'First message',
                timestamp: '1234567890000000000',
                structuredMetadata: ['user_id' => '123']
            ),
            new LokiLogEntry(
                stream: ['level' => 'info'],
                entry: 'Second message',
                timestamp: '1234567890000000001',
                structuredMetadata: ['user_id' => '456', 'action' => 'login']
            ),
            new LokiLogEntry(
                stream: ['level' => 'info'],
                entry: 'Third message without metadata',
                timestamp: '1234567890000000002',
                structuredMetadata: []
            ),
        ];

        $job = $this->createJobMock();
        $streams = $this->invokePrivateMethod($job, 'prepareStreams', [$entries]);

        $this->assertIsArray($streams);
        $this->assertCount(1, $streams); // All have same stream labels
        
        $values = $streams[0]->toArray()['values'];
        $this->assertCount(3, $values);
        
        // First entry with metadata
        $this->assertCount(3, $values[0]);
        $this->assertIsArray($values[0][2]);
        $this->assertArrayHasKey('user_id', $values[0][2]);
        
        // Second entry with metadata
        $this->assertCount(3, $values[1]);
        $this->assertIsArray($values[1][2]);
        $this->assertArrayHasKey('user_id', $values[1][2]);
        $this->assertArrayHasKey('action', $values[1][2]);
        
        // Third entry without metadata
        $this->assertCount(2, $values[2]);
        
        fwrite(STDERR, "    ✓ Mixed entries correctly formatted (3-element and 2-element)\n");
    }

    /**
     * Test: Streams are grouped by their label sets
     * 
     * Log entries with different label combinations should be grouped into
     * separate streams. This test verifies proper stream grouping logic.
     */
    public function testPrepareStreamsGroupsByStreamLabels()
    {
        fwrite(STDERR, "  → Testing stream grouping by labels...\n");
        $entries = [
            new LokiLogEntry(
                stream: ['level' => 'info'],
                entry: 'Info message',
                timestamp: '1234567890000000000',
                structuredMetadata: ['user_id' => '123']
            ),
            new LokiLogEntry(
                stream: ['level' => 'error'],
                entry: 'Error message',
                timestamp: '1234567890000000001',
                structuredMetadata: ['user_id' => '456']
            ),
            new LokiLogEntry(
                stream: ['level' => 'info'],
                entry: 'Another info message',
                timestamp: '1234567890000000002',
                structuredMetadata: ['user_id' => '789']
            ),
        ];

        $job = $this->createJobMock();
        $streams = $this->invokePrivateMethod($job, 'prepareStreams', [$entries]);

        $this->assertIsArray($streams);
        $this->assertCount(2, $streams); // Two different stream labels
        
        // Find info and error streams
        $infoStream = null;
        $errorStream = null;
        
        foreach ($streams as $stream) {
            $streamData = $stream->toArray();
            if ($streamData['stream']['level'] === 'info') {
                $infoStream = $streamData;
            } elseif ($streamData['stream']['level'] === 'error') {
                $errorStream = $streamData;
            }
        }
        
        $this->assertNotNull($infoStream);
        $this->assertNotNull($errorStream);
        $this->assertCount(2, $infoStream['values']); // Two info messages
        $this->assertCount(1, $errorStream['values']); // One error message
        
        fwrite(STDERR, "    ✓ Entries correctly grouped into separate streams by labels\n");
    }

    /**
     * Test: Complex structured metadata with multiple fields
     * 
     * This test validates that complex, real-world structured metadata
     * with many fields is correctly included in the stream values.
     */
    public function testPrepareStreamsWithComplexStructuredMetadata()
    {
        fwrite(STDERR, "  → Testing complex structured metadata scenario...\n");
        $entries = [
            new LokiLogEntry(
                stream: ['level' => 'info'],
                entry: 'Payment processed',
                timestamp: '1234567890000000000',
                structuredMetadata: [
                    'transaction_id' => 'txn_123',
                    'amount' => '99.99',
                    'currency' => 'USD',
                    'customer_id' => 'cust_456',
                    'tags' => '["payment","successful"]',
                    'is_refundable' => 'true',
                ]
            ),
        ];

        $job = $this->createJobMock();
        $streams = $this->invokePrivateMethod($job, 'prepareStreams', [$entries]);

        $this->assertIsArray($streams);
        $values = $streams[0]->toArray()['values'];
        
        $this->assertCount(3, $values[0]);
        $metadata = $values[0][2];
        
        $this->assertArrayHasKey('transaction_id', $metadata);
        $this->assertArrayHasKey('amount', $metadata);
        $this->assertArrayHasKey('currency', $metadata);
        $this->assertArrayHasKey('customer_id', $metadata);
        $this->assertArrayHasKey('tags', $metadata);
        $this->assertArrayHasKey('is_refundable', $metadata);
        
        // All values should be strings (already sanitized)
        foreach ($metadata as $value) {
            $this->assertIsString($value);
        }
        
        fwrite(STDERR, "    ✓ Complex structured metadata correctly included\n");
    }

    /**
     * Test: Empty entries list returns empty streams array
     * 
     * When no log entries are provided, stream preparation should
     * return an empty array without errors.
     */
    public function testPrepareStreamsWithEmptyEntries()
    {
        fwrite(STDERR, "  → Testing empty entries handling...\n");
        
        $job = $this->createJobMock();
        $streams = $this->invokePrivateMethod($job, 'prepareStreams', [[]]);

        $this->assertIsArray($streams);
        $this->assertEmpty($streams);
        
        fwrite(STDERR, "    ✓ Empty entries returns empty streams array\n");
    }
}
