<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Reporting;

use CPSIT\UpgradeAnalyzer\Domain\Contract\ResultInterface;
use CPSIT\UpgradeAnalyzer\Domain\Entity\AnalysisResult;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Installation;

/**
 * Service responsible for building report context data from analysis results.
 *
 * This service aggregates and transforms raw analysis results into structured
 * context data that can be used by template rendering systems. It handles
 * data extraction, risk calculations and statistics computation.
 */
class ReportContextBuilder
{
    /**
     * Build the report context from installation, extensions and analysis results.
     *
     * @param array<Extension>                      $extensions
     * @param array<string, array<ResultInterface>> $groupedResults
     */
    public function buildReportContext(
        Installation $installation,
        array $extensions,
        array $groupedResults,
        ?string $targetVersion = null,
    ): array {
        // Discovery results
        $discoveryResults = $groupedResults['discovery'];
        $installationDiscovery = array_filter(
            $discoveryResults,
            static fn (ResultInterface $r): bool => 'installation' === $r->getId(),
        );
        $extensionDiscovery = array_filter(
            $discoveryResults,
            static fn (ResultInterface $r): bool => 'extensions' === $r->getId(),
        );

        // Analysis results
        $analysisResults = $groupedResults['analysis'];
        $versionAnalysis = array_filter(
            $analysisResults,
            static fn (ResultInterface $r): bool => $r instanceof AnalysisResult && 'version_availability' === $r->getAnalyzerName(),
        );

        // CRITICAL FIX: Build extension key lookup map first to avoid circular references
        // Extract all extension keys upfront, then immediately drop Extension entity references
        $extensionKeyMap = [];
        foreach ($analysisResults as $result) {
            if ($result instanceof AnalysisResult) {
                $extensionKey = $result->getExtension()->getKey();
                $extensionKeyMap[spl_object_id($result)] = $extensionKey;
            }
        }

        // Now group results using the extracted keys without Extension entity access
        $resultsByExtensionKey = [];
        foreach ($analysisResults as $result) {
            if ($result instanceof AnalysisResult) {
                $extensionKey = $extensionKeyMap[spl_object_id($result)];
                $resultsByExtensionKey[$extensionKey][] = $result;
            }
        }

        // CRITICAL FIX: Build extension key-only lookup to avoid accessing Extension entities
        // Extract all extension keys upfront to eliminate any Extension entity access during processing
        $extensionKeys = [];
        foreach ($extensions as $extension) {
            $extensionResults = array_filter(
                $analysisResults,
                static fn (ResultInterface $r): bool => $r instanceof AnalysisResult && $r->getExtension()->getKey() === $extension->getKey(),
            );

            $extensionData[$extensionKey] = [
                // REMOVED 'extension' => $extension, // CAUSES SEGFAULTS - Extension entities cannot be serialized
                'extension_key' => $extensionKey,
                // REMOVED 'results' => $extensionResults, // CONTAINS AnalysisResult objects with Extension entities that cause segfaults
                'version_analysis' => $this->extractVersionAnalysis($extensionResults),
                'loc_analysis' => $this->extractLinesOfCodeAnalysis($extensionResults),
                'rector_analysis' => $this->extractRectorAnalysis($extensionResults, $extensionKey),
                'fractor_analysis' => $this->extractFractorAnalysis($extensionResults),
                'rector_detailed_findings' => $this->extractAnalyzerDetailedFindings($extensionResults, 'typo3_rector', $extensionKey),
                'fractor_detailed_findings' => $this->extractAnalyzerDetailedFindings($extensionResults, 'fractor', $extensionKey),
                'risk_summary' => $this->calculateExtensionRiskSummary($extensionResults),
            ];
        }

        // Overall statistics
        $stats = $this->calculateOverallStatistics($extensionData);

        // CRITICAL: Deep sanitize all context data to ensure NO Extension entity or AnalysisResult objects
        // can be serialized during template rendering, which causes segmentation faults
        $sanitizedContext = [
            // REMOVED 'installation' => $installation, // CONTAINS Extension entities that cause segfaults
            'installation_data' => [
                'path' => $installation->getPath(),
                'version' => $installation->getVersion()->toString(),
                'type' => $installation->getType(),
            ],
            // REMOVED 'extensions' => $extensions, // CONTAINS Extension entities that cause segfaults
            'extension_data' => $this->deepSanitizeExtensionData($extensionData),
            'target_version' => $targetVersion ?? '13.4', // Default fallback
            // REMOVED 'discovery' => [...] // CONTAINS ResultInterface objects that may have Extension entity references causing segfaults
            // REMOVED 'analysis' => [...] // CONTAINS AnalysisResult objects with Extension entities that cause segfaults
            'statistics' => $stats,
            'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        return $sanitizedContext;
    }

    /**
     * Calculate overall statistics from extension data.
     *
     * @param array<array> $extensionData
     */
    public function calculateOverallStatistics(array $extensionData): array
    {
        $total = \count($extensionData);
        $riskLevels = ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0, 'unknown' => 0];
        $availabilityStats = [
            'ter_available' => 0,
            'packagist_available' => 0,
            'git_available' => 0,
            'no_availability' => 0,
        ];

        foreach ($extensionData as $data) {
            $riskSummary = $data['risk_summary'];
            ++$riskLevels[$riskSummary['risk_level']];

            $versionAnalysis = $data['version_analysis'];
            if ($versionAnalysis) {
                if ($versionAnalysis['ter_available']) {
                    ++$availabilityStats['ter_available'];
                }
                if ($versionAnalysis['packagist_available']) {
                    ++$availabilityStats['packagist_available'];
                }
                if ($versionAnalysis['git_available']) {
                    ++$availabilityStats['git_available'];
                }
                if (!$versionAnalysis['ter_available'] && !$versionAnalysis['packagist_available'] && !$versionAnalysis['git_available']) {
                    ++$availabilityStats['no_availability'];
                }
            }
        }

        return [
            'total_extensions' => $total,
            'risk_distribution' => $riskLevels,
            'availability_stats' => $availabilityStats,
            'critical_extensions' => $riskLevels['critical'] + $riskLevels['high'],
        ];
    }

