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
use CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\Contract\AnalysisReportDataProviderInterface;

/**
 * Service responsible for building report context data from analysis results.
 *
 * This service aggregates and transforms raw analysis results into structured
 * context data that can be used by template rendering systems. It handles
 * data extraction, risk calculations and statistics computation.
 */
readonly class ReportContextBuilder
{
    /**
     * @param iterable<AnalysisReportDataProviderInterface> $dataProviders
     */
    public function __construct(
        private iterable $dataProviders,
    ) {
    }

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
        array $configuration = [],
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

        // Build detailed extension data with analysis results
        $extensionData = [];
        $activeAnalyzers = [];

        foreach ($extensions as $extension) {
            $extensionResults = array_filter(
                $analysisResults,
                static fn (ResultInterface $r): bool => $r instanceof AnalysisResult && $r->getExtension()->getKey() === $extension->getKey(),
            );

            $extensionContext = [
                'extension' => $extension,
                'results' => $extensionResults,
                'risk_summary' => $this->calculateExtensionRiskSummary($extensionResults),
            ];

            foreach ($this->dataProviders as $dataProvider) {
                $data = $dataProvider->extractData($extensionResults);
                $key = $dataProvider->getResultKey();
                $extensionContext[$key] = $data;

                // Track active analyzers if data was found
                if (null !== $data) {
                    $analyzerName = $dataProvider->getAnalyzerName();
                    if (!isset($activeAnalyzers[$analyzerName])) {
                        $activeAnalyzers[$analyzerName] = [
                            'name' => $analyzerName,
                            'templates' => [
                                'html' => $dataProvider->getTemplatePath('html'),
                                'md' => $dataProvider->getTemplatePath('md'),
                            ],
                        ];
                    }
                }
            }

            $extensionData[] = $extensionContext;
        }

        // Overall statistics
        $stats = $this->calculateOverallStatistics($extensionData);

        return [
            'installation' => $installation,
            'extensions' => $extensions,
            'extension_data' => $extensionData,
            'target_version' => $targetVersion ?? '13.4', // Default fallback
            'discovery' => [
                'installation' => reset($installationDiscovery) ?: null,
                'extensions' => reset($extensionDiscovery) ?: null,
            ],
            'analysis' => [
                'version_availability' => $versionAnalysis,
            ],
            'active_analyzers' => array_values($activeAnalyzers),
            'statistics' => $stats,
            'configuration' => $configuration,
            'generated_at' => new \DateTimeImmutable(),
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

    /**
     * Calculate overall statistics from extension data.
     *
     * @param array<array> $extensionData
     */
    public function calculateOverallStatistics(array $extensionData): array
    {
        $total = \count($extensionData);
        $riskLevels = [
            'low' => 0,
            'medium' => 0,
            'high' => 0,
            'critical' => 0,
            'unknown' => 0,
        ];
        $availabilityStats = [
            'ter_available' => 0,
            'packagist_available' => 0,
            'vcs_available' => 0,
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
                if (true === ($versionAnalysis['vcs_available'] ?? null)) {
                    ++$availabilityStats['vcs_available'];
                }
                if (!$versionAnalysis['ter_available'] && !$versionAnalysis['packagist_available'] && true !== ($versionAnalysis['vcs_available'] ?? null)) {
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
}
