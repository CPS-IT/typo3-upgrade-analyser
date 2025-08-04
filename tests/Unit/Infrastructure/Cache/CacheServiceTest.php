<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Cache;

use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CacheServiceTest extends TestCase
{
    private LoggerInterface $logger;
    private string $tempDir;
    private CacheService $cacheService;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->tempDir = sys_get_temp_dir() . '/cache_test_' . uniqid();
        $this->cacheService = new CacheService($this->logger, $this->tempDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::has
     */
    public function testHasReturnsFalseForNonExistentKey(): void
    {
        $this->assertFalse($this->cacheService->has('nonexistent_key'));
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::has
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::set
     */
    public function testHasReturnsTrueForExistingKey(): void
    {
        $key = 'test_key';
        $data = ['test' => 'data'];

        $this->cacheService->set($key, $data);
        $this->assertTrue($this->cacheService->has($key));
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::get
     */
    public function testGetReturnsNullForNonExistentKey(): void
    {
        $this->assertNull($this->cacheService->get('nonexistent_key'));
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::set
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::get
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::ensureCacheDirectoryExists
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::getCacheFilePath
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::getCacheDirectory
     */
    public function testSetAndGetWithValidData(): void
    {
        $key = 'test_key';
        $data = [
            'string' => 'value',
            'number' => 42,
            'array' => ['nested' => 'data'],
            'boolean' => true,
        ];

        // Allow any debug calls - we test functionality, not logging details
        $this->logger->expects($this->any())->method('debug');

        $result = $this->cacheService->set($key, $data);
        $this->assertTrue($result);

        $retrievedData = $this->cacheService->get($key);
        $this->assertSame($data, $retrievedData);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::set
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::ensureCacheDirectoryExists
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::getCacheDirectory
     */
    public function testSetCreatesDirectoryIfNotExists(): void
    {
        $cacheDir = $this->tempDir . '/var/results';
        $this->assertFalse(is_dir($cacheDir));

        // Allow any debug calls, we'll verify the directory creation by filesystem check
        $this->logger->expects($this->any())->method('debug');

        $result = $this->cacheService->set('test_key', ['data' => 'value']);
        $this->assertTrue($result);
        $this->assertTrue(is_dir($cacheDir));
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::set
     */
    public function testSetWithJsonEncodingError(): void
    {
        $key = 'test_key';
        // Create data that will cause JSON encoding to fail
        $resource = fopen('php://memory', 'r');
        $data = ['resource' => $resource];

        $result = $this->cacheService->set($key, $data);
        $this->assertFalse($result);

        fclose($resource);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::get
     */
    public function testGetWithCorruptedJsonFile(): void
    {
        $key = 'test_key';

        // First create the cache directory
        $cacheDir = $this->tempDir . '/var/results';
        mkdir($cacheDir, 0o755, true);

        // Manually create a corrupted cache file
        $filePath = $cacheDir . '/' . $key . '.json';
        file_put_contents($filePath, 'invalid json content');

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to retrieve from cache', $this->callback(function ($context) use ($key) {
                return isset($context['key']) && $context['key'] === $key && isset($context['error']);
            }));

        $result = $this->cacheService->get($key);
        $this->assertNull($result);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::get
     */
    public function testGetWithUnreadableFile(): void
    {
        $key = 'test_key';

        // Create cache directory and file
        $cacheDir = $this->tempDir . '/var/results';
        mkdir($cacheDir, 0o755, true);
        $filePath = $cacheDir . '/' . $key . '.json';
        file_put_contents($filePath, '{"test": "data"}');

        // Make file unreadable (simulate permission issue)
        chmod($filePath, 0o000);

        // Skip this test on Windows where chmod doesn't work the same way
        if (DIRECTORY_SEPARATOR === '\\' || PHP_OS_FAMILY === 'Darwin') {
            $this->markTestSkipped('File permission tests not reliable on Windows or macOS');
        }

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Failed to read cache file', ['key' => $key, 'path' => $filePath]);

        $result = $this->cacheService->get($key);
        $this->assertNull($result);

        // Restore permissions for cleanup
        chmod($filePath, 0o644);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::delete
     */
    public function testDeleteNonExistentKey(): void
    {
        $result = $this->cacheService->delete('nonexistent_key');
        $this->assertTrue($result);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::delete
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::set
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::has
     */
    public function testDeleteExistingKey(): void
    {
        $key = 'test_key';
        $data = ['test' => 'data'];

        $this->cacheService->set($key, $data);
        $this->assertTrue($this->cacheService->has($key));

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Cache entry deleted', ['key' => $key]);

        $result = $this->cacheService->delete($key);
        $this->assertTrue($result);
        $this->assertFalse($this->cacheService->has($key));
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::delete
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::set
     */
    public function testDeleteWithPermissionError(): void
    {
        $key = 'test_key';
        $data = ['test' => 'data'];

        // Allow debug calls from set operation but don't expect specific calls
        $this->logger->expects($this->any())->method('debug');

        $this->cacheService->set($key, $data);

        // Make the cache directory read-only to simulate permission error
        $cacheDir = $this->tempDir . '/var/results';

        // Skip this test on Windows where chmod doesn't work the same way
        if (DIRECTORY_SEPARATOR === '\\' || PHP_OS_FAMILY === 'Darwin') {
            $this->markTestSkipped('File permission tests not reliable on Windows or macOS');
        }

        chmod($cacheDir, 0o444);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to delete cache entry', $this->callback(function ($context) use ($key) {
                return isset($context['key']) && $context['key'] === $key && isset($context['error']);
            }));

        $result = $this->cacheService->delete($key);
        $this->assertFalse($result);

        // Restore permissions for cleanup
        chmod($cacheDir, 0o755);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::clear
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::getCacheDirectory
     */
    public function testClearWithNoDirectory(): void
    {
        $result = $this->cacheService->clear();
        $this->assertTrue($result);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::clear
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::getCacheDirectory
     */
    public function testClearWithEmptyDirectory(): void
    {
        // Create empty cache directory
        $cacheDir = $this->tempDir . '/var/results';
        mkdir($cacheDir, 0o755, true);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Cache cleared', ['deleted_files' => 0]);

        $result = $this->cacheService->clear();
        $this->assertTrue($result);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::clear
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::set
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::has
     */
    public function testClearWithMultipleFiles(): void
    {
        $keys = ['key1', 'key2', 'key3'];
        $data = ['test' => 'data'];

        // Add multiple cache entries
        foreach ($keys as $key) {
            $this->cacheService->set($key, $data);
        }

        // Verify all files exist
        foreach ($keys as $key) {
            $this->assertTrue($this->cacheService->has($key));
        }

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Cache cleared', ['deleted_files' => 3]);

        $result = $this->cacheService->clear();
        $this->assertTrue($result);

        // Verify all files are deleted
        foreach ($keys as $key) {
            $this->assertFalse($this->cacheService->has($key));
        }
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::clear
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::getCacheDirectory
     */
    public function testClearWithMixedFiles(): void
    {
        // Create cache directory with mixed files (some .json, some other extensions)
        $cacheDir = $this->tempDir . '/var/results';
        mkdir($cacheDir, 0o755, true);

        file_put_contents($cacheDir . '/cache1.json', '{"test": "data1"}');
        file_put_contents($cacheDir . '/cache2.json', '{"test": "data2"}');
        file_put_contents($cacheDir . '/other.txt', 'other content');
        file_put_contents($cacheDir . '/readme.md', '# Readme');

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Cache cleared', ['deleted_files' => 2]);

        $result = $this->cacheService->clear();
        $this->assertTrue($result);

        // Only .json files should be deleted
        $this->assertFalse(file_exists($cacheDir . '/cache1.json'));
        $this->assertFalse(file_exists($cacheDir . '/cache2.json'));
        $this->assertTrue(file_exists($cacheDir . '/other.txt'));
        $this->assertTrue(file_exists($cacheDir . '/readme.md'));
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::generateKey
     */
    public function testGenerateKeyWithBasicParams(): void
    {
        $type = 'installation_discovery';
        $path = '/path/to/installation';

        // Mock filemtime to return a consistent value
        $key1 = $this->cacheService->generateKey($type, $path);
        $key2 = $this->cacheService->generateKey($type, $path);

        // Keys should be consistent for same input
        $this->assertSame($key1, $key2);
        $this->assertStringStartsWith($type . '_', $key1);
        $this->assertIsString($key1);
        $this->assertGreaterThan(\strlen($type . '_'), \strlen($key1));
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::generateKey
     */
    public function testGenerateKeyWithAdditionalParams(): void
    {
        $type = 'extension_discovery';
        $path = '/path/to/installation';
        $params = ['version' => '12.4', 'mode' => 'strict'];

        $key1 = $this->cacheService->generateKey($type, $path, $params);
        $key2 = $this->cacheService->generateKey($type, $path, $params);
        $key3 = $this->cacheService->generateKey($type, $path, ['version' => '13.4', 'mode' => 'strict']);

        // Same parameters should generate same key
        $this->assertSame($key1, $key2);
        // Different parameters should generate different keys
        $this->assertNotSame($key1, $key3);
        $this->assertStringStartsWith($type . '_', $key1);
        $this->assertStringStartsWith($type . '_', $key3);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::generateKey
     */
    public function testGenerateKeyWithNonExistentPath(): void
    {
        $type = 'test';
        $path = '/nonexistent/path';

        $key = $this->cacheService->generateKey($type, $path);

        // Should still generate a valid key even for non-existent paths
        $this->assertStringStartsWith($type . '_', $key);
        $this->assertIsString($key);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::generateKey
     */
    public function testGenerateKeyWithDifferentTypes(): void
    {
        $path = '/same/path';

        $key1 = $this->cacheService->generateKey('type1', $path);
        $key2 = $this->cacheService->generateKey('type2', $path);

        $this->assertNotSame($key1, $key2);
        $this->assertStringStartsWith('type1_', $key1);
        $this->assertStringStartsWith('type2_', $key2);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::generateKey
     */
    public function testGenerateKeyWithSamePath(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'cache_test_');
        file_put_contents($tempFile, 'test content');

        try {
            $key1 = $this->cacheService->generateKey('test', $tempFile);

            // Modify file to change mtime
            sleep(1);
            file_put_contents($tempFile, 'modified content');

            $key2 = $this->cacheService->generateKey('test', $tempFile);

            // Keys should be different because file modification time changed
            $this->assertNotSame($key1, $key2);
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::set
     */
    public function testSetWithWriteFailure(): void
    {
        // Create a scenario where writing fails by making the cache directory read-only
        $key = 'test_key';
        $data = ['test' => 'data'];

        // First create the directory
        $cacheDir = $this->tempDir . '/var/results';
        mkdir($cacheDir, 0o755, true);

        // Skip this test on Windows where chmod doesn't work the same way
        if (DIRECTORY_SEPARATOR === '\\' || PHP_OS_FAMILY === 'Darwin') {
            $this->markTestSkipped('File permission tests not reliable on Windows or macOS');
        }

        // Make directory read-only
        chmod($cacheDir, 0o444);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to write to cache file', $this->callback(function ($context) use ($key) {
                return isset($context['key']) && $context['key'] === $key && isset($context['path']);
            }));

        $result = $this->cacheService->set($key, $data);
        $this->assertFalse($result);

        // Restore permissions for cleanup
        chmod($cacheDir, 0o755);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::set
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::ensureCacheDirectoryExists
     */
    public function testSetWithDirectoryCreationFailure(): void
    {
        // Create a service with an invalid project root to simulate directory creation failure
        $invalidRoot = '/root/invalid_path_' . uniqid();
        $invalidCacheService = new CacheService($this->logger, $invalidRoot);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to store in cache', $this->callback(function ($context) {
                return isset($context['key']) && isset($context['error']);
            }));

        $result = $invalidCacheService->set('test_key', ['data' => 'value']);
        $this->assertFalse($result);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::set
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::get
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::has
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::delete
     */
    public function testCompleteWorkflow(): void
    {
        $key = 'workflow_test';
        $data = [
            'installation' => [
                'path' => '/path/to/typo3',
                'version' => '12.4.0',
            ],
            'extensions' => [
                ['name' => 'news', 'version' => '10.0.0'],
                ['name' => 'tt_address', 'version' => '7.1.0'],
            ],
        ];

        // Test set operation
        $this->assertFalse($this->cacheService->has($key));
        $result = $this->cacheService->set($key, $data);
        $this->assertTrue($result);

        // Test has operation
        $this->assertTrue($this->cacheService->has($key));

        // Test get operation
        $retrievedData = $this->cacheService->get($key);
        $this->assertSame($data, $retrievedData);

        // Test delete operation
        $result = $this->cacheService->delete($key);
        $this->assertTrue($result);
        $this->assertFalse($this->cacheService->has($key));
        $this->assertNull($this->cacheService->get($key));
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::set
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::getCacheFilePath
     */
    public function testCacheFileExtension(): void
    {
        $key = 'test_key';
        $data = ['test' => 'data'];

        $this->cacheService->set($key, $data);

        $cacheDir = $this->tempDir . '/var/results';
        $expectedFile = $cacheDir . '/' . $key . '.json';

        $this->assertTrue(file_exists($expectedFile));
        $this->assertStringEndsWith('.json', $expectedFile);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::set
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::getCacheFilePath
     */
    public function testCacheContentFormat(): void
    {
        $key = 'format_test';
        $data = [
            'string' => 'value',
            'number' => 42,
            'array' => ['nested' => true],
            'null' => null,
        ];

        $this->cacheService->set($key, $data);

        $cacheDir = $this->tempDir . '/var/results';
        $cacheFile = $cacheDir . '/' . $key . '.json';
        $content = file_get_contents($cacheFile);

        // Verify it's valid JSON
        $decodedData = json_decode($content, true);
        $this->assertSame($data, $decodedData);

        // Verify it's pretty printed (contains newlines and indentation)
        $this->assertStringContainsString("\n", $content);
        $this->assertStringContainsString('    ', $content);
    }
}
