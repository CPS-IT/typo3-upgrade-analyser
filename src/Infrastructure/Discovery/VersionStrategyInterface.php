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

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;

/**
 * Interface for TYPO3 version extraction strategies.
 *
 * Different strategies can extract TYPO3 version information from various sources:
 * - composer.lock files (most reliable for Composer installations)
 * - composer.json files (constraint-based detection)
 * - PackageStates.php files (system extension versions)
 * - TYPO3 source files (direct version class inspection)
 * - Legacy version files (for older installations)
 */
interface VersionStrategyInterface
{
    /**
     * Extract TYPO3 version from an installation path.
     *
     * @param string $installationPath Path to the TYPO3 installation
     *
     * @return Version|null The detected version, or null if not found
     */
    public function extractVersion(string $installationPath): ?Version;

    /**
     * Check if this strategy can extract version from the given installation.
     *
     * @param string $installationPath Path to check
     *
     * @return bool True if this strategy can be applied
     */
    public function supports(string $installationPath): bool;

    /**
     * Get the priority of this version extraction strategy.
     *
     * Higher priority strategies are tried first. More reliable strategies
     * (like composer.lock) should have higher priority than less reliable
     * ones (like version file parsing).
     *
     * @return int Priority value (higher = higher priority)
     */
    public function getPriority(): int;

    /**
     * Get a human-readable name for this version strategy.
     *
     * @return string Strategy name
     */
    public function getName(): string;

    /**
     * Get the files/paths this strategy requires to operate.
     *
     * @return array<string> Array of required file paths relative to installation root
     */
    public function getRequiredFiles(): array;

    /**
     * Get the reliability score of this strategy.
     *
     * Returns a float between 0.0 and 1.0 indicating how reliable
     * this strategy is for version detection.
     *
     * @return float Reliability score (0.0 = unreliable, 1.0 = highly reliable)
     */
    public function getReliabilityScore(): float;
}
