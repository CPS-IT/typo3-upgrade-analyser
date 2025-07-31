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
 * Parses Git repository versions and determines TYPO3 compatibility
 */
class GitVersionParser
{
    /**
     * Find Git tags that are compatible with the target TYPO3 version
     *
     * @param array<GitTag> $tags
     * @return array<GitTag>
     */
    public function findCompatibleVersions(array $tags, Version $targetVersion): array
    {
        $compatibleTags = [];
        
        foreach ($tags as $tag) {
            if ($this->isTagCompatibleWithTypo3Version($tag, $targetVersion)) {
                $compatibleTags[] = $tag;
            }
        }
        
        // Sort by date (newest first) or by semantic version
        usort($compatibleTags, function (GitTag $a, GitTag $b) {
            if ($a->getDate() && $b->getDate()) {
                return $b->getDate() <=> $a->getDate();
            }
            
            // Fall back to version comparison
            $aVersion = $a->getSemanticVersion();
            $bVersion = $b->getSemanticVersion();
            
            if ($aVersion && $bVersion) {
                return version_compare($bVersion, $aVersion);
            }
            
            return 0;
        });
        
        return $compatibleTags;
    }

    /**
     * Check if composer.json content is compatible with target TYPO3 version
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
     * Check if a Git tag is compatible with target TYPO3 version
     */
    private function isTagCompatibleWithTypo3Version(GitTag $tag, Version $targetVersion): bool
    {
        $semanticVersion = $tag->getSemanticVersion();
        
        if (!$semanticVersion) {
            // For non-semantic versions, we can't determine compatibility from tag alone
            return false;
        }
        
        // Exclude pre-release versions
        if ($tag->isPreRelease()) {
            return false;
        }
        
        // Extract major and minor versions
        $major = $tag->getMajorVersion();
        $minor = $tag->getMinorVersion();
        
        if ($major === null) {
            return false;
        }
        
        $targetMajor = $targetVersion->getMajor();
        $targetMinor = $targetVersion->getMinor();
        
        // For TYPO3 extensions, we typically expect:
        // - Major version might correspond to TYPO3 major version
        // - Or extensions might use their own versioning scheme
        
        // For Git tags, we use exact major version matching
        // Tag major version must match TYPO3 major version
        if ($major === $targetMajor) {
            // For extensions with TYPO3-style versioning, 
            // we need at least the same minor version
            if ($minor !== null && $targetMinor !== null) {
                return $minor >= $targetMinor;
            }
            return true;
        }
        
        return false;
    }

    /**
     * Check if a composer constraint is compatible with target TYPO3 version
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
            return $constraintVersion->getMajor() === $targetVersion->getMajor() &&
                   $constraintVersion->getMinor() <= $targetVersion->getMinor();
        }
        
        // Handle tilde constraints (~12.4)
        if (str_starts_with($constraint, '~')) {
            $version = substr($constraint, 1);
            $constraintVersion = Version::fromString($version);
            
            // Tilde allows patch-level updates only
            return $constraintVersion->getMajor() === $targetVersion->getMajor() &&
                   $constraintVersion->getMinor() === $targetVersion->getMinor();
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
                return $minor === null || $minor <= $targetVersion->getMinor();
            }
        }
        
        return false;
    }
}