<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Discovery;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Installation;

/**
 * Interface for installation validation rules.
 *
 * Validation rules check various aspects of discovered TYPO3 installations:
 * - Required files and directories exist
 * - File permissions are correct
 * - Extension structure is valid
 * - Configuration files are readable
 * - No obvious corruption indicators
 */
interface ValidationRuleInterface
{
    /**
     * Validate an installation against this rule.
     *
     * @param Installation $installation The installation to validate
     *
     * @return array<ValidationIssue> Array of validation issues found
     */
    public function validate(Installation $installation): array;

    /**
     * Get the name of this validation rule.
     *
     * @return string Rule name
     */
    public function getName(): string;

    /**
     * Get the severity level of issues this rule detects.
     *
     * @return ValidationSeverity Severity level
     */
    public function getSeverity(): ValidationSeverity;

    /**
     * Get a description of what this rule validates.
     *
     * @return string Rule description
     */
    public function getDescription(): string;

    /**
     * Check if this rule applies to the given installation.
     *
     * Some rules may only apply to specific installation types or versions.
     *
     * @param Installation $installation Installation to check
     *
     * @return bool True if this rule should be applied
     */
    public function appliesTo(Installation $installation): bool;

    /**
     * Get the category of this validation rule.
     *
     * Categories help organize rules and allow selective validation.
     * Common categories: 'structure', 'permissions', 'integrity', 'performance'
     *
     * @return string Rule category
     */
    public function getCategory(): string;
}
