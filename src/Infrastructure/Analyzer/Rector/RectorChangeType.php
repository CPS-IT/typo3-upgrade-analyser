<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector;

/**
 * Enumeration for types of changes Rector can detect and suggest.
 */
enum RectorChangeType: string
{
    case BREAKING_CHANGE = 'breaking_change';
    case DEPRECATION = 'deprecation';
    case METHOD_SIGNATURE = 'method_signature';
    case CLASS_REMOVAL = 'class_removal';
    case INTERFACE_CHANGE = 'interface_change';
    case CONFIGURATION_CHANGE = 'configuration_change';
    case ANNOTATION_CHANGE = 'annotation_change';
    case BEST_PRACTICE = 'best_practice';
    case PERFORMANCE = 'performance';
    case SECURITY = 'security';
    case CODE_STYLE = 'code_style';

    /**
     * Get the category this change type belongs to.
     */
    public function getCategory(): string
    {
        return match ($this) {
            self::BREAKING_CHANGE, self::CLASS_REMOVAL => 'Breaking Changes',
            self::DEPRECATION => 'Deprecations',
            self::METHOD_SIGNATURE, self::INTERFACE_CHANGE => 'API Changes',
            self::CONFIGURATION_CHANGE, self::ANNOTATION_CHANGE => 'Configuration',
            self::BEST_PRACTICE, self::CODE_STYLE => 'Code Quality',
            self::PERFORMANCE => 'Performance',
            self::SECURITY => 'Security',
        };
    }

    /**
     * Get human-readable display name.
     */
    public function getDisplayName(): string
    {
        return match ($this) {
            self::BREAKING_CHANGE => 'Breaking Change',
            self::DEPRECATION => 'Deprecation',
            self::METHOD_SIGNATURE => 'Method Signature Change',
            self::CLASS_REMOVAL => 'Class Removal',
            self::INTERFACE_CHANGE => 'Interface Change',
            self::CONFIGURATION_CHANGE => 'Configuration Change',
            self::ANNOTATION_CHANGE => 'Annotation Change',
            self::BEST_PRACTICE => 'Best Practice',
            self::PERFORMANCE => 'Performance Optimization',
            self::SECURITY => 'Security Improvement',
            self::CODE_STYLE => 'Code Style',
        };
    }

    /**
     * Get estimated effort in minutes to fix this type of change.
     */
    public function getEstimatedEffort(): int
    {
        return match ($this) {
            self::BREAKING_CHANGE => 60,  // 1 hour for breaking changes
            self::CLASS_REMOVAL => 45,    // 45 minutes for class removals
            self::INTERFACE_CHANGE => 30, // 30 minutes for interface changes
            self::METHOD_SIGNATURE => 20, // 20 minutes for method changes
            self::CONFIGURATION_CHANGE => 15, // 15 minutes for config changes
            self::DEPRECATION => 10,      // 10 minutes for deprecations
            self::ANNOTATION_CHANGE => 5, // 5 minutes for annotation changes
            self::BEST_PRACTICE => 8,     // 8 minutes for best practices
            self::PERFORMANCE => 12,      // 12 minutes for performance improvements
            self::SECURITY => 25,         // 25 minutes for security fixes
            self::CODE_STYLE => 3,        // 3 minutes for code style
        };
    }

    /**
     * Check if this change type requires manual intervention.
     */
    public function requiresManualIntervention(): bool
    {
        return match ($this) {
            self::BREAKING_CHANGE,
            self::CLASS_REMOVAL,
            self::INTERFACE_CHANGE,
            self::METHOD_SIGNATURE,
            self::CONFIGURATION_CHANGE => true,
            default => false,
        };
    }

    /**
     * Get the severity level for this change type.
     */
    public function getSeverity(): RectorRuleSeverity
    {
        return match ($this) {
            self::BREAKING_CHANGE, self::CLASS_REMOVAL => RectorRuleSeverity::CRITICAL,
            self::DEPRECATION, self::INTERFACE_CHANGE, self::METHOD_SIGNATURE => RectorRuleSeverity::WARNING,
            self::CONFIGURATION_CHANGE, self::ANNOTATION_CHANGE, self::SECURITY => RectorRuleSeverity::INFO,
            self::BEST_PRACTICE, self::PERFORMANCE, self::CODE_STYLE => RectorRuleSeverity::SUGGESTION,
        };
    }

    /**
     * Get the color code for UI display.
     */
    public function getColorCode(): string
    {
        return match ($this) {
            self::BREAKING_CHANGE, self::CLASS_REMOVAL, self::SECURITY => '#dc3545', // Red
            self::DEPRECATION, self::INTERFACE_CHANGE, self::METHOD_SIGNATURE => '#ffc107', // Yellow
            self::CONFIGURATION_CHANGE, self::ANNOTATION_CHANGE => '#17a2b8', // Blue
            self::BEST_PRACTICE, self::PERFORMANCE, self::CODE_STYLE => '#28a745', // Green
        };
    }
}
