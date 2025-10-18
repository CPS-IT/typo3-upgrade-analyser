<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor;

/**
 * Enumeration for types of changes Fractor can detect and suggest.
 */
enum FractorChangeType: string
{
    case FLEXFORM_MIGRATION = 'flexform_migration';
    case TCA_MIGRATION = 'tca_migration';
    case TYPOSCRIPT_MIGRATION = 'typoscript_migration';
    case FLUID_MIGRATION = 'fluid_migration';
    case CONFIGURATION_UPDATE = 'configuration_update';
    case TEMPLATE_UPDATE = 'template_update';
    case MODERNIZATION = 'modernization';
    case DEPRECATION_REMOVAL = 'deprecation_removal';

    /**
     * Get the category this change type belongs to.
     */
    public function getCategory(): string
    {
        return match ($this) {
            self::FLEXFORM_MIGRATION => 'FlexForm Updates',
            self::TCA_MIGRATION => 'TCA Configuration',
            self::TYPOSCRIPT_MIGRATION => 'TypoScript Updates',
            self::FLUID_MIGRATION => 'Fluid Template Updates',
            self::CONFIGURATION_UPDATE => 'Configuration',
            self::TEMPLATE_UPDATE => 'Templates',
            self::MODERNIZATION => 'Code Modernization',
            self::DEPRECATION_REMOVAL => 'Deprecation Fixes',
        };
    }

    /**
     * Get human-readable display name.
     */
    public function getDisplayName(): string
    {
        return match ($this) {
            self::FLEXFORM_MIGRATION => 'FlexForm Migration',
            self::TCA_MIGRATION => 'TCA Migration',
            self::TYPOSCRIPT_MIGRATION => 'TypoScript Migration',
            self::FLUID_MIGRATION => 'Fluid Migration',
            self::CONFIGURATION_UPDATE => 'Configuration Update',
            self::TEMPLATE_UPDATE => 'Template Update',
            self::MODERNIZATION => 'Code Modernization',
            self::DEPRECATION_REMOVAL => 'Deprecation Removal',
        };
    }

    /**
     * Get estimated effort in minutes to manually apply this type of change.
     */
    public function getEstimatedEffort(): int
    {
        return match ($this) {
            self::FLEXFORM_MIGRATION => 15,
            self::TCA_MIGRATION => 20,
            self::TYPOSCRIPT_MIGRATION => 10,
            self::FLUID_MIGRATION => 5,
            self::CONFIGURATION_UPDATE => 10,
            self::TEMPLATE_UPDATE => 8,
            self::MODERNIZATION => 5,
            self::DEPRECATION_REMOVAL => 3,
        };
    }

    /**
     * Check if this change type typically requires manual intervention.
     */
    public function requiresManualIntervention(): bool
    {
        return match ($this) {
            self::FLEXFORM_MIGRATION, self::TCA_MIGRATION => true,
            default => false,
        };
    }

    /**
     * Get the priority for this change type (higher = more important).
     */
    public function getPriority(): int
    {
        return match ($this) {
            self::DEPRECATION_REMOVAL => 10,
            self::FLEXFORM_MIGRATION, self::TCA_MIGRATION => 8,
            self::CONFIGURATION_UPDATE => 6,
            self::TYPOSCRIPT_MIGRATION, self::FLUID_MIGRATION => 4,
            self::TEMPLATE_UPDATE, self::MODERNIZATION => 2,
        };
    }
}
