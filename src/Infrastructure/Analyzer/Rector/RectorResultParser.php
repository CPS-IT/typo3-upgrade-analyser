<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
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
        $categorized = $this->categorizeFindings($findings);

        $severityCounts = $categorized->getSeverityCounts();
        $fileCounts = $categorized->getFileCounts();
        $ruleCounts = $categorized->getRuleCounts();
        $typeCounts = $categorized->getTypeCounts();

        $totalFiles = $this->countUniqueFiles($findings);
        $complexityScore = $this->calculateComplexityScore($findings);
        $estimatedFixTime = $this->calculateEstimatedFixTime($findings);

        return new RectorAnalysisSummary(
            totalFindings: \count($findings),
            criticalIssues: $severityCounts['critical'] ?? 0,
            warnings: $severityCounts['warning'] ?? 0,
            infoIssues: $severityCounts['info'] ?? 0,
            suggestions: $severityCounts['suggestion'] ?? 0,
            affectedFiles: $categorized->getAffectedFileCount(),
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
     */
    public function categorizeFindings(array $findings): RectorFindingsCollection
    {
        $breakingChanges = [];
        $deprecations = [];
        $improvements = [];
        $bySeverity = [
            'critical' => [],
            'warning' => [],
            'info' => [],
            'suggestion' => [],
        ];
        $byFile = [];
        $byRule = [];

        foreach ($findings as $finding) {
            // Categorize by impact type
            if ($finding->isBreakingChange()) {
                $breakingChanges[] = $finding;
            } elseif ($finding->isDeprecation()) {
                $deprecations[] = $finding;
            } else {
                $improvements[] = $finding;
            }

            // Categorize by severity
            $severityKey = $finding->getSeverity()->value;
            $bySeverity[$severityKey][] = $finding;

            // Categorize by file
            $file = $finding->getFile();
            if (!isset($byFile[$file])) {
                $byFile[$file] = [];
            }
            $byFile[$file][] = $finding;

            // Categorize by rule
            $rule = $finding->getRuleClass();
            if (!isset($byRule[$rule])) {
                $byRule[$rule] = [];
            }
            $byRule[$rule][] = $finding;
        }

        return new RectorFindingsCollection(
            $breakingChanges,
            $deprecations,
            $improvements,
            $bySeverity,
            $byFile,
            $byRule,
        );
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
        $categorized = $this->categorizeFindings($findings);
        $severityCounts = $categorized->getSeverityCounts();
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
        $severity = $this->ruleRegistry->getRuleSeverity($ruleClass);
        $changeType = $this->ruleRegistry->getRuleChangeType($ruleClass);

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
     * Count unique files in findings.
     *
     * @param array<RectorFinding> $findings
     */
    private function countUniqueFiles(array $findings): int
    {
        $files = array_unique(array_map(fn ($f): string => $f->getFile(), $findings));

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
}
