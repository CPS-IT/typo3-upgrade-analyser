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
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorFinding;
use CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\ReportContextBuilder;
use PHPUnit\Framework\TestCase;

class ReportContextBuilderTest extends TestCase
{
    private ReportContextBuilder $subject;

    protected function setUp(): void
    {
        $this->subject = new ReportContextBuilder();
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

    public function testExtractRectorAnalysisWithoutRawData(): void
    {
        // Arrange
        $installation = new Installation('/test/path', new Version('12.0.0'));
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $extensions = [$extension];

        // Create Rector analysis result without raw data
        $rectorResult = $this->createMock(AnalysisResult::class);
        $rectorResult->method('getExtension')->willReturn($extension);
        $rectorResult->method('getAnalyzerName')->willReturn('typo3_rector');
        $rectorResult->method('getMetric')->willReturnMap([
            ['total_findings', 5],
            ['affected_files', 3],
            ['total_files', 10],
            ['processed_files', 8],
            ['execution_time', 1.5],
            ['findings_by_severity', ['critical' => 2, 'warning' => 3]],
            ['findings_by_type', ['deprecation' => 3, 'breaking' => 2]],
            ['top_affected_files', ['file1.php', 'file2.php']],
            ['top_rules_triggered', ['Rule1', 'Rule2']],
            ['estimated_fix_time', 300],
            ['estimated_fix_time_hours', 0.083],
            ['complexity_score', 6.5],
            ['upgrade_readiness_score', 7.2],
            ['has_breaking_changes', true],
            ['has_deprecations', true],
            ['file_impact_percentage', 30.0],
            ['summary_text', 'Test summary'],
            ['rector_version', '0.18.0'],
            ['raw_findings', null], // No raw findings
            ['raw_summary', null], // No raw summary
        ]);
        $rectorResult->method('getRiskScore')->willReturn(6.8);
        $rectorResult->method('getRecommendations')->willReturn(['Fix deprecations']);

        $groupedResults = [
            'discovery' => [],
            'analysis' => [$rectorResult],
            'reporting' => [],
        ];

        // Act
        $context = $this->subject->buildReportContext($installation, $extensions, $groupedResults, '13.0');

        // Assert - Check basic rector analysis structure
        self::assertArrayHasKey('extension_data', $context);
        $extensionData = $context['extension_data'][0];
        self::assertArrayHasKey('rector_analysis', $extensionData);

        $rectorAnalysis = $extensionData['rector_analysis'];
        self::assertSame(5, $rectorAnalysis['total_findings']);
        self::assertSame(3, $rectorAnalysis['affected_files']);
        self::assertSame('Test summary', $rectorAnalysis['summary_text']);
        self::assertSame(6.8, $rectorAnalysis['risk_score']);

        // Assert - detailed_findings should NOT be present when raw data is null
        self::assertArrayNotHasKey('detailed_findings', $rectorAnalysis);
    }

    public function testExtractRectorAnalysisWithRawData(): void
    {
        // Arrange
        $installation = new Installation('/test/path', new Version('12.0.0'));
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $extensions = [$extension];

        // Create mock raw findings and summary
        $rawFinding1 = $this->createMock(RectorFinding::class);
        $rawFinding1->method('toArray')->willReturn([
            'file' => 'Classes/TestClass.php',
            'line' => 25,
            'rule_class' => 'TestRule',
            'message' => 'Test finding message',
            'severity' => 'critical',
            'change_type' => 'deprecation',
        ]);

        $rawFinding2 = $this->createMock(RectorFinding::class);
        $rawFinding2->method('toArray')->willReturn([
            'file' => 'Classes/AnotherClass.php',
            'line' => 15,
            'rule_class' => 'AnotherRule',
            'message' => 'Another finding message',
            'severity' => 'warning',
            'change_type' => 'breaking',
        ]);

        // Convert to arrays as done by the real analyzers when storing metrics
        $rawSummaryArray = [
            'total_findings' => 2,
            'critical_issues' => 1,
            'warnings' => 1,
            'info_issues' => 0,
            'suggestions' => 0,
            'affected_files' => 2,
            'total_files' => 5,
            'rule_breakdown' => ['TestRule' => 1, 'AnotherRule' => 1],
            'file_breakdown' => ['Classes/TestClass.php' => 1, 'Classes/AnotherClass.php' => 1],
            'type_breakdown' => ['deprecation' => 1, 'breaking' => 1],
        ];

        $rawFindingsArray = [
            [
                'file' => 'Classes/TestClass.php',
                'line' => 25,
                'rule_class' => 'TestRule',
                'message' => 'Test finding message',
                'severity' => 'critical',
                'change_type' => 'deprecation',
            ],
            [
                'file' => 'Classes/AnotherClass.php',
                'line' => 15,
                'rule_class' => 'AnotherRule',
                'message' => 'Another finding message',
                'severity' => 'warning',
                'change_type' => 'breaking',
            ],
        ];

        // Create Rector analysis result with raw data
        $rectorResult = $this->createMock(AnalysisResult::class);
        $rectorResult->method('getExtension')->willReturn($extension);
        $rectorResult->method('getAnalyzerName')->willReturn('typo3_rector');
        $rectorResult->method('getMetric')->willReturnMap([
            ['total_findings', 2],
            ['affected_files', 2],
            ['total_files', 5],
            ['processed_files', 5],
            ['execution_time', 1.2],
            ['findings_by_severity', ['critical' => 1, 'warning' => 1]],
            ['findings_by_type', ['deprecation' => 1, 'breaking' => 1]],
            ['top_affected_files', ['Classes/TestClass.php', 'Classes/AnotherClass.php']],
            ['top_rules_triggered', ['TestRule', 'AnotherRule']],
            ['estimated_fix_time', 150],
            ['estimated_fix_time_hours', 0.042],
            ['complexity_score', 4.5],
            ['upgrade_readiness_score', 8.5],
            ['has_breaking_changes', true],
            ['has_deprecations', true],
            ['file_impact_percentage', 40.0],
            ['summary_text', 'Found critical issues'],
            ['rector_version', '0.18.0'],
            ['raw_findings', $rawFindingsArray], // Raw findings present
            ['raw_summary', $rawSummaryArray], // Raw summary present
        ]);
        $rectorResult->method('getRiskScore')->willReturn(7.5);
        $rectorResult->method('getRecommendations')->willReturn(['Address critical issues', 'Fix deprecations']);

        $groupedResults = [
            'discovery' => [],
            'analysis' => [$rectorResult],
            'reporting' => [],
        ];

        // Act
        $context = $this->subject->buildReportContext($installation, $extensions, $groupedResults, '13.0');

        // Assert - Check basic rector analysis structure
        self::assertArrayHasKey('extension_data', $context);
        $extensionData = $context['extension_data'][0];
        self::assertArrayHasKey('rector_analysis', $extensionData);

        $rectorAnalysis = $extensionData['rector_analysis'];
        self::assertSame(2, $rectorAnalysis['total_findings']);
        self::assertSame(2, $rectorAnalysis['affected_files']);
        self::assertSame('Found critical issues', $rectorAnalysis['summary_text']);
        self::assertSame(7.5, $rectorAnalysis['risk_score']);

        // Assert - detailed_findings should be present when raw data exists
        self::assertArrayHasKey('detailed_findings', $rectorAnalysis);

        $detailedFindings = $rectorAnalysis['detailed_findings'];

        // Assert - Check detailed_findings structure
        self::assertArrayHasKey('metadata', $detailedFindings);
        self::assertArrayHasKey('summary', $detailedFindings);
        self::assertArrayHasKey('findings', $detailedFindings);

        // Assert - Check metadata structure
        $metadata = $detailedFindings['metadata'];
        self::assertArrayHasKey('extension_key', $metadata);
        self::assertArrayHasKey('analysis_timestamp', $metadata);
        self::assertArrayHasKey('rector_version', $metadata);
        self::assertArrayHasKey('execution_time', $metadata);
        self::assertSame('test_ext', $metadata['extension_key']);
        self::assertSame('0.18.0', $metadata['rector_version']);
        self::assertSame(1.2, $metadata['execution_time']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $metadata['analysis_timestamp']);

        // Assert - Check summary structure (should match rawSummary->toArray())
        $summary = $detailedFindings['summary'];
        self::assertSame(2, $summary['total_findings']);
        self::assertSame(1, $summary['critical_issues']);
        self::assertSame(1, $summary['warnings']);
        self::assertSame(2, $summary['affected_files']);
        self::assertArrayHasKey('rule_breakdown', $summary);
        self::assertArrayHasKey('file_breakdown', $summary);
        self::assertArrayHasKey('type_breakdown', $summary);

        // Assert - Check findings array (should match rawFindings mapped through toArray())
        $findings = $detailedFindings['findings'];
        self::assertIsArray($findings);
        self::assertCount(2, $findings);

        $firstFinding = $findings[0];
        self::assertSame('Classes/TestClass.php', $firstFinding['file']);
        self::assertSame(25, $firstFinding['line']);
        self::assertSame('TestRule', $firstFinding['rule_class']);
        self::assertSame('Test finding message', $firstFinding['message']);
        self::assertSame('critical', $firstFinding['severity']);
        self::assertSame('deprecation', $firstFinding['change_type']);

        $secondFinding = $findings[1];
        self::assertSame('Classes/AnotherClass.php', $secondFinding['file']);
        self::assertSame(15, $secondFinding['line']);
        self::assertSame('AnotherRule', $secondFinding['rule_class']);
        self::assertSame('Another finding message', $secondFinding['message']);
        self::assertSame('warning', $secondFinding['severity']);
        self::assertSame('breaking', $secondFinding['change_type']);
    }

    public function testExtractRectorAnalysisWithEmptyRawFindings(): void
    {
        // Arrange
        $installation = new Installation('/test/path', new Version('12.0.0'));
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $extensions = [$extension];

        // Create Rector analysis result with empty raw findings
        $rectorResult = $this->createMock(AnalysisResult::class);
        $rectorResult->method('getExtension')->willReturn($extension);
        $rectorResult->method('getAnalyzerName')->willReturn('typo3_rector');
        $rectorResult->method('getMetric')->willReturnMap([
            ['total_findings', 0],
            ['affected_files', 0],
            ['total_files', 5],
            ['processed_files', 5],
            ['execution_time', 0.5],
            ['findings_by_severity', []],
            ['findings_by_type', []],
            ['top_affected_files', []],
            ['top_rules_triggered', []],
            ['estimated_fix_time', 0],
            ['estimated_fix_time_hours', 0.0],
            ['complexity_score', 1.0],
            ['upgrade_readiness_score', 10.0],
            ['has_breaking_changes', false],
            ['has_deprecations', false],
            ['file_impact_percentage', 0.0],
            ['summary_text', 'No issues found'],
            ['rector_version', '0.18.0'],
            ['raw_findings', []], // Empty raw findings array
            ['raw_summary', null], // No summary
        ]);
        $rectorResult->method('getRiskScore')->willReturn(1.0);
        $rectorResult->method('getRecommendations')->willReturn([]);

        $groupedResults = [
            'discovery' => [],
            'analysis' => [$rectorResult],
            'reporting' => [],
        ];

        // Act
        $context = $this->subject->buildReportContext($installation, $extensions, $groupedResults, '13.0');

        // Assert - Check basic rector analysis structure
        self::assertArrayHasKey('extension_data', $context);
        $extensionData = $context['extension_data'][0];
        self::assertArrayHasKey('rector_analysis', $extensionData);

        $rectorAnalysis = $extensionData['rector_analysis'];
        self::assertSame(0, $rectorAnalysis['total_findings']);
        self::assertSame('No issues found', $rectorAnalysis['summary_text']);

        // Assert - detailed_findings should NOT be present when raw_summary is null
        self::assertArrayNotHasKey('detailed_findings', $rectorAnalysis);
    }

    public function testExtractRectorAnalysisWithOnlyRawFindings(): void
    {
        // Arrange
        $installation = new Installation('/test/path', new Version('12.0.0'));
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $extensions = [$extension];

        // Create mock raw finding
        $rawFinding = $this->createMock(RectorFinding::class);
        $rawFinding->method('toArray')->willReturn([
            'file' => 'Classes/TestClass.php',
            'line' => 25,
            'rule_class' => 'TestRule',
            'message' => 'Test finding message',
            'severity' => 'critical',
            'change_type' => 'deprecation',
        ]);

        $rawFindingsArray = [
            [
                'file' => 'Classes/TestClass.php',
                'line' => 25,
                'rule_class' => 'TestRule',
                'message' => 'Test finding message',
                'severity' => 'critical',
                'change_type' => 'deprecation',
            ],
        ];

        // Create Rector analysis result with raw findings but no summary
        $rectorResult = $this->createMock(AnalysisResult::class);
        $rectorResult->method('getExtension')->willReturn($extension);
        $rectorResult->method('getAnalyzerName')->willReturn('typo3_rector');
        $rectorResult->method('getMetric')->willReturnMap([
            ['total_findings', 1],
            ['affected_files', 1],
            ['total_files', 5],
            ['processed_files', 5],
            ['execution_time', 0.8],
            ['findings_by_severity', ['critical' => 1]],
            ['findings_by_type', ['deprecation' => 1]],
            ['top_affected_files', ['Classes/TestClass.php']],
            ['top_rules_triggered', ['TestRule']],
            ['estimated_fix_time', 60],
            ['estimated_fix_time_hours', 0.017],
            ['complexity_score', 3.0],
            ['upgrade_readiness_score', 8.0],
            ['has_breaking_changes', false],
            ['has_deprecations', true],
            ['file_impact_percentage', 20.0],
            ['summary_text', 'Found deprecations'],
            ['rector_version', '0.18.0'],
            ['raw_findings', $rawFindingsArray], // Raw findings present
            ['raw_summary', null], // No raw summary
        ]);
        $rectorResult->method('getRiskScore')->willReturn(5.0);
        $rectorResult->method('getRecommendations')->willReturn(['Fix deprecations']);

        $groupedResults = [
            'discovery' => [],
            'analysis' => [$rectorResult],
            'reporting' => [],
        ];

        // Act
        $context = $this->subject->buildReportContext($installation, $extensions, $groupedResults, '13.0');

        // Assert - Check basic rector analysis structure
        self::assertArrayHasKey('extension_data', $context);
        $extensionData = $context['extension_data'][0];
        self::assertArrayHasKey('rector_analysis', $extensionData);

        $rectorAnalysis = $extensionData['rector_analysis'];
        self::assertSame(1, $rectorAnalysis['total_findings']);
        self::assertSame('Found deprecations', $rectorAnalysis['summary_text']);

        // Assert - detailed_findings should NOT be present when raw_summary is null
        self::assertArrayNotHasKey('detailed_findings', $rectorAnalysis);
    }

    public function testExtractRectorAnalysisWithMultipleRectorResults(): void
    {
        // Arrange - This tests the edge case where multiple rector results exist (should use first one)
        $installation = new Installation('/test/path', new Version('12.0.0'));
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $extensions = [$extension];

        // Create two Rector results (should take first one)
        $rectorResult1 = $this->createMock(AnalysisResult::class);
        $rectorResult1->method('getExtension')->willReturn($extension);
        $rectorResult1->method('getAnalyzerName')->willReturn('typo3_rector');
        $rectorResult1->method('getMetric')->willReturnCallback(fn ($name): int|string|null => match ($name) {
            'total_findings' => 10, // First result has more findings
            'summary_text' => 'First rector result',
            default => null,
        });
        $rectorResult1->method('getRiskScore')->willReturn(8.0);
        $rectorResult1->method('getRecommendations')->willReturn(['Fix first']);

        $rectorResult2 = $this->createMock(AnalysisResult::class);
        $rectorResult2->method('getExtension')->willReturn($extension);
        $rectorResult2->method('getAnalyzerName')->willReturn('typo3_rector');
        $rectorResult2->method('getMetric')->willReturnCallback(fn ($name): int|string|null => match ($name) {
            'total_findings' => 5, // Second result has fewer findings
            'summary_text' => 'Second rector result',
            default => null,
        });
        $rectorResult2->method('getRiskScore')->willReturn(4.0);
        $rectorResult2->method('getRecommendations')->willReturn(['Fix second']);

        $groupedResults = [
            'discovery' => [],
            'analysis' => [$rectorResult1, $rectorResult2], // Multiple rector results
            'reporting' => [],
        ];

        // Act
        $context = $this->subject->buildReportContext($installation, $extensions, $groupedResults, '13.0');

        // Assert - Should use first rector result
        self::assertArrayHasKey('extension_data', $context);
        $extensionData = $context['extension_data'][0];
        self::assertArrayHasKey('rector_analysis', $extensionData);

        $rectorAnalysis = $extensionData['rector_analysis'];
        self::assertSame(10, $rectorAnalysis['total_findings']); // From first result
        self::assertSame('First rector result', $rectorAnalysis['summary_text']); // From first result
        self::assertSame(8.0, $rectorAnalysis['risk_score']); // From first result
    }

    public function testExtractRectorAnalysisWithNoRectorResults(): void
    {
        // Arrange - Test with no rector results at all
        $installation = new Installation('/test/path', new Version('12.0.0'));
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $extensions = [$extension];

        // Create non-rector analysis result
        $otherResult = $this->createMock(AnalysisResult::class);
        $otherResult->method('getExtension')->willReturn($extension);
        $otherResult->method('getAnalyzerName')->willReturn('version_availability'); // Not rector

        $groupedResults = [
            'discovery' => [],
            'analysis' => [$otherResult], // No rector results
            'reporting' => [],
        ];

        // Act
        $context = $this->subject->buildReportContext($installation, $extensions, $groupedResults, '13.0');

        // Assert - Should have rector_analysis key but value should be null when no rector results
        self::assertArrayHasKey('extension_data', $context);
        $extensionData = $context['extension_data'][0];
        self::assertArrayHasKey('rector_analysis', $extensionData);
        self::assertNull($extensionData['rector_analysis']);
    }
}
