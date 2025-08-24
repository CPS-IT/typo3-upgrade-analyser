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
