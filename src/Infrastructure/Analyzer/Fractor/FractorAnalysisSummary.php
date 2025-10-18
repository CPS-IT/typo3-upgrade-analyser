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

use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Shared\FindingsSummaryInterface;

/**
 * Summary of Fractor analysis results.
 */
readonly class FractorAnalysisSummary implements FindingsSummaryInterface
{
    /**
     * @param array<FractorFinding> $findings
     * @param array<string>         $filePaths
     * @param array<string>         $appliedRules
     */
    public function __construct(
        public int $filesScanned,
        public int $rulesApplied,
        public array $findings,
        public bool $successful,
        public int $changeBlocks = 0,
        public int $changedLines = 0,
        public array $filePaths = [],
        public array $appliedRules = [],
        public ?string $errorMessage = null,
    ) {
    }

    public function hasFindings(): bool
    {
        return $this->filesScanned > 0 || $this->getTotalIssues() > 0;
    }

    public function getTotalIssues(): int
    {
        return $this->rulesApplied;
    }

    /**
     * Get the total number of findings discovered (interface method).
     */
    public function getTotalFindings(): int
    {
        return \count($this->findings);
    }

    /**
     * Get the number of files scanned by the analyzer (interface method).
     */
    public function getFilesScanned(): int
    {
        return $this->filesScanned;
    }

    /**
     * Get the number of rules applied during analysis (interface method).
     */
    public function getRulesApplied(): int
    {
        return $this->rulesApplied;
    }

    /**
     * Check if the analysis was successful (interface method).
     */
    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    /**
     * Get error message if analysis failed (interface method).
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * Convert summary to array format for serialization.
     *
     * @return array<string, mixed> Summary data as associative array
     */
    public function toArray(): array
    {
        return [
            // Interface-required fields
            'total_findings' => $this->getTotalFindings(),
            'files_scanned' => $this->filesScanned,
            'rules_applied' => $this->rulesApplied,
            'successful' => $this->successful,
            'has_findings' => $this->hasFindings(),
            'error_message' => $this->errorMessage,

            // Fractor-specific fields
            'findings' => array_map(
                static fn (FractorFinding $finding): array => $finding->toArray(),
                $this->findings,
            ),
            'change_blocks' => $this->changeBlocks,
            'changed_lines' => $this->changedLines,
            'file_paths' => $this->filePaths,
            'applied_rules' => $this->appliedRules,
            'total_issues' => $this->getTotalIssues(),
        ];
    }
}
