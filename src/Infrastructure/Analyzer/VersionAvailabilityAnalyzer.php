<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\Entity\AnalysisResult;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\PackagistClient;
use Psr\Log\LoggerInterface;

/**
 * Analyzer that checks version availability across different repositories
 */
class VersionAvailabilityAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly TerApiClient $terClient,
        private readonly PackagistClient $packagistClient,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getName(): string
    {
        return 'version_availability';
    }

    public function getDescription(): string
    {
        return 'Checks if compatible versions exist in TER, Packagist, or Git repositories';
    }

    public function supports(Extension $extension): bool
    {
        // This analyzer supports all extensions
        return true;
    }

    public function analyze(Extension $extension, AnalysisContext $context): AnalysisResult
    {
        $result = new AnalysisResult($this->getName(), $extension);
        
        try {
            $this->logger->info('Analyzing version availability for extension', [
                'extension' => $extension->getKey(),
                'target_version' => $context->getTargetVersion()->toString(),
            ]);

            // Check TER availability
            $terAvailable = $this->checkTerAvailability($extension, $context);
            $result->addMetric('ter_available', $terAvailable);

            // Check Packagist availability (if extension has composer name)
            if ($extension->hasComposerName()) {
                $packagistAvailable = $this->checkPackagistAvailability($extension, $context);
                $result->addMetric('packagist_available', $packagistAvailable);
            } else {
                $result->addMetric('packagist_available', false);
            }

            // Calculate risk score based on availability
            $riskScore = $this->calculateRiskScore($result->getMetrics(), $extension);
            $result->setRiskScore($riskScore);

            // Add recommendations
            $this->addRecommendations($result, $extension);

            $this->logger->info('Version availability analysis completed', [
                'extension' => $extension->getKey(),
                'risk_score' => $riskScore,
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('Version availability analysis failed', [
                'extension' => $extension->getKey(),
                'error' => $e->getMessage(),
            ]);
            
            $result->setError('Analysis failed: ' . $e->getMessage());
            // Return early to ensure failed result
            return $result;
        }

        return $result;
    }

    public function getRequiredTools(): array
    {
        return ['curl']; // Required for HTTP API calls
    }

    public function hasRequiredTools(): bool
    {
        // Check if curl is available
        return function_exists('curl_init');
    }

    private function checkTerAvailability(Extension $extension, AnalysisContext $context): bool
    {
        try {
            return $this->terClient->hasVersionFor(
                $extension->getKey(),
                $context->getTargetVersion()
            );
        } catch (\Throwable $e) {
            // Let fatal errors bubble up to cause complete analysis failure
            if (str_contains($e->getMessage(), 'Fatal error')) {
                throw $e;
            }
            
            $this->logger->warning('TER availability check failed', [
                'extension' => $extension->getKey(),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function checkPackagistAvailability(Extension $extension, AnalysisContext $context): bool
    {
        if (!$extension->hasComposerName()) {
            return false;
        }

        try {
            return $this->packagistClient->hasVersionFor(
                $extension->getComposerName(),
                $context->getTargetVersion()
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Packagist availability check failed', [
                'extension' => $extension->getKey(),
                'composer_name' => $extension->getComposerName(),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function calculateRiskScore(array $metrics, Extension $extension): float
    {
        $terAvailable = $metrics['ter_available'] ?? false;
        $packagistAvailable = $metrics['packagist_available'] ?? false;

        // System extensions are low risk (maintained by TYPO3 core team)
        if ($extension->isSystemExtension()) {
            return 1.0;
        }

        // If available in both TER and Packagist - very low risk
        if ($terAvailable && $packagistAvailable) {
            return 2.0;
        }

        // If available in either TER or Packagist - low to medium risk
        if ($terAvailable || $packagistAvailable) {
            return 4.0;
        }

        // If not available anywhere - high risk
        return 8.0;
    }

    private function addRecommendations(AnalysisResult $result, Extension $extension): void
    {
        $terAvailable = $result->getMetric('ter_available');
        $packagistAvailable = $result->getMetric('packagist_available');

        if (!$terAvailable && !$packagistAvailable) {
            $result->addRecommendation('No compatible version found in public repositories. Consider contacting extension author or finding alternative.');
        } elseif (!$terAvailable && $packagistAvailable) {
            $result->addRecommendation('Extension is only available via Composer/Packagist. Ensure Composer mode is used.');
        } elseif ($terAvailable && !$packagistAvailable && $extension->hasComposerName()) {
            $result->addRecommendation('Extension is only available in TER. Consider migrating to Composer if needed.');
        }

        if ($extension->isLocalExtension() && ($terAvailable || $packagistAvailable)) {
            $result->addRecommendation('Local extension has public alternatives available. Consider using official version.');
        }
    }
}