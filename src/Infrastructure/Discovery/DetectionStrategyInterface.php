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

use CPSIT\UpgradeAnalyzer\Domain\Entity\Installation;

/**
 * Interface for TYPO3 installation detection strategies.
 *
 * Different detection strategies can be implemented to handle various TYPO3 installation patterns:
 * - Composer-based installations
 * - Legacy installations (future)
 * - Custom deployment patterns (future)
 */
interface DetectionStrategyInterface
{
    /**
     * Detect a TYPO3 installation at the given path.
     *
     * @param string $path Filesystem path to analyze
     *
     * @return Installation|null Returns Installation if detected, null otherwise
     */
    public function detect(string $path): ?Installation;

    /**
     * Check if this strategy can handle the given path.
     *
     * @param string $path Filesystem path to check
     *
     * @return bool True if this strategy can analyze the path
     */
    public function supports(string $path): bool;

    /**
     * Get the priority of this detection strategy.
     *
     * Higher priority strategies are tried first. This allows more specific
     * strategies to take precedence over generic ones.
     *
     * @return int Priority value (higher = higher priority)
     */
    public function getPriority(): int;

    /**
     * Get the required indicators that must be present for this strategy.
     *
     * Returns an array of file/directory names that must exist for this
     * strategy to consider attempting detection. This is used for quick
     * pre-filtering before calling supports().
     *
     * @return array<string> Array of required file/directory names
     */
    public function getRequiredIndicators(): array;

    /**
     * Get a human-readable name for this detection strategy.
     *
     * @return string Strategy name
     */
    public function getName(): string;

    /**
     * Get a description of what this strategy detects.
     *
     * @return string Strategy description
     */
    public function getDescription(): string;
}
