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
 * Parses Git repository versions and determines TYPO3 compatibility.
 */
class GitVersionParser
{
    /**
     * Find Git tags that are compatible with the target TYPO3 version.
     *
     * For Git repositories, we need to check composer.json to determine TYPO3 compatibility
     * since extension version numbers are independent from TYPO3 versions.
     *
     * @param array<GitTag> $tags
     *
     * @return array<GitTag>
     */
    public function findCompatibleVersions(array $tags, Version $targetVersion, ?array $composerJson = null): array
    {
        // If we have composer.json from the main branch, check if it's compatible
        if ($composerJson && $this->isComposerCompatible($composerJson, $targetVersion)) {
            // If main branch is compatible, return all stable tags
            // This is a simplified approach - ideally we'd check composer.json for each tag
            return array_filter($tags, fn ($tag) => !$tag->isPreRelease());
        }

        // Fallback: without composer.json analysis, we can't reliably determine compatibility
        // Return empty array to be conservative
        return [];
    }

    /**
     * Check if composer.json content is compatible with target TYPO3 version.
     */
    public function isComposerCompatible(?array $composerJson, Version $targetVersion): bool
    {
        if (!$composerJson || !isset($composerJson['require'])) {
            return false;
        }

        $requirements = $composerJson['require'];

        // Check for TYPO3 core requirements
        $typo3Requirements = array_filter($requirements, function ($package) {
            return str_starts_with($package, 'typo3/cms-');
        }, ARRAY_FILTER_USE_KEY);

        if (empty($typo3Requirements)) {
            return false;
        }

        // Check if any TYPO3 requirement is compatible with target version
        foreach ($typo3Requirements as $package => $constraint) {
            if ($this->isConstraintCompatible($constraint, $targetVersion)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a composer constraint is compatible with target TYPO3 version.
     */
    private function isConstraintCompatible(string $constraint, Version $targetVersion): bool
    {
        // Simple constraint parsing - in a full implementation,
        // we'd use a proper composer constraint parser

        // Remove spaces
        $constraint = trim($constraint);

        // Handle caret constraints (^12.4)
        if (str_starts_with($constraint, '^')) {
            $version = substr($constraint, 1);
            $constraintVersion = Version::fromString($version);

            // Caret allows patch-level and minor updates
            return $constraintVersion->getMajor() === $targetVersion->getMajor()
                   && $constraintVersion->getMinor() <= $targetVersion->getMinor();
        }

        // Handle tilde constraints (~12.4)
        if (str_starts_with($constraint, '~')) {
            $version = substr($constraint, 1);
            $constraintVersion = Version::fromString($version);

            // Tilde allows patch-level updates only
            return $constraintVersion->getMajor() === $targetVersion->getMajor()
                   && $constraintVersion->getMinor() === $targetVersion->getMinor();
        }

        // Handle range constraints (>=12.0,<13.0)
        if (str_contains($constraint, ',')) {
            // This would need more sophisticated parsing
            return str_contains($constraint, (string) $targetVersion->getMajor());
        }

        // Handle exact version or simple constraint
        if (preg_match('/(\d+)\.?(\d+)?/', $constraint, $matches)) {
            $major = (int) $matches[1];
            $minor = isset($matches[2]) ? (int) $matches[2] : null;

            if ($major === $targetVersion->getMajor()) {
                return null === $minor || $minor <= $targetVersion->getMinor();
            }
        }

        return false;
    }
}
