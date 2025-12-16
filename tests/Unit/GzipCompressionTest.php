<?php

namespace Omniboost\LaravelLoggingLoki\Tests\Unit;

use Omniboost\LaravelLoggingLoki\LokiClient;
use Omniboost\LaravelLoggingLoki\DTOs\LokiStream;
use Orchestra\Testbench\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Middleware;

/**
 * Unit Tests for GZIP Compression Feature
 * 
 * These tests verify that the GZIP compression functionality works correctly
 * for data sent to Grafana Loki.
 */
class GzipCompressionTest extends TestCase
{
    /**
     * Test: LokiClient sends compressed data when gzip_compression is enabled
     * 
     * When GZIP compression is enabled, the payload should be gzipped
     * and the Content-Encoding header should be set to 'gzip'.
     */
    public function testPushWithGzipCompressionEnabled()
    {
        fwrite(STDERR, "\n  → Testing GZIP compression enabled...\n");
        
        $container = [];
        $history = Middleware::history($container);
        
        $mock = new MockHandler([
            new Response(204), // Successful response
        ]);
        
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push($history);
        
        // Create client with mocked HTTP client
        $client = new \ReflectionClass(LokiClient::class);
        $instance = $client->newInstanceArgs(['http://localhost:3100', null, null, true]);
        
        // Replace HTTP client with mocked one
        $httpClient = new Client(['handler' => $handlerStack]);
        $httpClientProperty = $client->getProperty('httpClient');
        $httpClientProperty->setAccessible(true);
        $httpClientProperty->setValue($instance, $httpClient);
        
        // Create test data
        $streams = [
            new LokiStream(
                ['level' => 'info', 'app' => 'test'],
                [['1234567890000000000', 'Test log message']]
            ),
        ];
        
        // Push logs
        $result = $instance->push($streams);
        
        $this->assertTrue($result);
        $this->assertCount(1, $container);
        
        $request = $container[0]['request'];
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('/loki/api/v1/push', $request->getUri()->getPath());
        $this->assertEquals('gzip', $request->getHeaderLine('Content-Encoding'));
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        
        // Verify the body is compressed
        $body = (string) $request->getBody();
        $this->assertNotEmpty($body);
        
        // Decompress and verify the payload
        $decompressed = gzdecode($body);
        $this->assertNotFalse($decompressed);
        
        $payload = json_decode($decompressed, true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('streams', $payload);
        
        fwrite(STDERR, "    ✓ GZIP compression enabled and working correctly\n");
    }

    /**
     * Test: LokiClient sends uncompressed data when gzip_compression is disabled
     * 
     * When GZIP compression is disabled, the payload should be sent as plain JSON
     * without the Content-Encoding header.
     */
    public function testPushWithGzipCompressionDisabled()
    {
        fwrite(STDERR, "  → Testing GZIP compression disabled...\n");
        
        $container = [];
        $history = Middleware::history($container);
        
        $mock = new MockHandler([
            new Response(204), // Successful response
        ]);
        
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push($history);
        
        // Create client with compression disabled
        $client = new \ReflectionClass(LokiClient::class);
        $instance = $client->newInstanceArgs(['http://localhost:3100', null, null, false]);
        
        // Replace HTTP client with mocked one
        $httpClient = new Client(['handler' => $handlerStack]);
        $httpClientProperty = $client->getProperty('httpClient');
        $httpClientProperty->setAccessible(true);
        $httpClientProperty->setValue($instance, $httpClient);
        
        // Create test data
        $streams = [
            new LokiStream(
                ['level' => 'info', 'app' => 'test'],
                [['1234567890000000000', 'Test log message']]
            ),
        ];
        
        // Push logs
        $result = $instance->push($streams);
        
        $this->assertTrue($result);
        $this->assertCount(1, $container);
        
        $request = $container[0]['request'];
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('/loki/api/v1/push', $request->getUri()->getPath());
        $this->assertFalse($request->hasHeader('Content-Encoding'));
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        
        // Verify the body is plain JSON
        $body = (string) $request->getBody();
        $this->assertNotEmpty($body);
        
        $payload = json_decode($body, true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('streams', $payload);
        
        fwrite(STDERR, "    ✓ GZIP compression disabled and plain JSON sent\n");
    }

    /**
     * Test: GZIP compression reduces payload size
     * 
     * Verify that GZIP compression actually reduces the payload size
     * for typical log data.
     */
    public function testGzipCompressionReducesPayloadSize()
    {
        fwrite(STDERR, "  → Testing GZIP compression size reduction...\n");
        
        // Create test data with repetitive log messages (compresses well)
        $streams = [];
        for ($i = 0; $i < 100; $i++) {
            $streams[] = new LokiStream(
                ['level' => 'info', 'app' => 'test'],
                [[(string)(1234567890000000000 + $i), 'This is a test log message that will be repeated many times to test compression']]
            );
        }
        
        $payload = ['streams' => $streams];
        $jsonPayload = json_encode($payload);
        $compressedPayload = gzencode($jsonPayload);
        
        $this->assertNotFalse($compressedPayload);
        
        $originalSize = strlen($jsonPayload);
        $compressedSize = strlen($compressedPayload);
        
        $this->assertLessThan($originalSize, $compressedSize);
        
        $compressionRatio = ($originalSize - $compressedSize) / $originalSize * 100;
        fwrite(STDERR, sprintf("    ✓ Compression reduced payload by %.1f%% (%d bytes -> %d bytes)\n", 
            $compressionRatio, $originalSize, $compressedSize));
    }

    /**
     * Test: Compressed payload can be decompressed correctly
     * 
     * Ensure that the compressed data can be properly decompressed
     * and matches the original data.
     */
    public function testCompressedPayloadCanBeDecompressed()
    {
        fwrite(STDERR, "  → Testing compressed payload decompression...\n");
        
        $streams = [
            new LokiStream(
                ['level' => 'error', 'app' => 'test'],
                [
                    ['1234567890000000000', 'Error message 1'],
                    ['1234567890000000001', 'Error message 2'],
                ]
            ),
        ];
        
        $payload = ['streams' => $streams];
        $jsonPayload = json_encode($payload);
        $compressedPayload = gzencode($jsonPayload);
        
        $this->assertNotFalse($compressedPayload);
        
        $decompressed = gzdecode($compressedPayload);
        $this->assertNotFalse($decompressed);
        $this->assertEquals($jsonPayload, $decompressed);
        
        $decompressedPayload = json_decode($decompressed, true);
        $this->assertIsArray($decompressedPayload);
        $this->assertArrayHasKey('streams', $decompressedPayload);
        
        fwrite(STDERR, "    ✓ Compressed payload successfully decompressed\n");
    }

    /**
     * Test: LokiClient with basic auth and GZIP compression
     * 
     * Verify that basic authentication works correctly with GZIP compression.
     */
    public function testPushWithBasicAuthAndGzipCompression()
    {
        fwrite(STDERR, "  → Testing GZIP compression with basic auth...\n");
        
        $container = [];
        $history = Middleware::history($container);
        
        $mock = new MockHandler([
            new Response(204),
        ]);
        
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push($history);
        
        // Create client with basic auth and compression
        $client = new \ReflectionClass(LokiClient::class);
        $instance = $client->newInstanceArgs(['http://localhost:3100', 'user', 'pass', true]);
        
        // Replace HTTP client with mocked one
        $httpClient = new Client(['handler' => $handlerStack]);
        $httpClientProperty = $client->getProperty('httpClient');
        $httpClientProperty->setAccessible(true);
        $httpClientProperty->setValue($instance, $httpClient);
        
        // Create test data
        $streams = [
            new LokiStream(
                ['level' => 'info'],
                [['1234567890000000000', 'Test message']]
            ),
        ];
        
        // Push logs
        $result = $instance->push($streams);
        
        $this->assertTrue($result);
        $this->assertCount(1, $container);
        
        $request = $container[0]['request'];
        $this->assertTrue($request->hasHeader('Authorization'));
        $this->assertEquals('gzip', $request->getHeaderLine('Content-Encoding'));
        
        fwrite(STDERR, "    ✓ Basic auth works correctly with GZIP compression\n");
    }

    /**
     * Test: Empty streams array with compression enabled
     * 
     * Verify that empty streams are handled correctly with compression enabled.
     */
    public function testPushWithEmptyStreamsAndGzipCompression()
    {
        fwrite(STDERR, "  → Testing empty streams with GZIP compression...\n");
        
        $client = new LokiClient('http://localhost:3100', null, null, true);
        
        // Push empty streams
        $result = $client->push([]);
        
        $this->assertTrue($result);
        
        fwrite(STDERR, "    ✓ Empty streams handled correctly with GZIP compression\n");
    }
}