    /**
     * Calculate risk summary for a single extension from its analysis results.
     *
     * @param array<ResultInterface> $results
     */
    public function calculateExtensionRiskSummary(array $results): array
    {
        $riskScores = [];
        $hasErrors = false;

        foreach ($results as $result) {
            if ($result instanceof AnalysisResult) {
                if (!$result->isSuccessful()) {
                    $hasErrors = true;
                } else {
                    $riskScores[] = $result->getRiskScore();
                }
            }
        }

        if (empty($riskScores)) {
            return [
                'overall_risk' => $hasErrors ? 10.0 : 0.0,
                'risk_level' => $hasErrors ? 'critical' : 'unknown',
                'has_errors' => $hasErrors,
            ];
        }

        $avgRisk = array_sum($riskScores) / \count($riskScores);
        $maxRisk = max($riskScores);

        return [
            'overall_risk' => $avgRisk,
            'max_risk' => $maxRisk,
            'risk_level' => $this->getRiskLevel($avgRisk),
            'has_errors' => $hasErrors,
        ];
    }

    /**
     * Extract version availability analysis data from results.
     *
     * @param array<ResultInterface> $results
     */
    private function extractVersionAnalysis(array $results): ?array
    {
        $versionResult = array_filter(
            $results,
            static fn (ResultInterface $r): bool => $r instanceof AnalysisResult && 'version_availability' === $r->getAnalyzerName(),
        );

        if (empty($versionResult)) {
            return null;
        }

        /** @var AnalysisResult $result */
        $result = reset($versionResult);

        return [
            'skipped' => $result->getMetric('skipped'),
            'skip_reason' => $result->getMetric('skip_reason'),
            'distribution_type' => $result->getExtension()->getDistribution()?->getType(),
            'ter_available' => $result->getMetric('ter_available'),
            'packagist_available' => $result->getMetric('packagist_available'),
            'git_available' => $result->getMetric('git_available'),
            'git_repository_url' => $result->getMetric('git_repository_url'),
            'git_repository_health' => $result->getMetric('git_repository_health'),
            'git_latest_version' => $result->getMetric('git_latest_version'),
            'risk_score' => $result->getRiskScore(),
            'risk_level' => $result->getRiskLevel(),
            'recommendations' => $result->getRecommendations(),
        ];
    }

