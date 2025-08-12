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
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorAnalysisSummary;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorConfigGenerator;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorExecutionResult;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorExecutor;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorResultParser;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorRuleRegistry;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use Psr\Log\LoggerInterface;

/**
 * Analyzer that uses TYPO3 Rector to detect deprecated code patterns and breaking changes.
 */
class Typo3RectorAnalyzer extends AbstractCachedAnalyzer
{
    public const string NAME = 'typo3_rector';
    public const string DESCRIPTION =
        'Uses TYPO3 Rector to detect deprecated code patterns, breaking changes, and upgrade requirements';

    public function __construct(
        CacheService $cacheService,
        LoggerInterface $logger,
        private readonly RectorExecutor $rectorExecutor,
        private readonly RectorConfigGenerator $configGenerator,
        private readonly RectorResultParser $resultParser,
        private readonly RectorRuleRegistry $ruleRegistry,
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
        return ['php', 'rector'];
    }

    public function hasRequiredTools(): bool
    {
        // Check if Rector is available
        if (!$this->rectorExecutor->isAvailable()) {
            $this->logger->warning('Rector binary not available', [
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

        $this->logger->info('Starting TYPO3 Rector analysis', [
            'extension' => $extension->getKey(),
            'target_version' => $context->getTargetVersion()->toString(),
            'current_version' => $context->getCurrentVersion()->toString(),
        ]);

        try {
            // Get extension path for analysis
            $extensionPath = $this->getExtensionPath($extension, $context);

            // Generate Rector configuration
            $configPath = $this->generateRectorConfig($extension, $context, $extensionPath);

            // Execute Rector analysis
            $executionResult = $this->executeRectorAnalysis($configPath, $extensionPath);

            // Parse results into structured data
            $summary = $this->resultParser->aggregateFindings($executionResult->getFindings());

            // Add metrics to result
            $this->addMetricsToResult($result, $summary, $executionResult);

            // Calculate risk score
            $riskScore = $this->calculateRiskScore($summary);
            $result->setRiskScore($riskScore);

            // Generate recommendations
            $recommendations = $this->generateRecommendations($summary, $context);
            foreach ($recommendations as $recommendation) {
                $result->addRecommendation($recommendation);
            }

            $this->logger->info('TYPO3 Rector analysis completed', [
                'extension' => $extension->getKey(),
                'findings_count' => $summary->getTotalFindings(),
                'risk_score' => $riskScore,
                'execution_time' => $executionResult->getExecutionTime(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('TYPO3 Rector analysis failed', [
                'extension' => $extension->getKey(),
                'error' => $e->getMessage(),
            ]);

            throw new AnalyzerException("TYPO3 Rector analysis failed for extension {$extension->getKey()}: {$e->getMessage()}", $this->getName(), $e);
        } finally {
            // Clean up generated configuration files
            $this->configGenerator->cleanup();
        }

        return $result;
    }

    protected function getAnalyzerSpecificCacheKeyComponents(Extension $extension, AnalysisContext $context): array
    {
        $components = [];

        // Include version information in cache key
        $components['current_version'] = $context->getCurrentVersion()->toString();
        $components['target_version'] = $context->getTargetVersion()->toString();

        // Include Rector version if available
        $rectorVersion = $this->rectorExecutor->getVersion();
        if ($rectorVersion) {
            $components['rector_version'] = $rectorVersion;
        }

        // Include set count to invalidate cache when sets change
        $sets = $this->ruleRegistry->getSetsForVersionUpgrade(
            $context->getCurrentVersion(),
            $context->getTargetVersion(),
        );
        $components['set_count'] = \count($sets);

        return $components;
    }

    /**
     * Generate Rector configuration for the extension.
     */
    private function generateRectorConfig(Extension $extension, AnalysisContext $context, string $extensionPath): string
    {
        return $this->configGenerator->generateConfig($extension, $context, $extensionPath);
    }

    /**
     * Execute Rector analysis with configuration.
     */
    private function executeRectorAnalysis(string $configPath, string $extensionPath): RectorExecutionResult
    {
        $options = [
            'memory_limit' => '1G',
        ];

        return $this->rectorExecutor->execute($configPath, $extensionPath, $options);
    }

    /**
     * Get extension path for analysis based on installation configuration.
     * Note: Core extensions are excluded during discovery, so only custom extensions reach this analyzer.
     */
    private function getExtensionPath(Extension $extension, AnalysisContext $context): string
    {
        $installationPath = $context->getConfigurationValue('installation_path', '');
        $customPaths = $context->getConfigurationValue('custom_paths', []);

        if (empty($installationPath)) {
            $this->logger->warning('No installation path available - using fallback paths', [
                'extension' => $extension->getKey(),
            ]);

            return 'typo3conf/ext/' . $extension->getKey();
        }

        // Convert relative path to absolute path
        $currentDir = getcwd();
        if (!str_starts_with($installationPath, '/') && false !== $currentDir && !str_starts_with($installationPath, $currentDir)) {
            $resolvedPath = realpath($currentDir . '/' . $installationPath);
            if ($resolvedPath) {
                $installationPath = $resolvedPath;
            }
        }

        // All discovered extensions are custom extensions (core extensions are filtered out during discovery)
        // Custom extensions always go to typo3conf/ext/ in TYPO3 11+ regardless of composer management
        $typo3confDir = $customPaths['typo3conf-dir'] ?? 'public/typo3conf';

        // Handle direct extension path (for test fixtures)
        if ($typo3confDir === $extension->getKey()) {
            $extensionPath = $installationPath . '/' . $typo3confDir;
        } else {
            // Standard typo3conf/ext structure for all custom extensions
            $extensionPath = $installationPath . '/' . $typo3confDir . '/ext/' . $extension->getKey();
        }

        $this->logger->info('Rector analyzer resolved extension path', [
            'extension' => $extension->getKey(),
            'extension_type' => $extension->getType(),
            'composer_name' => $extension->getComposerName(),
            'installation_path' => $installationPath,
            'typo3conf_dir' => $typo3confDir,
            'resolved_path' => $extensionPath,
            'path_exists' => is_dir($extensionPath),
        ]);

        return $extensionPath;
    }

    /**
     * Add metrics from summary to analysis result.
     */
    private function addMetricsToResult(
        AnalysisResult $result,
        RectorAnalysisSummary $summary,
        RectorExecutionResult $executionResult,
    ): void {
        // Core metrics
        $result->addMetric('total_findings', $summary->getTotalFindings());
        $result->addMetric('affected_files', $summary->getAffectedFiles());
        $result->addMetric('total_files', $summary->getTotalFiles());
        $result->addMetric('execution_time', $executionResult->getExecutionTime());

        // Severity breakdown
        $severityDistribution = $summary->getSeverityDistribution();
        $result->addMetric('findings_by_severity', $severityDistribution);

        // Type breakdown
        $result->addMetric('findings_by_type', $summary->getTypeBreakdown());

        // Top affected files (limit to 10)
        $result->addMetric('top_affected_files', $summary->getTopIssuesByFile(10));

        // Top triggered rules (limit to 10)
        $result->addMetric('top_rules_triggered', $summary->getTopIssuesByRule(10));

        // Time and complexity metrics
        $result->addMetric('estimated_fix_time', $summary->getEstimatedFixTime());
        $result->addMetric('estimated_fix_time_hours', $summary->getEstimatedFixTimeHours());
        $result->addMetric('complexity_score', $summary->getComplexityScore());

        // Upgrade readiness metrics
        $result->addMetric('upgrade_readiness_score', $summary->getUpgradeReadinessScore());
        $result->addMetric('has_breaking_changes', $summary->hasBreakingChanges());
        $result->addMetric('has_deprecations', $summary->hasDeprecations());

        // File impact percentage
        $result->addMetric('file_impact_percentage', $summary->getFileImpactPercentage());

        // Summary text
        $result->addMetric('summary_text', $summary->getSummaryText());

        // Additional Rector-specific metrics
        $result->addMetric('rector_version', $this->rectorExecutor->getVersion());
        $result->addMetric('processed_files', $executionResult->getProcessedFileCount());
    }

    /**
     * Calculate risk score based on analysis summary.
     */
    private function calculateRiskScore(RectorAnalysisSummary $summary): float
    {
        $baseRisk = 1.0;

        // If no findings, return low risk
        if (0 === $summary->getTotalFindings()) {
            return $baseRisk;
        }

        // Breaking changes contribute heavily to risk
        $baseRisk += $summary->getCriticalIssues() * 1.2;

        // Deprecations contribute moderately
        $baseRisk += $summary->getWarnings() * 0.6;

        // Info issues contribute lightly
        $baseRisk += $summary->getInfoIssues() * 0.2;

        // File coverage impact
        $fileImpactRatio = $summary->getFileImpactPercentage() / 100;
        $baseRisk += $fileImpactRatio * 1.5;

        // Complexity multiplier
        $complexityMultiplier = 1 + ($summary->getComplexityScore() / 20); // Divide by 20 to get 0.5 max multiplier
        $baseRisk *= $complexityMultiplier;

        // Estimated effort factor (hours)
        $effortHours = $summary->getEstimatedFixTimeHours();
        if ($effortHours > 16) { // More than 2 days
            $baseRisk += 2.0;
        } elseif ($effortHours > 8) { // More than 1 day
            $baseRisk += 1.0;
        } elseif ($effortHours > 4) { // More than half day
            $baseRisk += 0.5;
        }

        // Cap at 10.0
        return min($baseRisk, 10.0);
    }

    /**
     * Generate recommendations based on analysis results.
     *
     * @return array<string>
     */
    private function generateRecommendations(
        RectorAnalysisSummary $summary,
        AnalysisContext $context,
    ): array {
        $recommendations = [];

        if (0 === $summary->getTotalFindings()) {
            $recommendations[] = 'No deprecated code patterns detected - extension appears ready for TYPO3 ' . $context->getTargetVersion()->toString();

            return $recommendations;
        }

        // Critical issues recommendations
        if ($summary->hasBreakingChanges()) {
            $recommendations[] = \sprintf(
                'Critical: %d breaking changes must be fixed before upgrading to TYPO3 %s',
                $summary->getCriticalIssues(),
                $context->getTargetVersion()->toString(),
            );
        }

        // Deprecation recommendations
        if ($summary->hasDeprecations()) {
            $recommendations[] = \sprintf(
                'Update %d deprecated code patterns to ensure compatibility with future TYPO3 versions',
                $summary->getWarnings(),
            );
        }

        // Effort-based recommendations
        $effortHours = $summary->getEstimatedFixTimeHours();
        if ($effortHours > 16) {
            $recommendations[] = \sprintf(
                'Large refactoring effort required (~%.1f hours). Consider staged implementation over multiple releases',
                $effortHours,
            );
        } elseif ($effortHours > 8) {
            $recommendations[] = \sprintf(
                'Significant refactoring needed (~%.1f hours). Plan dedicated development time for upgrade',
                $effortHours,
            );
        } elseif ($effortHours > 2) {
            $recommendations[] = \sprintf(
                'Moderate changes required (~%.1f hours). Review and test thoroughly',
                $effortHours,
            );
        }

        // Complexity-based recommendations
        if ($summary->getComplexityScore() > 7.0) {
            $recommendations[] = 'High complexity changes detected. Consider code review and extensive testing';
        } elseif ($summary->getComplexityScore() > 5.0) {
            $recommendations[] = 'Moderate complexity changes. Test all affected functionality thoroughly';
        }

        // File impact recommendations
        if ($summary->getFileImpactPercentage() > 50) {
            $recommendations[] = 'More than half of extension files are affected. Consider comprehensive testing strategy';
        } elseif ($summary->getFileImpactPercentage() > 25) {
            $recommendations[] = 'Significant portion of files affected. Focus testing on modified components';
        }

        // Top rule-based recommendations
        $topRules = $summary->getTopIssuesByRule(3);
        foreach ($topRules as $rule => $count) {
            if ($count > 5) { // Only mention rules with significant occurrences
                // For individual rules found in findings, we can still provide basic description
                $ruleDescription = $this->getBasicRuleDescription($rule);
                $recommendations[] = \sprintf(
                    'Focus on %s (%d occurrences): %s',
                    $rule,
                    $count,
                    $ruleDescription,
                );
            }
        }

        // Upgrade readiness recommendation
        $readinessScore = $summary->getUpgradeReadinessScore();
        if ($readinessScore < 4.0) {
            $recommendations[] = 'Extension requires significant work before upgrade. Consider alternatives or extensive refactoring';
        } elseif ($readinessScore < 6.0) {
            $recommendations[] = 'Extension needs moderate changes but should be upgradeable with effort';
        } else {
            $recommendations[] = 'Extension is in good shape for upgrade with minimal changes required';
        }

        return $recommendations;
    }

    /**
     * Get basic description for a rule found in analysis results.
     */
    private function getBasicRuleDescription(string $rule): string
    {
        // Simple heuristic-based descriptions for common patterns
        if (str_contains($rule, 'Remove')) {
            return 'Deprecated method or class removal';
        } elseif (str_contains($rule, 'Substitute') || str_contains($rule, 'Replace')) {
            return 'Method or class replacement required';
        } elseif (str_contains($rule, 'Migrate')) {
            return 'Configuration migration needed';
        } elseif (str_contains($rule, 'Annotation')) {
            return 'Annotation changes required';
        } else {
            return 'Code modernization required';
        }
    }
}
