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

use Psr\Log\LoggerInterface;

/**
 * Service for parsing and aggregating Rector analysis results.
 */
class RectorResultParser
{
    public function __construct(
        private readonly RectorRuleRegistry $ruleRegistry,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Parse Rector JSON output into structured findings.
     *
     * @return array<RectorFinding>
     */
    public function parseRectorOutput(string $jsonOutput): array
    {
        if (empty(trim($jsonOutput))) {
            return [];
        }

        try {
            $data = json_decode($jsonOutput, true, 512, JSON_THROW_ON_ERROR);

            return $this->extractFindingsFromData($data);
        } catch (\JsonException $e) {
            $this->logger->error('Failed to parse Rector JSON output', [
                'error' => $e->getMessage(),
                'output_preview' => substr($jsonOutput, 0, 200),
            ]);

            return [];
        }
    }

    /**
     * Aggregate findings into summary statistics.
     */
    public function aggregateFindings(array $findings): RectorAnalysisSummary
    {
        $totalFiles = $this->countUniqueFiles($findings);
        $affectedFiles = \count($this->groupFindingsByFile($findings));

        $severityCounts = $this->countBySeverity($findings);
        $typeCounts = $this->countByType($findings);
        $fileCounts = $this->groupFindingsByFile($findings);
        $ruleCounts = $this->groupFindingsByRule($findings);

        $complexityScore = $this->calculateComplexityScore($findings);
        $estimatedFixTime = $this->calculateEstimatedFixTime($findings);

        return new RectorAnalysisSummary(
            totalFindings: \count($findings),
            criticalIssues: $severityCounts['critical'],
            warnings: $severityCounts['warning'],
            infoIssues: $severityCounts['info'],
            suggestions: $severityCounts['suggestion'],
            affectedFiles: $affectedFiles,
            totalFiles: $totalFiles,
            ruleBreakdown: $ruleCounts,
            fileBreakdown: $fileCounts,
            typeBreakdown: $typeCounts,
            complexityScore: $complexityScore,
            estimatedFixTime: $estimatedFixTime,
        );
    }

    /**
     * Categorize findings by various criteria.
     *
     * @param array<RectorFinding> $findings
     *
     * @return array<string, array<RectorFinding>>
     */
    public function categorizeFindings(array $findings): array
    {
        $categories = [
            'breaking_changes' => [],
            'deprecations' => [],
            'improvements' => [],
            'by_severity' => [
                'critical' => [],
                'warning' => [],
                'info' => [],
                'suggestion' => [],
            ],
            'by_file' => [],
            'by_rule' => [],
        ];

        foreach ($findings as $finding) {
            // Categorize by impact type
            if ($finding->isBreakingChange()) {
                $categories['breaking_changes'][] = $finding;
            } elseif ($finding->isDeprecation()) {
                $categories['deprecations'][] = $finding;
            } else {
                $categories['improvements'][] = $finding;
            }

            // Categorize by severity
            $severityKey = $finding->getSeverity()->value;
            $categories['by_severity'][$severityKey][] = $finding;

            // Categorize by file
            $file = $finding->getFile();
            if (!isset($categories['by_file'][$file])) {
                $categories['by_file'][$file] = [];
            }
            $categories['by_file'][$file][] = $finding;

            // Categorize by rule
            $rule = $finding->getRuleClass();
            if (!isset($categories['by_rule'][$rule])) {
                $categories['by_rule'][$rule] = [];
            }
            $categories['by_rule'][$rule][] = $finding;
        }

        return $categories;
    }

    /**
     * Calculate complexity score based on findings characteristics.
     */
    public function calculateComplexityScore(array $findings): float
    {
        if (empty($findings)) {
            return 0.0;
        }

        $totalComplexity = 0.0;
        $weights = [
            'rule_diversity' => 0.3,    // More unique rules = more complex
            'file_spread' => 0.2,       // More files affected = more complex
            'severity_mix' => 0.3,      // Mix of severities = more complex
            'manual_intervention' => 0.2, // More manual fixes = more complex
        ];

        // Rule diversity factor
        $uniqueRules = \count(array_unique(array_map(fn ($f) => $f->getRuleClass(), $findings)));
        $ruleDiversity = min($uniqueRules / 10, 1.0); // Normalize to 0-1
        $totalComplexity += $ruleDiversity * $weights['rule_diversity'];

        // File spread factor
        $uniqueFiles = \count(array_unique(array_map(fn ($f) => $f->getFile(), $findings)));
        $fileSpread = min($uniqueFiles / 20, 1.0); // Normalize to 0-1
        $totalComplexity += $fileSpread * $weights['file_spread'];

        // Severity mix factor
        $severityCounts = $this->countBySeverity($findings);
        $severityEntropy = $this->calculateEntropy(array_values($severityCounts));
        $totalComplexity += $severityEntropy * $weights['severity_mix'];

        // Manual intervention factor
        $manualCount = \count(array_filter($findings, fn ($f) => $f->requiresManualIntervention()));
        $manualRatio = $manualCount / \count($findings);
        $totalComplexity += $manualRatio * $weights['manual_intervention'];

        return round($totalComplexity * 10, 1); // Scale to 0-10
    }

    /**
     * Extract findings from parsed JSON data.
     *
     * @return array<RectorFinding>
     */
    private function extractFindingsFromData(array $data): array
    {
        $findings = [];

        if (!isset($data['changed_files']) || !\is_array($data['changed_files'])) {
            return $findings;
        }

        foreach ($data['changed_files'] as $fileData) {
            $file = $fileData['file'] ?? '';

            if (!isset($fileData['applied_rectors']) || !\is_array($fileData['applied_rectors'])) {
                continue;
            }

            foreach ($fileData['applied_rectors'] as $rectorData) {
                $findings[] = $this->createFindingFromRectorData($file, $rectorData);
            }
        }

        $this->logger->info('Parsed Rector findings', [
            'total_findings' => \count($findings),
            'affected_files' => \count($data['changed_files']),
        ]);

        return $findings;
    }

    /**
     * Create RectorFinding from raw Rector data.
     */
    private function createFindingFromRectorData(string $file, array $rectorData): RectorFinding
    {
        $ruleClass = $rectorData['class'] ?? 'UnknownRule';
        $message = $rectorData['message'] ?? 'No message provided';
        $line = (int) ($rectorData['line'] ?? 0);
        $oldCode = $rectorData['old'] ?? null;
        $newCode = $rectorData['new'] ?? null;

        // Determine severity and change type based on rule class name patterns
        $severity = $this->inferSeverityFromRuleClass($ruleClass);
        $changeType = $this->inferChangeTypeFromRuleClass($ruleClass);

        $suggestedFix = null;
        if ($oldCode && $newCode && $oldCode !== $newCode) {
            $suggestedFix = "Replace '{$oldCode}' with '{$newCode}'";
        } elseif ($newCode && !$oldCode) {
            $suggestedFix = "Add: '{$newCode}'";
        } elseif ($oldCode && !$newCode) {
            $suggestedFix = "Remove: '{$oldCode}'";
        }

        return new RectorFinding(
            file: $file,
            line: $line,
            ruleClass: $ruleClass,
            message: $message,
            severity: $severity,
            changeType: $changeType,
            suggestedFix: $suggestedFix,
            oldCode: $oldCode,
            newCode: $newCode,
            context: $rectorData,
        );
    }

    /**
     * Count findings by severity.
     *
     * @param array<RectorFinding> $findings
     *
     * @return array<string, int>
     */
    private function countBySeverity(array $findings): array
    {
        $counts = [
            'critical' => 0,
            'warning' => 0,
            'info' => 0,
            'suggestion' => 0,
        ];

        foreach ($findings as $finding) {
            ++$counts[$finding->getSeverity()->value];
        }

        return $counts;
    }

    /**
     * Count findings by change type.
     *
     * @param array<RectorFinding> $findings
     *
     * @return array<string, int>
     */
    private function countByType(array $findings): array
    {
        $counts = [];

        foreach ($findings as $finding) {
            $type = $finding->getChangeType()->value;
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Group findings by file.
     *
     * @param array<RectorFinding> $findings
     *
     * @return array<string, int>
     */
    private function groupFindingsByFile(array $findings): array
    {
        $groups = [];

        foreach ($findings as $finding) {
            $file = $finding->getFile();
            $groups[$file] = ($groups[$file] ?? 0) + 1;
        }

        return $groups;
    }

    /**
     * Group findings by rule.
     *
     * @param array<RectorFinding> $findings
     *
     * @return array<string, int>
     */
    private function groupFindingsByRule(array $findings): array
    {
        $groups = [];

        foreach ($findings as $finding) {
            $rule = $finding->getRuleName();
            $groups[$rule] = ($groups[$rule] ?? 0) + 1;
        }

        return $groups;
    }

    /**
     * Count unique files in findings.
     *
     * @param array<RectorFinding> $findings
     */
    private function countUniqueFiles(array $findings): int
    {
        $files = array_unique(array_map(fn ($f) => $f->getFile(), $findings));

        return \count($files);
    }

    /**
     * Calculate estimated time to fix all findings.
     *
     * @param array<RectorFinding> $findings
     */
    private function calculateEstimatedFixTime(array $findings): int
    {
        $totalMinutes = 0;

        foreach ($findings as $finding) {
            $totalMinutes += $finding->getEstimatedEffort();
        }

        return $totalMinutes;
    }

    /**
     * Calculate entropy for measuring distribution diversity.
     *
     * @param array<int> $values
     */
    private function calculateEntropy(array $values): float
    {
        $total = array_sum($values);

        if (0 === $total) {
            return 0.0;
        }

        $entropy = 0.0;

        foreach ($values as $value) {
            if ($value > 0) {
                $probability = $value / $total;
                $entropy -= $probability * log($probability, 2);
            }
        }

        // Normalize to 0-1 range (max entropy for 4 categories is log2(4) = 2)
        return $entropy / 2;
    }

    /**
     * Infer severity from rule class name patterns.
     */
    private function inferSeverityFromRuleClass(string $ruleClass): RectorRuleSeverity
    {
        // Breaking changes in newer TYPO3 versions
        if (str_contains($ruleClass, 'v12\\') || str_contains($ruleClass, 'v13\\') || str_contains($ruleClass, 'v14\\')) {
            return RectorRuleSeverity::CRITICAL;
        }

        // Deprecations in older versions
        if (str_contains($ruleClass, 'v10\\') || str_contains($ruleClass, 'v11\\')) {
            return RectorRuleSeverity::WARNING;
        }

        // Code quality improvements
        if (str_contains($ruleClass, 'CodeQuality') || str_contains($ruleClass, 'General')) {
            return RectorRuleSeverity::INFO;
        }

        // Default for unknown patterns
        return RectorRuleSeverity::WARNING;
    }

    /**
     * Infer change type from rule class name patterns.
     */
    private function inferChangeTypeFromRuleClass(string $ruleClass): RectorChangeType
    {
        // Breaking changes
        if (str_contains($ruleClass, 'Remove') || str_contains($ruleClass, 'v12\\') || str_contains($ruleClass, 'v13\\') || str_contains($ruleClass, 'v14\\')) {
            return RectorChangeType::BREAKING_CHANGE;
        }

        // Deprecations
        if (str_contains($ruleClass, 'Deprecat') || str_contains($ruleClass, 'v10\\') || str_contains($ruleClass, 'v11\\')) {
            return RectorChangeType::DEPRECATION;
        }

        // Code quality improvements
        if (str_contains($ruleClass, 'CodeQuality') || str_contains($ruleClass, 'General')) {
            return RectorChangeType::BEST_PRACTICE;
        }

        // Default for unknown patterns
        return RectorChangeType::DEPRECATION;
    }
}
