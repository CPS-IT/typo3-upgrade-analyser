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
 * Interface for analyzers that provide detailed findings for report generation.
 *
 * This interface identifies analyzers that can provide structured finding objects
 * and summary data for detailed report pages and JSON export.
 */
interface DetailedAnalysisInterface
{
    /**
     * Get all findings discovered by this analyzer.
     *
     * @return array<AnalyzerFindingInterface>
     */
    public function getFindings(): array;

    /**
     * Get the analysis summary object.
     */
    public function getSummary(): FindingsSummaryInterface;

    /**
     * Get the analyzer type identifier (e.g., 'rector', 'fractor').
     */
    public function getAnalyzerType(): string;

    /**
     * Check if this analysis has detailed findings available.
     */
    public function hasDetailedFindings(): bool;
}
