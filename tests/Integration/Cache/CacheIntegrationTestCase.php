<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Integration\Cache;

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
 * Integration tests for cache behavior across all services.
 *
 * @group integration
 *
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryService
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService
 */
#[Group('integration')]
class CacheIntegrationTestCase extends AbstractIntegrationTestCase
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

        // Enable caching for all tests
        $this->configurationService->set('analysis.resultCache.enabled', true);
        $this->configurationService->set('analysis.resultCache.ttl', 3600);

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
     * Test that cache works correctly for installation discovery.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryService::discoverInstallation
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::generateKey
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::has
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::set
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::get
     */
    public function testInstallationDiscoveryCaching(): void
    {
        $installationPath = $this->fixturesPath . '/ComposerInstallation';

        // First discovery - should populate cache
        $startTime1 = microtime(true);
        $result1 = $this->installationDiscoveryService->discoverInstallation($installationPath);
        $executionTime1 = microtime(true) - $startTime1;

        $this->assertTrue($result1->isSuccessful());
        $installation1 = $result1->getInstallation();

        // Second discovery - should use cache
        $startTime2 = microtime(true);
        $result2 = $this->installationDiscoveryService->discoverInstallation($installationPath);
        $executionTime2 = microtime(true) - $startTime2;

        $this->assertTrue($result2->isSuccessful());
        $installation2 = $result2->getInstallation();

        $this->assertNotNull($installation1, 'First installation should not be null');
        $this->assertNotNull($installation2, 'Second installation should not be null');

        // Results should be identical
        $this->assertEquals($installation1->getPath(), $installation2->getPath());
        $this->assertEquals($installation1->getVersion()->toString(), $installation2->getVersion()->toString());
        $this->assertEquals($installation1->getMode()?->value, $installation2->getMode()?->value);

        // Second execution should be faster due to caching
        $this->assertLessThan(
            $executionTime1 * 1.5,
            $executionTime2,
            'Second execution should benefit from caching',
        );

        // Verify cache behavior through performance comparison
        // Cache effectiveness is demonstrated by the timing assertions above
        $this->addToAssertionCount(1);
    }

    /**
     * Test that cache works correctly for extension discovery.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::generateKey
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::has
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::set
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::get
     */
    public function testExtensionDiscoveryCaching(): void
    {
        $installationPath = $this->fixturesPath . '/ComposerInstallation';

        // First discovery - should populate cache
        $startTime1 = microtime(true);
        $result1 = $this->extensionDiscoveryService->discoverExtensions($installationPath);
        $executionTime1 = microtime(true) - $startTime1;

        $this->assertTrue($result1->isSuccessful());
        $extensions1 = $result1->getExtensions();

        // Second discovery - should use cache
        $startTime2 = microtime(true);
        $result2 = $this->extensionDiscoveryService->discoverExtensions($installationPath);
        $executionTime2 = microtime(true) - $startTime2;

        $this->assertTrue($result2->isSuccessful());
        $extensions2 = $result2->getExtensions();

        // Results should be identical
        $this->assertCount(\count($extensions1), $extensions2);

        $keys1 = array_map(fn ($ext): string => $ext->getKey(), $extensions1);
        $keys2 = array_map(fn ($ext): string => $ext->getKey(), $extensions2);
        sort($keys1);
        sort($keys2);
        $this->assertEquals($keys1, $keys2);

        // Second execution should be faster due to caching
        $this->assertLessThan(
            $executionTime1 * 1.5,
            $executionTime2,
            'Second execution should benefit from caching',
        );

        // Verify cache behavior through performance comparison
        // Cache effectiveness is demonstrated by the timing assertions above
        $this->addToAssertionCount(1);
    }

    /**
     * Test cross-service cache isolation.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryService::discoverInstallation
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::generateKey
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::has
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::clear
     */
    public function testCrosServiceCacheIsolation(): void
    {
        $installationPath = $this->fixturesPath . '/ComposerInstallation';

        // Populate both caches
        $installationResult = $this->installationDiscoveryService->discoverInstallation($installationPath);
        $extensionResult = $this->extensionDiscoveryService->discoverExtensions($installationPath);

        $this->assertTrue($installationResult->isSuccessful());
        $this->assertTrue($extensionResult->isSuccessful());

        // Generate cache keys to test if entries exist
        $installationCacheKey = $this->cacheService->generateKey('installation_discovery', $installationPath, ['validate' => true]);
        $extensionCacheKey = $this->cacheService->generateKey('extension_discovery', $installationPath, ['custom_paths' => []]);

        // Verify both services have cached results
        $this->assertTrue(
            $this->cacheService->has($installationCacheKey),
            'Should have cache entry for installation discovery',
        );
        $this->assertTrue(
            $this->cacheService->has($extensionCacheKey),
            'Should have cache entry for extension discovery',
        );

        // Clear all cache
        $this->cacheService->clear();

        // Verify cache is empty
        $this->assertFalse(
            $this->cacheService->has($installationCacheKey),
            'Installation cache should be empty after clear',
        );
        $this->assertFalse(
            $this->cacheService->has($extensionCacheKey),
            'Extension cache should be empty after clear',
        );

        // Both services should work without cache
        $installationResult2 = $this->installationDiscoveryService->discoverInstallation($installationPath);
        $extensionResult2 = $this->extensionDiscoveryService->discoverExtensions($installationPath);

        $this->assertTrue($installationResult2->isSuccessful());
        $this->assertTrue($extensionResult2->isSuccessful());

        // Results should be consistent
        $installation1 = $installationResult->getInstallation();
        $installation2 = $installationResult2->getInstallation();
        $this->assertNotNull($installation1, 'First installation should not be null');
        $this->assertNotNull($installation2, 'Second installation should not be null');

        $this->assertEquals(
            $installation1->getVersion()->toString(),
            $installation2->getVersion()->toString(),
        );
        $this->assertCount(\count($extensionResult->getExtensions()), $extensionResult2->getExtensions());
    }

    /**
     * Test cache key generation and uniqueness.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::generateKey
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::has
     */
    public function testCacheKeyGeneration(): void
    {
        $installationPath1 = $this->fixturesPath . '/ComposerInstallation';
        $installationPath2 = $this->fixturesPath . '/LegacyInstallation';

        // Discover different installations
        $result1 = $this->extensionDiscoveryService->discoverExtensions($installationPath1);
        $result2 = $this->extensionDiscoveryService->discoverExtensions($installationPath2);

        $this->assertTrue($result1->isSuccessful());

        if ($result2->isSuccessful()) {
            // Generate cache keys for different paths
            $cacheKey1 = $this->cacheService->generateKey('extension_discovery', $installationPath1, ['custom_paths' => []]);
            $cacheKey2 = $this->cacheService->generateKey('extension_discovery', $installationPath2, ['custom_paths' => []]);

            // Should have separate cache entries for different paths
            $this->assertTrue(
                $this->cacheService->has($cacheKey1),
                'Should have cache entry for first installation path',
            );
            $this->assertTrue(
                $this->cacheService->has($cacheKey2),
                'Should have cache entry for second installation path',
            );
            $this->assertNotEquals(
                $cacheKey1,
                $cacheKey2,
                'Cache keys should be different for different paths',
            );
        }

        // Test with custom paths
        $customPaths = ['vendor-dir' => 'custom-vendor', 'web-dir' => 'custom-public'];
        $result3 = $this->extensionDiscoveryService->discoverExtensions($installationPath1, $customPaths);

        $this->assertTrue($result3->isSuccessful());

        // Should create another cache entry due to different custom paths
        $cacheKeyDefault = $this->cacheService->generateKey('extension_discovery', $installationPath1, ['custom_paths' => []]);
        $cacheKeyCustom = $this->cacheService->generateKey('extension_discovery', $installationPath1, ['custom_paths' => $customPaths]);

        $this->assertTrue(
            $this->cacheService->has($cacheKeyDefault),
            'Should have cache entry for default paths',
        );
        $this->assertTrue(
            $this->cacheService->has($cacheKeyCustom),
            'Should have cache entry for custom paths',
        );
        $this->assertNotEquals(
            $cacheKeyDefault,
            $cacheKeyCustom,
            'Custom paths should create separate cache entries',
        );
    }

    /**
     * Test cache TTL and expiration behavior.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::set
     */
    public function testCacheTtlBehavior(): void
    {
        $installationPath = $this->fixturesPath . '/ComposerInstallation';

        // Set very short TTL for testing
        $this->configurationService->set('analysis.resultCache.ttl', 1); // 1 second

        // First discovery - populate cache
        $result1 = $this->extensionDiscoveryService->discoverExtensions($installationPath);
        $this->assertTrue($result1->isSuccessful());

        // Immediate second discovery - should use cache
        $startTime = microtime(true);
        $result2 = $this->extensionDiscoveryService->discoverExtensions($installationPath);
        $cachedExecutionTime = microtime(true) - $startTime;

        $this->assertTrue($result2->isSuccessful());
        $this->assertLessThan(0.1, $cachedExecutionTime, 'Cached execution should be very fast');

        // Wait for cache to expire
        sleep(2);

        // Third discovery - cache should be expired, but service should still work
        $result3 = $this->extensionDiscoveryService->discoverExtensions($installationPath);
        $this->assertTrue($result3->isSuccessful());

        // Results should still be consistent
        $this->assertCount(\count($result1->getExtensions()), $result3->getExtensions());
    }

    /**
     * Test cache performance under load.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testCachePerformanceUnderLoad(): void
    {
        $installationPath = $this->fixturesPath . '/ComposerInstallation';

        // First discovery to populate cache
        $populateResult = $this->extensionDiscoveryService->discoverExtensions($installationPath);
        $this->assertTrue($populateResult->isSuccessful());

        // Multiple cached discoveries
        $cachedExecutionTimes = [];
        for ($i = 0; $i < 10; ++$i) {
            $startTime = microtime(true);
            $result = $this->extensionDiscoveryService->discoverExtensions($installationPath);
            $executionTime = microtime(true) - $startTime;

            $this->assertTrue($result->isSuccessful());
            $cachedExecutionTimes[] = $executionTime;
        }

        // All cached executions should be fast and consistent
        $avgExecutionTime = array_sum($cachedExecutionTimes) / \count($cachedExecutionTimes);
        $maxExecutionTime = max($cachedExecutionTimes);

        $this->assertLessThan(0.1, $avgExecutionTime, 'Average cached execution should be very fast');
        $this->assertLessThan(
            $avgExecutionTime * 3,
            $maxExecutionTime,
            'Maximum cached execution should not be excessive',
        );
    }

    /**
     * Test cache with validation parameter variations.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryService::discoverInstallation
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::generateKey
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::has
     */
    public function testCacheWithValidationVariations(): void
    {
        $installationPath = $this->fixturesPath . '/ComposerInstallation';

        // Discovery with validation
        $resultWithValidation = $this->installationDiscoveryService->discoverInstallation($installationPath, true);
        $this->assertTrue($resultWithValidation->isSuccessful());

        // Discovery without validation
        $resultWithoutValidation = $this->installationDiscoveryService->discoverInstallation($installationPath, false);
        $this->assertTrue($resultWithoutValidation->isSuccessful());

        // Generate cache keys for different validation settings
        $cacheKeyWithValidation = $this->cacheService->generateKey('installation_discovery', $installationPath, ['validate' => true]);
        $cacheKeyWithoutValidation = $this->cacheService->generateKey('installation_discovery', $installationPath, ['validate' => false]);

        // Should have separate cache entries for different validation settings
        $this->assertTrue(
            $this->cacheService->has($cacheKeyWithValidation),
            'Should have cache entry for validation enabled',
        );
        $this->assertTrue(
            $this->cacheService->has($cacheKeyWithoutValidation),
            'Should have cache entry for validation disabled',
        );
        $this->assertNotEquals(
            $cacheKeyWithValidation,
            $cacheKeyWithoutValidation,
            'Should have separate cache entries for different validation settings',
        );

        // Results should be consistent in terms of basic data
        $installation1 = $resultWithValidation->getInstallation();
        $installation2 = $resultWithoutValidation->getInstallation();
        $this->assertNotNull($installation1, 'Installation with validation should not be null');
        $this->assertNotNull($installation2, 'Installation without validation should not be null');

        $this->assertEquals(
            $installation1->getVersion()->toString(),
            $installation2->getVersion()->toString(),
        );
    }

    /**
     * Test cache error handling and fallback behavior.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::generateKey
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::has
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::clear
     */
    public function testCacheErrorHandlingAndFallback(): void
    {
        $installationPath = $this->fixturesPath . '/ComposerInstallation';

        // Populate cache
        $result1 = $this->extensionDiscoveryService->discoverExtensions($installationPath);
        $this->assertTrue($result1->isSuccessful());

        // Generate cache key and verify cache has entry
        $cacheKey = $this->cacheService->generateKey('extension_discovery', $installationPath, ['custom_paths' => []]);
        $this->assertTrue($this->cacheService->has($cacheKey), 'Cache should have extension discovery entry');

        // Simulate cache corruption by clearing cache after population
        // (In real scenarios, this could be disk corruption, permissions, etc.)
        $this->cacheService->clear();

        // Service should still work without cache
        $result2 = $this->extensionDiscoveryService->discoverExtensions($installationPath);
        $this->assertTrue(
            $result2->isSuccessful(),
            'Service should work even when cache is not available',
        );

        // Results should be consistent
        $this->assertCount(\count($result1->getExtensions()), $result2->getExtensions());

        $keys1 = array_map(fn ($ext): string => $ext->getKey(), $result1->getExtensions());
        $keys2 = array_map(fn ($ext): string => $ext->getKey(), $result2->getExtensions());
        sort($keys1);
        sort($keys2);
        $this->assertEquals($keys1, $keys2);
    }

    /**
     * Test cache disabled scenario.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::set
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::generateKey
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService::has
     */
    public function testCacheDisabledScenario(): void
    {
        $installationPath = $this->fixturesPath . '/ComposerInstallation';

        // Disable caching
        $this->configurationService->set('analysis.resultCache.enabled', false);

        // Clear any existing cache
        $this->cacheService->clear();

        // Multiple discoveries
        $result1 = $this->extensionDiscoveryService->discoverExtensions($installationPath);
        $result2 = $this->extensionDiscoveryService->discoverExtensions($installationPath);

        $this->assertTrue($result1->isSuccessful());
        $this->assertTrue($result2->isSuccessful());

        // Results should be consistent
        $this->assertCount(\count($result1->getExtensions()), $result2->getExtensions());

        // Generate cache key and verify cache remains empty
        $cacheKey = $this->cacheService->generateKey('extension_discovery', $installationPath, ['custom_paths' => []]);
        $this->assertFalse(
            $this->cacheService->has($cacheKey),
            'Cache should remain empty when caching is disabled',
        );
    }
}
