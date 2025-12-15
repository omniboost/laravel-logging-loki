<?php

namespace Omniboost\LaravelLoggingLoki\Tests\Unit;

use Monolog\Level;
use Monolog\LogRecord;
use Omniboost\LaravelLoggingLoki\DTOs\LokiLogEntry;
use Omniboost\LaravelLoggingLoki\Logging\LokiBufferedHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use DateTime;

class StructuredMetadataTest extends TestCase
{
    private function invokePrivateMethod($object, $methodName, array $parameters = [])
    {
        $reflection = new ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }

    private function createHandler(string $prefix = ''): LokiBufferedHandler
    {
        return new LokiBufferedHandler(
            url: 'http://localhost:3100',
            level: Level::Debug->value,
            bufferSize: 100,
            flushInterval: 5.0,
            defaultLabels: ['app' => 'test'],
            username: null,
            password: null,
            structuredMetadataPrefix: $prefix,
            bubble: true
        );
    }

    private function createLogRecord(array $context = []): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'Test message',
            context: $context,
            extra: []
        );
    }

    public function testExtractStructuredMetadataWithBlankPrefix()
    {
        $handler = $this->createHandler('');
        $context = [
            'user_id' => 123,
            'action' => 'login',
            'ip_address' => '192.168.1.1',
        ];

        $result = $this->invokePrivateMethod($handler, 'extractStructuredMetadata', [$context]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('user_id', $result);
        $this->assertArrayHasKey('action', $result);
        $this->assertArrayHasKey('ip_address', $result);
        $this->assertEquals('123', $result['user_id']);
        $this->assertEquals('login', $result['action']);
        $this->assertEquals('192.168.1.1', $result['ip_address']);
    }

    public function testExtractStructuredMetadataWithPrefix()
    {
        $handler = $this->createHandler('meta_');
        $context = [
            'meta_user_id' => 456,
            'meta_order_id' => 789,
            'meta_amount' => 99.99,
            'internal_flag' => true,
        ];

        $result = $this->invokePrivateMethod($handler, 'extractStructuredMetadata', [$context]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('user_id', $result);
        $this->assertArrayHasKey('order_id', $result);
        $this->assertArrayHasKey('amount', $result);
        $this->assertArrayNotHasKey('internal_flag', $result);
        $this->assertEquals('456', $result['user_id']);
        $this->assertEquals('789', $result['order_id']);
        $this->assertEquals('99.99', $result['amount']);
    }

    public function testExtractStructuredMetadataExcludesLabels()
    {
        $handler = $this->createHandler('');
        $context = [
            'user_id' => 123,
            'labels' => ['level' => 'info', 'channel' => 'app'],
        ];

        $result = $this->invokePrivateMethod($handler, 'extractStructuredMetadata', [$context]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('user_id', $result);
        $this->assertArrayNotHasKey('labels', $result);
    }

    public function testExtractStructuredMetadataWithEmptyContext()
    {
        $handler = $this->createHandler('');
        $context = [];

        $result = $this->invokePrivateMethod($handler, 'extractStructuredMetadata', [$context]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testExtractStructuredMetadataWithOnlyLabels()
    {
        $handler = $this->createHandler('');
        $context = [
            'labels' => ['level' => 'info'],
        ];

        $result = $this->invokePrivateMethod($handler, 'extractStructuredMetadata', [$context]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testExtractStructuredMetadataWithExactPrefixMatch()
    {
        $handler = $this->createHandler('meta_');
        $context = [
            'meta_' => 'should_be_ignored',
            'meta_user_id' => 123,
        ];

        $result = $this->invokePrivateMethod($handler, 'extractStructuredMetadata', [$context]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('user_id', $result);
        $this->assertArrayNotHasKey('', $result);
        $this->assertEquals('123', $result['user_id']);
    }

    public function testExtractStructuredMetadataWithNoMatchingPrefix()
    {
        $handler = $this->createHandler('meta_');
        $context = [
            'user_id' => 123,
            'action' => 'login',
        ];

        $result = $this->invokePrivateMethod($handler, 'extractStructuredMetadata', [$context]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testSanitizeStructuredMetadataHandlesNullValues()
    {
        $handler = $this->createHandler('');
        $metadata = [
            'user_id' => 123,
            'optional_field' => null,
            'name' => 'John',
        ];

        $result = $this->invokePrivateMethod($handler, 'sanitizeStructuredMetadata', [$metadata]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('user_id', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayNotHasKey('optional_field', $result);
    }

    public function testSanitizeStructuredMetadataHandlesArrays()
    {
        $handler = $this->createHandler('');
        $metadata = [
            'user_id' => 123,
            'tags' => ['tag1', 'tag2', 'tag3'],
        ];

        $result = $this->invokePrivateMethod($handler, 'sanitizeStructuredMetadata', [$metadata]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('user_id', $result);
        $this->assertArrayHasKey('tags', $result);
        $this->assertEquals('123', $result['user_id']);
        $this->assertIsString($result['tags']);
        $this->assertEquals('["tag1","tag2","tag3"]', $result['tags']);
    }

    public function testSanitizeStructuredMetadataHandlesObjects()
    {
        $handler = $this->createHandler('');
        $metadata = [
            'user_id' => 123,
            'user_data' => (object)['id' => 456, 'name' => 'John'],
        ];

        $result = $this->invokePrivateMethod($handler, 'sanitizeStructuredMetadata', [$metadata]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('user_id', $result);
        $this->assertArrayHasKey('user_data', $result);
        $this->assertIsString($result['user_data']);
        $this->assertJson($result['user_data']);
    }

    public function testSanitizeStructuredMetadataHandlesBooleans()
    {
        $handler = $this->createHandler('');
        $metadata = [
            'is_active' => true,
            'is_deleted' => false,
        ];

        $result = $this->invokePrivateMethod($handler, 'sanitizeStructuredMetadata', [$metadata]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('is_active', $result);
        $this->assertArrayHasKey('is_deleted', $result);
        $this->assertEquals('true', $result['is_active']);
        $this->assertEquals('false', $result['is_deleted']);
    }

    public function testSanitizeStructuredMetadataConvertsScalarsToStrings()
    {
        $handler = $this->createHandler('');
        $metadata = [
            'int_value' => 123,
            'float_value' => 99.99,
            'string_value' => 'test',
        ];

        $result = $this->invokePrivateMethod($handler, 'sanitizeStructuredMetadata', [$metadata]);

        $this->assertIsArray($result);
        $this->assertIsString($result['int_value']);
        $this->assertIsString($result['float_value']);
        $this->assertIsString($result['string_value']);
        $this->assertEquals('123', $result['int_value']);
        $this->assertEquals('99.99', $result['float_value']);
        $this->assertEquals('test', $result['string_value']);
    }

    public function testSanitizeStructuredMetadataHandlesComplexScenario()
    {
        $handler = $this->createHandler('');
        $metadata = [
            'user_id' => 123,
            'name' => 'John Doe',
            'is_active' => true,
            'tags' => ['admin', 'developer'],
            'metadata' => ['key' => 'value'],
            'optional' => null,
            'score' => 95.5,
        ];

        $result = $this->invokePrivateMethod($handler, 'sanitizeStructuredMetadata', [$metadata]);

        $this->assertIsArray($result);
        $this->assertCount(6, $result); // null value excluded
        $this->assertArrayHasKey('user_id', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('is_active', $result);
        $this->assertArrayHasKey('tags', $result);
        $this->assertArrayHasKey('metadata', $result);
        $this->assertArrayHasKey('score', $result);
        $this->assertArrayNotHasKey('optional', $result);
        
        // All values should be strings
        foreach ($result as $value) {
            $this->assertIsString($value);
        }
    }

    public function testPrepareLogEntryWithBlankPrefixIncludesAllContext()
    {
        $handler = $this->createHandler('');
        $context = [
            'user_id' => 123,
            'action' => 'login',
            'labels' => ['level' => 'info'],
        ];
        $record = $this->createLogRecord($context);

        $result = $this->invokePrivateMethod($handler, 'prepareLogEntry', [$record]);

        $this->assertInstanceOf(LokiLogEntry::class, $result);
        $this->assertIsArray($result->structuredMetadata);
        $this->assertArrayHasKey('user_id', $result->structuredMetadata);
        $this->assertArrayHasKey('action', $result->structuredMetadata);
        $this->assertArrayNotHasKey('labels', $result->structuredMetadata);
    }

    public function testPrepareLogEntryWithPrefixIncludesOnlyPrefixedFields()
    {
        $handler = $this->createHandler('meta_');
        $context = [
            'meta_user_id' => 456,
            'meta_action' => 'logout',
            'internal_field' => 'value',
        ];
        $record = $this->createLogRecord($context);

        $result = $this->invokePrivateMethod($handler, 'prepareLogEntry', [$record]);

        $this->assertInstanceOf(LokiLogEntry::class, $result);
        $this->assertIsArray($result->structuredMetadata);
        $this->assertArrayHasKey('user_id', $result->structuredMetadata);
        $this->assertArrayHasKey('action', $result->structuredMetadata);
        $this->assertArrayNotHasKey('internal_field', $result->structuredMetadata);
    }

    public function testPrepareLogEntryWithEmptyContextHasNoStructuredMetadata()
    {
        $handler = $this->createHandler('');
        $record = $this->createLogRecord([]);

        $result = $this->invokePrivateMethod($handler, 'prepareLogEntry', [$record]);

        $this->assertInstanceOf(LokiLogEntry::class, $result);
        $this->assertIsArray($result->structuredMetadata);
        $this->assertEmpty($result->structuredMetadata);
    }
}
