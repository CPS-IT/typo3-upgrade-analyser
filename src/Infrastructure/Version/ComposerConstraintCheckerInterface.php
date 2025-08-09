<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Version;

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;

/**
 * Interface for checking Composer version constraints.
 */
interface ComposerConstraintCheckerInterface
{
    /**
     * Check if a Composer constraint is compatible with target TYPO3 version.
     */
    public function isConstraintCompatible(string $constraint, Version $targetVersion): bool;

    /**
     * Check if composer.json requirements are compatible with target TYPO3 version.
     */
    public function isComposerJsonCompatible(?array $composerJson, Version $targetVersion): bool;

    /**
     * Extract TYPO3-related requirements from composer.json requirements.
     */
    public function findTypo3Requirements(array $requirements): array;

    /**
     * Normalize a Version object to Composer-compatible version string.
     */
    public function normalizeVersion(Version $version): string;
}
