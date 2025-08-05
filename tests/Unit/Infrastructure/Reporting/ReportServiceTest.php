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
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\ExtensionType;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\InstallationMode;
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
    private TwigEnvironment $twig;
    private LoggerInterface $logger;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->twig = $this->createMock(TwigEnvironment::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->subject = new ReportService($this->twig, $this->logger);
        
        // Create temporary directory for test outputs
        $this->tempDir = sys_get_temp_dir() . '/typo3-analyzer-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
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

        $this->twig->expects(self::once())
            ->method('render')
            ->with('detailed-report.md.twig', self::isType('array'))
            ->willReturn('# Test Report');

        $this->logger->expects(self::exactly(2))
            ->method('info');

        $reportResults = $this->subject->generateReport(
            $installation,
            $extensions,
            $results,
            ['markdown'],
            $this->tempDir
        );

        self::assertCount(1, $reportResults);
        self::assertInstanceOf(ReportingResult::class, $reportResults[0]);
        self::assertSame('report_markdown', $reportResults[0]->getId());
        self::assertSame('markdown', $reportResults[0]->getValue('format'));
        self::assertStringEndsWith('.md', $reportResults[0]->getValue('output_path'));
        self::assertFileExists($reportResults[0]->getValue('output_path'));
    }

    public function testGenerateReportWithHtmlFormat(): void
    {
        $installation = new Installation('/test/path', new Version('12.0.0'), 'composer');
        $extensions = [];
        $results = [];

        $this->twig->expects(self::once())
            ->method('render')
            ->with('detailed-report.html.twig', self::isType('array'))
            ->willReturn('<html><body>Test Report</body></html>');

        $reportResults = $this->subject->generateReport(
            $installation,
            $extensions,
            $results,
            ['html'],
            $this->tempDir
        );

        self::assertCount(1, $reportResults);
        self::assertStringEndsWith('.html', $reportResults[0]->getValue('output_path'));
        self::assertFileExists($reportResults[0]->getValue('output_path'));
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
            $this->tempDir
        );

        self::assertCount(1, $reportResults);
        self::assertStringEndsWith('.json', $reportResults[0]->getValue('output_path'));
        self::assertFileExists($reportResults[0]->getValue('output_path'));
        
        $jsonContent = file_get_contents($reportResults[0]->getValue('output_path'));
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
            $this->tempDir
        );

        self::assertCount(3, $reportResults);
        
        $formats = array_map(fn($r) => $r->getValue('format'), $reportResults);
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
                return $context['format'] === 'unsupported' && 
                       str_contains($context['error'], 'Unsupported report format');
            }));

        $reportResults = $this->subject->generateReport(
            $installation,
            $extensions,
            $results,
            ['unsupported'],
            $this->tempDir
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
            $nonExistentDir
        );

        self::assertDirectoryExists($nonExistentDir);
        self::assertFileExists($reportResults[0]->getValue('output_path'));
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

        $this->twig->expects(self::once())
            ->method('render')
            ->with('detailed-report.md.twig', self::callback(function ($context) {
                return isset($context['installation']) &&
                       isset($context['extensions']) &&
                       isset($context['extension_data']) &&
                       isset($context['statistics']) &&
                       count($context['extensions']) === 2 &&
                       count($context['extension_data']) === 2;
            }))
            ->willReturn('# Detailed Report');

        $reportResults = $this->subject->generateReport(
            $installation,
            $extensions,
            $results,
            ['markdown'],
            $this->tempDir,
            '13.4'
        );

        self::assertCount(1, $reportResults);
        self::assertTrue($reportResults[0]->isSuccessful());
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
        $this->twig->expects(self::once())
            ->method('render')
            ->willReturnCallback(function ($template, $context) use (&$capturedContext) {
                $capturedContext = $context;
                return '# Test';
            });

        $this->subject->generateReport(
            $installation,
            $extensions,
            $results,
            ['markdown'],
            $this->tempDir,
            '13.4'
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
                return $context['format'] === 'markdown' && 
                       $context['error'] === 'Template error';
            }));

        $reportResults = $this->subject->generateReport(
            $installation,
            $extensions,
            $results,
            ['markdown'],
            $this->tempDir
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
            $capturedContext = $context;
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
            $this->tempDir
        );

        $expectedSize = strlen($content);
        self::assertSame($expectedSize, $reportResults[0]->getValue('file_size'));
    }
}