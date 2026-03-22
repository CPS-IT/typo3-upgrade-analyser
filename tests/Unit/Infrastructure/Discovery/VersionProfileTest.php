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

use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\DTO\VersionProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VersionProfile::class)]
final class VersionProfileTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $profile = new VersionProfile(
            majorVersion: 11,
            defaultVendorDir: 'vendor',
            defaultWebDir: 'public',
            legacyDefaultWebDir: '.',
            corePackagePrefix: 'typo3/cms-',
            legacyCoreExtensionDir: 'typo3/sysext',
            supportsComposerMode: true,
            supportsLegacyMode: true,
        );

        self::assertSame(11, $profile->majorVersion);
        self::assertSame('vendor', $profile->defaultVendorDir);
        self::assertSame('public', $profile->defaultWebDir);
        self::assertSame('.', $profile->legacyDefaultWebDir);
        self::assertSame('typo3/cms-', $profile->corePackagePrefix);
        self::assertSame('typo3/sysext', $profile->legacyCoreExtensionDir);
        self::assertTrue($profile->supportsComposerMode);
        self::assertTrue($profile->supportsLegacyMode);
    }

    #[Test]
    public function composerOnlyProfileHasNoLegacySupport(): void
    {
        $profile = new VersionProfile(
            majorVersion: 13,
            defaultVendorDir: 'vendor',
            defaultWebDir: 'public',
            legacyDefaultWebDir: '.',
            corePackagePrefix: 'typo3/cms-',
            legacyCoreExtensionDir: 'typo3/sysext',
            supportsComposerMode: true,
            supportsLegacyMode: false,
        );

        self::assertTrue($profile->supportsComposerMode);
        self::assertFalse($profile->supportsLegacyMode);
    }

    #[Test]
    public function corePackagePrefixEnablesVendorMatching(): void
    {
        $profile = new VersionProfile(
            majorVersion: 12,
            defaultVendorDir: 'vendor',
            defaultWebDir: 'public',
            legacyDefaultWebDir: '.',
            corePackagePrefix: 'typo3/cms-',
            legacyCoreExtensionDir: 'typo3/sysext',
            supportsComposerMode: true,
            supportsLegacyMode: true,
        );

        self::assertTrue(str_starts_with('typo3/cms-core', $profile->corePackagePrefix));
        self::assertTrue(str_starts_with('typo3/cms-backend', $profile->corePackagePrefix));
        self::assertFalse(str_starts_with('vendor/some-ext', $profile->corePackagePrefix));
    }

    #[Test]
    #[DataProvider('versionProfileDataProvider')]
    public function profileReflectsVersionSpecificValues(
        int $majorVersion,
        bool $expectedComposerMode,
        bool $expectedLegacyMode,
    ): void {
        $profile = new VersionProfile(
            majorVersion: $majorVersion,
            defaultVendorDir: 'vendor',
            defaultWebDir: 'public',
            legacyDefaultWebDir: '.',
            corePackagePrefix: 'typo3/cms-',
            legacyCoreExtensionDir: 'typo3/sysext',
            supportsComposerMode: $expectedComposerMode,
            supportsLegacyMode: $expectedLegacyMode,
        );

        self::assertSame($majorVersion, $profile->majorVersion);
        self::assertSame($expectedComposerMode, $profile->supportsComposerMode);
        self::assertSame($expectedLegacyMode, $profile->supportsLegacyMode);
    }

    /**
     * @return iterable<string, array{int, bool, bool}>
     */
    public static function versionProfileDataProvider(): iterable
    {
        yield 'v11 supports both modes' => [11, true, true];
        yield 'v12 supports both modes' => [12, true, true];
        yield 'v13 supports both modes' => [13, true, true];
        yield 'v14 supports both modes' => [14, true, true];
    }
}
