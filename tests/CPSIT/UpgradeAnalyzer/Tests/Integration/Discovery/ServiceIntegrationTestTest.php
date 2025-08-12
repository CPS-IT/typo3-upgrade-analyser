<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Integration\Discovery;

use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryServiceInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryServiceInterface;
use CPSIT\UpgradeAnalyzer\Shared\Configuration\ContainerFactory;
use CPSIT\UpgradeAnalyzer\Tests\Integration\AbstractIntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for service interactions and dependency injection.
 *
 * @group integration
 *
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryService
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService
 * @covers \CPSIT\UpgradeAnalyzer\Shared\Configuration\ContainerFactory
 */
#[Group('integration')]
class ServiceIntegrationTestTest extends AbstractIntegrationTestCase
{
    private ExtensionDiscoveryServiceInterface $extensionDiscoveryService;
    private InstallationDiscoveryServiceInterface $installationDiscoveryService;
    private ConfigurationService $configurationService;
    private CacheService $cacheService;
    private string $fixturesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturesPath = __DIR__ . '/../Fixtures/TYPO3Installations';

        // Setup container and services
        $container = ContainerFactory::create();

        $extensionService = $container->get(ExtensionDiscoveryService::class);
        \assert($extensionService instanceof ExtensionDiscoveryService);
        $this->extensionDiscoveryService = $extensionService;

        $installationService = $container->get(InstallationDiscoveryService::class);
        \assert($installationService instanceof InstallationDiscoveryService);
        $this->installationDiscoveryService = $installationService;

        $configService = $container->get(ConfigurationService::class);
        \assert($configService instanceof ConfigurationService);
        $this->configurationService = $configService;

        $cacheService = $container->get(CacheService::class);
        \assert($cacheService instanceof CacheService);
        $this->cacheService = $cacheService;

