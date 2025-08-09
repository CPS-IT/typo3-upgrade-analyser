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
 * Result of Rector binary execution.
 */
class RectorExecutionResult
{
    /**
     * @param array<RectorFinding> $findings
     * @param array<string>        $errors
     */
    public function __construct(
        private readonly bool $successful,
        private readonly array $findings,
        private readonly array $errors,
        private readonly float $executionTime,
        private readonly int $exitCode,
        private readonly string $rawOutput,
        private readonly int $processedFileCount = 0,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    /**
     * @return array<RectorFinding>
     */
    public function getFindings(): array
    {
        return $this->findings;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * @return array<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getExecutionTime(): float
    {
        return $this->executionTime;
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function getRawOutput(): string
    {
        return $this->rawOutput;
    }

    public function getProcessedFileCount(): int
    {
        return $this->processedFileCount;
    }

    public function getTotalIssueCount(): int
    {
        return \count($this->findings);
    }

    /**
     * Check if execution was successful and found issues.
     */
    public function hasFindings(): bool
    {
        return $this->successful && !empty($this->findings);
    }

    /**
     * Get findings by severity level.
     *
     * @return array<RectorFinding>
     */
    public function getFindingsBySeverity(RectorRuleSeverity $severity): array
    {
        return array_values(array_filter(
            $this->findings,
            fn (RectorFinding $finding) => $finding->getSeverity() === $severity,
        ));
    }

    /**
     * Get findings by change type.
     *
     * @return array<RectorFinding>
     */
    public function getFindingsByChangeType(RectorChangeType $changeType): array
    {
        return array_values(array_filter(
            $this->findings,
            fn (RectorFinding $finding) => $finding->getChangeType() === $changeType,
        ));
    }

    /**
     * Get summary statistics.
     *
     * @return array<string, mixed>
     */
    public function getSummaryStats(): array
    {
        $severityCounts = [
            'critical' => 0,
            'warning' => 0,
            'info' => 0,
            'suggestion' => 0,
        ];

        $typeCounts = [];
        $fileCounts = [];

        foreach ($this->findings as $finding) {
            ++$severityCounts[$finding->getSeverity()->value];

            $type = $finding->getChangeType()->value;
            $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;

            $file = $finding->getFile();
            $fileCounts[$file] = ($fileCounts[$file] ?? 0) + 1;
        }

        return [
            'total_findings' => \count($this->findings),
            'processed_files' => $this->processedFileCount,
            'affected_files' => \count($fileCounts),
            'execution_time' => $this->executionTime,
            'severity_counts' => $severityCounts,
            'type_counts' => $typeCounts,
            'file_counts' => $fileCounts,
        ];
    }
}
