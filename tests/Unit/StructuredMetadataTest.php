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

    protected function tearDown(): void
    {
        // Manually flush all handler instances while Laravel container is still available
        // This prevents shutdown handler from running after Laravel is torn down
        $reflection = new ReflectionClass(LokiBufferedHandler::class);
        $instancesProperty = $reflection->getProperty('handlerInstances');
        $instancesProperty->setAccessible(true);
        $instances = $instancesProperty->getValue();
        
        foreach ($instances as $handler) {
            if ($handler instanceof LokiBufferedHandler) {
                try {
                    $handler->flushMemoryBuffer();
                } catch (\Throwable $e) {
                    // Ignore flush errors during teardown - handler might try to send logs to Loki
                }
            }
        }
        
        // Clear the instances array to prevent shutdown handler from running
        $instancesProperty->setValue(null, []);
        
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
     * Test: Sanitization - Indexed arrays are skipped
     *
     * Loki doesn't accept indexed/list arrays in structured metadata.
     * These should be filtered out.
     */
    public function testSanitizeStructuredMetadataHandlesArrays()
    {
        fwrite(STDERR, "  → Testing indexed array handling (skipped)...\n");

        $handler = $this->createHandler('');
        $metadata = [
            'user_id' => 123,
            'tags' => ['tag1', 'tag2', 'tag3'],
        ];

        $result = $this->invokePrivateMethod($handler, 'sanitizeStructuredMetadata', [$metadata]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('user_id', $result);
        // Indexed array 'tags' should be skipped
        $this->assertArrayNotHasKey('tags', $result);
        $this->assertEquals('123', $result['user_id']);

        fwrite(STDERR, "    ✓ Indexed arrays skipped\n");
    }

    /**
     * Test: Sanitization - Objects are expanded as nested arrays
     *
     * Objects should be converted to associative arrays and expanded recursively.
     */
    public function testSanitizeStructuredMetadataHandlesObjects()
    {
        fwrite(STDERR, "  → Testing object handling (expanded)...\n");

        $handler = $this->createHandler('');
        $metadata = [
            'user_id' => 123,
            'user_data' => (object)['id' => 456, 'name' => 'John'],
        ];

        $result = $this->invokePrivateMethod($handler, 'sanitizeStructuredMetadata', [$metadata]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('user_id', $result);
        $this->assertArrayHasKey('user_data', $result);
        // Object should be expanded as nested array, not JSON string
        $this->assertIsArray($result['user_data']);
        $this->assertArrayHasKey('id', $result['user_data']);
        $this->assertArrayHasKey('name', $result['user_data']);
        $this->assertEquals('456', $result['user_data']['id']);
        $this->assertEquals('John', $result['user_data']['name']);

        fwrite(STDERR, "    ✓ Objects expanded as nested arrays\n");
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
            'tags' => ['admin', 'developer'],  // indexed array - will be skipped
            'metadata' => ['key' => 'value'],  // associative array - will be expanded
            'optional' => null,
            'score' => 95.5,
        ];

        $result = $this->invokePrivateMethod($handler, 'sanitizeStructuredMetadata', [$metadata]);

        $this->assertIsArray($result);
        $this->assertCount(5, $result); // null excluded, indexed array (tags) excluded
        $this->assertArrayHasKey('user_id', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('is_active', $result);
        $this->assertArrayNotHasKey('tags', $result); // indexed array should be excluded
        $this->assertArrayHasKey('metadata', $result);
        $this->assertArrayHasKey('score', $result);
        $this->assertArrayNotHasKey('optional', $result);

        // Scalar values should be strings
        $this->assertIsString($result['user_id']);
        $this->assertIsString($result['name']);
        $this->assertIsString($result['is_active']);
        $this->assertIsString($result['score']);
        
        // Associative array should be expanded, not JSON-encoded
        $this->assertIsArray($result['metadata']);
        $this->assertArrayHasKey('key', $result['metadata']);
        $this->assertEquals('value', $result['metadata']['key']);

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
     * Test: Nested associative arrays are expanded recursively
     *
     * Deeply nested objects/associative arrays should be handled correctly.
     */
    public function testSanitizeStructuredMetadataHandlesNestedAssociativeArrays()
    {
        fwrite(STDERR, "  → Testing nested associative arrays...\n");

        $handler = $this->createHandler('');
        $metadata = [
            'user' => [
                'id' => 123,
                'profile' => [
                    'name' => 'John Doe',
                    'age' => 30,
                    'active' => true,
                ],
            ],
        ];

        $result = $this->invokePrivateMethod($handler, 'sanitizeStructuredMetadata', [$metadata]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('user', $result);
        $this->assertIsArray($result['user']);
        $this->assertArrayHasKey('id', $result['user']);
        $this->assertArrayHasKey('profile', $result['user']);
        $this->assertIsArray($result['user']['profile']);
        $this->assertEquals('123', $result['user']['id']);
        $this->assertEquals('John Doe', $result['user']['profile']['name']);
        $this->assertEquals('30', $result['user']['profile']['age']);
        $this->assertEquals('true', $result['user']['profile']['active']);

        fwrite(STDERR, "    ✓ Nested associative arrays expanded correctly\n");
    }

    /**
     * Test: Mixed associative and indexed arrays
     *
     * When an associative array contains indexed arrays, the indexed arrays
     * should be skipped while keeping the rest of the structure.
     */
    public function testSanitizeStructuredMetadataHandlesMixedArrayTypes()
    {
        fwrite(STDERR, "  → Testing mixed associative and indexed arrays...\n");

        $handler = $this->createHandler('');
        $metadata = [
            'user' => [
                'id' => 123,
                'name' => 'John',
                'tags' => ['admin', 'developer'], // indexed - should be skipped
                'settings' => [
                    'theme' => 'dark',
                    'notifications' => ['email', 'sms'], // indexed - should be skipped
                ],
            ],
        ];

        $result = $this->invokePrivateMethod($handler, 'sanitizeStructuredMetadata', [$metadata]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('user', $result);
        $this->assertIsArray($result['user']);
        $this->assertArrayHasKey('id', $result['user']);
        $this->assertArrayHasKey('name', $result['user']);
        $this->assertArrayNotHasKey('tags', $result['user']); // indexed array excluded
        $this->assertArrayHasKey('settings', $result['user']);
        $this->assertArrayHasKey('theme', $result['user']['settings']);
        $this->assertArrayNotHasKey('notifications', $result['user']['settings']); // indexed array excluded

        fwrite(STDERR, "    ✓ Mixed array types handled correctly\n");
    }

    /**
     * Test: Empty associative arrays are skipped
     *
     * Empty associative arrays (after filtering) should not be included.
     */
    public function testSanitizeStructuredMetadataHandlesEmptyAssociativeArrays()
    {
        fwrite(STDERR, "  → Testing empty associative arrays...\n");

        $handler = $this->createHandler('');
        $metadata = [
            'user_id' => 123,
            'empty_object' => [],
            'object_with_only_indexed_array' => [
                'tags' => ['tag1', 'tag2'], // only indexed array, whole object becomes empty
            ],
        ];

        $result = $this->invokePrivateMethod($handler, 'sanitizeStructuredMetadata', [$metadata]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('user_id', $result);
        // Empty arrays should be skipped
        $this->assertArrayNotHasKey('empty_object', $result);
        $this->assertArrayNotHasKey('object_with_only_indexed_array', $result);

        fwrite(STDERR, "    ✓ Empty associative arrays skipped\n");
    }

    /**
     * Test: Deep nesting with various types
     *
     * Test a complex real-world scenario with deep nesting.
     */
    public function testSanitizeStructuredMetadataHandlesDeeplyNestedStructures()
    {
        fwrite(STDERR, "  → Testing deeply nested structures...\n");

        $handler = $this->createHandler('');
        $metadata = [
            'request' => [
                'method' => 'POST',
                'path' => '/api/users',
                'headers' => [
                    'content-type' => 'application/json',
                    'accept' => 'application/json',
                ],
                'body' => [
                    'user' => [
                        'name' => 'John',
                        'email' => 'john@example.com',
                        'roles' => ['admin', 'user'], // indexed - skipped
                    ],
                ],
            ],
        ];

        $result = $this->invokePrivateMethod($handler, 'sanitizeStructuredMetadata', [$metadata]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('request', $result);
        $this->assertIsArray($result['request']);
        $this->assertArrayHasKey('method', $result['request']);
        $this->assertArrayHasKey('headers', $result['request']);
        $this->assertArrayHasKey('body', $result['request']);
        $this->assertEquals('POST', $result['request']['method']);
        $this->assertEquals('application/json', $result['request']['headers']['content-type']);
        $this->assertEquals('John', $result['request']['body']['user']['name']);
        $this->assertArrayNotHasKey('roles', $result['request']['body']['user']); // indexed array skipped

        fwrite(STDERR, "    ✓ Deeply nested structures handled correctly\n");
    }

    /**
     * Test: All null values in nested structure are removed
     */
    public function testSanitizeStructuredMetadataRemovesAllNullValuesRecursively()
    {
        fwrite(STDERR, "  → Testing recursive null value removal...\n");

        $handler = $this->createHandler('');
        $metadata = [
            'user' => [
                'id' => 123,
                'name' => null,
                'profile' => [
                    'email' => 'test@example.com',
                    'phone' => null,
                ],
            ],
        ];

        $result = $this->invokePrivateMethod($handler, 'sanitizeStructuredMetadata', [$metadata]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('user', $result);
        $this->assertArrayHasKey('id', $result['user']);
        $this->assertArrayNotHasKey('name', $result['user']); // null removed
        $this->assertArrayHasKey('profile', $result['user']);
        $this->assertArrayHasKey('email', $result['user']['profile']);
        $this->assertArrayNotHasKey('phone', $result['user']['profile']); // null removed

        fwrite(STDERR, "    ✓ Null values removed recursively\n");
    }

    /**
     * Test: Object with only primitives is expanded
     */
    public function testSanitizeStructuredMetadataHandlesSimpleObject()
    {
        fwrite(STDERR, "  → Testing simple object with primitives...\n");

        $handler = $this->createHandler('');
        
        $obj = new \stdClass();
        $obj->id = 123;
        $obj->name = 'Test';
        $obj->active = true;
        
        $metadata = [
            'data' => $obj,
        ];

        $result = $this->invokePrivateMethod($handler, 'sanitizeStructuredMetadata', [$metadata]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertIsArray($result['data']);
        $this->assertArrayHasKey('id', $result['data']);
        $this->assertArrayHasKey('name', $result['data']);
        $this->assertArrayHasKey('active', $result['data']);
        $this->assertEquals('123', $result['data']['id']);
        $this->assertEquals('Test', $result['data']['name']);
        $this->assertEquals('true', $result['data']['active']);

        fwrite(STDERR, "    ✓ Simple object expanded correctly\n");
    }
}
