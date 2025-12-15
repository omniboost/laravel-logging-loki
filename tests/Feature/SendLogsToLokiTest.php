<?php

namespace Omniboost\LaravelLoggingLoki\Tests\Feature;

use Omniboost\LaravelLoggingLoki\DTOs\LokiLogEntry;
use Omniboost\LaravelLoggingLoki\Jobs\SendLogsToLoki;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class SendLogsToLokiTest extends TestCase
{
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

    public function testPrepareStreamsWithoutStructuredMetadata()
    {
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
    }

    public function testPrepareStreamsWithStructuredMetadata()
    {
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
    }

    public function testPrepareStreamsMixedEntriesWithAndWithoutMetadata()
    {
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
    }

    public function testPrepareStreamsGroupsByStreamLabels()
    {
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
    }

    public function testPrepareStreamsWithComplexStructuredMetadata()
    {
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
    }

    public function testPrepareStreamsWithEmptyEntries()
    {
        $job = $this->createJobMock();
        $streams = $this->invokePrivateMethod($job, 'prepareStreams', [[]]);

        $this->assertIsArray($streams);
        $this->assertEmpty($streams);
    }
}
