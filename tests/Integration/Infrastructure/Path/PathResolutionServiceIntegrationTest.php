<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Integration\Infrastructure\Path;

use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Cache\MultiLayerPathResolutionCache;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\ExtensionIdentifier;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathConfiguration;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionRequest;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\InstallationTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\PathResolutionService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Recovery\ErrorRecoveryManager;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\ExtensionPathResolutionStrategy;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\PathResolutionStrategyRegistry;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Validation\PathResolutionValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Integration tests for the complete PathResolutionService workflow.
 * Tests end-to-end functionality including strategy coordination, caching, validation, and error recovery.
 */
final class PathResolutionServiceIntegrationTest extends TestCase
{
    private PathResolutionService $pathResolutionService;
    private string $testInstallationPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a temporary test installation directory
        $this->testInstallationPath = sys_get_temp_dir() . '/typo3-test-' . uniqid();
        $this->createTestInstallation($this->testInstallationPath);

        // Set up the complete service with all dependencies
        $logger = new NullLogger();

        $strategy = new ExtensionPathResolutionStrategy($logger);
        $strategyRegistry = new PathResolutionStrategyRegistry($logger, [$strategy]);

        $validator = new PathResolutionValidator($logger);
        $cache = new MultiLayerPathResolutionCache($logger);
        $errorRecoveryManager = new ErrorRecoveryManager($logger);

