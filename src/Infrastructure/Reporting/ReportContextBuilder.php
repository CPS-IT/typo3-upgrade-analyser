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
use CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationServiceInterface;

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
     * @param array<string>                         $extensionAvailableInTargetVersion
     * @param array<string, array<string, mixed>>   $extensionConfiguration
     * @param array<string, mixed>                  $estimatedHours
     */
    public function buildReportContext(
        Installation $installation,
        array $extensions,
        array $groupedResults,
        ?string $targetVersion = null,
        array $extensionAvailableInTargetVersion = [],
        array $extensionConfiguration = [],
        array $estimatedHours = [],
        int|float $hourlyRate = 960,
    ): array {
        error_log('ReportContextBuilder: estimatedHours = ' . json_encode($estimatedHours));
        error_log('ReportContextBuilder: hourlyRate = ' . $hourlyRate);

        // Discovery results
        $discoveryResults = $groupedResults['discovery'];
        $installationDiscovery = array_filter(
            $discoveryResults,
            fn (ResultInterface $r): bool => 'installation' === $r->getId(),
        );
        $extensionDiscovery = array_filter(
            $discoveryResults,
            fn (ResultInterface $r): bool => 'extensions' === $r->getId(),
        );

        // Analysis results
        $analysisResults = $groupedResults['analysis'];
        $versionAnalysis = array_filter(
            $analysisResults,
            fn (ResultInterface $r): bool => $r instanceof AnalysisResult && 'version_availability' === $r->getAnalyzerName(),
        );

        // Build detailed extension data with analysis results
        $extensionData = [];
        foreach ($extensions as $extension) {
            $extensionResults = array_filter(
                $analysisResults,
                fn (ResultInterface $r): bool => $r instanceof AnalysisResult && $r->getExtension()->getKey() === $extension->getKey(),
            );

            // Check for configured estimated-hours override
            $extensionKey = $extension->getKey();
            $extensionEstimatedHours = $extensionConfiguration[$extensionKey]['estimated-hours'] ?? null;

            $extensionData[] = [
                'extension' => $extension,
                'results' => $extensionResults,
                'version_analysis' => $this->extractVersionAnalysis($extensionResults),
                'loc_analysis' => $this->extractLinesOfCodeAnalysis($extensionResults),
                'rector_analysis' => $this->extractRectorAnalysis($extensionResults),
                'fractor_analysis' => $this->extractFractorAnalysis($extensionResults),
                'risk_summary' => $this->calculateExtensionRiskSummary($extensionResults),
                'estimated_hours' => $extensionEstimatedHours,
            ];
        }

        // Overall statistics
        $stats = $this->calculateOverallStatistics($extensionData);

        return [
            'installation' => $installation,
            'extensions' => $extensions,
            'extension_data' => $extensionData,
            'target_version' => $targetVersion ?? '13.4', // Default fallback
            'extensionAvailableInTargetVersion' => $extensionAvailableInTargetVersion,
            'discovery' => [
                'installation' => reset($installationDiscovery) ?: null,
                'extensions' => reset($extensionDiscovery) ?: null,
            ],
            'analysis' => [
                'version_availability' => $versionAnalysis,
            ],
            'statistics' => $stats,
            'generated_at' => new \DateTimeImmutable(),
            'estimated_hours' => $estimatedHours,
            'hourly_rate' => $hourlyRate,
        ];
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
            fn (ResultInterface $r): bool => $r instanceof AnalysisResult && 'version_availability' === $r->getAnalyzerName(),
        );

        if (empty($versionResult)) {
            return null;
        }

        /** @var AnalysisResult $result */
        $result = reset($versionResult);

        return [
            'ter_available' => $result->getMetric('ter_available'),
            'packagist_available' => $result->getMetric('packagist_available'),
            'git_available' => $result->getMetric('git_available'),
            'git_repository_url' => $result->getMetric('git_repository_url'),
            'git_repository_health' => $result->getMetric('git_repository_health'),
            'git_latest_version' => $result->getMetric('git_latest_version'),
            'latest_version' => $result->getMetric('latest_version'),
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
            fn (ResultInterface $r): bool => $r instanceof AnalysisResult && 'lines_of_code' === $r->getAnalyzerName(),
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
    private function extractRectorAnalysis(array $results): ?array
    {
        $rectorResult = array_filter(
            $results,
            fn (ResultInterface $r): bool => $r instanceof AnalysisResult && 'typo3_rector' === $r->getAnalyzerName(),
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
                    'extension_key' => $result->getExtension()->getKey(),
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
            fn (ResultInterface $r): bool => $r instanceof AnalysisResult && 'fractor' === $r->getAnalyzerName(),
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
            'findings' => $result->getMetric('findings'),
            'risk_score' => $result->getRiskScore(),
            'recommendations' => $result->getRecommendations(),
            'error_message' => $result->getMetric('error_message'),
            'execution_failed' => $result->getMetric('execution_failed'),
            'analysis_error' => $result->getMetric('analysis_error'),
        ];
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
