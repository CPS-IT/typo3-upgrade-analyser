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
 * Generic interface for analyzer summary objects.
 *
 * This interface provides a common contract for all analyzer summary types
 * to enable consistent data access and template rendering.
 */
interface FindingsSummaryInterface
{
    /**
     * Get the total number of findings discovered.
     */
    public function getTotalFindings(): int;

    /**
     * Get the number of files scanned by the analyzer.
     */
    public function getFilesScanned(): int;

    /**
     * Get the number of rules applied during analysis.
     */
    public function getRulesApplied(): int;

    /**
     * Check if the analysis was successful.
     */
    public function isSuccessful(): bool;

    /**
     * Check if any findings were discovered.
     */
    public function hasFindings(): bool;

    /**
     * Get error message if analysis failed.
     */
    public function getErrorMessage(): ?string;

    /**
     * Convert summary to array format for serialization and templating.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
