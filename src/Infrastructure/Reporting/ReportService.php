<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Reporting;

use CPSIT\UpgradeAnalyzer\Domain\Contract\ResultInterface;
use CPSIT\UpgradeAnalyzer\Domain\Entity\AnalysisResult;
use CPSIT\UpgradeAnalyzer\Domain\Entity\DiscoveryResult;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Installation;
use CPSIT\UpgradeAnalyzer\Domain\Entity\ReportingResult;
use Psr\Log\LoggerInterface;
use Twig\Environment as TwigEnvironment;

/**
 * Service for generating detailed reports from discovery and analysis results.
 */
class ReportService
{
    public function __construct(
        private readonly TwigEnvironment $twig,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Generate comprehensive report from all phases.
     *
     * @param array<ResultInterface> $results
     * @param array<string> $formats
     */
    public function generateReport(
        Installation $installation,
        array $extensions,
        array $results,
        array $formats = ['markdown'],
        string $outputDirectory = 'var/reports/'
    ): array {
        $this->logger->info('Starting report generation', [
            'extensions_count' => count($extensions),
            'results_count' => count($results),
            'formats' => $formats,
        ]);

        $reportResults = [];

        // Group results by type
        $groupedResults = $this->groupResultsByType($results);

        // Generate context for templates
        $context = $this->buildReportContext($installation, $extensions, $groupedResults);

        foreach ($formats as $format) {
            try {
                $reportResult = $this->generateFormatReport($format, $context, $outputDirectory);
                $reportResults[] = $reportResult;

                $this->logger->info('Report generated successfully', [
                    'format' => $format,
                    'output_path' => $reportResult->getValue('output_path'),
                ]);
            } catch (\Throwable $e) {
                $errorResult = new ReportingResult(
                    "report_{$format}",
                    "Report generation ({$format})"
                );
                $errorResult->setError($e->getMessage());
                $reportResults[] = $errorResult;

                $this->logger->error('Report generation failed', [
                    'format' => $format,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $reportResults;
    }

    /**
     * @param array<ResultInterface> $results
     * @return array<string, array<ResultInterface>>
     */
    private function groupResultsByType(array $results): array
    {
        $grouped = [
            'discovery' => [],
            'analysis' => [],
            'reporting' => [],
        ];

        foreach ($results as $result) {
            $grouped[$result->getType()][] = $result;
        }

        return $grouped;
    }

    /**
     * @param array<Extension> $extensions
     * @param array<string, array<ResultInterface>> $groupedResults
     */
    private function buildReportContext(
        Installation $installation,
        array $extensions,
        array $groupedResults
    ): array {
        // Discovery results
        $discoveryResults = $groupedResults['discovery'];
        $installationDiscovery = array_filter(
            $discoveryResults,
            fn(ResultInterface $r) => str_contains($r->getId(), 'installation')
        );
        $extensionDiscovery = array_filter(
            $discoveryResults,
            fn(ResultInterface $r) => str_contains($r->getId(), 'extension')
        );

        // Analysis results
        $analysisResults = $groupedResults['analysis'];
        $versionAnalysis = array_filter(
            $analysisResults,
            fn(ResultInterface $r) => $r instanceof AnalysisResult && $r->getAnalyzerName() === 'version_availability'
        );

        // Build detailed extension data with analysis results
        $extensionData = [];
        foreach ($extensions as $extension) {
            $extensionResults = array_filter(
                $analysisResults,
                fn(ResultInterface $r) => $r instanceof AnalysisResult && $r->getExtension()->getKey() === $extension->getKey()
            );

            $extensionData[] = [
                'extension' => $extension,
                'results' => $extensionResults,
                'version_analysis' => $this->extractVersionAnalysis($extensionResults),
                'risk_summary' => $this->calculateExtensionRiskSummary($extensionResults),
            ];
        }

        // Overall statistics
        $stats = $this->calculateOverallStatistics($extensionData);

        return [
            'installation' => $installation,
            'extensions' => $extensions,
            'extension_data' => $extensionData,
            'discovery' => [
                'installation' => reset($installationDiscovery) ?: null,
                'extensions' => reset($extensionDiscovery) ?: null,
            ],
            'analysis' => [
                'version_availability' => $versionAnalysis,
            ],
            'statistics' => $stats,
            'generated_at' => new \DateTimeImmutable(),
        ];
    }

    /**
     * @param array<ResultInterface> $results
     */
    private function extractVersionAnalysis(array $results): ?array
    {
        $versionResult = array_filter(
            $results,
            fn(ResultInterface $r) => $r instanceof AnalysisResult && $r->getAnalyzerName() === 'version_availability'
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
            'risk_score' => $result->getRiskScore(),
            'risk_level' => $result->getRiskLevel(),
            'recommendations' => $result->getRecommendations(),
        ];
    }

    /**
     * @param array<ResultInterface> $results
     */
    private function calculateExtensionRiskSummary(array $results): array
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

        $avgRisk = array_sum($riskScores) / count($riskScores);
        $maxRisk = max($riskScores);

        return [
            'overall_risk' => $avgRisk,
            'max_risk' => $maxRisk,
            'risk_level' => $this->getRiskLevel($avgRisk),
            'has_errors' => $hasErrors,
        ];
    }

    /**
     * @param array<array> $extensionData
     */
    private function calculateOverallStatistics(array $extensionData): array
    {
        $total = count($extensionData);
        $riskLevels = ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];
        $availabilityStats = [
            'ter_available' => 0,
            'packagist_available' => 0,
            'git_available' => 0,
            'no_availability' => 0,
        ];

        foreach ($extensionData as $data) {
            $riskSummary = $data['risk_summary'];
            $riskLevels[$riskSummary['risk_level']]++;

            $versionAnalysis = $data['version_analysis'];
            if ($versionAnalysis) {
                if ($versionAnalysis['ter_available']) {
                    $availabilityStats['ter_available']++;
                }
                if ($versionAnalysis['packagist_available']) {
                    $availabilityStats['packagist_available']++;
                }
                if ($versionAnalysis['git_available']) {
                    $availabilityStats['git_available']++;
                }
                if (!$versionAnalysis['ter_available'] && !$versionAnalysis['packagist_available'] && !$versionAnalysis['git_available']) {
                    $availabilityStats['no_availability']++;
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

    private function generateFormatReport(string $format, array $context, string $outputDirectory): ReportingResult
    {
        $outputPath = rtrim($outputDirectory, '/') . '/';

        // Ensure output directory exists
        if (!is_dir($outputPath)) {
            mkdir($outputPath, 0755, true);
        }

        $result = new ReportingResult(
            "report_{$format}",
            "Detailed report ({$format})"
        );

        $result->setValue('format', $format);

        switch ($format) {
            case 'markdown':
                $filename = $outputPath . 'detailed-analysis-report.md';
                $content = $this->twig->render('detailed-report.md.twig', $context);
                break;

            case 'html':
                $filename = $outputPath . 'detailed-analysis-report.html';
                $content = $this->twig->render('detailed-report.html.twig', $context);
                break;

            case 'json':
                $filename = $outputPath . 'detailed-analysis-report.json';
                $content = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                break;

            default:
                throw new \InvalidArgumentException("Unsupported report format: {$format}");
        }

        file_put_contents($filename, $content);

        $result->setValue('output_path', $filename);
        $result->setValue('file_size', filesize($filename));

        return $result;
    }

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