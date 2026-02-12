<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
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
        // We cannot reliably determine tag compatibility without checking composer.json for EACH tag
        // The main branch's composer.json might be compatible, but older tags might not be
        // For example, main branch might support TYPO3 13, but v1.0.0 tag might only support TYPO3 11
        //
        // To properly determine compatibility, we would need to:
        // 1. Fetch composer.json for each tag (expensive - many API calls)
        // 2. Check TYPO3 constraints for each version
        //
        // Since we cannot do this efficiently, be conservative and return empty array
        // This means Git availability will be false unless we can verify compatibility

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
