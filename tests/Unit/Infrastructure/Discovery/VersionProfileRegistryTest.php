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
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\VersionProfileRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VersionProfileRegistry::class)]
final class VersionProfileRegistryTest extends TestCase
{
    private VersionProfileRegistry $registry;

    protected function setUp(): void
    {
        $v11 = new VersionProfile(
            majorVersion: 11,
            defaultVendorDir: 'vendor',
            defaultWebDir: 'public',
            legacyDefaultWebDir: '.',
            corePackagePrefix: 'typo3/cms-',
            legacyCoreExtensionDir: 'typo3/sysext',
            supportsComposerMode: true,
            supportsLegacyMode: true,
        );
        $v12 = new VersionProfile(
            majorVersion: 12,
            defaultVendorDir: 'vendor',
            defaultWebDir: 'public',
            legacyDefaultWebDir: '.',
            corePackagePrefix: 'typo3/cms-',
            legacyCoreExtensionDir: 'typo3/sysext',
            supportsComposerMode: true,
            supportsLegacyMode: true,
        );

        $this->registry = new VersionProfileRegistry([
            11 => $v11,
            12 => $v12,
        ]);
    }

    #[Test]
    public function getProfileReturnsCorrectProfileForKnownVersion(): void
    {
        $profile = $this->registry->getProfile(11);

        self::assertSame(11, $profile->majorVersion);
        self::assertTrue($profile->supportsLegacyMode);
    }

    #[Test]
    public function getProfileReturnsCorrectProfileForSecondVersion(): void
    {
        $profile = $this->registry->getProfile(12);

        self::assertSame(12, $profile->majorVersion);
        self::assertSame('typo3/cms-', $profile->corePackagePrefix);
    }

    #[Test]
    public function getProfileThrowsExceptionForUnknownVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No version profile registered for TYPO3 v99');

        $this->registry->getProfile(99);
    }

    #[Test]
    public function getSupportedVersionsReturnsAllRegisteredVersions(): void
    {
        $versions = $this->registry->getSupportedVersions();

        self::assertSame([11, 12], $versions);
    }

    #[Test]
    public function emptyRegistryReturnsEmptySupportedVersions(): void
    {
        $emptyRegistry = new VersionProfileRegistry([]);

        self::assertSame([], $emptyRegistry->getSupportedVersions());
    }
}
