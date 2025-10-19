<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Reporting;

use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Shared\AnalyzerFindingInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Shared\FindingsSummaryInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment;

/**
 * Generic renderer for analyzer findings detail pages.
 *
 * This service provides unified rendering of detailed findings pages for any analyzer type
 * that implements the generic analyzer interfaces. It uses analyzer-specific templates
 * while maintaining consistent structure and behavior.
 */
class FindingsDetailPageRenderer
{
    public function __construct(
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Render detailed findings pages for a specific analyzer.
     *
     * @param string               $analyzerType The analyzer type (e.g., 'fractor', 'rector')
     * @param array<string, mixed> $context      Template context containing findings and metadata
     * @param string               $format       Output format ('html' or 'markdown')
     *
     * @return array<string, string> Rendered content indexed by page type
     */
    public function renderDetailPages(
        string $analyzerType,
        array $context,
        string $format = 'html',
    ): array {
        if (!\in_array($format, ['html', 'markdown'], true)) {
            throw new \InvalidArgumentException(\sprintf('Format "%s" not supported', $format));
        }

        $renderedPages = [];

        try {
            // Render main detail page using generic template
            $detailPageContent = $this->renderMainDetailPage($analyzerType, $context, $format);
            $renderedPages['detail'] = $detailPageContent;

            $this->logger->info('Successfully rendered analyzer detail pages', [
                'analyzer_type' => $analyzerType,
                'pages_rendered' => \count($renderedPages),
                'findings_count' => $this->getFindingsCount($context),
                'in' => __METHOD__,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to render analyzer detail pages', [
                'analyzer_type' => $analyzerType,
                'error' => $e->getMessage(),
                'context_keys' => array_keys($context),
            ]);

            throw $e;
        }

        return $renderedPages;
    }

    /**
     * Render the main detail page for an analyzer.
     */
    private function renderMainDetailPage(string $analyzerType, array $context, string $format): string
    {
        // Ensure required template variables are available
        $templateContext = $this->prepareTemplateContext($analyzerType, $context);

        // Validate that analyzer-specific partials exist
        $this->validateAnalyzerTemplates($analyzerType, $format);

        $templateFile = $format === 'markdown'
            ? 'md/analyzer-findings-detail.md.twig'
            : 'html/analyzer-findings-detail.html.twig';

        return $this->twig->render($templateFile, $templateContext);
    }

    /**
     * Prepare template context with required variables.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function prepareTemplateContext(string $analyzerType, array $context): array
    {
        $templateContext = $context;

        // Ensure analyzer_type is available
        $templateContext['analyzer_type'] = $analyzerType;

        // Ensure generated_at timestamp is available
        if (!isset($templateContext['generated_at'])) {
            $templateContext['generated_at'] = new \DateTime();
        }

        // Ensure detailed_findings structure exists
        if (!isset($templateContext['detailed_findings'])) {
            $templateContext['detailed_findings'] = [
                'findings' => [],
                'summary' => $this->createEmptySummary(),
                'metadata' => $this->createDefaultMetadata($analyzerType),
            ];
        }

        // Ensure summary has required fields
        $templateContext['detailed_findings']['summary'] = $this->normalizeSummary(
            $templateContext['detailed_findings']['summary'] ?? [],
        );

        return $templateContext;
    }

    /**
     * Validate that required analyzer-specific templates exist.
     */
    private function validateAnalyzerTemplates(string $analyzerType, string $format): void
    {
        $templatePrefix = $format === 'markdown' ? 'md/partials' : 'html/partials';
        $templateExtension = $format === 'markdown' ? 'md.twig' : 'html.twig';

        $requiredPartials = [
            \sprintf('%s/%s-findings/summary-overview.%s', $templatePrefix, $analyzerType, $templateExtension),
            \sprintf('%s/%s-findings/findings-table.%s', $templatePrefix, $analyzerType, $templateExtension),
        ];

        foreach ($requiredPartials as $template) {
            if (!$this->twig->getLoader()->exists($template)) {
                throw new \RuntimeException(\sprintf('Required template "%s" does not exist for analyzer type "%s"', $template, $analyzerType));
            }
        }
    }

    /**
     * Create empty summary structure.
     *
     * @return array<string, mixed>
     */
    private function createEmptySummary(): array
    {
        return [
            'total_findings' => 0,
            'files_scanned' => 0,
            'rules_applied' => 0,
            'successful' => false,
            'has_findings' => false,
            'error_message' => null,
            'severity_distribution' => [],
            'change_type_distribution' => [],
            'top_issues_by_file' => [],
            'top_issues_by_rule' => [],
        ];
    }

    /**
     * Create default metadata structure.
     *
     * @return array<string, mixed>
     */
    private function createDefaultMetadata(string $analyzerType): array
    {
        return [
            'extension_key' => 'unknown',
            'analyzer_type' => $analyzerType,
            'analysis_timestamp' => date('Y-m-d H:i:s'),
            'execution_time' => 0.0,
        ];
    }

    /**
     * Normalize summary data to ensure required fields exist.
     *
     * @param array<string, mixed> $summary
     *
     * @return array<string, mixed>
     */
    private function normalizeSummary(array $summary): array
    {
        $defaults = $this->createEmptySummary();
        $normalized = array_merge($defaults, $summary);

        // Ensure has_findings is computed correctly
        $normalized['has_findings'] = $normalized['total_findings'] > 0
            || $normalized['files_scanned'] > 0
            || $normalized['rules_applied'] > 0;

        return $normalized;
    }

    /**
     * Extract findings count from context for logging.
     */
    private function getFindingsCount(array $context): int
    {
        return \count($context['detailed_findings']['findings'] ?? []);
    }

    /**
     * Check if analyzer supports detailed findings.
     */
    public function supportsAnalyzer(string $analyzerType): bool
    {
        $summaryTemplate = \sprintf('html/partials/%s-findings/summary-overview.html.twig', $analyzerType);
        $tableTemplate = \sprintf('html/partials/%s-findings/findings-table.html.twig', $analyzerType);

        return $this->twig->getLoader()->exists($summaryTemplate)
            && $this->twig->getLoader()->exists($tableTemplate);
    }

    /**
     * Get available analyzer types that have template support.
     *
     * @return array<string>
     */
    public function getSupportedAnalyzers(): array
    {
        $analyzers = ['rector', 'fractor']; // Known analyzer types

        return array_filter($analyzers, fn ($analyzer): bool => $this->supportsAnalyzer($analyzer));
    }

    /**
     * Process findings to add computed fields for template use.
     *
     * @param array<AnalyzerFindingInterface> $findings
     *
     * @return array<array<string, mixed>>
     */
    public function processFindings(array $findings): array
    {
        return array_map(function (AnalyzerFindingInterface $finding): array {
            $findingArray = $finding->toArray();

            // Add computed template-friendly fields
            $findingArray['file_basename'] = basename($finding->getFile());
            $findingArray['has_code_change'] = $finding->hasCodeChange();
            $findingArray['has_documentation'] = $finding->hasDocumentation();

            return $findingArray;
        }, $findings);
    }

    /**
     * Generate summary statistics from findings.
     *
     * @param array<AnalyzerFindingInterface> $findings
     *
     * @return array<string, mixed>
     */
    public function generateSummaryStatistics(array $findings, ?FindingsSummaryInterface $summaryData = null): array
    {
        $summary = $summaryData ? $summaryData->toArray() : $this->createEmptySummary();

        if (!empty($findings)) {
            // Calculate severity distribution
            $severityCount = [];
            $changeTypeCount = [];
            $fileCount = [];
            $ruleCount = [];

            foreach ($findings as $finding) {
                $severity = $finding->getSeverityValue();
                $severityCount[$severity] = ($severityCount[$severity] ?? 0) + 1;

                // Extract change type from array data for generic handling
                $findingData = $finding->toArray();
                if (isset($findingData['change_type'])) {
                    $changeType = $findingData['change_type'];
                    $changeTypeCount[$changeType] = ($changeTypeCount[$changeType] ?? 0) + 1;
                }

                $file = basename($finding->getFile());
                $fileCount[$file] = ($fileCount[$file] ?? 0) + 1;

                $rule = $finding->getRuleName();
                $ruleCount[$rule] = ($ruleCount[$rule] ?? 0) + 1;
            }

            // Sort by count descending and take top 5
            arsort($fileCount);
            arsort($ruleCount);

            $summary['severity_distribution'] = $severityCount;
            $summary['change_type_distribution'] = $changeTypeCount;
            $summary['top_issues_by_file'] = \array_slice($fileCount, 0, 5, true);
            $summary['top_issues_by_rule'] = \array_slice($ruleCount, 0, 5, true);

            // Calculate modernization/readiness score if findings have change types
            if (\count($findings) > 0 && !empty($changeTypeCount)) {
                $summary['modernization_score'] = $this->calculateModernizationScore($findings);
            }
        }

        return $this->normalizeSummary($summary);
    }

    /**
     * Calculate modernization score based on findings.
     *
     * @param array<AnalyzerFindingInterface> $findings
     */
    private function calculateModernizationScore(array $findings): float
    {
        if (empty($findings)) {
            return 0.0;
        }

        $totalPriority = 0.0;
        $maxPossiblePriority = \count($findings) * 10.0; // Assuming max priority is 10

        foreach ($findings as $finding) {
            $totalPriority += $finding->getPriorityScore() * 10; // Convert to 0-10 scale
        }

        return min(10.0, ($totalPriority / $maxPossiblePriority) * 10.0);
    }
}