    /**
     * Extract lines of code analysis data from results.
     *
     * @param array<ResultInterface> $results
     */
    private function extractLinesOfCodeAnalysis(array $results): ?array
    {
        $locResult = array_filter(
            $results,
            static fn (ResultInterface $r): bool => $r instanceof AnalysisResult && 'lines_of_code' === $r->getAnalyzerName(),
        );

        if (empty($locResult)) {
            return null;
        }

        /** @var AnalysisResult $result */
        $result = reset($locResult);

        return [
            'total_lines' => $result->getMetric('total_lines'),
            'code_lines' => $result->getMetric('code_lines'),
            'comment_lines' => $result->getMetric('comment_lines'),
            'blank_lines' => $result->getMetric('blank_lines'),
            'php_files' => $result->getMetric('php_files'),
            'classes' => $result->getMetric('classes'),
            'methods' => $result->getMetric('methods'),
            'functions' => $result->getMetric('functions'),
            'largest_file_lines' => $result->getMetric('largest_file_lines'),
            'largest_file_path' => $result->getMetric('largest_file_path'),
            'average_file_size' => $result->getMetric('average_file_size'),
            'risk_score' => $result->getRiskScore(),
            'recommendations' => $result->getRecommendations(),
        ];
    }

    /**
     * Extract Rector analysis data from results.
     *
     * @param array<ResultInterface> $results
     */
    private function extractRectorAnalysis(array $results, string $extensionKey): ?array
    {
        $rectorResult = array_filter(
            $results,
            static fn (ResultInterface $r): bool => $r instanceof AnalysisResult && 'typo3_rector' === $r->getAnalyzerName(),
        );

        if (empty($rectorResult)) {
            return null;
        }

        /** @var AnalysisResult $result */
        $result = reset($rectorResult);

        $analysisData = [
            'total_findings' => $result->getMetric('total_findings'),
            'affected_files' => $result->getMetric('affected_files'),
            'total_files' => $result->getMetric('total_files'),
            'processed_files' => $result->getMetric('processed_files'),
            'execution_time' => $result->getMetric('execution_time'),
            'findings_by_severity' => $result->getMetric('findings_by_severity'),
            'findings_by_type' => $result->getMetric('findings_by_type'),
            'top_affected_files' => $result->getMetric('top_affected_files'),
            'top_rules_triggered' => $result->getMetric('top_rules_triggered'),
            'estimated_fix_time' => $result->getMetric('estimated_fix_time'),
            'estimated_fix_time_hours' => $result->getMetric('estimated_fix_time_hours'),
            'complexity_score' => $result->getMetric('complexity_score'),
            'upgrade_readiness_score' => $result->getMetric('upgrade_readiness_score'),
            'has_breaking_changes' => $result->getMetric('has_breaking_changes'),
            'has_deprecations' => $result->getMetric('has_deprecations'),
            'file_impact_percentage' => $result->getMetric('file_impact_percentage'),
            'summary_text' => $result->getMetric('summary_text'),
            'rector_version' => $result->getMetric('rector_version'),
            'risk_score' => $result->getRiskScore(),
            'recommendations' => $result->getRecommendations(),
        ];

        // Add detailed findings data if available
        $rawFindings = $result->getMetric('raw_findings');
        $rawSummary = $result->getMetric('raw_summary');

        if (!empty($rawFindings) && !empty($rawSummary)) {
            $analysisData['detailed_findings'] = [
                'metadata' => [
                    'extension_key' => $extensionKey,
                    'analysis_timestamp' => (new \DateTime())->format('c'),
                    'rector_version' => $result->getMetric('rector_version'),
                    'execution_time' => $result->getMetric('execution_time'),
                ],
                'summary' => $rawSummary,  // Already converted to array in the analyzer
                'findings' => $rawFindings,  // Already converted to array in the analyzer
            ];
        }

        return $analysisData;
    }

