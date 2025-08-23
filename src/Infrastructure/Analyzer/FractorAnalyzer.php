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
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorAnalysisSummary;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorConfigGenerator;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorExecutionException;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorExecutor;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorResultParser;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\ExtensionIdentifier;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathConfiguration;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionRequestBuilder;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\InstallationTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\PathResolutionServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Analyzer that uses Fractor to modernize code and detect improvement opportunities.
 */
class FractorAnalyzer extends AbstractCachedAnalyzer
{
    public const string NAME = 'fractor';
    public const string DESCRIPTION = 'Uses Fractor to modernize code patterns and detect improvement opportunities';

    public function __construct(
        CacheService $cacheService,
        LoggerInterface $logger,
        private readonly FractorExecutor $fractorExecutor,
        private readonly FractorConfigGenerator $configGenerator,
        private readonly FractorResultParser $resultParser,
        private readonly PathResolutionServiceInterface $pathResolutionService,
    ) {
        parent::__construct($cacheService, $logger);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return self::DESCRIPTION;
    }

    public function supports(Extension $extension): bool
    {
        // Support all extensions that have PHP files to analyze
        return true;
    }

    public function getRequiredTools(): array
    {
        return ['php', 'fractor'];
    }

    public function hasRequiredTools(): bool
    {
        // Check if Fractor is available
        if (!$this->fractorExecutor->isAvailable()) {
            $this->logger->warning('Fractor binary not available', [
                'analyzer' => $this->getName(),
            ]);

            return false;
        }

        // Check if PHP is available (should always be true since we're running in PHP)
        return \function_exists('exec');
    }

    protected function doAnalyze(Extension $extension, AnalysisContext $context): AnalysisResult
    {
        $result = new AnalysisResult($this->getName(), $extension);

        $this->logger->info('Starting Fractor analysis', [
            'extension' => $extension->getKey(),
            'target_version' => $context->getTargetVersion()->toString(),
        ]);

        try {
            // Get extension path for analysis
            $extensionPath = $this->getExtensionPath($extension, $context);

            // Generate Fractor configuration
            $configPath = $this->generateFractorConfig($extension, $context, $extensionPath);

            // Execute Fractor analysis
            $executionResult = $this->fractorExecutor->execute($configPath, $extensionPath, true);

            // Parse results
            $summary = $this->resultParser->parse($executionResult);

            // Store results in AnalysisResult
            $this->storeResults($result, $summary, $extension);

            // Clean up temporary config file
            if (file_exists($configPath)) {
                unlink($configPath);
            }
        } catch (FractorExecutionException $e) {
            $this->logger->error('Fractor execution failed', [
                'extension' => $extension->getKey(),
                'error' => $e->getMessage(),
            ]);

            // Return partial result with error indication
            $result->addMetric('execution_failed', true);
            $result->addMetric('error_message', $e->getMessage());
            $result->setRiskScore(8.0); // High risk due to analysis failure
            $result->addRecommendation('Fractor analysis failed - manual code review recommended');
        } catch (\Throwable $e) {
            $this->logger->error('Unexpected error during Fractor analysis', [
                'extension' => $extension->getKey(),
                'error' => $e->getMessage(),
            ]);

            $result->addMetric('analysis_error', true);
            $result->setRiskScore(5.0);
            $result->addRecommendation('Analysis encountered errors - results may be incomplete');
        }

        return $result;
    }

    protected function getAnalyzerSpecificCacheKeyComponents(Extension $extension, AnalysisContext $context): array
    {
        return [
            'target_version' => $context->getTargetVersion()->toString(),
            'extension_path' => $this->getExtensionPathForCache($extension, $context),
        ];
    }

    private function getExtensionPath(Extension $extension, AnalysisContext $context): string
    {
        // Get installation path from context
        $installationPath = $context->getConfigurationValue('installation_path', '');

        if (empty($installationPath)) {
            throw new AnalyzerException('No installation path available in context', $this->getName());
        }

        // Convert relative path to absolute path
        if (!str_starts_with($installationPath, '/')) {
            $installationPath = realpath(getcwd() . '/' . $installationPath);
            if (!$installationPath) {
                throw new AnalyzerException('Invalid installation path - could not resolve to absolute path', $this->getName());
            }
        }

        // Use PathResolutionService to find extension path
        $extensionPath = $this->findExtensionPath($installationPath, $extension->getKey(), $context, $extension);

        if (!$extensionPath || !is_dir($extensionPath)) {
            throw new AnalyzerException(\sprintf('Extension directory not found for %s (attempted: %s)', $extension->getKey(), $extensionPath ?? 'unknown'), $this->getName());
        }

        return $extensionPath;
    }