        $this->pathResolutionService = new PathResolutionService(
            $strategyRegistry,
            $validator,
            $cache,
            $errorRecoveryManager,
            $logger,
        );
    }

    protected function tearDown(): void
    {
        // Clean up test installation
        if (is_dir($this->testInstallationPath)) {
            $this->removeDirectory($this->testInstallationPath);
        }
        parent::tearDown();
    }

    public function testCompletePathResolutionWorkflow(): void
    {
        // Arrange: Create a path resolution request
        $request = PathResolutionRequest::create(
            PathTypeEnum::EXTENSION,
            $this->testInstallationPath,
            InstallationTypeEnum::COMPOSER_STANDARD,
            PathConfiguration::createDefault(),
            new ExtensionIdentifier('test_extension'),
        );

        // Act: Resolve the path
        $response = $this->pathResolutionService->resolvePath($request);

        // Assert: Verify the response structure
        $this->assertEquals(PathTypeEnum::EXTENSION, $response->pathType);
        $this->assertIsFloat($response->resolutionTime);
        $this->assertIsString($response->cacheKey);

        // The response should indicate success or provide alternatives
        $this->assertTrue(
            $response->isSuccess() || null !== $response->getBestAlternative(),
            'Response should either succeed or provide alternatives',
        );
    }

    public function testBatchProcessingOptimization(): void
    {
        // Arrange: Create multiple requests for the same installation
        $requests = [];
        for ($i = 1; $i <= 5; ++$i) {
            $requests[] = PathResolutionRequest::create(
                PathTypeEnum::EXTENSION,
                $this->testInstallationPath,
                InstallationTypeEnum::COMPOSER_STANDARD,
                PathConfiguration::createDefault(),
                new ExtensionIdentifier("test_extension_{$i}"),
            );
        }

        // Act: Process batch requests
        $responses = $this->pathResolutionService->resolveMultiplePaths($requests);

        // Assert: Verify batch processing results
        $this->assertCount(5, $responses);

        foreach ($responses as $response) {
            $this->assertEquals(PathTypeEnum::EXTENSION, $response->pathType);
            $this->assertIsFloat($response->resolutionTime);
        }
    }

    public function testCachingBehavior(): void
    {
        // Arrange: Create a request
        $request = PathResolutionRequest::create(
            PathTypeEnum::EXTENSION,
            $this->testInstallationPath,
            InstallationTypeEnum::COMPOSER_STANDARD,
            PathConfiguration::createDefault(),
            new ExtensionIdentifier('cached_extension'),
        );

        // Act: Resolve the same path twice
        $firstResponse = $this->pathResolutionService->resolvePath($request);
        $secondResponse = $this->pathResolutionService->resolvePath($request);

        // Assert: Second response should be faster (cached)
        $this->assertEquals($firstResponse->pathType, $secondResponse->pathType);
        $this->assertEquals($firstResponse->cacheKey, $secondResponse->cacheKey);

        // Cache behavior verification - second call should have cache metadata
        $this->assertInstanceOf(\CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionMetadata::class, $secondResponse->metadata);
    }

    public function testValidationErrorHandling(): void
    {
        // Arrange: Create a request with invalid installation path
        $invalidPath = '/nonexistent/path/that/does/not/exist';
        $request = PathResolutionRequest::create(
            PathTypeEnum::EXTENSION,
            $invalidPath,
            InstallationTypeEnum::COMPOSER_STANDARD,
            PathConfiguration::createDefault(),
            new ExtensionIdentifier('test_extension'),
        );

        // Act: Resolve the path with invalid input
        $response = $this->pathResolutionService->resolvePath($request);

        // Assert: Should receive error response with validation information
        $this->assertFalse($response->isSuccess());
        $this->assertNotEmpty($response->errors);
        $this->assertStringContainsString('does not exist', implode(' ', $response->errors));
    }

    public function testServiceCapabilities(): void
    {
        // Act: Get service capabilities
        $capabilities = $this->pathResolutionService->getResolutionCapabilities();

        // Assert: Verify complete Phase 2 implementation
        $this->assertEquals(2, $capabilities['phase']);
        $this->assertEquals('fully_implemented', $capabilities['status']);
        $this->assertTrue($capabilities['validation_enabled']);
        $this->assertTrue($capabilities['error_recovery_enabled']);
        $this->assertTrue($capabilities['batch_processing_enabled']);
        $this->assertIsArray($capabilities['supported_path_types']);
        $this->assertIsArray($capabilities['cache_statistics']);
        $this->assertArrayHasKey('strategy_count', $capabilities);
    }

    public function testPathTypeSupportChecking(): void
    {
        // Act & Assert: Verify path type support
        $this->assertTrue($this->pathResolutionService->supportsPathType(PathTypeEnum::EXTENSION));

        $availableTypes = $this->pathResolutionService->getAvailablePathTypes(InstallationTypeEnum::COMPOSER_STANDARD);
        $this->assertContains(PathTypeEnum::EXTENSION, $availableTypes);
    }

    public function testEmptyBatchProcessing(): void
    {
        // Act: Process empty batch
        $responses = $this->pathResolutionService->resolveMultiplePaths([]);

        // Assert: Should return empty array
        $this->assertEmpty($responses);
    }

    public function testMixedInstallationTypeBatchProcessing(): void
    {
        // Arrange: Create requests with different installation types
        $composerRequest = PathResolutionRequest::create(
            PathTypeEnum::EXTENSION,
            $this->testInstallationPath,
            InstallationTypeEnum::COMPOSER_STANDARD,
            PathConfiguration::createDefault(),
            new ExtensionIdentifier('composer_ext'),
        );

        $legacyRequest = PathResolutionRequest::create(
            PathTypeEnum::EXTENSION,
            $this->testInstallationPath,
            InstallationTypeEnum::LEGACY_SOURCE,
            PathConfiguration::createDefault(),
            new ExtensionIdentifier('legacy_ext'),
        );

        // Act: Process mixed batch
        $responses = $this->pathResolutionService->resolveMultiplePaths([$composerRequest, $legacyRequest]);

        // Assert: Should handle both types
        $this->assertCount(2, $responses);
        $this->assertEquals(InstallationTypeEnum::COMPOSER_STANDARD, $responses[0]->metadata->installationType);
        $this->assertEquals(InstallationTypeEnum::LEGACY_SOURCE, $responses[1]->metadata->installationType);
    }

    /**
     * Create a test TYPO3 installation structure for testing.
     */
    private function createTestInstallation(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0o755, true);
        }

        // Create basic TYPO3 structure
        mkdir($path . '/public', 0o755, true);
        mkdir($path . '/public/typo3conf', 0o755, true);
        mkdir($path . '/public/typo3conf/ext', 0o755, true);
        mkdir($path . '/public/typo3conf/ext/test_extension', 0o755, true);
        mkdir($path . '/public/fileadmin', 0o755, true);

        // Create composer.json to indicate Composer installation
        file_put_contents($path . '/composer.json', json_encode([
            'name' => 'test/typo3-installation',
            'require' => [
                'typo3/cms-core' => '^12.0',
            ],
        ], JSON_PRETTY_PRINT));

        // Create extension files
        file_put_contents($path . '/public/typo3conf/ext/test_extension/ext_emconf.php', '<?php
$EM_CONF[$_EXTKEY] = [
    "title" => "Test Extension",
    "version" => "1.0.0",
];
');
    }

    /**
     * Recursively remove a directory and its contents.
     */
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
}
