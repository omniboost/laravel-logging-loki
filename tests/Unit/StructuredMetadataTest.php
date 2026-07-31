<?php

namespace Omniboost\LaravelLoggingLoki\Tests\Unit;

use Monolog\Level;
use Monolog\LogRecord;
use Omniboost\LaravelLoggingLoki\DTOs\LokiLogEntry;
use Omniboost\LaravelLoggingLoki\Services\LokiBufferedHandler;
use Omniboost\LaravelLoggingLoki\LokiServiceProvider;
use Orchestra\Testbench\TestCase;
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
        $record = $this->createLogRecord([
            'user_id' => 123,
            'action' => 'login',
            'ip_address' => '192.168.1.1',
        ]);

        $result = $this->invokePrivateMethod($handler, 'extractStructuredMetadata', [$record]);

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
        $record = $this->createLogRecord([
            'meta_user_id' => 456,
            'meta_order_id' => 789,
            'meta_amount' => 99.99,
            'internal_flag' => true,
        ]);

        $result = $this->invokePrivateMethod($handler, 'extractStructuredMetadata', [$record]);

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
        $record = $this->createLogRecord([
            'user_id' => 123,
            'labels' => ['level' => 'info', 'channel' => 'app'],
        ]);

        $result = $this->invokePrivateMethod($handler, 'extractStructuredMetadata', [$record]);

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
        $record = $this->createLogRecord([]);

        $result = $this->invokePrivateMethod($handler, 'extractStructuredMetadata', [$record]);

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
        $record = $this->createLogRecord([
            'labels' => ['level' => 'info'],
        ]);

        $result = $this->invokePrivateMethod($handler, 'extractStructuredMetadata', [$record]);

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
        $record = $this->createLogRecord([
            'meta_' => 'should_be_ignored',
            'meta_user_id' => 123,
        ]);

        $result = $this->invokePrivateMethod($handler, 'extractStructuredMetadata', [$record]);

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
        $record = $this->createLogRecord([
            'user_id' => 123,
            'action' => 'login',
        ]);

        $result = $this->invokePrivateMethod($handler, 'extractStructuredMetadata', [$record]);

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

    /**
     * Test: Extract structured metadata from both context and extra fields
     *
     * The feature should merge both context and extra arrays when extracting
     * structured metadata, allowing metadata to come from either source.
     */
    public function testExtractStructuredMetadataFromContextAndExtra()
    {
        fwrite(STDERR, "  → Testing extraction from both context and extra fields...\n");

        $handler = $this->createHandler('');
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'Test message',
            context: [
                'user_id' => 123,
                'action' => 'login',
            ],
            extra: [
                'request_id' => 'req-456',
                'ip_address' => '192.168.1.1',
            ]
        );

        $result = $this->invokePrivateMethod($handler, 'extractStructuredMetadata', [$record]);

        $this->assertIsArray($result);
        // Fields from context
        $this->assertArrayHasKey('user_id', $result);
        $this->assertArrayHasKey('action', $result);
        // Fields from extra
        $this->assertArrayHasKey('request_id', $result);
        $this->assertArrayHasKey('ip_address', $result);
        
        $this->assertEquals('123', $result['user_id']);
        $this->assertEquals('login', $result['action']);
        $this->assertEquals('req-456', $result['request_id']);
        $this->assertEquals('192.168.1.1', $result['ip_address']);

        fwrite(STDERR, "    ✓ Both context and extra fields included in structured metadata\n");
    }

    /**
     * Test: Extra fields override context fields with same key
     *
     * When both context and extra contain the same key, array_merge behavior
     * means the extra value should take precedence (last value wins).
     */
    public function testExtractStructuredMetadataExtraOverridesContext()
    {
        fwrite(STDERR, "  → Testing extra field precedence over context...\n");

        $handler = $this->createHandler('');
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'Test message',
            context: [
                'user_id' => 123,
                'priority' => 'low',
            ],
            extra: [
                'priority' => 'high',  // This should override context value
                'source' => 'api',
            ]
        );

        $result = $this->invokePrivateMethod($handler, 'extractStructuredMetadata', [$record]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('user_id', $result);
        $this->assertArrayHasKey('priority', $result);
        $this->assertArrayHasKey('source', $result);
        
        $this->assertEquals('123', $result['user_id']);
        $this->assertEquals('high', $result['priority']);  // Extra value wins
        $this->assertEquals('api', $result['source']);

        fwrite(STDERR, "    ✓ Extra field correctly overrides context field\n");
    }

    /**
     * Test: Extract structured metadata from extra with prefix
     *
     * When using a prefix, the feature should check both context and extra
     * fields for matching prefixes.
     */
    public function testExtractStructuredMetadataFromExtraWithPrefix()
    {
        fwrite(STDERR, "  → Testing prefixed extraction from both context and extra...\n");

        $handler = $this->createHandler('meta_');
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'Test message',
            context: [
                'meta_user_id' => 123,
                'internal_flag' => true,
            ],
            extra: [
                'meta_request_id' => 'req-456',
                'debug_info' => 'test',
            ]
        );

        $result = $this->invokePrivateMethod($handler, 'extractStructuredMetadata', [$record]);

        $this->assertIsArray($result);
        // Prefixed fields from context
        $this->assertArrayHasKey('user_id', $result);
        // Prefixed fields from extra
        $this->assertArrayHasKey('request_id', $result);
        // Non-prefixed fields should not be included
        $this->assertArrayNotHasKey('internal_flag', $result);
        $this->assertArrayNotHasKey('debug_info', $result);
        
        $this->assertEquals('123', $result['user_id']);
        $this->assertEquals('req-456', $result['request_id']);

        fwrite(STDERR, "    ✓ Prefixed fields extracted from both context and extra\n");
    }

    /**
     * Test: Label-prefixed fields are excluded from structured metadata
     *
     * When both structured metadata and labels use different prefixes,
     * fields matching the label prefix should NOT appear in structured metadata
     * to prevent duplication.
     */
    public function testLabelPrefixedFieldsExcludedFromStructuredMetadata()
    {
        fwrite(STDERR, "  → Testing label-prefixed fields excluded from structured metadata...\n");

        // Create handler with both structured metadata prefix (empty = all) and labels prefix
        $handler = new LokiBufferedHandler(
            url: 'http://localhost:3100',
            bufferSize: 100,
            flushInterval: 5.0,
            defaultLabels: ['app' => 'test'],
            username: null,
            password: null,
            structuredMetadataPrefix: '', // Empty means all context included
            labelsPrefix: 'label_', // But fields starting with label_ should be excluded
        );

        $record = $this->createLogRecord([
            'label_integration' => 'stripe',
            'label_user_id' => 123,
            'regular_field' => 'value',
            'another_field' => 'data',
        ]);

        $result = $this->invokePrivateMethod($handler, 'extractStructuredMetadata', [$record]);

        $this->assertIsArray($result);
        // Label-prefixed fields should NOT be in structured metadata
        $this->assertArrayNotHasKey('label_integration', $result);
        $this->assertArrayNotHasKey('label_user_id', $result);
        // Regular fields should be included
        $this->assertArrayHasKey('regular_field', $result);
        $this->assertArrayHasKey('another_field', $result);
        $this->assertEquals('value', $result['regular_field']);
        $this->assertEquals('data', $result['another_field']);

        fwrite(STDERR, "    ✓ Label-prefixed fields correctly excluded from structured metadata\n");
    }

    /**
     * Test: Label-prefixed fields are excluded even with custom structured metadata prefix
     *
     * When both structured metadata and labels use different prefixes,
     * label-prefixed fields should never appear in structured metadata,
     * even when a custom structured metadata prefix is configured.
     */
    public function testLabelPrefixedFieldsExcludedWithCustomStructuredPrefix()
    {
        fwrite(STDERR, "  → Testing label exclusion with custom structured metadata prefix...\n");

        // Create handler with both prefixes configured
        $handler = new LokiBufferedHandler(
            url: 'http://localhost:3100',
            bufferSize: 100,
            flushInterval: 5.0,
            defaultLabels: ['app' => 'test'],
            username: null,
            password: null,
            structuredMetadataPrefix: 'meta_',
            labelsPrefix: 'label_',
        );

        $record = $this->createLogRecord([
            'label_integration' => 'stripe',
            'meta_user_id' => 123,
            'meta_request_id' => 'req-456',
            'regular_field' => 'value',
        ]);

        $result = $this->invokePrivateMethod($handler, 'extractStructuredMetadata', [$record]);

        $this->assertIsArray($result);
        // Label-prefixed fields should NOT be in structured metadata
        $this->assertArrayNotHasKey('label_integration', $result);
        // Meta-prefixed fields should be included (with prefix removed)
        $this->assertArrayHasKey('user_id', $result);
        $this->assertArrayHasKey('request_id', $result);
        // Regular field should not be included (no meta_ prefix)
        $this->assertArrayNotHasKey('regular_field', $result);
        
        $this->assertEquals('123', $result['user_id']);
        $this->assertEquals('req-456', $result['request_id']);

        fwrite(STDERR, "    ✓ Label-prefixed fields excluded even with custom structured metadata prefix\n");
    }

    /**
     * Test: Integration test - Full log entry preparation validates no duplication
     *
     * This end-to-end test verifies that when a log entry is prepared,
     * label-prefixed fields appear only in labels, not in structured metadata.
     */
    public function testPrepareLogEntryExcludesLabelFieldsFromStructuredMetadata()
    {
        fwrite(STDERR, "  → Testing full log entry preparation without duplication...\n");

        $handler = new LokiBufferedHandler(
            url: 'http://localhost:3100',
            bufferSize: 100,
            flushInterval: 5.0,
            defaultLabels: ['app' => 'test'],
            username: null,
            password: null,
            structuredMetadataPrefix: '', // All context included in structured metadata
            labelsPrefix: 'label_', // Except label-prefixed fields
        );

        $context = [
            'label_integration' => 'stripe',
            'label_endpoint' => '/api/payment',
            'user_id' => 123,
            'amount' => 99.99,
        ];
        $record = $this->createLogRecord($context);

        $result = $this->invokePrivateMethod($handler, 'prepareLogEntry', [$record]);

        $this->assertInstanceOf(LokiLogEntry::class, $result);
        
        // Labels should contain the label-prefixed fields (with prefix removed)
        $this->assertIsArray($result->stream);
        $this->assertArrayHasKey('integration', $result->stream);
        $this->assertArrayHasKey('endpoint', $result->stream);
        $this->assertEquals('stripe', $result->stream['integration']);
        $this->assertEquals('/api/payment', $result->stream['endpoint']);
        
        // Structured metadata should NOT contain label-prefixed fields
        $this->assertIsArray($result->structuredMetadata);
        $this->assertArrayNotHasKey('label_integration', $result->structuredMetadata);
        $this->assertArrayNotHasKey('label_endpoint', $result->structuredMetadata);
        // But should contain regular fields
        $this->assertArrayHasKey('user_id', $result->structuredMetadata);
        $this->assertArrayHasKey('amount', $result->structuredMetadata);
        $this->assertEquals('123', $result->structuredMetadata['user_id']);
        $this->assertEquals('99.99', $result->structuredMetadata['amount']);

        fwrite(STDERR, "    ✓ Full log entry prepared correctly without duplication\n");
    }
}