    private function getExtensionPathForCache(Extension $extension, AnalysisContext $context): string
    {
        try {
            return $this->getExtensionPath($extension, $context);
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    private function generateFractorConfig(Extension $extension, AnalysisContext $context, string $extensionPath): string
    {
        return $this->configGenerator->generateConfig($extension, $context, $extensionPath);
    }

    private function storeResults(AnalysisResult $result, FractorAnalysisSummary $summary, Extension $extension): void
    {
        // Store basic metrics
        $result->addMetric('files_scanned', $summary->filesScanned);
        $result->addMetric('files_changed', $summary->filesScanned); // Same as files_scanned since Fractor only reports files with changes
        $result->addMetric('rules_applied', $summary->rulesApplied);
        $result->addMetric('total_issues', $summary->getTotalIssues());
        $result->addMetric('has_findings', $summary->hasFindings());
        $result->addMetric('analysis_successful', $summary->successful);

        // Store detailed metrics from parser
        $result->addMetric('change_blocks', $summary->changeBlocks ?? 0);
        $result->addMetric('changed_lines', $summary->changedLines ?? 0);
        $result->addMetric('file_paths', $summary->filePaths ?? []);
        $result->addMetric('applied_rules', $summary->appliedRules ?? []);

        // Store error message if analysis failed
        if ($summary->errorMessage) {
            $result->addMetric('error_message', $summary->errorMessage);
        }

        // Store findings (limited to avoid excessive data) - excluding diff changes
        $limitedFindings = \array_slice($summary->findings, 0, 20);
        $result->addMetric('findings', $limitedFindings);

        // Calculate risk score
        $riskScore = $this->calculateRiskScore($summary);
        $result->setRiskScore($riskScore);

        // Add recommendations
        $recommendations = $this->generateRecommendations($summary, $extension);
        foreach ($recommendations as $recommendation) {
            $result->addRecommendation($recommendation);
        }

        $this->logger->info('Fractor analysis completed', [
            'extension' => $extension->getKey(),
            'files_scanned' => $summary->filesScanned,
            'rules_applied' => $summary->rulesApplied,
            'change_blocks' => $summary->changeBlocks ?? 0,
            'changed_lines' => $summary->changedLines ?? 0,
            'risk_score' => $riskScore,
        ]);
    }

    private function calculateRiskScore(FractorAnalysisSummary $summary): float
    {
        // Base score on number of issues found
        $score = 1.0; // Baseline low risk

        if (!$summary->successful) {
            return 9.0; // High risk if analysis failed
        }

        // Add risk based on number of rules that would be applied
        $rulesApplied = $summary->rulesApplied;
        if ($rulesApplied > 50) {
            $score += 4.0; // Many changes needed
        } elseif ($rulesApplied > 20) {
            $score += 3.0; // Moderate changes
        } elseif ($rulesApplied > 5) {
            $score += 2.0; // Some changes
        } elseif ($rulesApplied > 0) {
            $score += 1.0; // Minor changes
        }

        // Add risk based on files affected
        $filesScanned = $summary->filesScanned;
        if ($filesScanned > 20) {
            $score += 2.0; // Many files affected
        } elseif ($filesScanned > 5) {
            $score += 1.0; // Some files affected
        }

        return min($score, 10.0); // Cap at 10.0
    }

    /**
     * @return array<string>
     */
    private function generateRecommendations(FractorAnalysisSummary $summary, Extension $extension): array
    {
        $recommendations = [];

        if (!$summary->successful) {
            $recommendations[] = 'Fractor analysis failed - consider manual code review and modernization';

            return $recommendations;
        }

        $rulesApplied = $summary->rulesApplied;
        $filesScanned = $summary->filesScanned;

        if (0 === $rulesApplied) {
            $recommendations[] = 'Code appears to follow modern patterns - minimal refactoring needed';
        } elseif ($rulesApplied > 50) {
            $recommendations[] = "Many modernization opportunities found ({$rulesApplied} rules) - consider systematic refactoring";
            $recommendations[] = 'Plan extensive testing after applying Fractor suggestions';
        } elseif ($rulesApplied > 20) {
            $recommendations[] = "Moderate modernization opportunities found ({$rulesApplied} rules) - review and apply selectively";
        } elseif ($rulesApplied > 5) {
            $recommendations[] = "Some modernization opportunities found ({$rulesApplied} rules) - consider applying before upgrade";
        } else {
            $recommendations[] = "Minor modernization opportunities found ({$rulesApplied} rules) - low priority for upgrade";
        }

        if ($filesScanned > 10) {
            $recommendations[] = "Analysis covered {$filesScanned} files - coordinate with development team for implementation";
        } elseif ($filesScanned > 0) {
            $recommendations[] = "Analysis covered {$filesScanned} files - review impact before applying changes";
        }

        // Add specific recommendations based on findings
        if ($summary->hasFindings()) {
            $recommendations[] = 'Review specific Fractor suggestions in detailed analysis results';
        }

        return $recommendations;
    }

    /**
     * Find extension path using PathResolutionService.
     */
    private function findExtensionPath(string $installationPath, string $extensionKey, AnalysisContext $context, Extension $extension): ?string
    {
        $customPaths = $context->getConfigurationValue('custom_paths', null);

        $extensionIdentifier = new ExtensionIdentifier(
            $extension->getKey(),
            $extension->getVersion()->toString(),
            $extension->getType(),
            $extension->getComposerName(),
        );

        $pathConfiguration = PathConfiguration::fromArray([
            'customPaths' => $customPaths ?? [],
        ]);

        $builder = new PathResolutionRequestBuilder();
        $request = $builder
            ->installationPath($installationPath)
            ->pathType(PathTypeEnum::EXTENSION)
            ->installationType(InstallationTypeEnum::AUTO_DETECT) // Let PathResolutionService auto-detect
            ->pathConfiguration($pathConfiguration)
            ->extensionIdentifier($extensionIdentifier)
            ->build();

        $response = $this->pathResolutionService->resolvePath($request);

        if ($response->isSuccess()) {
            $this->logger->debug('PathResolutionService found extension path', [
                'extension' => $extensionKey,
                'path' => $response->resolvedPath,
            ]);

            return $response->resolvedPath;
        }

        $this->logger->warning('PathResolutionService failed to find extension path', [
            'extension' => $extensionKey,
            'errors' => $response->errors,
        ]);

        return null;
    }
}
