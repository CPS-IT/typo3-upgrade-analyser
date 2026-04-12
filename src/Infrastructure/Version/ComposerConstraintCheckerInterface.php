<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
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
     *
     * When $targetVersion->hasPatch() is false (e.g. constructed from "13.4"), the check
     * is performed against the ceiling of the minor series (13.4.9999). This allows
     * constraints like "^13.4.20" to be correctly treated as compatible with an
     * upgrade target of "13.4" (meaning the entire 13.4.x series).
     *
     * When $targetVersion->hasPatch() is true (e.g. constructed from "13.4.0"), the check
     * is performed against that exact version. "^13.4.20" would return false for "13.4.0".
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
