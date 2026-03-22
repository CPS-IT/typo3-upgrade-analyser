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

readonly class VersionProfileRegistry
{
    /**
     * @param array<int, VersionProfile> $profiles
     */
    public function __construct(
        private array $profiles,
    ) {
    }

    public function getProfile(int $majorVersion): VersionProfile
    {
        if (!isset($this->profiles[$majorVersion])) {
            throw new \InvalidArgumentException(\sprintf('No version profile registered for TYPO3 v%d', $majorVersion));
        }

        return $this->profiles[$majorVersion];
    }

    /**
     * @return array<int>
     */
    public function getSupportedVersions(): array
    {
        return array_keys($this->profiles);
    }
}
