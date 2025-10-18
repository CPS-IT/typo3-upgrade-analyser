<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Shared;

/**
 * Interface for analyzer findings that provide additional helper methods.
 *
 * This interface defines optional helper functionality that analyzer findings
 * can implement for enhanced display and processing capabilities.
 */
interface AnalyzerFindingHelperInterface
{
    /**
     * Get a short description of the file location.
     */
    public function getFileLocation(): string;

    /**
     * Check if this finding requires immediate attention.
     */
    public function requiresImmediateAction(): bool;

    /**
     * Get the analyzer type from the rule class namespace.
     */
    public function getAnalyzerType(): string;

    /**
     * Compare findings for sorting by priority (highest first).
     */
    public function comparePriority(AnalyzerFindingInterface $other): int;

    /**
     * Get severity level as display-friendly string.
     */
    public function getSeverityDisplay(): string;

    /**
     * Get effort category for display.
     */
    public function getEffortCategory(): string;
}
