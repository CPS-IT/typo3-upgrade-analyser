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
 * Collection class for categorized Rector findings with structured access methods.
 */
class RectorFindingsCollection
{
    /**
     * @param array<RectorFinding> $breakingChanges
     * @param array<RectorFinding> $deprecations
     * @param array<RectorFinding> $improvements
     * @param array<string, array<RectorFinding>> $bySeverity
     * @param array<string, array<RectorFinding>> $byFile
     * @param array<string, array<RectorFinding>> $byRule
     */
    public function __construct(
        private readonly array $breakingChanges,
        private readonly array $deprecations,
        private readonly array $improvements,
        private readonly array $bySeverity,
        private readonly array $byFile,
        private readonly array $byRule,
    )
    {
    }

    /**
     * Get all breaking change findings.
     *
     * @return array<RectorFinding>
     */
    public function getBreakingChanges(): array
    {
        return $this->breakingChanges;
    }

    /**
     * Get all deprecation findings.
     *
     * @return array<RectorFinding>
     */
    public function getDeprecations(): array
    {
        return $this->deprecations;
    }

    /**
     * Get all improvement findings.
     *
     * @return array<RectorFinding>
     */
    public function getImprovements(): array
    {
        return $this->improvements;
    }

    /**
     * Get findings grouped by severity level.
     *
     * @return array<string, array<RectorFinding>>
     */
    public function getBySeverity(): array
    {
        return $this->bySeverity;
    }

    /**
     * Get findings for a specific severity level.
     *
     * @return array<RectorFinding>
     */
    public function getFindingsWithSeverity(RectorRuleSeverity $severity): array
    {
        return $this->bySeverity[$severity->value] ?? [];
    }

    /**
     * Get findings grouped by file path.
     *
     * @return array<string, array<RectorFinding>>
     */
    public function getByFile(): array
    {
        return $this->byFile;
    }

    /**
     * Get findings for a specific file.
     *
     * @return array<RectorFinding>
     */
    public function getFindingsInFile(string $filePath): array
    {
        return $this->byFile[$filePath] ?? [];
    }

    /**
     * Get findings grouped by Rector rule class.
     *
     * @return array<string, array<RectorFinding>>
     */
    public function getByRule(): array
    {
        return $this->byRule;
    }

    /**
     * Get findings for a specific Rector rule.
     *
     * @return array<RectorFinding>
     */
    public function getFindingsForRule(string $ruleClass): array
    {
        return $this->byRule[$ruleClass] ?? [];
    }

    /**
     * Check if there are any breaking changes.
     */
    public function hasBreakingChanges(): bool
    {
        return !empty($this->breakingChanges);
    }

    /**
     * Check if there are any deprecations.
     */
    public function hasDeprecations(): bool
    {
        return !empty($this->deprecations);
    }

    /**
     * Check if there are any improvements.
     */
    public function hasImprovements(): bool
    {
        return !empty($this->improvements);
    }

    /**
     * Get total count of findings across all categories.
     */
    public function getTotalCount(): int
    {
        return count($this->breakingChanges) + count($this->deprecations) + count($this->improvements);
    }

    /**
     * Get count of findings by severity.
     *
     * @return array<string, int>
     */
    public function getSeverityCounts(): array
    {
        return array_map('count', $this->bySeverity);
    }

    /**
     * Get count of findings by file.
     *
     * @return array<string, int>
     */
    public function getFileCounts(): array
    {
        return array_map('count', $this->byFile);
    }

    /**
     * Get count of findings by rule.
     *
     * @return array<string, int>
     */
    public function getRuleCounts(): array
    {
        return array_map('count', $this->byRule);
    }

    /**
     * Get files with the most findings (sorted descending).
     *
     * @return array<string, int>
     */
    public function getTopAffectedFiles(int $limit = 10): array
    {
        $fileCounts = $this->getFileCounts();
        arsort($fileCounts);

        return array_slice($fileCounts, 0, $limit, true);
    }

    /**
     * Get rules that were triggered most often (sorted descending).
     *
     * @return array<string, int>
     */
    public function getTopTriggeredRules(int $limit = 10): array
    {
        $ruleCounts = $this->getRuleCounts();
        arsort($ruleCounts);

        return array_slice($ruleCounts, 0, $limit, true);
    }

    /**
     * Get count of unique files affected.
     */
    public function getAffectedFileCount(): int
    {
        return count($this->byFile);
    }

    /**
     * Get count of unique rules triggered.
     */
    public function getTriggeredRuleCount(): int
    {
        return count($this->byRule);
    }

    /**
     * Get count of findings by change type.
     *
     * @return array<string, int>
     */
    public function getTypeCounts(): array
    {
        $counts = [];
        $allFindings = array_merge($this->breakingChanges, $this->deprecations, $this->improvements);

        foreach ($allFindings as $finding) {
            $type = $finding->getChangeType()->value;
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        return $counts;
    }
}
