<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\DTO;

final readonly class VersionProfile
{
    public function __construct(
        public int $majorVersion,
        public string $defaultVendorDir,
        public string $defaultWebDir,
        public string $legacyDefaultWebDir,
        public string $corePackagePrefix,
        public string $legacyCoreExtensionDir,
        public bool $supportsComposerMode,
        public bool $supportsLegacyMode,
    ) {
    }
}
