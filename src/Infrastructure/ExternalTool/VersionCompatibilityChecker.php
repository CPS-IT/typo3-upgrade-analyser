<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool;

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;

/**
 * Checks version compatibility for TER extension versions.
 */
class VersionCompatibilityChecker
{
    /**
     * Check if any version is compatible with the target TYPO3 version.
     */
    public function hasCompatibleVersion(array $versions, Version $typo3Version): bool
    {
        foreach ($versions as $versionData) {
            if ($this->isVersionCompatible($versionData, $typo3Version)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find all versions compatible with the target TYPO3 version.
     */
    public function findCompatibleVersions(array $versions, Version $typo3Version): array
    {
        $compatibleVersions = [];

        foreach ($versions as $versionData) {
            if ($this->isVersionCompatible($versionData, $typo3Version)) {
                if (isset($versionData['number'])) {
                    $compatibleVersions[] = $versionData['number'];
                }
            }
        }

        return $compatibleVersions;
    }

    /**
     * Get the latest compatible version.
     */
    public function getLatestCompatibleVersion(array $versions, Version $typo3Version): ?string
    {
        $compatibleVersions = $this->findCompatibleVersions($versions, $typo3Version);

        if (empty($compatibleVersions)) {
            return null;
        }

        // Sort versions and return the latest
        usort($compatibleVersions, 'version_compare');

        return end($compatibleVersions);
    }

    /**
     * Check if a specific version is compatible with TYPO3 version.
     */
    public function isVersionCompatible(array $versionData, Version $typo3Version): bool
    {
        if (!isset($versionData['typo3_versions'])) {
            return false;
        }

        $typo3Versions = $versionData['typo3_versions'];
        $majorVersion = $typo3Version->getMajor();

        // Check for universal compatibility
        if (\in_array('*', $typo3Versions, true)) {
            return true;
        }

        // Check each supported version for compatibility
        foreach ($typo3Versions as $supportedVersion) {
            if ($this->isVersionSupported($supportedVersion, $majorVersion)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a supported version matches the target major version.
     */
    private function isVersionSupported($supportedVersion, int $majorVersion): bool
    {
        // Handle integer versions (TER API returns integers like 8, 9, 10)
        if (\is_int($supportedVersion)) {
            return $supportedVersion === $majorVersion;
        }

        // Handle string versions
        $supportedVersionStr = (string) $supportedVersion;

        // Check for wildcard patterns like "12.*"
        if (str_ends_with($supportedVersionStr, '.*')) {
            $supportedMajor = (int) substr($supportedVersionStr, 0, -2);

            return $supportedMajor === $majorVersion;
        }

        // Check for exact major version match like "12.0"
        if (preg_match('/^(\d+)\.0$/', $supportedVersionStr, $matches)) {
            $supportedMajor = (int) $matches[1];

            return $supportedMajor === $majorVersion;
        }

        // Check for plain version number like "12" or "11"
        if (preg_match('/^(\d+)$/', $supportedVersionStr, $matches)) {
            $supportedMajor = (int) $matches[1];

            return $supportedMajor === $majorVersion;
        }

        return false;
    }
}
