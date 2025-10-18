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

use Psr\Log\LoggerInterface;
use Twig\Environment as TwigEnvironment;

/**
 * Service for rendering report content in various formats.
 *
 * This service handles format-specific content generation using Twig templates
 * and JSON encoding. It focuses solely on content rendering without file I/O.
 */
class TemplateRenderer
{
    public function __construct(
        private readonly TwigEnvironment $twig,
        private readonly FindingsDetailPageRenderer $findingsDetailPageRenderer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Render main report content in the specified format.
     *
     * @param array<string, mixed> $context Report context data
     * @param string               $format  Output format (markdown, html, json)
     *
     * @return array{content: string, filename: string} Rendered content and suggested filename
     */
    public function renderMainReport(array $context, string $format): array
    {
        return match ($format) {
            'markdown' => [
                'content' => $this->twig->render('md/main-report.md.twig', $context),
                'filename' => 'analysis-report.md',
            ],
            'html' => [
                'content' => $this->twig->render('html/main-report.html.twig', $context),
                'filename' => 'analysis-report.html',
            ],
            'json' => [
                'content' => $this->renderMainReportJson($context),
                'filename' => 'analysis-report.json',
            ],
            default => throw new \InvalidArgumentException("Unsupported report format: {$format}"),
        };
    }

    /**
     * Render extension reports for all extensions in the specified format.
     *
     * @param array<string, mixed> $context Report context data
     * @param string               $format  Output format (markdown, html, json)
     *
     * @return array<int, array{content: string, filename: string, extension: string}> Array of rendered extension reports
     */
    public function renderExtensionReports(array $context, string $format): array
    {
        $reports = [];

        foreach ($context['extension_data'] as $extensionData) {
            $extensionKey = $extensionData['extension']->getKey();

            // Create context for individual extension
            $extensionContext = [
                'installation' => $context['installation'],
                'target_version' => $context['target_version'],
                'extension' => $extensionData['extension'],
                'extension_data' => $extensionData,
                'generated_at' => $context['generated_at'],
            ];

            $renderedReport = $this->renderSingleExtensionReport($extensionContext, $format, $extensionKey);
            if (null !== $renderedReport) {
                $reports[] = $renderedReport;
            }
        }

        return $reports;
    }

    /**
     * Render a single extension report.
     *
     * @param array<string, mixed> $extensionContext Extension-specific context
     * @param string               $format           Output format
     * @param string               $extensionKey     Extension key for filename
     *
     * @return array{content: string, filename: string, extension: string}|null Rendered report or null for unsupported formats
     */
    private function renderSingleExtensionReport(array $extensionContext, string $format, string $extensionKey): ?array
    {
        return match ($format) {
            'markdown' => [
                'content' => $this->twig->render('md/extension-detail.md.twig', $extensionContext),
                'filename' => $extensionKey . '.md',
                'extension' => $extensionKey,
            ],
            'html' => [
                'content' => $this->twig->render('html/extension-detail.html.twig', $extensionContext),
                'filename' => $extensionKey . '.html',
                'extension' => $extensionKey,
            ],
            'json' => [
                'content' => json_encode($extensionContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
                'filename' => $extensionKey . '.json',
                'extension' => $extensionKey,
            ],
            default => null, // Skip unsupported formats
        };
    }

    /**
     * Render Rector findings detail pages for extensions with detailed findings.
     * Only generates pages for HTML/Markdown formats where detailed_findings exist.
     *
     * @param array<string, mixed> $context Report context data from ReportContextBuilder
     * @param string               $format  Output format (html, markdown)
     *
     * @return array<int, array{content: string, filename: string, extension: string}> Rendered Rector detail pages
     */
    public function renderRectorFindingsDetailPages(array $context, string $format): array
    {
        // Skip JSON format - detailed findings already included in extension JSON
        if ('json' === $format) {
            return [];
        }

        $detailPages = [];

        foreach ($context['extension_data'] as $extensionData) {
            $rectorAnalysis = $extensionData['rector_analysis'];

            // Only create detail pages for extensions with detailed findings
            if ($rectorAnalysis && !empty($rectorAnalysis['detailed_findings'])) {
                $extensionKey = $extensionData['extension']->getKey();

                $findingsContext = [
                    'extension_key' => $extensionKey,
                    'extension' => $extensionData['extension'],
                    'detailed_findings' => $rectorAnalysis['detailed_findings'],
                    'rector_analysis' => $rectorAnalysis,
                    'generated_at' => $context['generated_at'],
                ];

                $renderedPage = $this->renderSingleRectorFindingsDetailPage($findingsContext, $format, $extensionKey);
                if (null !== $renderedPage) {
                    $detailPages[] = $renderedPage;
                }
            }
        }

        return $detailPages;
    }

    /**
     * Render a single Rector findings detail page.
     *
     * @param array<string, mixed> $findingsContext Findings-specific context
     * @param string               $format          Output format
     * @param string               $extensionKey    Extension key for filename
     *
     * @return array{content: string, filename: string, extension: string}|null Rendered page or null for unsupported formats
     */
    private function renderSingleRectorFindingsDetailPage(array $findingsContext, string $format, string $extensionKey): ?array
    {
        return match ($format) {
            'markdown' => [
                'content' => $this->twig->render('md/rector-findings-detail.md.twig', $findingsContext),
                'filename' => $extensionKey . '.md',
                'extension' => $extensionKey,
            ],
            'html' => [
                'content' => $this->twig->render('html/rector-findings-detail.html.twig', $findingsContext),
                'filename' => $extensionKey . '.html',
                'extension' => $extensionKey,
            ],
            default => null, // Skip unsupported formats
        };
    }

    /**
     * Render analyzer findings detail pages using generic renderer.
     * Works for any analyzer type that has detailed findings (fractor, rector, etc.).
     *
     * @param array<string, mixed> $context      Report context data from ReportContextBuilder
     * @param string               $analyzerType Analyzer type (e.g., 'fractor', 'rector')
     * @param string               $format       Output format (html, markdown)
     *
     * @return array<int, array{content: string, filename: string, extension: string}> Rendered detail pages
     */
    public function renderAnalyzerFindingsDetailPages(array $context, string $analyzerType, string $format): array
    {
        // Skip JSON format - detailed findings already included in extension JSON
        if ('json' === $format) {
            return [];
        }

        // Check if the findings renderer supports this analyzer
        if (!$this->findingsDetailPageRenderer->supportsAnalyzer($analyzerType)) {
            $this->logger->warning('Analyzer not supported for detailed findings', [
                'analyzer_type' => $analyzerType,
                'supported_analyzers' => $this->findingsDetailPageRenderer->getSupportedAnalyzers(),
            ]);

            return [];
        }

        $detailPages = [];
        $detailedFindingsKey = $analyzerType . '_detailed_findings';

        foreach ($context['extension_data'] as $extensionData) {
            // Check if this extension has detailed findings for the specified analyzer
            if (!isset($extensionData[$detailedFindingsKey]) || empty($extensionData[$detailedFindingsKey])) {
                continue;
            }

            $extensionKey = $extensionData['extension']->getKey();
            $detailedFindings = $extensionData[$detailedFindingsKey];

            // Skip if no actual findings data
            if (empty($detailedFindings['findings']) && empty($detailedFindings['summary'])) {
                continue;
            }

            $findingsContext = [
                'extension_key' => $extensionKey,
                'extension' => $extensionData['extension'],
                'detailed_findings' => $detailedFindings,
                'analyzer_type' => $analyzerType,
                'generated_at' => $context['generated_at'],
            ];

            try {
                $renderedPages = $this->findingsDetailPageRenderer->renderDetailPages(
                    $analyzerType,
                    $findingsContext,
                    $format,
                );

                foreach ($renderedPages as $pageType => $content) {
                    $filename = match ($pageType) {
                        'detail' => $extensionKey . '-' . $analyzerType . '-findings.' . $format,
                        default => $extensionKey . '-' . $analyzerType . '-' . $pageType . '.' . $format,
                    };

                    $detailPages[] = [
                        'content' => $content,
                        'filename' => $filename,
                        'extension' => $extensionKey,
                        'analyzer_type' => $analyzerType,
                        'page_type' => $pageType,
                    ];
                }
            } catch (\Exception $e) {
                $this->logger->error('Failed to render analyzer findings detail page', [
                    'extension_key' => $extensionKey,
                    'analyzer_type' => $analyzerType,
                    'format' => $format,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $detailPages;
    }

    /**
     * Render all analyzer findings detail pages for all supported analyzers.
     * This is a convenience method that renders detailed pages for all analyzers.
     *
     * @param array<string, mixed> $context Report context data
     * @param string               $format  Output format (html, markdown)
     *
     * @return array<int, array{content: string, filename: string, extension: string, analyzer_type?: string, page_type?: string}> All rendered detail pages
     */
    public function renderAllAnalyzerFindingsDetailPages(array $context, string $format): array
    {
        $allDetailPages = [];

        foreach ($this->findingsDetailPageRenderer->getSupportedAnalyzers() as $analyzerType) {
            $analyzerPages = $this->renderAnalyzerFindingsDetailPages($context, $analyzerType, $format);
            $allDetailPages = array_merge($allDetailPages, $analyzerPages);
        }

        return $allDetailPages;
    }

    /**
     * Render main report as JSON, excluding extension details to avoid duplication.
     *
     * @param array<string, mixed> $context Original context
     *
     * @return string JSON-encoded content
     */
    private function renderMainReportJson(array $context): string
    {
        $contextCopy = $context;
        // Remove extension details from main JSON to avoid duplication
        unset($contextCopy['extension_data']);

        return json_encode($contextCopy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
