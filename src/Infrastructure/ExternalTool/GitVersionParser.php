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
use CPSIT\UpgradeAnalyzer\Infrastructure\Version\ComposerConstraintCheckerInterface;

/**
 * Parses Git repository versions and determines TYPO3 compatibility.
 */
class GitVersionParser
{
    public function __construct(
        private readonly ComposerConstraintCheckerInterface $constraintChecker,
    ) {
    }

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
        return $this->constraintChecker->isComposerJsonCompatible($composerJson, $targetVersion);
    }
}
