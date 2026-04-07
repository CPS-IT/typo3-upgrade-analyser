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
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\VcsAvailability;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailability\VersionSourceInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

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
        $configuredSources = $context->getConfigurationValue('analysis.analyzers.version_availability.sources', ['ter', 'packagist', 'vcs']);

        // Normalize configured sources (map github -> vcs)
        $enabledSources = array_map(static function ($source) {
            return 'github' === $source ? 'vcs' : $source;
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

        // Include normalized sources in cache key (github → vcs)
        $configuredSources = $context->getConfigurationValue('analysis.analyzers.version_availability.sources', ['ter', 'packagist', 'vcs']);
        $normalizedSources = array_map(static fn ($s): mixed => 'github' === $s ? 'vcs' : $s, $configuredSources);
        $components['sources'] = implode(',', $normalizedSources);

        return $components;
    }

    public function getRequiredTools(): array
    {
        return ['curl', 'git'];
    }

    public function hasRequiredTools(): bool
    {
        if (!\function_exists('curl_init')) {
            return false;
        }

        $process = new Process(['git', '--version']);
        $process->run();

        return $process->isSuccessful();
    }

    private function calculateRiskScore(array $metrics, Extension $extension, array $enabledSources): float
    {
        $terAvailable = $metrics['ter_available'] ?? false;
        $packagistAvailable = $metrics['packagist_available'] ?? false;

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

        // VCS availability: enum (Available=2pts, Unavailable=0pts, Unknown=skip)
        if (\in_array('vcs', $enabledSources, true)) {
            $vcsAvailable = $metrics['vcs_available'] ?? VcsAvailability::Unknown;
            // Normalize string → enum in case the value was deserialized from a higher-level cache.
            if (\is_string($vcsAvailable)) {
                $vcsAvailable = VcsAvailability::tryFrom($vcsAvailable) ?? VcsAvailability::Unknown;
            }
            if ($vcsAvailable instanceof VcsAvailability && VcsAvailability::Unknown !== $vcsAvailable) {
                $maxPossibleScore += 2;
                if (VcsAvailability::Available === $vcsAvailable) {
                    $availabilityScore += 2;
                }
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

        // No availability
        return 9.0;
    }

    private function addRecommendations(AnalysisResult $result, Extension $extension): void
    {
        $terAvailable = $result->getMetric('ter_available');
        $packagistAvailable = $result->getMetric('packagist_available');
        $vcsAvailable = $result->getMetric('vcs_available');
        $vcsUrl = $result->getMetric('vcs_source_url');

        // Normalize string → enum in case the value was deserialized from a higher-level cache.
        if (\is_string($vcsAvailable)) {
            $vcsAvailable = VcsAvailability::tryFrom($vcsAvailable) ?? VcsAvailability::Unknown;
        }

        $vcsIsAvailable = $vcsAvailable instanceof VcsAvailability && VcsAvailability::Available === $vcsAvailable;
        $anyAvailable = $terAvailable || $packagistAvailable || $vcsIsAvailable;

        // No availability anywhere
        if (!$anyAvailable) {
            $result->addRecommendation('Extension not available in any known repository. Consider finding alternative or contact the author.');

            return;
        }

        // VCS-specific recommendations (only VCS source available)
        if ($vcsIsAvailable && !$terAvailable && !$packagistAvailable) {
            $result->addRecommendation('Extension is only available via VCS repository. Consider repository maintenance status before upgrade.');

            if ($vcsUrl) {
                $result->addRecommendation("VCS repository: {$vcsUrl}");
            }
        }

        // Mixed availability recommendations
        if ($vcsIsAvailable && ($terAvailable || $packagistAvailable)) {
            $result->addRecommendation('Extension is available in multiple sources. Consider using most stable source for production.');
        }

        if ($terAvailable && $packagistAvailable && !$vcsIsAvailable) {
            $result->addRecommendation('Extension is available in multiple sources (TER and Packagist). Consider using Composer for better dependency management.');
        }

        // Standard TER/Packagist recommendations
        if (!$terAvailable && $packagistAvailable && !$vcsIsAvailable) {
            $result->addRecommendation('Extension is only available via Composer/Packagist. Ensure Composer mode is used.');
        } elseif ($terAvailable && !$packagistAvailable && !$vcsIsAvailable && $extension->hasComposerName()) {
            $result->addRecommendation('Extension is only available in TER. Consider migrating to Composer if needed.');
        }

        // Local extension with alternatives (at this point $anyAvailable is always true — early return above handles false)
        if ($extension->isLocalExtension()) {
            $result->addRecommendation('Local extension has public alternatives available. Consider using official version.');
        }
    }
}