    /**
     * Extract Fractor analysis data from results.
     *
     * @param array<ResultInterface> $results
     *
     * @return array<string, mixed>|null
     */
    private function extractFractorAnalysis(array $results): ?array
    {
        $fractorResult = array_filter(
            $results,
            static fn (ResultInterface $r): bool => $r instanceof AnalysisResult && 'fractor' === $r->getAnalyzerName(),
        );

        if (empty($fractorResult)) {
            return null;
        }

        /** @var AnalysisResult $result */
        $result = reset($fractorResult);

        return [
            'files_scanned' => $result->getMetric('files_scanned'),
            'files_changed' => $result->getMetric('files_changed'),
            'rules_applied' => $result->getMetric('rules_applied'),
            'total_issues' => $result->getMetric('total_issues'),
            'has_findings' => $result->getMetric('has_findings'),
            'analysis_successful' => $result->getMetric('analysis_successful'),
            'change_blocks' => $result->getMetric('change_blocks'),
            'changed_lines' => $result->getMetric('changed_lines'),
            'file_paths' => $result->getMetric('file_paths'),
            'applied_rules' => $result->getMetric('applied_rules'),
            // CRITICAL FIX: Sanitize FractorFinding objects to prevent segfaults during serialization
            'findings' => $this->sanitizeFractorFindings($result->getMetric('findings')),
            'risk_score' => $result->getRiskScore(),
            'recommendations' => $result->getRecommendations(),
            'error_message' => $result->getMetric('error_message'),
            'execution_failed' => $result->getMetric('execution_failed'),
            'analysis_error' => $result->getMetric('analysis_error'),
        ];
    }

    /**
     * Extract detailed findings data from analysis results for generic analyzer support.
     *
     * @param array<ResultInterface> $results
     *
     * @return array<string, mixed>|null
     */
    private function extractAnalyzerDetailedFindings(array $results, string $analyzerName, string $extensionKey): ?array
    {
        $analyzerResult = array_filter(
            $results,
            fn (ResultInterface $r): bool => $r instanceof AnalysisResult && $analyzerName === $r->getAnalyzerName(),
        );

        if (empty($analyzerResult)) {
            return null;
        }

        /** @var AnalysisResult $result */
        $result = reset($analyzerResult);

        // Check if detailed findings are available
        $findings = $result->getMetric('findings');
        $summary = null;

        // Try to get summary data - check for different possible metric names
        if ('fractor' === $analyzerName) {
            // CRITICAL FIX: Convert FractorFinding objects to arrays before processing
            // This prevents segfaults during distribution calculations
            $findingsArray = [];
            if (!empty($findings)) {
                foreach ($findings as $finding) {
                    if (is_object($finding) && method_exists($finding, 'toArray')) {
                        $findingsArray[] = $finding->toArray();
                    } elseif (is_array($finding)) {
                        $findingsArray[] = $finding;
                    }
                }
            }

            // For Fractor, the summary might be in the analysis result itself
            $summary = [
                'total_findings' => \count($findingsArray),
                'files_scanned' => $result->getMetric('files_scanned'),
                'rules_applied' => $result->getMetric('rules_applied'),
                'successful' => $result->getMetric('analysis_successful'),
                'has_findings' => $result->getMetric('has_findings'),
                'error_message' => $result->getMetric('error_message'),
                'change_blocks' => $result->getMetric('change_blocks'),
                'changed_lines' => $result->getMetric('changed_lines'),
                'severity_distribution' => $this->calculateSeverityDistribution($findingsArray),
                'change_type_distribution' => $this->calculateChangeTypeDistribution($findingsArray),
                'top_issues_by_file' => $this->calculateTopIssuesByFile($findingsArray),
                'top_issues_by_rule' => $this->calculateTopIssuesByRule($findingsArray),
                'manual_intervention_count' => $this->calculateManualInterventionCount($findingsArray),
            ];

            // Use the converted arrays as findings - CRITICAL: already sanitized above
            $findings = $findingsArray;
        } elseif ('typo3_rector' === $analyzerName) {
            // For Rector, check for raw_summary
            $rawSummary = $result->getMetric('raw_summary');
            $summary = $rawSummary ?: [
                'total_findings' => $result->getMetric('total_findings'),
                'affected_files' => $result->getMetric('affected_files'),
                'total_files' => $result->getMetric('total_files'),
                'successful' => $result->isSuccessful(),
                'has_findings' => ($result->getMetric('total_findings') ?? 0) > 0,
                'severity_distribution' => $result->getMetric('findings_by_severity'),
                'top_issues_by_file' => $result->getMetric('top_affected_files'),
                'top_issues_by_rule' => $result->getMetric('top_rules_triggered'),
            ];

            // Use raw_findings if available, otherwise use findings
            $rawFindings = $result->getMetric('raw_findings');
            if ($rawFindings) {
                $findings = $rawFindings;
            }
        }

        if (empty($findings) && empty($summary)) {
            return null;
        }

        return [
            'findings' => $findings ?? [],
            'summary' => $summary ?? $this->createEmptyAnalyzerSummary(),
            'metadata' => [
                'extension_key' => $extensionKey,
                'analyzer_type' => 'typo3_rector' === $analyzerName ? 'rector' : $analyzerName,
                'analysis_timestamp' => (new \DateTime())->format('c'),
                'execution_time' => $result->getMetric('execution_time') ?? 0.0,
            ],
        ];
    }

