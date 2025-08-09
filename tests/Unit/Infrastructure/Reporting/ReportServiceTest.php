<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Reporting;

use CPSIT\UpgradeAnalyzer\Domain\Entity\AnalysisResult;
use CPSIT\UpgradeAnalyzer\Domain\Entity\DiscoveryResult;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Installation;
use CPSIT\UpgradeAnalyzer\Domain\Entity\ReportingResult;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\ReportService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Twig\Environment as TwigEnvironment;

/**
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\ReportService
 */
class ReportServiceTest extends TestCase
{
    private ReportService $subject;
    private \PHPUnit\Framework\MockObject\MockObject $twig;
    private \PHPUnit\Framework\MockObject\MockObject $logger;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->twig = $this->createMock(TwigEnvironment::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->subject = new ReportService($this->twig, $this->logger);

        // Create temporary directory for test outputs
        $this->tempDir = sys_get_temp_dir() . '/typo3-analyzer-test-' . uniqid();
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temporary directory
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testGenerateReportWithMarkdownFormat(): void
    {
        $installation = new Installation('/test/path', new Version('12.0.0'), 'composer');
        $extensions = [new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local')];
        $results = [];

        $this->twig->expects(self::exactly(2))
            ->method('render')
            ->willReturnCallback(function ($template, $context) {
                if ('md/main-report.md.twig' === $template) {
                    return '# Main Report';
                }
                if ('md/extension-detail.md.twig' === $template) {
                    return '# Extension Detail';
                }

                return '';
            });

        $this->logger->expects(self::exactly(2))
            ->method('info');

        $reportResults = $this->subject->generateReport(
            $installation,
            $extensions,
            $results,
            ['markdown'],
            $this->tempDir,
        );

        self::assertCount(1, $reportResults);
        self::assertInstanceOf(ReportingResult::class, $reportResults[0]);
        self::assertSame('report_markdown', $reportResults[0]->getId());
        self::assertSame('markdown', $reportResults[0]->getValue('format'));

        // Test multi-file structure
        $mainReport = $reportResults[0]->getValue('main_report');
        self::assertIsArray($mainReport);
        self::assertStringEndsWith('.md', $mainReport['path']);
        self::assertFileExists($mainReport['path']);

        // Test extension reports count
        self::assertSame(1, $reportResults[0]->getValue('extension_reports_count'));
    }

    public function testGenerateReportWithHtmlFormat(): void
    {
        $installation = new Installation('/test/path', new Version('12.0.0'), 'composer');
        $extensions = [];
        $results = [];

        $this->twig->expects(self::once())
            ->method('render')
            ->with('html/main-report.html.twig', self::isType('array'))
            ->willReturn('<html><body>Test Report</body></html>');

        $reportResults = $this->subject->generateReport(
            $installation,
            $extensions,
            $results,
            ['html'],
            $this->tempDir,
        );

        self::assertCount(1, $reportResults);
        $mainReport = $reportResults[0]->getValue('main_report');
        self::assertIsArray($mainReport);
        self::assertStringEndsWith('.html', $mainReport['path']);
        self::assertFileExists($mainReport['path']);
    }

    public function testGenerateReportWithJsonFormat(): void
    {
        $installation = new Installation('/test/path', new Version('12.0.0'), 'composer');
        $extensions = [];
        $results = [];

        // JSON format doesn't use Twig
        $this->twig->expects(self::never())->method('render');

        $reportResults = $this->subject->generateReport(
            $installation,
            $extensions,
            $results,
            ['json'],
            $this->tempDir,
        );

        self::assertCount(1, $reportResults);
        $mainReport = $reportResults[0]->getValue('main_report');
        self::assertIsArray($mainReport);
        self::assertStringEndsWith('.json', $mainReport['path']);
        self::assertFileExists($mainReport['path']);

        $jsonContent = file_get_contents($mainReport['path']);
        $decoded = json_decode($jsonContent, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('installation', $decoded);
        self::assertArrayHasKey('extensions', $decoded);
    }

    public function testGenerateReportWithMultipleFormats(): void
    {
        $installation = new Installation('/test/path', new Version('12.0.0'), 'composer');
        $extensions = [];
        $results = [];

        $this->twig->expects(self::exactly(2))
            ->method('render')
            ->willReturnCallback(function ($template) {
                return str_contains($template, '.md.') ? '# Markdown' : '<html>HTML</html>';
            });

        $reportResults = $this->subject->generateReport(
            $installation,
            $extensions,
            $results,
            ['markdown', 'html', 'json'],
            $this->tempDir,
        );

        self::assertCount(3, $reportResults);

        $formats = array_map(fn ($r) => $r->getValue('format'), $reportResults);
        self::assertContains('markdown', $formats);
        self::assertContains('html', $formats);
        self::assertContains('json', $formats);
    }

    public function testGenerateReportWithUnsupportedFormat(): void
    {
        $installation = new Installation('/test/path', new Version('12.0.0'), 'composer');
        $extensions = [];
        $results = [];

        $this->logger->expects(self::once())
            ->method('error')
            ->with('Report generation failed', self::callback(function ($context) {
                return 'unsupported' === $context['format']
                       && str_contains($context['error'], 'Unsupported report format');
            }));

        $reportResults = $this->subject->generateReport(
            $installation,
            $extensions,
            $results,
            ['unsupported'],
            $this->tempDir,
        );

        self::assertCount(1, $reportResults);
        self::assertFalse($reportResults[0]->isSuccessful());
        self::assertStringContainsString('Unsupported report format', $reportResults[0]->getError());
    }

    public function testGenerateReportCreatesOutputDirectory(): void
    {
        $installation = new Installation('/test/path', new Version('12.0.0'), 'composer');
        $extensions = [];
        $results = [];

        $nonExistentDir = $this->tempDir . '/subdir/reports';

        $this->twig->method('render')->willReturn('# Test');

        $reportResults = $this->subject->generateReport(
            $installation,
            $extensions,
            $results,
            ['markdown'],
            $nonExistentDir,
        );

        self::assertDirectoryExists($nonExistentDir);
        $mainReport = $reportResults[0]->getValue('main_report');
        self::assertFileExists($mainReport['path']);
    }

    public function testGenerateReportWithExtensionsAndResults(): void
    {
        $installation = new Installation('/test/path', new Version('12.0.0'), 'composer');

        $extension1 = new Extension('ext1', 'Extension 1', new Version('1.0.0'), 'local');
        $extension2 = new Extension('ext2', 'Extension 2', new Version('2.0.0'), 'third_party');
        $extensions = [$extension1, $extension2];

        // Create various result types
        $discoveryResult = new DiscoveryResult('discovery', 'installation_discovery', 'Installation Discovery');
        $analysisResult1 = new AnalysisResult('version_availability', $extension1);
        $analysisResult1->addMetric('ter_available', true);
        $analysisResult1->addMetric('packagist_available', false);
        $analysisResult1->setRiskScore(3.5);

        $analysisResult2 = new AnalysisResult('lines_of_code', $extension1);
        $analysisResult2->addMetric('total_lines', 1500);
        $analysisResult2->addMetric('php_files', 12);
        $analysisResult2->setRiskScore(2.0);

        $results = [$discoveryResult, $analysisResult1, $analysisResult2];

        $this->twig->expects(self::exactly(3))
            ->method('render')
            ->willReturnCallback(function ($template, $context) {
                if ('md/main-report.md.twig' === $template) {
                    self::assertCount(2, $context['extensions']);
                    self::assertCount(2, $context['extension_data']);

                    return '# Main Report';
                }
                if ('md/extension-detail.md.twig' === $template) {
                    return '# Extension Detail';
                }

                return '';
            });

        $reportResults = $this->subject->generateReport(
            $installation,
            $extensions,
            $results,
            ['markdown'],
            $this->tempDir,
            '13.4',
        );

        self::assertCount(1, $reportResults);
        self::assertTrue($reportResults[0]->isSuccessful());
        self::assertSame(2, $reportResults[0]->getValue('extension_reports_count'));
    }

    public function testGenerateReportBuildsCorrectContext(): void
    {
        $installation = new Installation('/test/path', new Version('12.0.0'), 'composer');
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $extensions = [$extension];

        $analysisResult = new AnalysisResult('version_availability', $extension);
        $analysisResult->addMetric('ter_available', true);
        $analysisResult->addMetric('git_available', false);
        $analysisResult->setRiskScore(4.0);
        $analysisResult->addRecommendation('Test recommendation');

        $results = [$analysisResult];

        $capturedContext = null;
        $this->twig->expects(self::exactly(2))
            ->method('render')
            ->willReturnCallback(function ($template, $context) use (&$capturedContext) {
                if ('md/main-report.md.twig' === $template) {
                    $capturedContext = $context;
                }

                return '# Test';
            });

        $this->subject->generateReport(
            $installation,
            $extensions,
            $results,
            ['markdown'],
            $this->tempDir,
            '13.4',
        );

        self::assertNotNull($capturedContext);
        self::assertSame($installation, $capturedContext['installation']);
        self::assertSame($extensions, $capturedContext['extensions']);
        self::assertSame('13.4', $capturedContext['target_version']);
        self::assertInstanceOf(\DateTimeImmutable::class, $capturedContext['generated_at']);

        // Check extension data structure
        self::assertCount(1, $capturedContext['extension_data']);
        $extensionData = $capturedContext['extension_data'][0];
        self::assertSame($extension, $extensionData['extension']);
        self::assertIsArray($extensionData['results']);
        self::assertIsArray($extensionData['version_analysis']);
        self::assertIsArray($extensionData['risk_summary']);

        // Check version analysis
        $versionAnalysis = $extensionData['version_analysis'];
        self::assertTrue($versionAnalysis['ter_available']);
        self::assertFalse($versionAnalysis['git_available']);
        self::assertSame(4.0, $versionAnalysis['risk_score']);
        self::assertContains('Test recommendation', $versionAnalysis['recommendations']);

        // Check statistics
        self::assertIsArray($capturedContext['statistics']);
        self::assertArrayHasKey('total_extensions', $capturedContext['statistics']);
        self::assertArrayHasKey('risk_distribution', $capturedContext['statistics']);
        self::assertArrayHasKey('availability_stats', $capturedContext['statistics']);
    }

    public function testGenerateReportHandlesTwigException(): void
    {
        $installation = new Installation('/test/path', new Version('12.0.0'), 'composer');
        $extensions = [];
        $results = [];

        $this->twig->expects(self::once())
            ->method('render')
            ->willThrowException(new \RuntimeException('Template error'));

        $this->logger->expects(self::once())
            ->method('error')
            ->with('Report generation failed', self::callback(function ($context) {
                return 'markdown' === $context['format']
                       && 'Template error' === $context['error'];
            }));

        $reportResults = $this->subject->generateReport(
            $installation,
            $extensions,
            $results,
            ['markdown'],
            $this->tempDir,
        );

        self::assertCount(1, $reportResults);
        self::assertFalse($reportResults[0]->isSuccessful());
        self::assertSame('Template error', $reportResults[0]->getError());
    }

    public function testGenerateReportCalculatesStatisticsCorrectly(): void
    {
        $installation = new Installation('/test/path', new Version('12.0.0'), 'composer');

        $ext1 = new Extension('low_risk', 'Low Risk Extension', new Version('1.0.0'), 'local');
        $ext2 = new Extension('high_risk', 'High Risk Extension', new Version('1.0.0'), 'local');
        $extensions = [$ext1, $ext2];

        // Low risk extension
        $result1 = new AnalysisResult('version_availability', $ext1);
        $result1->addMetric('ter_available', true);
        $result1->addMetric('packagist_available', true);
        $result1->setRiskScore(1.5); // Low risk

        // High risk extension
        $result2 = new AnalysisResult('version_availability', $ext2);
        $result2->addMetric('ter_available', false);
        $result2->addMetric('packagist_available', false);
        $result2->addMetric('git_available', false);
        $result2->setRiskScore(8.5); // High risk

        $results = [$result1, $result2];

        $capturedContext = null;
        $this->twig->method('render')->willReturnCallback(function ($template, $context) use (&$capturedContext) {
            if ('md/main-report.md.twig' === $template) {
                $capturedContext = $context;
            }

            return '# Test';
        });

        $this->subject->generateReport($installation, $extensions, $results, ['markdown'], $this->tempDir);

        $stats = $capturedContext['statistics'];
        self::assertSame(2, $stats['total_extensions']);
        self::assertSame(1, $stats['risk_distribution']['low']);
        self::assertSame(1, $stats['risk_distribution']['critical']);
        self::assertSame(1, $stats['availability_stats']['ter_available']);
        self::assertSame(1, $stats['availability_stats']['no_availability']);
        self::assertSame(1, $stats['critical_extensions']); // high + critical
    }

    public function testGenerateReportWithDefaultTargetVersion(): void
    {
        $installation = new Installation('/test/path', new Version('12.0.0'), 'composer');
        $extensions = [];
        $results = [];

        $capturedContext = null;
        $this->twig->method('render')->willReturnCallback(function ($template, $context) use (&$capturedContext) {
            $capturedContext = $context;

            return '# Test';
        });

        $this->subject->generateReport($installation, $extensions, $results, ['markdown'], $this->tempDir);

        self::assertSame('13.4', $capturedContext['target_version']);
    }

    public function testGenerateReportSetsCorrectFileSize(): void
    {
        $installation = new Installation('/test/path', new Version('12.0.0'), 'composer');
        $extensions = [];
        $results = [];

        $content = '# Test Report Content';
        $this->twig->method('render')->willReturn($content);

        $reportResults = $this->subject->generateReport(
            $installation,
            $extensions,
            $results,
            ['markdown'],
            $this->tempDir,
        );

        $expectedSize = \strlen($content);
        $mainReport = $reportResults[0]->getValue('main_report');
        self::assertSame($expectedSize, $mainReport['size']);
    }
}
