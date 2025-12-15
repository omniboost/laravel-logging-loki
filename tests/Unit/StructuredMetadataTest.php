<?php

namespace Omniboost\LaravelLoggingLoki\Tests\Unit;

use Monolog\Level;
use Monolog\LogRecord;
use Omniboost\LaravelLoggingLoki\DTOs\LokiLogEntry;
use Omniboost\LaravelLoggingLoki\Services\LokiBufferedHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use DateTime;

/**
 * Unit Tests for Structured Metadata Extraction
 *
 * These tests verify the extraction and sanitization of structured metadata
 * from log context based on the configured prefix.
 */
class StructuredMetadataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        fwrite(STDERR, "\n");
    }

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
            bufferSize: 100,
            flushInterval: 5.0,
            defaultLabels: ['app' => 'test'],
            username: null,
            password: null,
            structuredMetadataPrefix: $prefix,
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

    /**
     * Test: Extract structured metadata when prefix is blank (default mode)
     *
     * When the prefix is empty, ALL context fields (except 'labels') should be
     * included as structured metadata and converted to strings.
     */
    public function testExtractStructuredMetadataWithBlankPrefix()
    {
        fwrite(STDERR, "  → Testing blank prefix mode (all context included)...\n");

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

        fwrite(STDERR, "    ✓ All context fields extracted and sanitized\n");
    }

    /**
     * Test: Extract structured metadata with a custom prefix
     *
     * When a prefix is configured, ONLY fields starting with that prefix should
     * be extracted, and the prefix should be removed from the key names.
     */
    public function testExtractStructuredMetadataWithPrefix()
    {
        fwrite(STDERR, "  → Testing prefix mode (selective extraction)...\n");
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

        fwrite(STDERR, "    ✓ Only prefixed fields extracted, prefix removed\n");
    }

    /**
     * Test: Labels field is always excluded from structured metadata
     *
     * The 'labels' field is handled separately in Loki and should never
     * be included in structured metadata.
     */
    public function testExtractStructuredMetadataExcludesLabels()
    {
        fwrite(STDERR, "  → Testing labels exclusion...\n");

        $handler = $this->createHandler('');
        $context = [
            'user_id' => 123,
            'labels' => ['level' => 'info', 'channel' => 'app'],
        ];

        $result = $this->invokePrivateMethod($handler, 'extractStructuredMetadata', [$context]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('user_id', $result);
        $this->assertArrayNotHasKey('labels', $result);

        fwrite(STDERR, "    ✓ Labels field correctly excluded\n");
    }

    /**
     * Test: Empty context returns empty metadata
     *
     * When no context is provided, the extraction should return an empty array.
     */
    public function testExtractStructuredMetadataWithEmptyContext()
    {
        fwrite(STDERR, "  → Testing empty context handling...\n");

        $handler = $this->createHandler('');
        $context = [];

        $result = $this->invokePrivateMethod($handler, 'extractStructuredMetadata', [$context]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);

        fwrite(STDERR, "    ✓ Empty context returns empty array\n");
    }

    /**
     * Test: Context with only labels returns empty metadata
     *
     * If context contains only the labels field, structured metadata should be empty.
     */
    public function testExtractStructuredMetadataWithOnlyLabels()
    {
        fwrite(STDERR, "  → Testing context with only labels...\n");

        $handler = $this->createHandler('');
        $context = [
            'labels' => ['level' => 'info'],
        ];

        $result = $this->invokePrivateMethod($handler, 'extractStructuredMetadata', [$context]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);

        fwrite(STDERR, "    ✓ Context with only labels returns empty array\n");
    }

    /**
     * Test: Edge case - key exactly matching prefix is excluded
     *
     * If a context key is exactly the prefix (e.g., 'meta_'), it should be
     * excluded as it would result in an empty key after prefix removal.
     */
    public function testExtractStructuredMetadataWithExactPrefixMatch()
    {
        fwrite(STDERR, "  → Testing exact prefix match edge case...\n");

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

        fwrite(STDERR, "    ✓ Exact prefix match correctly excluded\n");
    }

    /**
     * Test: No fields match the configured prefix
     *
     * When a prefix is configured but no context fields match it,
     * the extraction should return an empty array.
     */
    public function testExtractStructuredMetadataWithNoMatchingPrefix()
    {
        fwrite(STDERR, "  → Testing no matching prefix scenario...\n");
        $handler = $this->createHandler('meta_');
        $context = [
            'user_id' => 123,
            'action' => 'login',
        ];

        $result = $this->invokePrivateMethod($handler, 'extractStructuredMetadata', [$context]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);

        fwrite(STDERR, "    ✓ No matching prefix returns empty array\n");
    }

    /**
     * Test: Sanitization - Null values are excluded
     *
     * Loki doesn't accept null values in structured metadata, so they
     * should be completely excluded from the output.
     */
    public function testSanitizeStructuredMetadataHandlesNullValues()
    {
        fwrite(STDERR, "  → Testing null value handling (sanitization)...\n");

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

        fwrite(STDERR, "    ✓ Null values correctly excluded\n");
    }

    /**
     * Test: Sanitization - Arrays are JSON encoded
     *
     * Loki only accepts string values, so arrays must be JSON encoded.
     */
    public function testSanitizeStructuredMetadataHandlesArrays()
    {
        fwrite(STDERR, "  → Testing array handling (JSON encoding)...\n");

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

        fwrite(STDERR, "    ✓ Arrays JSON encoded to strings\n");
    }

    /**
     * Test: Sanitization - Objects are JSON encoded
     *
     * Like arrays, objects must be converted to JSON strings for Loki.
     */
    public function testSanitizeStructuredMetadataHandlesObjects()
    {
        fwrite(STDERR, "  → Testing object handling (JSON encoding)...\n");

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

        fwrite(STDERR, "    ✓ Objects JSON encoded to strings\n");
    }

    /**
     * Test: Sanitization - Booleans converted to string representation
     *
     * Boolean values must be converted to "true" or "false" strings.
     */
    public function testSanitizeStructuredMetadataHandlesBooleans()
    {
        fwrite(STDERR, "  → Testing boolean conversion...\n");

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

        fwrite(STDERR, "    ✓ Booleans converted to 'true'/'false' strings\n");
    }

    /**
     * Test: Sanitization - Scalar values converted to strings
     *
     * All scalar types (int, float, string) must be string values in Loki.
     */
    public function testSanitizeStructuredMetadataConvertsScalarsToStrings()
    {
        fwrite(STDERR, "  → Testing scalar type conversions...\n");

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

        fwrite(STDERR, "    ✓ All scalars converted to strings\n");
    }

    /**
     * Test: Sanitization - Complex scenario with mixed types
     *
     * This test validates that all sanitization rules work together correctly
     * when processing a realistic metadata payload with various data types.
     */
    public function testSanitizeStructuredMetadataHandlesComplexScenario()
    {
        fwrite(STDERR, "  → Testing complex mixed-type scenario...\n");
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

        fwrite(STDERR, "    ✓ Complex scenario: all types sanitized correctly\n");
    }

    /**
     * Test: Integration - Blank prefix includes all context in log entry
     *
     * This end-to-end test verifies that when a log entry is prepared
     * with blank prefix, all context (except labels) is included as structured metadata.
     */
    public function testPrepareLogEntryWithBlankPrefixIncludesAllContext()
    {
        fwrite(STDERR, "  → Testing log entry preparation with blank prefix...\n");

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

        fwrite(STDERR, "    ✓ Log entry prepared with all context as structured metadata\n");
    }

    /**
     * Test: Integration - Prefix mode includes only prefixed fields
     *
     * This test verifies the complete flow when a prefix is configured,
     * ensuring only matching fields are extracted with prefix removed.
     */
    public function testPrepareLogEntryWithPrefixIncludesOnlyPrefixedFields()
    {
        fwrite(STDERR, "  → Testing log entry preparation with prefix...\n");

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

        fwrite(STDERR, "    ✓ Log entry prepared with only prefixed fields\n");
    }

    /**
     * Test: Integration - Empty context results in no structured metadata
     *
     * When no context is provided to a log entry, the structured metadata
     * should be empty.
     */
    public function testPrepareLogEntryWithEmptyContextHasNoStructuredMetadata()
    {
        fwrite(STDERR, "  → Testing log entry preparation with empty context...\n");

        $handler = $this->createHandler('');
        $record = $this->createLogRecord([]);

        $result = $this->invokePrivateMethod($handler, 'prepareLogEntry', [$record]);

        $this->assertInstanceOf(LokiLogEntry::class, $result);
        $this->assertIsArray($result->structuredMetadata);
        $this->assertEmpty($result->structuredMetadata);

        fwrite(STDERR, "    ✓ Log entry prepared with empty structured metadata\n");
    }
}
