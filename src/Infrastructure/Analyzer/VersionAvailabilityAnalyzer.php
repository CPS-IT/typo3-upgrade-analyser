<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer;

use CPSIT\UpgradeAnalyzer\Domain\Entity\AnalysisResult;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailability\VersionSourceInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use Psr\Log\LoggerInterface;

/**
 * Analyzer that checks version availability across different repositories.
 */
class VersionAvailabilityAnalyzer extends AbstractCachedAnalyzer
{
    /**
     * @param iterable<VersionSourceInterface> $sources
     */
    public function __construct(
        CacheService $cacheService,
        LoggerInterface $logger,
        private readonly iterable $sources,
    ) {
        parent::__construct($cacheService, $logger);
    }

    public function getName(): string
    {
        return 'version_availability';
    }

    public function getDescription(): string
    {
        return 'Checks if compatible versions exist in different sources';
    }

    public function supports(Extension $extension): bool
    {
        // This analyzer supports all extensions
        return true;
    }

    protected function doAnalyze(Extension $extension, AnalysisContext $context): AnalysisResult
    {
        $result = new AnalysisResult($this->getName(), $extension);

        $this->logger->info('Analyzing version availability for extension', [
            'extension' => $extension->getKey(),
            'target_version' => $context->getTargetVersion()->toString(),
        ]);

        $distribution = $extension->getDistribution();
        if (null !== $distribution && 'path' === $distribution->getType()) {
            $result->addMetric('skipped', true);
            $result->addMetric('skip_reason', 'External version checks skipped for local extension');
            $result->setRiskScore(1.0);
            $result->addRecommendation('Use Rector and Fractor to upgrade the extension and do the rest by hand.');

            return $result;
        }

        // Get configured sources (default to all if not specified)
        $configuredSources = $context->getConfigurationValue('analysis.analyzers.version_availability.sources', ['ter', 'packagist', 'git']);

        // Normalize configured sources (map github -> git)
        $enabledSources = array_map(static function ($source) {
            return 'github' === $source ? 'git' : $source;
        }, $configuredSources);

        foreach ($this->sources as $source) {
            if (\in_array($source->getName(), $enabledSources, true)) {
                $sourceMetrics = $source->checkAvailability($extension, $context);
                foreach ($sourceMetrics as $key => $value) {
                    $result->addMetric($key, $value);
                }
            }
        }

        // Calculate risk score based on availability
        $riskScore = $this->calculateRiskScore($result->getMetrics(), $extension, $enabledSources);
        $result->setRiskScore($riskScore);

        // Add recommendations
        $this->addRecommendations($result, $extension);

        $this->logger->info('Version availability analysis completed', [
            'extension' => $extension->getKey(),
            'risk_score' => $riskScore,
        ]);

        return $result;
    }

    protected function getAnalyzerSpecificCacheKeyComponents(Extension $extension, AnalysisContext $context): array
    {
        // Include composer name if available since it affects Packagist checks
        $components = [];

        if ($extension->hasComposerName()) {
            $components['composer_name'] = $extension->getComposerName();
        }

        if (null !== $extension->getDistribution()) {
            $components['distribution_type'] = $extension->getDistribution()->getType();
        }

        // Include configured sources in cache key
        $configuredSources = $context->getConfigurationValue('analysis.analyzers.version_availability.sources', ['ter', 'packagist', 'git']);
        $components['sources'] = implode(',', $configuredSources);

        return $components;
    }

    public function getRequiredTools(): array
    {
        return ['curl']; // Required for HTTP API calls
    }

    public function hasRequiredTools(): bool
    {
        // Check if curl is available
        return \function_exists('curl_init');
    }

