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
 * Summary of Rector analysis results with aggregated metrics.
 */
class RectorAnalysisSummary
{
    public function __construct(
        private readonly int $totalFindings,
        private readonly int $criticalIssues,
        private readonly int $warnings,
        private readonly int $infoIssues,
        private readonly int $suggestions,
        private readonly int $affectedFiles,
        private readonly int $totalFiles,
        private readonly array $ruleBreakdown,
        private readonly array $fileBreakdown,
        private readonly array $typeBreakdown,
        private readonly float $complexityScore,
        private readonly int $estimatedFixTime
    ) {}

    public function getTotalFindings(): int
    {
        return $this->totalFindings;
    }

    public function getCriticalIssues(): int
    {
        return $this->criticalIssues;
    }

    public function getWarnings(): int
    {
        return $this->warnings;
    }

    public function getInfoIssues(): int
    {
        return $this->infoIssues;
    }

    public function getSuggestions(): int
    {
        return $this->suggestions;
    }

    public function getAffectedFiles(): int
    {
        return $this->affectedFiles;
    }

    public function getTotalFiles(): int
    {
        return $this->totalFiles;
    }

    /**
     * Get breakdown of findings by rule class.
     *
     * @return array<string, int> Rule class name => count
     */
    public function getRuleBreakdown(): array
    {
        return $this->ruleBreakdown;
    }

    /**
     * Get breakdown of findings by file.
     *
     * @return array<string, int> File path => count
     */
    public function getFileBreakdown(): array
    {
        return $this->fileBreakdown;
    }

    /**
     * Get breakdown of findings by change type.
     *
     * @return array<string, int> Change type => count
     */
    public function getTypeBreakdown(): array
    {
        return $this->typeBreakdown;
    }

    public function getComplexityScore(): float
    {
        return $this->complexityScore;
    }

    /**
     * Get estimated time to fix all issues in minutes.
     */
    public function getEstimatedFixTime(): int
    {
        return $this->estimatedFixTime;
    }

    /**
     * Get estimated time to fix all issues in hours (rounded).
     */
    public function getEstimatedFixTimeHours(): float
    {
        return round($this->estimatedFixTime / 60, 1);
    }

    /**
     * Check if there are breaking changes present.
     */
    public function hasBreakingChanges(): bool
    {
        return $this->criticalIssues > 0;
    }

    /**
     * Check if there are deprecations present.
     */
    public function hasDeprecations(): bool
    {
        return $this->warnings > 0;
    }

    /**
     * Check if analysis found any issues.
     */
    public function hasIssues(): bool
    {
        return $this->totalFindings > 0;
    }

    /**
     * Get percentage of files affected by issues.
     */
    public function getFileImpactPercentage(): float
    {
        if ($this->totalFiles === 0) {
            return 0.0;
        }

        return round(($this->affectedFiles / $this->totalFiles) * 100, 1);
    }

    /**
     * Get top issues by file count.
     *
     * @return array<string, int> Top files with most issues
     */
    public function getTopIssuesByFile(int $limit = 10): array
    {
        $breakdown = $this->fileBreakdown;
        arsort($breakdown);
        return array_slice($breakdown, 0, $limit, true);
    }

    /**
     * Get top issues by rule count.
     *
     * @return array<string, int> Top rules with most occurrences
     */
    public function getTopIssuesByRule(int $limit = 10): array
    {
        $breakdown = $this->ruleBreakdown;
        arsort($breakdown);
        return array_slice($breakdown, 0, $limit, true);
    }

    /**
     * Get severity distribution.
     *
     * @return array<string, int> Severity level => count
     */
    public function getSeverityDistribution(): array
    {
        return [
            'critical' => $this->criticalIssues,
            'warning' => $this->warnings,
            'info' => $this->infoIssues,
            'suggestion' => $this->suggestions,
        ];
    }

    /**
     * Get upgrade readiness score (1-10, higher = more ready).
     */
    public function getUpgradeReadinessScore(): float
    {
        $baseScore = 10.0;

        // Penalize based on issues found
        $baseScore -= ($this->criticalIssues * 0.8);
        $baseScore -= ($this->warnings * 0.3);
        $baseScore -= ($this->infoIssues * 0.1);

        // Factor in complexity
        $baseScore -= ($this->complexityScore / 2);

        // Factor in file impact
        $fileImpactPenalty = ($this->getFileImpactPercentage() / 100) * 2;
        $baseScore -= $fileImpactPenalty;

        return max(1.0, min(10.0, $baseScore));
    }

    /**
     * Get risk assessment level.
     */
    public function getRiskLevel(): string
    {
        $readinessScore = $this->getUpgradeReadinessScore();

        if ($readinessScore >= 8.0) {
            return 'low';
        } elseif ($readinessScore >= 6.0) {
            return 'medium';
        } elseif ($readinessScore >= 3.0) {
            return 'high';
        } else {
            return 'critical';
        }
    }

    /**
     * Get human-readable summary.
     */
    public function getSummaryText(): string
    {
        if ($this->totalFindings === 0) {
            return 'No issues found - extension appears ready for upgrade';
        }

        $parts = [];

        if ($this->criticalIssues > 0) {
            $parts[] = "{$this->criticalIssues} critical issue" . ($this->criticalIssues > 1 ? 's' : '');
        }

        if ($this->warnings > 0) {
            $parts[] = "{$this->warnings} deprecation" . ($this->warnings > 1 ? 's' : '');
        }

        if ($this->infoIssues > 0) {
            $parts[] = "{$this->infoIssues} improvement" . ($this->infoIssues > 1 ? 's' : '');
        }

        $summary = 'Found ' . implode(', ', $parts);
        $summary .= " affecting {$this->affectedFiles} file" . ($this->affectedFiles > 1 ? 's' : '');

        if ($this->estimatedFixTime > 0) {
            $hours = $this->getEstimatedFixTimeHours();
            $summary .= " (est. {$hours}h to fix)";
        }

        return $summary;
    }
}
