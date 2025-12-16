<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Reporting;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Installation;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\ReportContextBuilder;
use CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\ReportFileManager;
use CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\ReportService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\TemplateRenderer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Basic integration tests for the refactored ReportService.
 *
 * Note: This is a simplified test suite focusing on the service coordination.
 * Comprehensive tests for individual services should be in their own test files.
 */
class ReportServiceTest extends TestCase
{
    private ReportService $subject;
    private \PHPUnit\Framework\MockObject\MockObject $contextBuilder;
    private \PHPUnit\Framework\MockObject\MockObject $templateRenderer;
    private \PHPUnit\Framework\MockObject\MockObject $fileManager;
    private \PHPUnit\Framework\MockObject\MockObject $logger;

    protected function setUp(): void
    {
        $this->contextBuilder = $this->createMock(ReportContextBuilder::class);
        $this->templateRenderer = $this->createMock(TemplateRenderer::class);
        $this->fileManager = $this->createMock(ReportFileManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->subject = new ReportService(
            $this->contextBuilder,
            $this->templateRenderer,
            $this->fileManager,
            $this->logger,
        );
    }

    public function testGenerateReportCoordinatesServices(): void
    {
        // Arrange
        $installation = new Installation('/test/path', new Version('12.0.0'));
        $extensions = [new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local')];
        $results = [];
        $context = ['test' => 'context'];
        $mainReport = ['content' => '# Main Report', 'filename' => 'report.md'];
        $extensionReports = [['content' => '# Extension', 'filename' => 'ext.md', 'extension' => 'test']];
        $files = [['type' => 'main_report', 'path' => '/test/report.md', 'size' => 100]];

        // Mock the service coordination
        $this->contextBuilder->method('buildReportContext')
            ->with($installation, $extensions, self::anything(), null, [], [], [], 960)
            ->willReturn($context);

        $this->templateRenderer->method('renderMainReport')
            ->with($context, 'markdown')
            ->willReturn($mainReport);

        $this->templateRenderer->method('renderExtensionReports')
            ->with($context, 'markdown')
            ->willReturn($extensionReports);

        // Mock rector detail pages rendering (should return empty array for non-rector tests)
        $this->templateRenderer->method('renderRectorFindingsDetailPages')
            ->with($context, 'markdown')
            ->willReturn([]);

        $this->fileManager->method('ensureOutputDirectory')
            ->with('var/reports/', 'markdown')
            ->willReturn('/test/markdown/');

        $this->fileManager->method('writeReportFilesWithRectorPages')
            ->with($mainReport, $extensionReports, [], '/test/markdown/')
            ->willReturn($files);

        // Act
        $results = $this->subject->generateReport(
            $installation,
            $extensions,
            $results,
            ['markdown'],
            'var/reports/',
        );

        // Assert
        self::assertCount(1, $results);
        self::assertSame('report_markdown', $results[0]->getId());
        self::assertSame('markdown', $results[0]->getValue('format'));
        self::assertSame($files, $results[0]->getValue('output_files'));
    }

    public function testGenerateReportHandlesErrors(): void
    {
        // Arrange
        $installation = new Installation('/test/path', new Version('12.0.0'));
        $extensions = [];
        $results = [];

        $this->contextBuilder->method('buildReportContext')
            ->willThrowException(new \RuntimeException('Context building failed'));

        // Act
        $reportResults = $this->subject->generateReport(
            $installation,
            $extensions,
            $results,
            ['markdown'],
        );

        // Assert
        self::assertCount(1, $reportResults);
        $result = $reportResults[0];
        self::assertNotEmpty($result->getError());
        self::assertStringContainsString('Context building failed', $result->getError());
    }
}