    /**
     * Calculate severity distribution from findings array.
     *
     * @param array<mixed> $findings
     *
     * @return array<string, int>
     */
    private function calculateSeverityDistribution(array $findings): array
    {
        $distribution = [];

        foreach ($findings as $finding) {
            $severity = 'unknown';

            if (\is_array($finding) && isset($finding['severity_value'])) {
                $severity = $finding['severity_value'];
            } elseif (\is_array($finding) && isset($finding['severity'])) {
                $severity = $finding['severity'];
            }

            $distribution[$severity] = ($distribution[$severity] ?? 0) + 1;
        }

        return $distribution;
    }

    /**
     * Calculate change type distribution from findings array.
     *
     * @param array<mixed> $findings
     *
     * @return array<string, int>
     */
    private function calculateChangeTypeDistribution(array $findings): array
    {
        $distribution = [];

        foreach ($findings as $finding) {
            if (\is_array($finding) && isset($finding['change_type'])) {
                $changeType = $finding['change_type'];
                $distribution[$changeType] = ($distribution[$changeType] ?? 0) + 1;
            }
        }

        return $distribution;
    }

    /**
     * Calculate top issues by file from findings array.
     *
     * @param array<mixed> $findings
     *
     * @return array<string, int>
     */
    private function calculateTopIssuesByFile(array $findings): array
    {
        $fileCount = [];

        foreach ($findings as $finding) {
            if (\is_array($finding) && isset($finding['file'])) {
                $file = basename($finding['file']);
                $fileCount[$file] = ($fileCount[$file] ?? 0) + 1;
            }
        }

        arsort($fileCount);

        return \array_slice($fileCount, 0, 5, true);
    }

    /**
     * Calculate top issues by rule from findings array.
     *
     * @param array<mixed> $findings
     *
     * @return array<string, int>
     */
    private function calculateTopIssuesByRule(array $findings): array
    {
        $ruleCount = [];

        foreach ($findings as $finding) {
            if (\is_array($finding) && isset($finding['rule_name'])) {
                $rule = $finding['rule_name'];
                $ruleCount[$rule] = ($ruleCount[$rule] ?? 0) + 1;
            } elseif (\is_array($finding) && isset($finding['rule_class'])) {
                $rule = basename(str_replace('\\', '/', $finding['rule_class']));
                $ruleCount[$rule] = ($ruleCount[$rule] ?? 0) + 1;
            }
        }

        arsort($ruleCount);

        return \array_slice($ruleCount, 0, 5, true);
    }

    /**
     * Calculate manual intervention count from findings array.
     *
     * @param array<mixed> $findings
     *
     * @return int
     */
    private function calculateManualInterventionCount(array $findings): int
    {
        $manualCount = 0;

        foreach ($findings as $finding) {
            if (\is_array($finding) &&
                isset($finding['requires_manual_intervention']) &&
                true === $finding['requires_manual_intervention']) {
                $manualCount++;
            }
        }

        return $manualCount;
    }

