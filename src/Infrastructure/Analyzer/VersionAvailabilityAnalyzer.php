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

use CPSIT\UpgradeAnalyzer\Domain\Entity\AnalysisResult;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitAnalysisException;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryAnalyzer;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\PackagistClient;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient;
use Psr\Log\LoggerInterface;

/**
 * Analyzer that checks version availability across different repositories.
 */
class VersionAvailabilityAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly TerApiClient $terClient,
        private readonly PackagistClient $packagistClient,
        private readonly GitRepositoryAnalyzer $gitAnalyzer,
        private readonly LoggerInterface $logger,
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

            // Check Git repository availability (if Git analyzer is available)
            $gitInfo = $this->checkGitAvailability($extension, $context);
            $result->addMetric('git_available', $gitInfo['available']);
            $result->addMetric('git_repository_health', $gitInfo['health']);
            $result->addMetric('git_repository_url', $gitInfo['url']);
            if ($gitInfo['latest_version']) {
                $result->addMetric('git_latest_version', $gitInfo['latest_version']);
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
        return \function_exists('curl_init');
    }

    private function checkTerAvailability(Extension $extension, AnalysisContext $context): bool
    {
        try {
            return $this->terClient->hasVersionFor(
                $extension->getKey(),
                $context->getTargetVersion(),
            );
        } catch (\Throwable $e) {
            // Let fatal errors bubble up to cause complete analysis failure
            if (str_contains($e->getMessage(), 'Fatal error')) {
                throw $e;
            }

            $this->logger->warning('TER availability check failed, checking fallback sources', [
                'extension' => $extension->getKey(),
                'error' => $e->getMessage(),
            ]);

            // TER specifically failed, return false for TER availability
            // Packagist will be checked separately
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
                $context->getTargetVersion(),
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

    private function checkGitAvailability(Extension $extension, AnalysisContext $context): array
    {
        // Default response when Git analysis is not available
        $defaultResponse = [
            'available' => false,
            'health' => null,
            'url' => null,
            'latest_version' => null,
        ];

        try {
            $gitInfo = $this->gitAnalyzer->analyzeExtension($extension, $context->getTargetVersion());

            return [
                'available' => $gitInfo->hasCompatibleVersion(),
                'health' => $gitInfo->getHealthScore(),
                'url' => $gitInfo->getRepositoryUrl(),
                'latest_version' => $gitInfo->getLatestCompatibleVersion()?->getName(),
            ];
        } catch (GitAnalysisException $e) {
            $this->logger->info('Git analysis skipped for extension', [
                'extension' => $extension->getKey(),
                'reason' => $e->getMessage(),
            ]);

            return $defaultResponse;
        } catch (\Throwable $e) {
            $this->logger->warning('Git availability check failed', [
                'extension' => $extension->getKey(),
                'error' => $e->getMessage(),
            ]);

            return $defaultResponse;
        }
    }

    private function calculateRiskScore(array $metrics, Extension $extension): float
    {
        $terAvailable = $metrics['ter_available'] ?? false;
        $packagistAvailable = $metrics['packagist_available'] ?? false;
        $gitAvailable = $metrics['git_available'] ?? false;
        $gitHealth = $metrics['git_repository_health'] ?? null;

        // System extensions are low risk (maintained by TYPO3 core team)
        if ($extension->isSystemExtension()) {
            return 1.0;
        }

        // Calculate base availability score
        $availabilityScore = 0;
        if ($terAvailable) {
            $availabilityScore += 4;
        } // TER is most trusted
        if ($packagistAvailable) {
            $availabilityScore += 3;
        } // Packagist is second
        if ($gitAvailable) {
            // Git availability weighted by repository health
            $gitWeight = $gitHealth ? (2 * $gitHealth) : 1;
            $availabilityScore += $gitWeight;
        }

        // Convert to risk score (higher availability = lower risk)
        if ($availabilityScore >= 6) {
            return 1.5;
        } // Multiple high-quality sources
        if ($availabilityScore >= 4) {
            return 2.5;
        } // At least one high-quality source
        if ($availabilityScore >= 2) {
            return 5.0;
        } // Some availability
        if ($availabilityScore >= 1) {
            return 7.0;
        } // Limited availability

        return 9.0; // No availability
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
            $result->addRecommendation('Extension not available in any known repository. Consider finding alternative or contacting author.');

            return;
        }

        // Git-specific recommendations
        if ($gitAvailable && !$terAvailable && !$packagistAvailable) {
            if ($gitHealth && $gitHealth > 0.7) {
                $result->addRecommendation('Extension only available via Git repository. Repository appears well-maintained.');
            } else {
                $result->addRecommendation('Extension only available via Git repository. Consider repository maintenance status before upgrade.');
            }

            if ($gitUrl) {
                $result->addRecommendation("Git repository: {$gitUrl}");
            }
        }

        // Mixed availability recommendations
        if ($gitAvailable && ($terAvailable || $packagistAvailable)) {
            $result->addRecommendation('Extension available in multiple sources. Consider using most stable source for production.');
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
