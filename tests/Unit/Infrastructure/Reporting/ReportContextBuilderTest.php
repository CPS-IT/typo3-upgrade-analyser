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

use CPSIT\UpgradeAnalyzer\Domain\Entity\AnalysisResult;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Installation;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\ReportContextBuilder;
use PHPUnit\Framework\TestCase;

class ReportContextBuilderTest extends TestCase
{
    private ReportContextBuilder $subject;

    protected function setUp(): void
    {
        $this->subject = new ReportContextBuilder();
    }

    public function testServiceCanBeInstantiated(): void
    {
        self::assertInstanceOf(ReportContextBuilder::class, $this->subject);
    }

    public function testBuildReportContextCreatesBasicStructure(): void
    {
        // Arrange
        $installation = new Installation('/test/path', new Version('12.0.0'));
        $extensions = [
            new Extension('test_ext1', 'Test Extension 1', new Version('1.0.0'), 'local'),
            new Extension('test_ext2', 'Test Extension 2', new Version('2.0.0'), 'local'),
        ];
        $groupedResults = [
            'discovery' => [],
            'analysis' => [],
            'reporting' => [],
        ];

        // Act
        $context = $this->subject->buildReportContext($installation, $extensions, $groupedResults, '13.0');

        // Assert - Check basic structure
        self::assertArrayHasKey('installation', $context);
        self::assertArrayHasKey('target_version', $context);
        self::assertArrayHasKey('extension_data', $context);
        self::assertArrayHasKey('statistics', $context);
        self::assertArrayHasKey('generated_at', $context);

        // Check installation data
        self::assertSame($installation, $context['installation']);
        self::assertSame('13.0', $context['target_version']);

        // Check extension data structure
        self::assertIsArray($context['extension_data']);
        self::assertCount(2, $context['extension_data']);

        // Check statistics structure
        $stats = $context['statistics'];
        self::assertArrayHasKey('total_extensions', $stats);
        self::assertArrayHasKey('risk_distribution', $stats);
        self::assertArrayHasKey('availability_stats', $stats);
        self::assertArrayHasKey('critical_extensions', $stats);

        // Check generated_at is a DateTimeImmutable object
        self::assertInstanceOf(\DateTimeImmutable::class, $context['generated_at']);
    }

    public function testBuildReportContextWithAnalysisResults(): void
    {
        // Arrange
        $installation = new Installation('/test/path', new Version('12.0.0'));
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $extensions = [$extension];

        // Create a mock analysis result
        $analysisResult = $this->createMock(AnalysisResult::class);
        $analysisResult->method('getExtension')->willReturn($extension);
        $analysisResult->method('getAnalyzerName')->willReturn('version_availability');
        $analysisResult->method('getData')->willReturn([
            'ter_available' => true,
            'packagist_available' => false,
            'git_available' => true,
            'risk_score' => 3.5,
        ]);

        $groupedResults = [
            'discovery' => [],
            'analysis' => [$analysisResult],
            'reporting' => [],
        ];

        // Act
        $context = $this->subject->buildReportContext($installation, $extensions, $groupedResults, '13.0');

        // Assert - Check that analysis results are processed
        self::assertArrayHasKey('extension_data', $context);
        $extensionData = $context['extension_data'][0];

        self::assertArrayHasKey('extension', $extensionData);
        self::assertArrayHasKey('version_analysis', $extensionData);
        self::assertSame($extension, $extensionData['extension']);

        // Check that statistics include analysis data
        $stats = $context['statistics'];
        self::assertSame(1, $stats['total_extensions']);
        self::assertIsArray($stats['availability_stats']);
    }

    public function testCalculateOverallStatisticsWithEmptyExtensions(): void
    {
        // Arrange
        $installation = new Installation('/test/path', new Version('12.0.0'));
        $extensions = [];
        $groupedResults = ['discovery' => [], 'analysis' => [], 'reporting' => []];

        // Act
        $context = $this->subject->buildReportContext($installation, $extensions, $groupedResults);

        // Assert
        $stats = $context['statistics'];
        self::assertSame(0, $stats['total_extensions']);
        self::assertSame(0, $stats['critical_extensions']);

        // Check risk distribution has all levels initialized
        $riskDist = $stats['risk_distribution'];
        self::assertArrayHasKey('low', $riskDist);
        self::assertArrayHasKey('medium', $riskDist);
        self::assertArrayHasKey('high', $riskDist);
        self::assertArrayHasKey('critical', $riskDist);
        self::assertSame(0, $riskDist['low']);
        self::assertSame(0, $riskDist['medium']);
        self::assertSame(0, $riskDist['high']);
        self::assertSame(0, $riskDist['critical']);
    }

    public function testBuildReportContextWithNullTargetVersion(): void
    {
        // Arrange
        $installation = new Installation('/test/path', new Version('12.0.0'));
        $extensions = [];
        $groupedResults = ['discovery' => [], 'analysis' => [], 'reporting' => []];

        // Act
        $context = $this->subject->buildReportContext($installation, $extensions, $groupedResults, null);

        // Assert
        self::assertSame('13.4', $context['target_version']);
    }
}
