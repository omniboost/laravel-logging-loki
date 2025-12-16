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
 * Unit Tests for Label Prefixing and Extraction
 *
 * These tests verify the extraction and sanitization of labels
 * from log context based on the configured prefix.
 */
class LabelPrefixingTest extends TestCase
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

    private function createHandler(string $labelsPrefix = ''): LokiBufferedHandler
    {
        return new LokiBufferedHandler(
            url: 'http://localhost:3100',
            bufferSize: 100,
            flushInterval: 5.0,
            defaultLabels: ['app' => 'test'],
            username: null,
            password: null,
            structuredMetadataPrefix: '',
            labelsPrefix: $labelsPrefix,
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
     * Test: Extract labels when prefix is blank (default mode)
     *
     * When the prefix is empty, ONLY the 'labels' key in context should be used
     * for extracting labels (traditional behavior).
     */
    public function testExtractLabelsWithBlankPrefixUsesLabelsKey()
    {
        fwrite(STDERR, "  → Testing blank prefix mode (labels key only)...\n");

        $handler = $this->createHandler('');
        $context = [
            'labels' => [
                'user_id' => 123,
                'action' => 'login',
            ],
            'other_field' => 'should_not_be_label',
        ];

        $result = $this->invokePrivateMethod($handler, 'extractLabels', [$context]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('user_id', $result);
        $this->assertArrayHasKey('action', $result);
        $this->assertArrayNotHasKey('other_field', $result);
        $this->assertEquals('123', $result['user_id']);
        $this->assertEquals('login', $result['action']);

        fwrite(STDERR, "    ✓ Labels key extracted in blank prefix mode\n");
    }

    /**
     * Test: Extract labels with a custom prefix
     *
     * When a prefix is configured, ONLY fields starting with that prefix should
     * be extracted as labels, and the prefix should be removed from the key names.
     */
    public function testExtractLabelsWithPrefix()
    {
        fwrite(STDERR, "  → Testing prefix mode (selective extraction)...\n");
        $handler = $this->createHandler('label_');
        $context = [
            'label_user_id' => 456,
            'label_endpoint' => '/api/users',
            'label_method' => 'GET',
            'internal_flag' => true,
            'labels' => ['ignored' => 'value'],  // Should be ignored when prefix is set
        ];

        $result = $this->invokePrivateMethod($handler, 'extractLabels', [$context]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('user_id', $result);
        $this->assertArrayHasKey('endpoint', $result);
        $this->assertArrayHasKey('method', $result);
        $this->assertArrayNotHasKey('internal_flag', $result);
        $this->assertArrayNotHasKey('ignored', $result);
        $this->assertEquals('456', $result['user_id']);
        $this->assertEquals('/api/users', $result['endpoint']);
        $this->assertEquals('GET', $result['method']);

        fwrite(STDERR, "    ✓ Only prefixed fields extracted as labels, prefix removed\n");
    }

    /**
     * Test: Empty context returns empty labels
     *
     * When no context is provided, the extraction should return an empty array.
     */
    public function testExtractLabelsWithEmptyContext()
    {
        fwrite(STDERR, "  → Testing empty context handling...\n");

        $handler = $this->createHandler('');
        $context = [];

        $result = $this->invokePrivateMethod($handler, 'extractLabels', [$context]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);

        fwrite(STDERR, "    ✓ Empty context returns empty array\n");
    }

    /**
     * Test: Context without labels key returns empty labels (blank prefix mode)
     *
     * If context doesn't contain the labels key and prefix is blank,
     * labels extraction should return empty array.
     */
    public function testExtractLabelsWithBlankPrefixAndNoLabelsKey()
    {
        fwrite(STDERR, "  → Testing blank prefix with no labels key...\n");

        $handler = $this->createHandler('');
        $context = [
            'user_id' => 123,
            'action' => 'login',
        ];

        $result = $this->invokePrivateMethod($handler, 'extractLabels', [$context]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);

        fwrite(STDERR, "    ✓ No labels key returns empty array in blank prefix mode\n");
    }

    /**
     * Test: Edge case - key exactly matching prefix is excluded
     *
     * If a context key is exactly the prefix (e.g., 'label_'), it should be
     * excluded as it would result in an empty key after prefix removal.
     */
    public function testExtractLabelsWithExactPrefixMatch()
    {
        fwrite(STDERR, "  → Testing exact prefix match edge case...\n");

        $handler = $this->createHandler('label_');
        $context = [
            'label_' => 'should_be_ignored',
            'label_user_id' => 123,
        ];

        $result = $this->invokePrivateMethod($handler, 'extractLabels', [$context]);

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
    public function testExtractLabelsWithNoMatchingPrefix()
    {
        fwrite(STDERR, "  → Testing no matching prefix scenario...\n");
        $handler = $this->createHandler('label_');
        $context = [
            'user_id' => 123,
            'action' => 'login',
        ];

        $result = $this->invokePrivateMethod($handler, 'extractLabels', [$context]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);

        fwrite(STDERR, "    ✓ No matching prefix returns empty array\n");
    }

    /**
     * Test: Sanitization - Null values are excluded
     *
     * Loki doesn't accept null values in labels, so they
     * should be completely excluded from the output.
     */
    public function testSanitizeLabelsHandlesNullValues()
    {
        fwrite(STDERR, "  → Testing null value handling (sanitization)...\n");

        $handler = $this->createHandler('');
        $labels = [
            'user_id' => 123,
            'optional_field' => null,
            'name' => 'John',
        ];

        $result = $this->invokePrivateMethod($handler, 'sanitizeLabels', [$labels]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('user_id', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayNotHasKey('optional_field', $result);

        fwrite(STDERR, "    ✓ Null values correctly excluded\n");
    }

    /**
     * Test: Sanitization - Empty strings are excluded
     *
     * Empty strings are not useful as labels and should be excluded.
     */
    public function testSanitizeLabelsHandlesEmptyStrings()
    {
        fwrite(STDERR, "  → Testing empty string handling (sanitization)...\n");

        $handler = $this->createHandler('');
        $labels = [
            'user_id' => 123,
            'empty_field' => '',
            'name' => 'John',
        ];

        $result = $this->invokePrivateMethod($handler, 'sanitizeLabels', [$labels]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('user_id', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayNotHasKey('empty_field', $result);

        fwrite(STDERR, "    ✓ Empty strings correctly excluded\n");
    }

    /**
     * Test: Sanitization - Arrays are JSON encoded
     *
     * Loki only accepts string values, so arrays must be JSON encoded.
     */
    public function testSanitizeLabelsHandlesArrays()
    {
        fwrite(STDERR, "  → Testing array handling (JSON encoding)...\n");

        $handler = $this->createHandler('');
        $labels = [
            'user_id' => 123,
            'tags' => ['tag1', 'tag2', 'tag3'],
        ];

        $result = $this->invokePrivateMethod($handler, 'sanitizeLabels', [$labels]);

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
    public function testSanitizeLabelsHandlesObjects()
    {
        fwrite(STDERR, "  → Testing object handling (JSON encoding)...\n");

        $handler = $this->createHandler('');
        $labels = [
            'user_id' => 123,
            'user_data' => (object)['id' => 456, 'name' => 'John'],
        ];

        $result = $this->invokePrivateMethod($handler, 'sanitizeLabels', [$labels]);

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
    public function testSanitizeLabelsHandlesBooleans()
    {
        fwrite(STDERR, "  → Testing boolean conversion...\n");

        $handler = $this->createHandler('');
        $labels = [
            'is_active' => true,
            'is_deleted' => false,
        ];

        $result = $this->invokePrivateMethod($handler, 'sanitizeLabels', [$labels]);

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
    public function testSanitizeLabelsConvertsScalarsToStrings()
    {
        fwrite(STDERR, "  → Testing scalar type conversions...\n");

        $handler = $this->createHandler('');
        $labels = [
            'int_value' => 123,
            'float_value' => 99.99,
            'string_value' => 'test',
        ];

        $result = $this->invokePrivateMethod($handler, 'sanitizeLabels', [$labels]);

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
     * when processing a realistic labels payload with various data types.
     */
    public function testSanitizeLabelsHandlesComplexScenario()
    {
        fwrite(STDERR, "  → Testing complex mixed-type scenario...\n");
        $handler = $this->createHandler('');
        $labels = [
            'user_id' => 123,
            'name' => 'John Doe',
            'is_active' => true,
            'tags' => ['admin', 'developer'],
            'metadata' => ['key' => 'value'],
            'optional' => null,
            'empty' => '',
            'score' => 95.5,
        ];

        $result = $this->invokePrivateMethod($handler, 'sanitizeLabels', [$labels]);

        $this->assertIsArray($result);
        $this->assertCount(6, $result); // null and empty string excluded
        $this->assertArrayHasKey('user_id', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('is_active', $result);
        $this->assertArrayHasKey('tags', $result);
        $this->assertArrayHasKey('metadata', $result);
        $this->assertArrayHasKey('score', $result);
        $this->assertArrayNotHasKey('optional', $result);
        $this->assertArrayNotHasKey('empty', $result);

        // All values should be strings
        foreach ($result as $value) {
            $this->assertIsString($value);
        }

        fwrite(STDERR, "    ✓ Complex scenario: all types sanitized correctly\n");
    }

    /**
     * Test: Integration - Blank prefix uses labels key in log entry
     *
     * This end-to-end test verifies that when a log entry is prepared
     * with blank prefix, the traditional 'labels' key is used for labels.
     */
    public function testPrepareLogEntryWithBlankPrefixUsesLabelsKey()
    {
        fwrite(STDERR, "  → Testing log entry preparation with blank prefix...\n");

        $handler = $this->createHandler('');
        $context = [
            'user_id' => 123,
            'action' => 'login',
            'labels' => ['endpoint' => '/api/login', 'method' => 'POST'],
        ];
        $record = $this->createLogRecord($context);

        $result = $this->invokePrivateMethod($handler, 'prepareLogEntry', [$record]);

        $this->assertInstanceOf(LokiLogEntry::class, $result);
        $this->assertIsArray($result->stream);
        // Should have default label 'app' plus the two from context
        $this->assertArrayHasKey('app', $result->stream);
        $this->assertArrayHasKey('endpoint', $result->stream);
        $this->assertArrayHasKey('method', $result->stream);
        $this->assertEquals('test', $result->stream['app']);
        $this->assertEquals('/api/login', $result->stream['endpoint']);
        $this->assertEquals('POST', $result->stream['method']);

        fwrite(STDERR, "    ✓ Log entry prepared with labels from labels key\n");
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

        $handler = $this->createHandler('label_');
        $context = [
            'label_user_id' => 456,
            'label_action' => 'logout',
            'internal_field' => 'value',
            'labels' => ['should_be_ignored' => 'yes'],
        ];
        $record = $this->createLogRecord($context);

        $result = $this->invokePrivateMethod($handler, 'prepareLogEntry', [$record]);

        $this->assertInstanceOf(LokiLogEntry::class, $result);
        $this->assertIsArray($result->stream);
        $this->assertArrayHasKey('app', $result->stream);
        $this->assertArrayHasKey('user_id', $result->stream);
        $this->assertArrayHasKey('action', $result->stream);
        $this->assertArrayNotHasKey('internal_field', $result->stream);
        $this->assertArrayNotHasKey('should_be_ignored', $result->stream);
        $this->assertEquals('456', $result->stream['user_id']);
        $this->assertEquals('logout', $result->stream['action']);

        fwrite(STDERR, "    ✓ Log entry prepared with only prefixed labels\n");
    }

    /**
     * Test: Integration - Empty context results in only default labels
     *
     * When no context is provided to a log entry, only default labels
     * should be present.
     */
    public function testPrepareLogEntryWithEmptyContextHasOnlyDefaultLabels()
    {
        fwrite(STDERR, "  → Testing log entry preparation with empty context...\n");

        $handler = $this->createHandler('');
        $record = $this->createLogRecord([]);

        $result = $this->invokePrivateMethod($handler, 'prepareLogEntry', [$record]);

        $this->assertInstanceOf(LokiLogEntry::class, $result);
        $this->assertIsArray($result->stream);
        $this->assertArrayHasKey('app', $result->stream);
        $this->assertEquals('test', $result->stream['app']);
        $this->assertCount(1, $result->stream);

        fwrite(STDERR, "    ✓ Log entry prepared with only default labels\n");
    }

    /**
     * Test: Integration - Null and empty labels are excluded from stream
     *
     * When labels contain null or empty values, they should not appear in the
     * final stream labels.
     */
    public function testPrepareLogEntryExcludesNullAndEmptyLabels()
    {
        fwrite(STDERR, "  → Testing null and empty label exclusion in log entry...\n");

        $handler = $this->createHandler('label_');
        $context = [
            'label_user_id' => 456,
            'label_empty' => '',
            'label_null' => null,
            'label_valid' => 'value',
        ];
        $record = $this->createLogRecord($context);

        $result = $this->invokePrivateMethod($handler, 'prepareLogEntry', [$record]);

        $this->assertInstanceOf(LokiLogEntry::class, $result);
        $this->assertIsArray($result->stream);
        $this->assertArrayHasKey('user_id', $result->stream);
        $this->assertArrayHasKey('valid', $result->stream);
        $this->assertArrayNotHasKey('empty', $result->stream);
        $this->assertArrayNotHasKey('null', $result->stream);

        fwrite(STDERR, "    ✓ Null and empty labels correctly excluded from stream\n");
    }
}
