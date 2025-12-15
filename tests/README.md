# Tests for Structured Metadata Feature

This directory contains comprehensive tests for the structured metadata functionality in the Laravel Loki Logging library.

## Test Structure

### Unit Tests (`tests/Unit/`)

#### `StructuredMetadataTest.php`
Tests the core functionality of structured metadata extraction and sanitization:

**Configuration Tests:**
- Blank prefix mode (all context included)
- Prefix mode (selective extraction)
- Labels exclusion
- Empty context handling
- Exact prefix match edge case
- No matching prefix scenario

**Sanitization Tests:**
- Null value handling (excluded from metadata)
- Array handling (JSON encoded)
- Object handling (JSON encoded)
- Boolean conversion (to "true"/"false" strings)
- Scalar to string conversion
- Complex scenarios with mixed types

**Integration Tests:**
- Log entry preparation with blank prefix
- Log entry preparation with prefix
- Empty context handling

### Feature Tests (`tests/Feature/`)

#### `SendLogsToLokiTest.php`
Tests the complete flow of sending logs with structured metadata to Loki:

**Stream Preparation Tests:**
- Entries without structured metadata (2 elements: timestamp, line)
- Entries with structured metadata (3 elements: timestamp, line, metadata)
- Mixed entries (some with metadata, some without)
- Stream grouping by labels
- Complex structured metadata
- Empty entries handling

## Running Tests

### Install Dependencies

```bash
composer install
```

### Run All Tests

```bash
composer test
```

Or with PHPUnit directly:

```bash
./vendor/bin/phpunit
```

### Run Specific Test Suite

```bash
./vendor/bin/phpunit tests/Unit
./vendor/bin/phpunit tests/Feature
```

### Run Specific Test File

```bash
./vendor/bin/phpunit tests/Unit/StructuredMetadataTest.php
./vendor/bin/phpunit tests/Feature/SendLogsToLokiTest.php
```

### Run Specific Test Method

```bash
./vendor/bin/phpunit --filter testExtractStructuredMetadataWithBlankPrefix
./vendor/bin/phpunit --filter testSanitizeStructuredMetadataHandlesNullValues
```

## Test Coverage

The tests cover the following scenarios:

### 1. Configuration Scenarios
- ✅ Blank prefix (default) - all context included
- ✅ Custom prefix - selective extraction
- ✅ Empty context
- ✅ Only labels in context

### 2. Data Type Handling
- ✅ Null values (excluded)
- ✅ Arrays (JSON encoded)
- ✅ Objects (JSON encoded)
- ✅ Booleans (converted to "true"/"false")
- ✅ Integers (converted to strings)
- ✅ Floats (converted to strings)
- ✅ Strings (kept as-is)

### 3. Edge Cases
- ✅ Key exactly matching prefix (excluded)
- ✅ No fields matching prefix (empty result)
- ✅ Labels field exclusion
- ✅ Mixed types in same context
- ✅ Empty arrays
- ✅ Nested objects/arrays

### 4. Loki Integration
- ✅ Correct values array format `[timestamp, line]`
- ✅ Correct values array format with metadata `[timestamp, line, metadata]`
- ✅ Stream grouping by labels
- ✅ Mixed entries in same stream

## Example Test Cases

### Blank Prefix Test
```php
// Config: structured_metadata_prefix = ''
$context = [
    'user_id' => 123,
    'action' => 'login',
];
// Result: All fields included as structured metadata
```

### Prefix Test
```php
// Config: structured_metadata_prefix = 'meta_'
$context = [
    'meta_user_id' => 456,
    'meta_action' => 'logout',
    'internal_field' => 'value',
];
// Result: Only 'user_id' and 'action' included (prefix removed)
```

### Null Values Test
```php
$metadata = [
    'user_id' => 123,
    'optional_field' => null,
];
// Result: 'optional_field' excluded, 'user_id' = "123"
```

### Array/Object Test
```php
$metadata = [
    'tags' => ['admin', 'user'],
    'user_data' => (object)['id' => 123],
];
// Result: 
// 'tags' = '["admin","user"]'
// 'user_data' = '{"id":123}'
```

## Continuous Integration

These tests should be run as part of your CI/CD pipeline before deploying updates. Example GitHub Actions workflow:

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.1'
      - run: composer install
      - run: composer test
```

## Adding New Tests

When adding new functionality:

1. Add unit tests to `tests/Unit/StructuredMetadataTest.php`
2. Add integration tests to `tests/Feature/SendLogsToLokiTest.php`
3. Ensure all edge cases are covered
4. Run tests locally before committing
5. Update this README if adding new test files

## Notes

- Tests use reflection to access private methods for thorough unit testing
- Mock objects are used where appropriate to isolate functionality
- All tests should be independent and not rely on execution order
- Tests follow PHPUnit best practices and naming conventions
