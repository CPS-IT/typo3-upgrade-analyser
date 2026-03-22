<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Discovery;

use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\DTO\VersionProfile;

class VersionProfileRegistryFactory
{
    public static function create(): VersionProfileRegistry
    {
        return new VersionProfileRegistry([
            11 => new VersionProfile(
                majorVersion: 11,
                defaultVendorDir: 'vendor',
                defaultWebDir: 'public',
                legacyDefaultWebDir: '.',
                corePackagePrefix: 'typo3/cms-',
                legacyCoreExtensionDir: 'typo3/sysext',
                supportsComposerMode: true,
                supportsLegacyMode: true,
            ),
            12 => new VersionProfile(
                majorVersion: 12,
                defaultVendorDir: 'vendor',
                defaultWebDir: 'public',
                legacyDefaultWebDir: '.',
                corePackagePrefix: 'typo3/cms-',
                legacyCoreExtensionDir: 'typo3/sysext',
                supportsComposerMode: true,
                supportsLegacyMode: true,
            ),
            13 => new VersionProfile(
                majorVersion: 13,
                defaultVendorDir: 'vendor',
                defaultWebDir: 'public',
                legacyDefaultWebDir: '.',
                corePackagePrefix: 'typo3/cms-',
                legacyCoreExtensionDir: 'typo3/sysext',
                supportsComposerMode: true,
                supportsLegacyMode: true,
            ),
            14 => new VersionProfile(
                majorVersion: 14,
                defaultVendorDir: 'vendor',
                defaultWebDir: 'public',
                legacyDefaultWebDir: '.',
                corePackagePrefix: 'typo3/cms-',
                legacyCoreExtensionDir: 'typo3/sysext',
                supportsComposerMode: true,
                supportsLegacyMode: true,
            ),
        ]);
    }
}