    private function calculateRiskScore(array $metrics, Extension $extension, array $enabledSources): float
    {
        $terAvailable = $metrics['ter_available'] ?? false;
        $packagistAvailable = $metrics['packagist_available'] ?? false;
        $gitAvailable = $metrics['git_available'] ?? false;
        $gitHealth = $metrics['git_repository_health'] ?? null;

        // System extensions are low risk (maintained by TYPO3 core team)
        if ($extension->isSystemExtension()) {
            return 1.0;
        }

        // Calculate base availability score and max possible score
        $availabilityScore = 0;
        $maxPossibleScore = 0;

        // TER is most trusted
        if (\in_array('ter', $enabledSources, true)) {
            $maxPossibleScore += 4;
            if ($terAvailable) {
                $availabilityScore += 4;
            }
        }

        // Packagist is second
        if (\in_array('packagist', $enabledSources, true)) {
            $maxPossibleScore += 3;
            if ($packagistAvailable) {
                $availabilityScore += 3;
            }
        }

        // Git availability weighted by repository health
        if (\in_array('git', $enabledSources, true)) {
            $maxPossibleScore += 2;
            if ($gitAvailable) {
                $gitWeight = $gitHealth ? (2 * $gitHealth) : 1;
                $availabilityScore += $gitWeight;
            }
        }

        // If no sources are enabled or max score is 0, return high risk
        if (0 === $maxPossibleScore) {
            return 9.0;
        }

        // Calculate thresholds dynamically based on max possible score
        // We use the same ratios as the original hardcoded thresholds (6/9, 4/9, 2/9)
        $thresholdHigh = $maxPossibleScore * 0.66;
        $thresholdMedium = $maxPossibleScore * 0.44;
        $thresholdLow = $maxPossibleScore * 0.22;

        // Convert to risk score (higher availability = lower risk)
        // High availability
        if ($availabilityScore >= $thresholdHigh) {
            return 1.5;
        }

        // Medium availability
        if ($availabilityScore >= $thresholdMedium) {
            return 2.5;
        }

        // Low availability
        if ($availabilityScore >= $thresholdLow) {
            return 5.0;
        }

        // Very low availability
        if ($availabilityScore >= 1) {
            return 7.0;
        }

        // No availability
        return 9.0;
    }

    private function addRecommendations(AnalysisResult $result, Extension $extension): void
    {
        $terAvailable = $result->getMetric('ter_available');
        $packagistAvailable = $result->getMetric('packagist_available');
        $gitAvailable = $result->getMetric('git_available');
        $gitHealth = $result->getMetric('git_repository_health');
        $gitUrl = $result->getMetric('git_repository_url');

        // No availability anywhere
        if (!$terAvailable && !$packagistAvailable && !$gitAvailable) {
            $result->addRecommendation('Extension not available in any known repository. Consider finding alternative or contact the author.');

            return;
        }

        // Git-specific recommendations
        if ($gitAvailable && !$terAvailable && !$packagistAvailable) {
            if ($gitHealth && $gitHealth > 0.7) {
                $result->addRecommendation('Extension is only available via Git repository. Repository appears well-maintained.');
            } else {
                $result->addRecommendation('Extension is only available via Git repository. Consider repository maintenance status before upgrade.');
            }

            if ($gitUrl) {
                $result->addRecommendation("Git repository: {$gitUrl}");
            }
        }

        // Mixed availability recommendations
        if ($gitAvailable && ($terAvailable || $packagistAvailable)) {
            $result->addRecommendation('Extension is available in multiple sources. Consider using most stable source for production.');
        }

        if ($terAvailable && $packagistAvailable && !$gitAvailable) {
            $result->addRecommendation('Extension is available in multiple sources (TER and Packagist). Consider using Composer for better dependency management.');
        }

        // Standard TER/Packagist recommendations
        if (!$terAvailable && $packagistAvailable && !$gitAvailable) {
            $result->addRecommendation('Extension is only available via Composer/Packagist. Ensure Composer mode is used.');
        } elseif ($terAvailable && !$packagistAvailable && !$gitAvailable && $extension->hasComposerName()) {
            $result->addRecommendation('Extension is only available in TER. Consider migrating to Composer if needed.');
        }

        // Git repository health warnings
        if ($gitAvailable && $gitHealth && $gitHealth < 0.3) {
            $result->addRecommendation('Git repository shows signs of poor maintenance. Consider alternative sources or extensions.');
        }

        // Local extension with alternatives
        if ($extension->isLocalExtension() && ($terAvailable || $packagistAvailable || $gitAvailable)) {
            $result->addRecommendation('Local extension has public alternatives available. Consider using official version.');
        }
    }
}
