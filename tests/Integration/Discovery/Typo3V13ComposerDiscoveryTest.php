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
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryServiceInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryServiceInterface;
use CPSIT\UpgradeAnalyzer\Shared\Configuration\ContainerFactory;
use CPSIT\UpgradeAnalyzer\Tests\Integration\AbstractIntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for TYPO3 v13 Composer installation discovery.
 *
 * Verifies that both the standard (public/) and custom-web-dir (web/) Composer
 * fixtures for TYPO3 v13 are detected correctly and that third-party extensions
 * are discovered while core packages (typo3/cms-*) are excluded.
 *
 * @group integration
 *
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryService
 */
#[Group('integration')]
final class Typo3V13ComposerDiscoveryTest extends AbstractIntegrationTestCase
{
    private ExtensionDiscoveryServiceInterface $extensionDiscoveryService;
    private InstallationDiscoveryServiceInterface $installationDiscoveryService;
    private CacheService $cacheService;
    private string $fixturesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturesPath = __DIR__ . '/../Fixtures/TYPO3Installations';

        $container = ContainerFactory::create();

        $extensionService = $container->get(ExtensionDiscoveryService::class);
        \assert($extensionService instanceof ExtensionDiscoveryService);
        $this->extensionDiscoveryService = $extensionService;

        $installationService = $container->get(InstallationDiscoveryService::class);
        \assert($installationService instanceof InstallationDiscoveryService);
        $this->installationDiscoveryService = $installationService;

        $cacheService = $container->get(CacheService::class);
        \assert($cacheService instanceof CacheService);
        $this->cacheService = $cacheService;