    /**
     * Create empty analyzer summary structure.
     *
     * @return array<string, mixed>
     */
    private function createEmptyAnalyzerSummary(): array
    {
        return [
            'total_findings' => 0,
            'files_scanned' => 0,
            'rules_applied' => 0,
            'successful' => false,
            'has_findings' => false,
            'error_message' => null,
            'severity_distribution' => [],
            'change_type_distribution' => [],
            'top_issues_by_file' => [],
            'top_issues_by_rule' => [],
        ];
    }

    /**
     * Deep sanitize extension data to ensure NO objects that could reference Extension entities
     * can cause segfaults during template rendering and file serialization.
     *
     * @param array<string, mixed> $extensionData
     *
     * @return array<string, mixed>
     */
    private function deepSanitizeExtensionData(array $extensionData): array
    {
        $sanitized = [];

        foreach ($extensionData as $extensionKey => $data) {
            $sanitized[$extensionKey] = $this->recursiveSanitizeValue($data);
        }

        return $sanitized;
    }

    /**
     * Recursively convert any value to a safe serializable format.
     * This removes objects, circular references, and any potential Extension entity references.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private function recursiveSanitizeValue($value)
    {
        if (is_object($value)) {
            // Convert objects to arrays if they have toArray method, otherwise to string representation
            if (method_exists($value, 'toArray')) {
                return $this->recursiveSanitizeValue($value->toArray());
            } elseif (method_exists($value, 'jsonSerialize')) {
                return $this->recursiveSanitizeValue($value->jsonSerialize());
            } elseif ($value instanceof \DateTimeInterface) {
                return $value->format(\DateTimeInterface::ATOM);
            } else {
                // Convert other objects to their string representation to prevent serialization issues
                return (string) $value;
            }
        } elseif (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                $sanitized[$key] = $this->recursiveSanitizeValue($item);
            }
            return $sanitized;
        }

        // Primitive values (string, int, float, bool, null) are safe
        return $value;
    }

    /**
     * CRITICAL FIX: Sanitize FractorFinding objects to prevent segfaults during serialization.
     * FractorFinding objects contain complex nested objects that cause circular references
     * and segmentation faults when passed to file_put_contents during template rendering.
     *
     * @param mixed $findings FractorFinding objects or arrays
     *
     * @return array<mixed> Safe array representation suitable for serialization
     */
    private function sanitizeFractorFindings($findings): array
    {
        if (empty($findings) || !is_array($findings)) {
            return [];
        }

        $sanitizedFindings = [];

        foreach ($findings as $finding) {
            if (is_object($finding)) {
                // Convert FractorFinding objects to arrays using toArray or jsonSerialize
                if (method_exists($finding, 'toArray')) {
                    $sanitizedFindings[] = $finding->toArray();
                } elseif (method_exists($finding, 'jsonSerialize')) {
                    $sanitizedFindings[] = $finding->jsonSerialize();
                } else {
                    // Fallback: extract essential data manually
                    $sanitizedFindings[] = [
                        'file' => method_exists($finding, 'getFile') ? $finding->getFile() : 'unknown',
                        'line_number' => method_exists($finding, 'getLineNumber') ? $finding->getLineNumber() : 0,
                        'message' => method_exists($finding, 'getMessage') ? $finding->getMessage() : 'unknown',
                        'rule_class' => method_exists($finding, 'getRuleClass') ? $finding->getRuleClass() : 'unknown',
                    ];
                }
            } elseif (is_array($finding)) {
                // Already an array - just pass it through (may already be sanitized)
                $sanitizedFindings[] = $finding;
            }
        }

        return $sanitizedFindings;
    }

    /**
     * Convert numeric risk score to risk level string.
     */
    private function getRiskLevel(float $score): string
    {
        return match (true) {
            $score <= 2.0 => 'low',
            $score <= 5.0 => 'medium',
            $score <= 8.0 => 'high',
            default => 'critical'
        };
    }
}
