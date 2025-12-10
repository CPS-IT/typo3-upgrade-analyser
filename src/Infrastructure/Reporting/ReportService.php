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

use CPSIT\UpgradeAnalyzer\Domain\Contract\ResultInterface;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Installation;
use CPSIT\UpgradeAnalyzer\Domain\Entity\ReportingResult;
use Psr\Log\LoggerInterface;

/**
 * Service for generating detailed reports from discovery and analysis results.
 *
 * This service orchestrates the report generation process by coordinating three
 * specialized services: ReportContextBuilder, TemplateRenderer, and ReportFileManager.
 */
class ReportService
{
    public function __construct(
        private readonly ReportContextBuilder $contextBuilder,
        private readonly TemplateRenderer $templateRenderer,
        private readonly ReportFileManager $fileManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Generate comprehensive report from all phases.
     *
     * @param array<Extension>       $extensions
     * @param array<ResultInterface> $results
     * @param array<string>          $formats
     * @param array<string>          $extensionAvailableInTargetVersion
     *
     * @return array<ReportingResult>
     */
    public function generateReport(
        Installation $installation,
        array $extensions,
        array $results,
        array $formats = ['markdown'],
        string $outputDirectory = 'var/reports/',
        ?string $targetVersion = null,
        array $extensionAvailableInTargetVersion = [],
    ): array {
        $this->logger->info('Starting report generation', [
            'extensions_count' => \count($extensions),
            'results_count' => \count($results),
            'formats' => $formats,
        ]);

        $reportResults = [];

        // Group results by type
        $this->logger->debug('Grouping analysis results by type', ['result_count' => \count($results)]);
        $groupedResults = $this->groupResultsByType($results);

        foreach ($formats as $format) {
            try {
                // Generate context for templates using ReportContextBuilder
                $this->logger->debug('Building report context for templates');
                $context = $this->contextBuilder->buildReportContext($installation, $extensions, $groupedResults, $targetVersion, $extensionAvailableInTargetVersion);
                $this->logger->debug('Report context built successfully');

                $this->logger->debug('Generating report for format', ['format' => $format]);
                $reportResult = $this->generateFormatReport($format, $context, $outputDirectory);
                $reportResults[] = $reportResult;
                $this->logger->debug(
                    'Successfully generated report for format',
                    ['format' => $format],
                );

                $this->logger->info('Report generated successfully', [
                    'format' => $format,
                    'main_report' => $reportResult->getValue('main_report')['path'] ?? null,
                    'extension_reports' => $reportResult->getValue('extension_reports_count'),
                ]);
            } catch (\Throwable $e) {
                $errorResult = new ReportingResult(
                    "report_{$format}",
                    "Report generation ({$format})",
                );
                $errorResult->setError($e->getMessage());
                $reportResults[] = $errorResult;

                $this->logger->error('Report generation failed', [
                    'format' => $format,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $reportResults;
    }

    /**
     * Group analysis results by type for easier processing.
     *
     * @param array<ResultInterface> $results
     *
     * @return array<string, array<ResultInterface>>
     */
    private function groupResultsByType(array $results): array
    {
        $grouped = [
            'discovery' => [],
            'analysis' => [],
            'reporting' => [],
        ];

        foreach ($results as $result) {
            $grouped[$result->getType()][] = $result;
        }

        return $grouped;
    }

    /**
     * Generate report for a specific format using the specialized services.
     *
     * @param string               $format          Output format
     * @param array<string, mixed> $context         Report context data
     * @param string               $outputDirectory Base output directory
     *
     * @return ReportingResult Report generation result
     */
    private function generateFormatReport(string $format, array $context, string $outputDirectory): ReportingResult
    {
        // 1. Ensure output directory structure exists
        $formatOutputPath = $this->fileManager->ensureOutputDirectory($outputDirectory, $format);

        // 2. Render content using TemplateRenderer
        $this->logger->debug('Rendering main report', ['format' => $format]);
        $mainReport = $this->templateRenderer->renderMainReport($context, $format);

        // Render German client report in multiple formats (only for HTML format)
        $clientReportDe = null;
        $clientReportDePdf = null;
        $clientReportDeXWiki = null;
        if ('html' === $format) {
            $this->logger->debug('Rendering German client report (HTML)');
            $clientReportDe = $this->templateRenderer->renderClientReportDe($context);

            $this->logger->debug('Rendering German client report (PDF)');
            $clientReportDePdf = $this->templateRenderer->renderClientReportDePdf($context);

            $this->logger->debug('Rendering German client report (XWiki)');
            $clientReportDeXWiki = $this->templateRenderer->renderClientReportDeXWiki($context);
        }

        $this->logger->debug('Rendering extension reports', ['format' => $format]);
        $extensionReports = $this->templateRenderer->renderExtensionReports($context, $format);

        // Render Rector findings detail pages for HTML/Markdown formats
        $this->logger->debug('Rendering Rector findings detail pages', ['format' => $format]);
        $rectorDetailPages = $this->templateRenderer->renderRectorFindingsDetailPages($context, $format);

        // 3. Write files using ReportFileManager
        $this->logger->debug('Writing report files', [
            'format' => $format,
            'extension_reports_count' => \count($extensionReports),
            'rector_detail_pages_count' => \count($rectorDetailPages),
            'has_client_report_de' => null !== $clientReportDe,
        ]);
        $allFiles = $this->fileManager->writeReportFilesWithRectorPages($mainReport, $extensionReports, $rectorDetailPages, $formatOutputPath);

        // Write German client reports if rendered (HTML, PDF, XWiki)
        if (null !== $clientReportDe) {
            $clientReportPath = $formatOutputPath . '/' . $clientReportDe['filename'];
            file_put_contents($clientReportPath, $clientReportDe['content']);
            $allFiles[] = [
                'path' => $clientReportPath,
                'size' => \strlen($clientReportDe['content']),
                'type' => 'client_report_de_html',
            ];
            $this->logger->info('German client report (HTML) written', ['path' => $clientReportPath]);
        }

        if (null !== $clientReportDePdf) {
            $clientReportPdfPath = $formatOutputPath . '/' . $clientReportDePdf['filename'];
            file_put_contents($clientReportPdfPath, $clientReportDePdf['content']);
            $allFiles[] = [
                'path' => $clientReportPdfPath,
                'size' => \strlen($clientReportDePdf['content']),
                'type' => 'client_report_de_pdf',
            ];
            $this->logger->info('German client report (PDF) written', ['path' => $clientReportPdfPath]);
        }

        if (null !== $clientReportDeXWiki) {
            $clientReportXWikiPath = $formatOutputPath . '/' . $clientReportDeXWiki['filename'];
            file_put_contents($clientReportXWikiPath, $clientReportDeXWiki['content']);
            $allFiles[] = [
                'path' => $clientReportXWikiPath,
                'size' => \strlen($clientReportDeXWiki['content']),
                'type' => 'client_report_de_xwiki',
            ];
            $this->logger->info('German client report (XWiki) written', ['path' => $clientReportXWikiPath]);
        }

        // 4. Create result object
        $result = new ReportingResult(
            "report_{$format}",
            "Detailed report ({$format})",
        );

        $result->setValue('format', $format);
        $result->setValue('output_files', $allFiles);
        $result->setValue('main_report', $allFiles[0] ?? null);
        $result->setValue('extension_reports_count', \count($extensionReports));

        $this->logger->debug('Report generation completed', [
            'format' => $format,
            'files_generated' => \count($allFiles),
        ]);

        return $result;
    }
}
