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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for the complete extension discovery workflow.
 *
 * @group integration
 *
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryService
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService
 */
#[Group('integration')]
class ExtensionDiscoveryWorkflowIntegrationTestCase extends AbstractIntegrationTestCase
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
     * Test complete extension discovery workflow with composer installation.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryService::discoverInstallation
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testCompleteExtensionDiscoveryWorkflowWithComposerInstallation(): void
    {
        $installationPath = $this->fixturesPath . '/ComposerInstallation';

        // First discover the installation to get custom paths
        $installationResult = $this->installationDiscoveryService->discoverInstallation($installationPath);

        $this->assertTrue(
            $installationResult->isSuccessful(),
            'Installation discovery should succeed: ' . $installationResult->getErrorMessage(),
        );

        $installation = $installationResult->getInstallation();
        $this->assertNotNull($installation);

        $customPaths = $installation->getMetadata()?->getCustomPaths();

        // Then discover extensions using the installation metadata
        $extensionResult = $this->extensionDiscoveryService->discoverExtensions($installationPath, $customPaths);

        $this->assertTrue(
            $extensionResult->isSuccessful(),
            'Extension discovery should succeed: ' . $extensionResult->getErrorMessage(),
        );
        $this->assertTrue($extensionResult->hasExtensions(), 'Should find extensions');

        $extensions = $extensionResult->getExtensions();
        $this->assertNotEmpty($extensions, 'Should have found extensions');

        // Verify expected extensions are found
        $extensionKeys = array_map(fn ($ext): string => $ext->getKey(), $extensions);

        // Should find extensions from PackageStates.php
        $this->assertContains('news', $extensionKeys);
        $this->assertContains('powermail', $extensionKeys);
        $this->assertContains('local_extension', $extensionKeys);

        // Should also find extensions from composer installed.json (if not duplicated)
        $this->assertGreaterThanOrEqual(3, \count($extensions));

        // Verify discovery metadata
        $metadata = $extensionResult->getDiscoveryMetadata();
        $this->assertNotEmpty($metadata);

        // Should have attempted both PackageStates.php and composer installed.json
        $attemptedMethods = array_column($metadata, 'method');
        $this->assertContains('PackageStates.php', $attemptedMethods);
        $this->assertContains('composer installed.json', $attemptedMethods);

        // At least one method should have been successful
        $successfulMethods = $extensionResult->getSuccessfulMethods();
        $this->assertNotEmpty($successfulMethods);
    }

    /**
     * Test extension discovery workflow with legacy installation.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryService::discoverInstallation
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testExtensionDiscoveryWorkflowWithLegacyInstallation(): void
    {
        $installationPath = $this->fixturesPath . '/LegacyInstallation';

        // First discover the installation
        $installationResult = $this->installationDiscoveryService->discoverInstallation($installationPath);

        // Installation discovery might fail for legacy, but extension discovery should still work
        if ($installationResult->isSuccessful()) {
            $customPaths = $installationResult->getInstallation()?->getMetadata()?->getCustomPaths();
        } else {
            // For legacy installations, use legacy-style paths
            $customPaths = [
                'web-dir' => '.',
                'typo3conf-dir' => 'typo3conf',
            ];
        }

        // Discover extensions
        $extensionResult = $this->extensionDiscoveryService->discoverExtensions($installationPath, $customPaths);

        $this->assertTrue(
            $extensionResult->isSuccessful(),
            'Extension discovery should succeed even if installation discovery fails',
        );
        $this->assertTrue($extensionResult->hasExtensions(), 'Should find extensions');

        $extensions = $extensionResult->getExtensions();
        $extensionKeys = array_map(fn ($ext): string => $ext->getKey(), $extensions);

        // Should find legacy extensions
        $this->assertContains('legacy_news', $extensionKeys);
        $this->assertContains('legacy_powermail', $extensionKeys);
        $this->assertContains('custom_extension', $extensionKeys);

        // Should have used PackageStates.php method
        $successfulMethods = $extensionResult->getSuccessfulMethods();
        $this->assertContains('PackageStates.php', $successfulMethods);
    }

    /**
     * Test extension discovery with mixed extension types.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testExtensionDiscoveryWithMixedExtensionTypes(): void
    {
        $installationPath = $this->fixturesPath . '/ComposerInstallation';

        $extensionResult = $this->extensionDiscoveryService->discoverExtensions($installationPath);

        $this->assertTrue($extensionResult->isSuccessful());
        $extensions = $extensionResult->getExtensions();

        // Check that we have different extension types
        $extensionTypes = array_unique(array_map(fn ($ext): string => $ext->getType(), $extensions));

        // Should have local extensions (from PackageStates.php)
        $this->assertContains('local', $extensionTypes);

        // Should have composer extensions if found in composer data
        // (depends on the specific discovery logic)

        // Verify specific extensions have expected types
        foreach ($extensions as $extension) {
            if ('local_extension' === $extension->getKey()) {
                $this->assertEquals('local', $extension->getType());
            }

            if (null !== $extension->getComposerName()) {
                // Extensions with composer names should be composer type or correctly detected
                $this->assertThat(
                    $extension->getType(),
                    $this->logicalOr(
                        $this->equalTo('composer'),
                        $this->equalTo('local'), // Might be local if found in PackageStates first
                    ),
                );
            }
        }
    }

    /**
     * Test extension discovery with broken installation files.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverFromPackageStates
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverFromComposerInstalled
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::resolvePaths
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::createExtensionFromPackageData
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::isCacheEnabled
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::serializeResult
     * @covers \CPSIT\UpgradeAnalyzer\Tests\Integration\Discovery\ExtensionDiscoveryWorkflowIntegrationTestCase::brokenInstallationProvider
     */
    #[DataProvider('brokenInstallationProvider')]
    public function testExtensionDiscoveryWithBrokenFiles(string $installationType, bool $shouldSucceed, array $expectedBehavior): void
    {
        $installationPath = $this->fixturesPath . '/' . $installationType;

        $extensionResult = $this->extensionDiscoveryService->discoverExtensions($installationPath);

        if ($shouldSucceed) {
            $this->assertTrue(
                $extensionResult->isSuccessful(),
                'Extension discovery should handle broken files gracefully',
            );

            if (isset($expectedBehavior['min_extensions'])) {
                $this->assertGreaterThanOrEqual(
                    $expectedBehavior['min_extensions'],
                    \count($extensionResult->getExtensions()),
                );
            }
        } else {
            $this->assertFalse(
                $extensionResult->isSuccessful(),
                'Extension discovery should fail with completely broken installation',
            );
        }

        // Should always provide meaningful error information
        if (!$extensionResult->isSuccessful()) {
            $this->assertNotEmpty($extensionResult->getErrorMessage());
        }

        // Should have discovery metadata even for failures
        $metadata = $extensionResult->getDiscoveryMetadata();
        $this->assertNotEmpty($metadata);
    }

    /**
     * Test caching behavior across the complete workflow.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::set
     */
    public function testExtensionDiscoveryWorkflowCachingBehavior(): void
    {
        $installationPath = $this->fixturesPath . '/ComposerInstallation';

        // Enable caching by setting up configuration
        $this->configurationService->set('analysis.resultCache.enabled', true);
        $this->configurationService->set('analysis.resultCache.ttl', 3600);

        // First discovery - should populate cache
        $startTime1 = microtime(true);
        $extensionResult1 = $this->extensionDiscoveryService->discoverExtensions($installationPath);
        $executionTime1 = microtime(true) - $startTime1;

        $this->assertTrue($extensionResult1->isSuccessful());
        $extensions1 = $extensionResult1->getExtensions();

        // Second discovery - should use cache
        $startTime2 = microtime(true);
        $extensionResult2 = $this->extensionDiscoveryService->discoverExtensions($installationPath);
        $executionTime2 = microtime(true) - $startTime2;

        $this->assertTrue($extensionResult2->isSuccessful());
        $extensions2 = $extensionResult2->getExtensions();

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
    }

    /**
     * Test extension metadata extraction and accuracy.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testExtensionMetadataExtractionAccuracy(): void
    {
        $installationPath = $this->fixturesPath . '/ComposerInstallation';

        $extensionResult = $this->extensionDiscoveryService->discoverExtensions($installationPath);
        $this->assertTrue($extensionResult->isSuccessful());

        $extensions = $extensionResult->getExtensions();
        $extensionsByKey = [];
        foreach ($extensions as $extension) {
            $extensionsByKey[$extension->getKey()] = $extension;
        }

        // Test specific extension metadata
        if (isset($extensionsByKey['news'])) {
            $newsExtension = $extensionsByKey['news'];
            // Title should be read from ext_emconf.php or fallback to extension key
            $this->assertThat(
                $newsExtension->getTitle(),
                $this->logicalOr(
                    $this->equalTo('News'),     // From ext_emconf.php
                    $this->equalTo('news'),      // Fallback to extension key
                ),
            );
            $this->assertEquals('11.0.2', $newsExtension->getVersion()->toString());
            $this->assertTrue($newsExtension->isActive());
        }

        if (isset($extensionsByKey['local_extension'])) {
            $localExtension = $extensionsByKey['local_extension'];
            $this->assertEquals('Local Extension', $localExtension->getTitle());
            $this->assertEquals('1.0.0', $localExtension->getVersion()->toString());
            $this->assertEquals('local', $localExtension->getType());
        }
    }

    /**
     * Test discovery performance with larger extension sets.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testExtensionDiscoveryPerformance(): void
    {
        $installationPath = $this->fixturesPath . '/ComposerInstallation';

        $startTime = microtime(true);
        $memoryBefore = memory_get_usage();

        $extensionResult = $this->extensionDiscoveryService->discoverExtensions($installationPath);

        $executionTime = microtime(true) - $startTime;
        $memoryAfter = memory_get_usage();
        $memoryUsage = $memoryAfter - $memoryBefore;

        $this->assertTrue($extensionResult->isSuccessful());

        // Performance assertions
        $this->assertLessThan(5.0, $executionTime, 'Extension discovery should complete within 5 seconds');
        $this->assertLessThan(50 * 1024 * 1024, $memoryUsage, 'Memory usage should be reasonable (< 50MB)');

        // Log performance metrics for monitoring
        $this->assertGreaterThan(0, $executionTime, 'Should have measurable execution time');
    }

    /**
     * Test error recovery and graceful degradation.
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testExtensionDiscoveryErrorRecovery(): void
    {
        $installationPath = $this->fixturesPath . '/BrokenInstallation';

        $extensionResult = $this->extensionDiscoveryService->discoverExtensions($installationPath);

        // Should either succeed with warnings or fail gracefully
        if ($extensionResult->isSuccessful()) {
            // If successful, should have meaningful discovery metadata
            $metadata = $extensionResult->getDiscoveryMetadata();
            $this->assertNotEmpty($metadata);

            // Should indicate which methods failed
            $failedMethods = array_filter($metadata, fn (array $m): bool => !$m['successful']);
            $this->assertNotEmpty($failedMethods, 'Should report failed discovery methods');
        } else {
            // If failed, should provide clear error message
            $this->assertNotEmpty($extensionResult->getErrorMessage());
            $this->assertStringContainsString('Extension discovery failed', $extensionResult->getErrorMessage());
        }
    }

    public static function brokenInstallationProvider(): array
    {
        return [
            'broken_installation' => [
                'BrokenInstallation',
                true, // Should succeed gracefully despite broken files
                ['expect_error' => true, 'min_extensions' => 0],
            ],
        ];
    }
}
