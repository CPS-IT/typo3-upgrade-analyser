<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector;

/**
 * Enumeration for Rector rule severity levels.
 */
enum RectorRuleSeverity: string
{
    case CRITICAL = 'critical';    // Breaking changes that prevent upgrade
    case WARNING = 'warning';      // Deprecations that will break in future versions
    case INFO = 'info';           // Code improvements and best practices
    case SUGGESTION = 'suggestion'; // Optional optimizations and enhancements

    /**
     * Get the risk weight for calculating overall risk scores.
     */
    public function getRiskWeight(): float
    {
        return match ($this) {
            self::CRITICAL => 1.0,
            self::WARNING => 0.6,
            self::INFO => 0.2,
            self::SUGGESTION => 0.1,
        };
    }

    /**
     * Get human-readable display name.
     */
    public function getDisplayName(): string
    {
        return match ($this) {
            self::CRITICAL => 'Critical',
            self::WARNING => 'Warning',
            self::INFO => 'Info',
            self::SUGGESTION => 'Suggestion',
        };
    }

    /**
     * Get detailed description of severity level.
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::CRITICAL => 'Breaking changes that must be fixed before upgrade',
            self::WARNING => 'Deprecated code that will cause issues in future TYPO3 versions',
            self::INFO => 'Code improvements and adherence to TYPO3 best practices',
            self::SUGGESTION => 'Optional optimizations and code quality enhancements',
        };
    }

    /**
     * Check if this severity level requires immediate action.
     */
    public function requiresImmediateAction(): bool
    {
        return self::CRITICAL === $this;
    }

    /**
     * Check if this severity level indicates deprecated code.
     */
    public function isDeprecation(): bool
    {
        return self::WARNING === $this;
    }
}