        // Clear cache before each test
        $this->cacheService->clear();
    }

    protected function tearDown(): void
    {
        // Clear cache after each test
        $this->cacheService->clear();
        parent::tearDown();
    }

    /**
     * Test that InstallationDiscoveryService and ExtensionDiscoveryService work together properly.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryService::discoverInstallation
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testInstallationAndExtensionDiscoveryServiceInteraction(): void
    {
        $installationPath = $this->fixturesPath . '/ComposerInstallation';

        // Step 1: Discover installation
        $installationResult = $this->installationDiscoveryService->discoverInstallation($installationPath);

        $this->assertTrue(
            $installationResult->isSuccessful(),
            'Installation discovery should succeed: ' . $installationResult->getErrorMessage(),
        );

        $installation = $installationResult->getInstallation();
        $this->assertNotNull($installation);

        // Verify installation has proper metadata
        $metadata = $installation->getMetadata();
        $this->assertNotNull($metadata);

        $customPaths = $metadata->getCustomPaths();

        // Step 2: Use installation metadata for extension discovery
        $extensionResult = $this->extensionDiscoveryService->discoverExtensions($installationPath, $customPaths);

        $this->assertTrue(
            $extensionResult->isSuccessful(),
            'Extension discovery should succeed when using installation metadata',
        );

        $extensions = $extensionResult->getExtensions();
        $this->assertNotEmpty($extensions);
    }

    /**
     * Test that services use dependency injection correctly.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryService::discoverInstallation
     * @covers \CPSIT\UpgradeAnalyzer\Shared\Configuration\ContainerFactory::create
     */
    public function testServiceDependencyInjection(): void
    {
        // Verify that services can work independently
        $installationPath = $this->fixturesPath . '/ComposerInstallation';

        // Extension discovery should work without prior installation discovery
        $extensionResult = $this->extensionDiscoveryService->discoverExtensions($installationPath);
        $this->assertTrue(
            $extensionResult->isSuccessful(),
            'Extension discovery should work independently',
        );

        // Installation discovery should work independently
        $installationResult = $this->installationDiscoveryService->discoverInstallation($installationPath);
        $this->assertTrue(
            $installationResult->isSuccessful(),
            'Installation discovery should work independently',
        );
    }

    /**
     * Test that the cache service is properly integrated across all services.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryService::discoverInstallation
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::set
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::generateKey
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::has
     */
    public function testCacheServiceIntegration(): void
    {
        $installationPath = $this->fixturesPath . '/ComposerInstallation';

        // Enable caching
        $this->configurationService->set('analysis.resultCache.enabled', true);
        $this->configurationService->set('analysis.resultCache.ttl', 3600);

        // Clear cache to start fresh
        $this->cacheService->clear();

        // First installation discovery - should populate cache
        $installationResult1 = $this->installationDiscoveryService->discoverInstallation($installationPath);
        $this->assertTrue($installationResult1->isSuccessful());

        // First extension discovery - should populate cache
        $extensionResult1 = $this->extensionDiscoveryService->discoverExtensions($installationPath);
        $this->assertTrue($extensionResult1->isSuccessful());

        // Generate cache keys and verify cache has entries
        $installationCacheKey = $this->cacheService->generateKey('installation_discovery', $installationPath, ['validate' => true]);
        $extensionCacheKey = $this->cacheService->generateKey('extension_discovery', $installationPath, [
            'custom_paths' => [],
        ]);

        $this->assertTrue(
            $this->cacheService->has($installationCacheKey),
            'Cache should have installation discovery entry',
        );
        $this->assertTrue(
            $this->cacheService->has($extensionCacheKey),
            'Cache should have extension discovery entry',
        );

        // Second discoveries - should use cache
        $startTime = microtime(true);
        $installationResult2 = $this->installationDiscoveryService->discoverInstallation($installationPath);
        $extensionResult2 = $this->extensionDiscoveryService->discoverExtensions($installationPath);
        $cachedExecutionTime = microtime(true) - $startTime;

        $this->assertTrue($installationResult2->isSuccessful());
        $this->assertTrue($extensionResult2->isSuccessful());

        // Results should be consistent
        $installation1 = $installationResult1->getInstallation();
        $installation2 = $installationResult2->getInstallation();
        $this->assertNotNull($installation1, 'First installation should not be null');
        $this->assertNotNull($installation2, 'Second installation should not be null');

        $this->assertEquals(
            $installation1->getVersion()->toString(),
            $installation2->getVersion()->toString(),
        );

        $this->assertCount(\count($extensionResult1->getExtensions()), $extensionResult2->getExtensions());

        // Cached execution should be faster
        $this->assertLessThan(
            1.0,
            $cachedExecutionTime,
            'Cached execution should complete quickly',
        );
    }

    /**
     * Test configuration service integration across all services.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::set
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testConfigurationServiceIntegration(): void
    {
        // Test that configuration changes affect service behavior
        $installationPath = $this->fixturesPath . '/ComposerInstallation';

        // Disable caching
        $this->configurationService->set('analysis.resultCache.enabled', false);

        $extensionResult1 = $this->extensionDiscoveryService->discoverExtensions($installationPath);
        $this->assertTrue($extensionResult1->isSuccessful());

        // Enable caching
        $this->configurationService->set('analysis.resultCache.enabled', true);
        $this->configurationService->set('analysis.resultCache.ttl', 3600);

        $extensionResult2 = $this->extensionDiscoveryService->discoverExtensions($installationPath);
        $this->assertTrue($extensionResult2->isSuccessful());

        // Results should be consistent regardless of caching settings
        $this->assertCount(\count($extensionResult1->getExtensions()), $extensionResult2->getExtensions());
    }

    /**
     * Test error propagation and handling across service boundaries.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryService::discoverInstallation
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testErrorPropagationAcrossServices(): void
    {
        $invalidPath = '/completely/invalid/path/that/does/not/exist';

        // Installation discovery should fail gracefully
        $installationResult = $this->installationDiscoveryService->discoverInstallation($invalidPath);
        $this->assertFalse($installationResult->isSuccessful());
        $this->assertNotEmpty($installationResult->getErrorMessage());

        // Extension discovery should also handle invalid paths gracefully
        $extensionResult = $this->extensionDiscoveryService->discoverExtensions($invalidPath);
        $this->assertFalse($extensionResult->isSuccessful());
        $this->assertNotEmpty($extensionResult->getErrorMessage());

        // Error messages should be specific and helpful
        $this->assertStringContainsString('does not exist', $installationResult->getErrorMessage());
        $this->assertStringContainsString('does not exist', $extensionResult->getErrorMessage());
    }

    /**
     * Test that services maintain proper state isolation.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryService::discoverInstallation
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testServiceStateIsolation(): void
    {
        $installationPath1 = $this->fixturesPath . '/ComposerInstallation';
        $installationPath2 = $this->fixturesPath . '/LegacyInstallation';

        // Discover first installation
        $installationResult1 = $this->installationDiscoveryService->discoverInstallation($installationPath1);
        $extensionResult1 = $this->extensionDiscoveryService->discoverExtensions($installationPath1);

        $this->assertTrue($installationResult1->isSuccessful());
        $this->assertTrue($extensionResult1->isSuccessful());

        // Discover second installation
        $installationResult2 = $this->installationDiscoveryService->discoverInstallation($installationPath2);
        $extensionResult2 = $this->extensionDiscoveryService->discoverExtensions($installationPath2);

        // Second discovery results should not be affected by first discovery
        if ($installationResult2->isSuccessful()) {
            $installation1 = $installationResult1->getInstallation();
            $installation2 = $installationResult2->getInstallation();
            $this->assertNotNull($installation1, 'First installation should not be null');
            $this->assertNotNull($installation2, 'Second installation should not be null');

            $this->assertNotEquals(
                $installation1->getPath(),
                $installation2->getPath(),
            );
        }

        if ($extensionResult2->isSuccessful()) {
            $extensions1 = array_map(fn ($ext): string => $ext->getKey(), $extensionResult1->getExtensions());
            $extensions2 = array_map(fn ($ext): string => $ext->getKey(), $extensionResult2->getExtensions());

            // Should have different extension sets
            $this->assertNotEquals(
                $extensions1,
                $extensions2,
                'Different installations should have different extension sets',
            );
        }
    }

    /**
     * Test service performance under load.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryService::discoverInstallation
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::clear
     */
    public function testServicePerformanceUnderLoad(): void
    {
        $installationPath = $this->fixturesPath . '/ComposerInstallation';

        $iterations = 5;
        $executionTimes = [];
        $memoryUsages = [];

        for ($i = 0; $i < $iterations; ++$i) {
            // Clear cache to ensure each iteration does real work
            $this->cacheService->clear();

            $memoryBefore = memory_get_usage();
            $startTime = microtime(true);

            $installationResult = $this->installationDiscoveryService->discoverInstallation($installationPath);
            $extensionResult = $this->extensionDiscoveryService->discoverExtensions($installationPath);

            $executionTime = microtime(true) - $startTime;
            $memoryUsage = memory_get_usage() - $memoryBefore;

            $executionTimes[] = $executionTime;
            $memoryUsages[] = $memoryUsage;

            $this->assertTrue($installationResult->isSuccessful());
            $this->assertTrue($extensionResult->isSuccessful());
        }

        // Performance should be consistent
        $avgExecutionTime = array_sum($executionTimes) / \count($executionTimes);
        $maxExecutionTime = max($executionTimes);
        $minExecutionTime = min($executionTimes);

        $this->assertLessThan(10.0, $avgExecutionTime, 'Average execution time should be reasonable');
        $this->assertLessThan(
            $avgExecutionTime * 3,
            $maxExecutionTime,
            'Maximum execution time should not be excessive',
        );

        // Memory usage should be reasonable
        $avgMemoryUsage = array_sum($memoryUsages) / \count($memoryUsages);
        $this->assertLessThan(
            100 * 1024 * 1024,
            $avgMemoryUsage,
            'Average memory usage should be under 100MB',
        );
    }

    /**
     * Test concurrent service operations (simulated).
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryService::discoverInstallation
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testServiceConcurrentOperations(): void
    {
        $installationPath = $this->fixturesPath . '/ComposerInstallation';

        // Simulate concurrent operations by running multiple discoveries in quick succession
        $results = [];

        for ($i = 0; $i < 3; ++$i) {
            $results[] = [
                'installation' => $this->installationDiscoveryService->discoverInstallation($installationPath),
                'extension' => $this->extensionDiscoveryService->discoverExtensions($installationPath),
            ];
        }

        // All operations should succeed
        foreach ($results as $index => $result) {
            $this->assertTrue(
                $result['installation']->isSuccessful(),
                "Installation discovery {$index} should succeed",
            );
            $this->assertTrue(
                $result['extension']->isSuccessful(),
                "Extension discovery {$index} should succeed",
            );
        }

        // Results should be consistent across concurrent operations
        $firstInstallation = $results[0]['installation']->getInstallation();
        $this->assertNotNull($firstInstallation, 'First installation result should not be null');
        $firstInstallationVersion = $firstInstallation->getVersion()->toString();
        $firstExtensionCount = \count($results[0]['extension']->getExtensions());

        foreach ($results as $result) {
            $installation = $result['installation']->getInstallation();
            $this->assertNotNull($installation, 'Installation result should not be null');

            $this->assertEquals(
                $firstInstallationVersion,
                $installation->getVersion()->toString(),
                'Installation version should be consistent across concurrent operations',
            );

            $this->assertEquals(
                $firstExtensionCount,
                \count($result['extension']->getExtensions()),
                'Extension count should be consistent across concurrent operations',
            );
        }
    }
}
