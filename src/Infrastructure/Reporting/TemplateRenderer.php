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
     * Render German client report (no links, simplified for clients).
     *
     * @param array<string, mixed> $context Report context data
     *
     * @return array{content: string, filename: string} Rendered content and suggested filename
     */
    public function renderClientReportDe(array $context): array
    {
        return [
            'content' => $this->twig->render('html/client-report-de.html.twig', $context),
            'filename' => 'client-report-de.html',
        ];
    }

    /**
     * Render German client report as PDF.
     *
     * @param array<string, mixed> $context Report context data
     *
     * @return array{content: string, filename: string} Rendered PDF content and filename
     */
    public function renderClientReportDePdf(array $context): array
    {
        // First render the HTML
        $html = $this->twig->render('html/client-report-de.html.twig', $context);

        // Convert to PDF using Dompdf
        $dompdf = new \Dompdf\Dompdf([
            'enable_remote' => false,
            'chroot' => getcwd(),
        ]);

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return [
            'content' => $dompdf->output(),
            'filename' => 'client-report-de.pdf',
        ];
    }

    /**
     * Render Angebot as PDF using client-report config.
     * Filename: Angebot_AN-{offernr}.pdf.
     *
     * @param array<string, mixed> $context Report context data
     *
     * @return array{content: string, filename: string} Rendered PDF content and filename
     */
    public function renderAngebotDePdf(array $context): array
    {
        $projectRoot = \dirname(__DIR__, 3);

        $logoPath = $projectRoot . '/resources/images/cps-logo.png';
        if (file_exists($logoPath)) {
            $context['logo_base64'] = 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoPath));
        }

        $html = $this->twig->render('html/angebot-de.html.twig', $context);

        // Use a project-local font cache dir to avoid polluting vendor/ and to
        // ensure paths are always valid regardless of where the project is deployed.
        $fontCacheDir = $projectRoot . '/var/fonts';
        if (!is_dir($fontCacheDir)) {
            mkdir($fontCacheDir, 0o755, true);
        }

        $dompdf = new \Dompdf\Dompdf([
            'enable_remote' => false,
            'chroot' => $projectRoot,
            'font_dir' => $fontCacheDir,
        ]);

        // Register custom fonts programmatically so they are available by family
        // name in CSS without needing @font-face declarations in the template.
        $fontsPath = $projectRoot . '/resources/fonts';
        $fontMetrics = $dompdf->getFontMetrics();
        foreach ([
            ['family' => 'FSDillon', 'weight' => 'normal', 'style' => 'normal', 'file' => 'FSDillon-Regular.ttf'],
            ['family' => 'FSDillon', 'weight' => '500',    'style' => 'normal', 'file' => 'FSDillon-Medium.ttf'],
            ['family' => 'FSDillon', 'weight' => 'bold',   'style' => 'normal', 'file' => 'FSDillon-Bold.ttf'],
            ['family' => 'Majorant',  'weight' => 'normal', 'style' => 'normal', 'file' => 'Majorant-Lt.ttf'],
            ['family' => 'Majorant',  'weight' => 'bold',   'style' => 'normal', 'file' => 'Majorant-Bd.ttf'],
        ] as $def) {
            $fontMetrics->registerFont(
                ['family' => $def['family'], 'weight' => $def['weight'], 'style' => $def['style']],
                'file://' . $fontsPath . '/' . $def['file'],
            );
        }

        $dompdf->loadHtml($html);
        $dompdf->setBasePath($projectRoot . '/resources/');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $offernr = $context['client_report']['offernr'] ?? 'XXX';

        return [
            'content' => $dompdf->output(),
            'filename' => 'Angebot_AN-' . $offernr . '.pdf',
        ];
    }

    /**
     * Render German client report as XWiki page.
     *
     * @param array<string, mixed> $context Report context data
     *
     * @return array{content: string, filename: string} Rendered XWiki content and filename
     */
    public function renderClientReportDeXWiki(array $context): array
    {
        // Render directly from XWiki template
        $xwiki = $this->twig->render('xwiki/client-report-de.xwiki.twig', $context);

        return [
            'content' => $xwiki,
            'filename' => 'client-report-de.xwiki',
        ];
    }

    /**
     * Convert HTML to XWiki 2.0 syntax.
     *
     * @param string $html HTML content
     *
     * @return string XWiki formatted content
     *
     * @phpstan-ignore method.unused
     */
    private function convertHtmlToXWiki(string $html): string
    {
        // Remove HTML/head/body tags and get content
        $html = (string) preg_replace('/<\?xml[^>]*>/i', '', $html);
        $html = (string) preg_replace('/<!DOCTYPE[^>]*>/i', '', $html);
        $html = (string) preg_replace('/<html[^>]*>(.*?)<\/html>/is', '$1', $html);
        $html = (string) preg_replace('/<head[^>]*>.*?<\/head>/is', '', $html);
        $html = (string) preg_replace('/<body[^>]*>(.*?)<\/body>/is', '$1', $html);

        // Convert headings
        $html = (string) preg_replace('/<h1[^>]*>(.*?)<\/h1>/i', '= $1 =', $html);
        $html = (string) preg_replace('/<h2[^>]*>(.*?)<\/h2>/i', '== $1 ==', $html);
        $html = (string) preg_replace('/<h3[^>]*>(.*?)<\/h3>/i', '=== $1 ===', $html);
        $html = (string) preg_replace('/<h4[^>]*>(.*?)<\/h4>/i', '==== $1 ====', $html);

        // Convert paragraphs
        $html = (string) preg_replace('/<p[^>]*>(.*?)<\/p>/i', "\n$1\n", $html);

        // Convert bold and italic
        $html = (string) preg_replace('/<strong[^>]*>(.*?)<\/strong>/i', '**$1**', $html);
        $html = (string) preg_replace('/<b[^>]*>(.*?)<\/b>/i', '**$1**', $html);
        $html = (string) preg_replace('/<em[^>]*>(.*?)<\/em>/i', '//$1//', $html);
        $html = (string) preg_replace('/<i[^>]*>(.*?)<\/i>/i', '//$1//', $html);

        // Convert tables - this is complex, handle basic structure
        $html = $this->convertTablesToXWiki($html);

        // Convert divs to newlines
        $html = (string) preg_replace('/<div[^>]*>/i', "\n", $html);
        $html = (string) preg_replace('/<\/div>/i', "\n", $html);

        // Remove remaining HTML tags
        $html = strip_tags($html);

        // Clean up multiple newlines
        $html = (string) preg_replace("/\n{3,}/", "\n\n", $html);

        // Trim whitespace
        return trim($html);
    }

    /**
     * Convert HTML tables to XWiki table syntax.
     *
     * @param string $html HTML content with tables
     *
     * @return string HTML with tables converted to XWiki syntax
     */
    private function convertTablesToXWiki(string $html): string
    {
        // Use DOMDocument to parse tables properly
        $dom = new \DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $tables = $dom->getElementsByTagName('table');

        foreach ($tables as $table) {
            $xwikiTable = "\n";

            // Process table rows
            $rows = $table->getElementsByTagName('tr');
            foreach ($rows as $row) {
                $cells = [];

                // Get header cells
                $headerCells = $row->getElementsByTagName('th');
                foreach ($headerCells as $cell) {
                    $cells[] = '|=' . trim($cell->textContent);
                }

                // Get data cells
                $dataCells = $row->getElementsByTagName('td');
                foreach ($dataCells as $cell) {
                    $cells[] = '|' . trim($cell->textContent);
                }

                if (!empty($cells)) {
                    $xwikiTable .= implode('', $cells) . "\n";
                }
            }

            // Replace table with XWiki table
            $tableHtml = $dom->saveHTML($table);
            if (false !== $tableHtml) {
                $html = str_replace($tableHtml, $xwikiTable, $html);
            }
        }

        return $html;
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
