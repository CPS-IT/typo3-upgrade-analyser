<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\Provider;

use CPSIT\UpgradeAnalyzer\Domain\Contract\ResultInterface;
use CPSIT\UpgradeAnalyzer\Domain\Entity\AnalysisResult;
use CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\Contract\AnalysisReportDataProviderInterface;
use CPSIT\UpgradeAnalyzer\Shared\Utility\DiffHtmlFormatter;

readonly class FractorDataProvider implements AnalysisReportDataProviderInterface
{
    public function __construct(
        private DiffHtmlFormatter $diffHtmlFormatter,
    ) {
    }

    public function getAnalyzerName(): string
    {
        return 'fractor';
    }

    public function getResultKey(): string
    {
        return 'fractor_analysis';
    }

    public function getTemplatePath(string $format): string
    {
        return match ($format) {
            'html' => 'html/partials/main-report/fractor-analysis-table.html.twig',
            'md' => 'md/partials/main-report/fractor-analysis-table.md.twig',
            default => throw new \InvalidArgumentException(\sprintf('Unsupported format: %s', $format)),
        };
    }

    public function extractData(array $results): ?array
    {
        $fractorResult = array_filter(
            $results,
            fn (ResultInterface $r): bool => $r instanceof AnalysisResult && $this->getAnalyzerName() === $r->getAnalyzerName(),
        );

        if (empty($fractorResult)) {
            return null;
        }

        /** @var AnalysisResult $result */
        $result = reset($fractorResult);

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
            'fractor_version' => $result->getMetric('fractor_version'),
            'risk_score' => $result->getRiskScore(),
            'isSuccessful' => $result->isSuccessful(),
            'recommendations' => $result->getRecommendations(),
        ];

        // Add detailed findings data if available
        $rawFindings = $result->getMetric('raw_findings');
        $rawSummary = $result->getMetric('raw_summary');

        if (!empty($rawFindings) && !empty($rawSummary)) {
            // Process findings to add HTML diffs
            $processedFindings = [];
            foreach ($rawFindings as $finding) {
                $findingData = \is_object($finding) && method_exists($finding, 'toArray')
                    ? $finding->toArray()
                    : (array) $finding;

                if (!empty($findingData['diff'])) {
                    $findingData['diff_html'] = $this->diffHtmlFormatter->format($findingData['diff']);
                }

                $processedFindings[] = $findingData;
            }

            $analysisData['detailed_findings'] = [
                'metadata' => [
                    'extension_key' => $result->getExtension()->getKey(),
                    'analysis_timestamp' => (new \DateTime())->format('c'),
                    'fractor_version' => $result->getMetric('fractor_version'),
                    'execution_time' => $result->getMetric('execution_time'),
                ],
                'summary' => $rawSummary,
                'findings' => $processedFindings,
            ];
        }

        return $analysisData;
    }
}
