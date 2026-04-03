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
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\VcsAvailability;
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

        $packagistLatestVersion = $result->getMetric('packagist_latest_version');
        $hasNewerPackagistVersion = false;

        if ($packagistLatestVersion && $result->getExtension()->getVersion()) {
            $currentVersion = $result->getExtension()->getVersion()->toString();
            // simple check to avoid comparing "dev-master" or similar non-semver strings if possible,
            // but version_compare handles many cases.
            // We assume strict semver for accurate comparison, but 'path' extensions might have arbitrary versions.
            if ('N/A' !== $currentVersion) {
                $packagistLatestVersionNormalized = ltrim((string) $packagistLatestVersion, 'v');
                $hasNewerPackagistVersion = version_compare($packagistLatestVersionNormalized, $currentVersion, '>');
            }
        }

        return [
            'skipped' => $result->getMetric('skipped'),
            'skip_reason' => $result->getMetric('skip_reason'),
            'distribution_type' => $result->getExtension()->getDistribution()?->getType(),
            'ter_available' => $result->getMetric('ter_available'),
            'packagist_available' => $result->getMetric('packagist_available'),
            'packagist_latest_version' => $packagistLatestVersion,
            'packagist_latest_compatible' => $result->getMetric('packagist_latest_compatible'),
            'has_newer_packagist_version' => $hasNewerPackagistVersion,
            'vcs_available' => ($result->getMetric('vcs_available') instanceof VcsAvailability)
                ? $result->getMetric('vcs_available')->value
                : VcsAvailability::Unknown->value,
            'vcs_source_url' => $result->getMetric('vcs_source_url'),
            'vcs_latest_version' => $result->getMetric('vcs_latest_version'),
            'risk_score' => $result->getRiskScore(),
            'risk_level' => $result->getRiskLevel(),
            'recommendations' => $result->getRecommendations(),
        ];
    }
}