        $this->cacheService->clear();
    }

    protected function tearDown(): void
    {
        $this->cacheService->clear();
        parent::tearDown();
    }

    /**
     * Test that v13Composer fixture is detected as a TYPO3 v13 Composer installation.
     */
    public function testV13ComposerInstallationIsDetected(): void
    {
        $installationPath = $this->fixturesPath . '/v13Composer';

        $result = $this->installationDiscoveryService->discoverInstallation($installationPath);

        $this->assertTrue(
            $result->isSuccessful(),
            'Installation discovery should succeed for v13Composer fixture: ' . $result->getErrorMessage(),
        );

        $installation = $result->getInstallation();
        $this->assertNotNull($installation);
        $this->assertSame(13, $installation->getVersion()->getMajor());
        $this->assertSame('composer', $installation->getType());
    }

    /**
     * Test that ExtensionDiscoveryService finds third-party extensions and excludes core
     * packages in the v13Composer fixture.
     */
    public function testV13ComposerExtensionDiscoveryFindsThirdPartyExtensionsAndExcludesCore(): void
    {
        $installationPath = $this->fixturesPath . '/v13Composer';

        $installationResult = $this->installationDiscoveryService->discoverInstallation($installationPath);
        $this->assertTrue(
            $installationResult->isSuccessful(),
            'Installation discovery must succeed before extension discovery: ' . $installationResult->getErrorMessage(),
        );

        $customPaths = $installationResult->getInstallation()?->getMetadata()?->getCustomPaths();

        $extensionResult = $this->extensionDiscoveryService->discoverExtensions($installationPath, $customPaths);

        $this->assertTrue(
            $extensionResult->isSuccessful(),
            'Extension discovery should succeed for v13Composer fixture: ' . $extensionResult->getErrorMessage(),
        );
        $this->assertTrue($extensionResult->hasExtensions(), 'Should find at least one extension');

        $extensionKeys = array_map(static fn ($ext): string => $ext->getKey(), $extensionResult->getExtensions());

        // Third-party extensions must be found
        $this->assertContains('news', $extensionKeys, 'georgringer/news should be discovered');
        $this->assertContains('powermail', $extensionKeys, 'example/powermail should be discovered');

        // Core packages must not appear (filtered by typo3/cms-* prefix)
        $composerNames = array_filter(
            array_map(static fn ($ext): ?string => $ext->getComposerName(), $extensionResult->getExtensions()),
        );
        $this->assertNotEmpty($composerNames, 'At least one composer name must be present to validate core exclusion');
        foreach ($composerNames as $composerName) {
            $this->assertStringNotContainsString(
                'typo3/cms-',
                $composerName,
                "Core package '{$composerName}' must not appear in extension discovery results",
            );
        }
    }

    /**
     * Test that v13ComposerCustomWebDir fixture is detected as a TYPO3 v13 Composer installation.
     */
    public function testV13ComposerCustomWebDirInstallationIsDetected(): void
    {
        $installationPath = $this->fixturesPath . '/v13ComposerCustomWebDir';

        $result = $this->installationDiscoveryService->discoverInstallation($installationPath);

        $this->assertTrue(
            $result->isSuccessful(),
            'Installation discovery should succeed for v13ComposerCustomWebDir fixture: ' . $result->getErrorMessage(),
        );

        $installation = $result->getInstallation();
        $this->assertNotNull($installation);
        $this->assertSame(13, $installation->getVersion()->getMajor());
        $this->assertSame('composer', $installation->getType());
    }

    /**
     * Test that the custom web-dir ("web") from composer.json overrides the profile default ("public")
     * in the v13ComposerCustomWebDir fixture.
     */
    public function testV13ComposerCustomWebDirReadsWebDirFromComposerJson(): void
    {
        $installationPath = $this->fixturesPath . '/v13ComposerCustomWebDir';

        $result = $this->installationDiscoveryService->discoverInstallation($installationPath);
        $this->assertTrue(
            $result->isSuccessful(),
            'Installation discovery must succeed: ' . $result->getErrorMessage(),
        );

        $customPaths = $result->getInstallation()?->getMetadata()?->getCustomPaths();

        // The web-dir must resolve to "web" (from composer.json), not "public" (profile default)
        $this->assertNotNull($customPaths, 'customPaths must not be null for a Composer installation');
        $this->assertArrayHasKey('web-dir', $customPaths, 'customPaths must contain web-dir key');
        $this->assertSame(
            'web',
            basename($customPaths['web-dir']),
            'web-dir should be "web" (from composer.json), not the profile default "public"',
        );
    }

    /**
     * Test that extension discovery works for the v13ComposerCustomWebDir fixture.
     */
    public function testV13ComposerCustomWebDirExtensionDiscoveryFindsThirdPartyExtensions(): void
    {
        $installationPath = $this->fixturesPath . '/v13ComposerCustomWebDir';

        $installationResult = $this->installationDiscoveryService->discoverInstallation($installationPath);
        $this->assertTrue(
            $installationResult->isSuccessful(),
            'Installation discovery must succeed: ' . $installationResult->getErrorMessage(),
        );

        $customPaths = $installationResult->getInstallation()?->getMetadata()?->getCustomPaths();

        $extensionResult = $this->extensionDiscoveryService->discoverExtensions($installationPath, $customPaths);

        $this->assertTrue(
            $extensionResult->isSuccessful(),
            'Extension discovery should succeed for v13ComposerCustomWebDir fixture: ' . $extensionResult->getErrorMessage(),
        );
        $this->assertTrue($extensionResult->hasExtensions(), 'Should find at least one extension');

        $extensionKeys = array_map(static fn ($ext): string => $ext->getKey(), $extensionResult->getExtensions());

        $this->assertContains('news', $extensionKeys, 'georgringer/news should be discovered');
        $this->assertContains('powermail', $extensionKeys, 'example/powermail should be discovered');

        // Core packages must not appear
        $composerNames = array_filter(
            array_map(static fn ($ext): ?string => $ext->getComposerName(), $extensionResult->getExtensions()),
        );
        $this->assertNotEmpty($composerNames, 'At least one composer name must be present to validate core exclusion');
        foreach ($composerNames as $composerName) {
            $this->assertStringNotContainsString(
                'typo3/cms-',
                $composerName,
                "Core package '{$composerName}' must not appear in extension discovery results",
            );
        }
    }
}
