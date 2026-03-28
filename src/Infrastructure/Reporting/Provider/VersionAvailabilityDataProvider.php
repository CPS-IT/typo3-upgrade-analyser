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

class VersionAvailabilityDataProvider implements AnalysisReportDataProviderInterface
{
    public function getAnalyzerName(): string
    {
        return 'version_availability';
    }

    public function getResultKey(): string
    {
        return 'version_analysis';
    }

    public function getTemplatePath(string $format): string
    {
        return match ($format) {
            'html' => 'html/partials/main-report/version-availability-table.html.twig',
            'md' => 'md/partials/main-report/version-availability-table.md.twig',
            default => throw new \InvalidArgumentException(\sprintf('Unsupported format: %s', $format)),
        };
    }

    public function extractData(array $results): ?array
    {
        $versionResult = array_filter(
            $results,
            fn (ResultInterface $r): bool => $r instanceof AnalysisResult && $this->getAnalyzerName() === $r->getAnalyzerName(),
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
}
