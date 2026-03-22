<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Discovery;

use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\VersionProfileRegistry;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\VersionProfileRegistryFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VersionProfileRegistryFactory::class)]
final class VersionProfileRegistryFactoryTest extends TestCase
{
    private VersionProfileRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = VersionProfileRegistryFactory::create();
    }

    #[Test]
    public function factoryCreatesRegistryWithAllFourVersions(): void
    {
        $versions = $this->registry->getSupportedVersions();

        self::assertCount(4, $versions);
        self::assertSame([11, 12, 13, 14], $versions);
    }

    #[Test]
    #[DataProvider('versionModeDataProvider')]
    public function profileHasCorrectDiscoveryModes(
        int $version,
        bool $expectedComposer,
        bool $expectedLegacy,
    ): void {
        $profile = $this->registry->getProfile($version);

        self::assertSame($expectedComposer, $profile->supportsComposerMode);
        self::assertSame($expectedLegacy, $profile->supportsLegacyMode);
    }

    /**
     * @return iterable<string, array{int, bool, bool}>
     */
    public static function versionModeDataProvider(): iterable
    {
        yield 'v11 supports composer and legacy' => [11, true, true];
        yield 'v12 supports composer and legacy' => [12, true, true];
        yield 'v13 supports composer and legacy' => [13, true, true];
        yield 'v14 supports composer and legacy' => [14, true, true];
    }

    #[Test]
    #[DataProvider('versionDirectoryDataProvider')]
    public function profileHasCorrectDirectoryDefaults(
        int $version,
        string $expectedVendorDir,
        string $expectedWebDir,
    ): void {
        $profile = $this->registry->getProfile($version);

        self::assertSame($expectedVendorDir, $profile->defaultVendorDir);
        self::assertSame($expectedWebDir, $profile->defaultWebDir);
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function versionDirectoryDataProvider(): iterable
    {
        yield 'v11' => [11, 'vendor', 'public'];
        yield 'v12' => [12, 'vendor', 'public'];
        yield 'v13' => [13, 'vendor', 'public'];
        yield 'v14' => [14, 'vendor', 'public'];
    }

    #[Test]
    #[DataProvider('versionCorePackagePrefixDataProvider')]
    public function profileHasCorrectCorePackagePrefix(int $version): void
    {
        $profile = $this->registry->getProfile($version);

        self::assertSame('typo3/cms-', $profile->corePackagePrefix);
        self::assertTrue(str_starts_with('typo3/cms-core', $profile->corePackagePrefix));
        self::assertTrue(str_starts_with('typo3/cms-backend', $profile->corePackagePrefix));
        self::assertFalse(str_starts_with('georgringer/news', $profile->corePackagePrefix));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function versionCorePackagePrefixDataProvider(): iterable
    {
        yield 'v11' => [11];
        yield 'v12' => [12];
        yield 'v13' => [13];
        yield 'v14' => [14];
    }

    #[Test]
    #[DataProvider('versionLegacyDirDataProvider')]
    public function profileHasCorrectLegacyCoreExtensionDir(int $version): void
    {
        $profile = $this->registry->getProfile($version);

        self::assertSame('typo3/sysext', $profile->legacyCoreExtensionDir);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function versionLegacyDirDataProvider(): iterable
    {
        yield 'v11' => [11];
        yield 'v12' => [12];
        yield 'v13' => [13];
        yield 'v14' => [14];
    }

    #[Test]
    #[DataProvider('versionLegacyWebDirDataProvider')]
    public function profileHasCorrectLegacyDefaultWebDir(int $version): void
    {
        $profile = $this->registry->getProfile($version);

        self::assertSame('.', $profile->legacyDefaultWebDir);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function versionLegacyWebDirDataProvider(): iterable
    {
        yield 'v11' => [11];
        yield 'v12' => [12];
        yield 'v13' => [13];
        yield 'v14' => [14];
    }

    #[Test]
    public function eachProfileHasMajorVersionSet(): void
    {
        foreach ([11, 12, 13, 14] as $version) {
            $profile = $this->registry->getProfile($version);
            self::assertSame($version, $profile->majorVersion);
        }
    }
}
