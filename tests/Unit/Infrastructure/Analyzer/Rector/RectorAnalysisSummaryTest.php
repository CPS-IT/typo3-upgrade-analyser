<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Analyzer\Rector;

use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorAnalysisSummary;
use PHPUnit\Framework\TestCase;

/**
 * Test case for RectorAnalysisSummary value object.
 */
class RectorAnalysisSummaryTest extends TestCase
{
    public function testConstructorAndBasicProperties(): void
    {
        $ruleBreakdown = ['Rule1' => 5, 'Rule2' => 3];
        $fileBreakdown = ['File1.php' => 4, 'File2.php' => 4];
        $typeBreakdown = ['breaking_change' => 2, 'deprecation' => 6];

        $summary = new RectorAnalysisSummary(
            totalFindings: 8,
            criticalIssues: 2,
            warnings: 4,
            infoIssues: 1,
            suggestions: 1,
            affectedFiles: 2,
            totalFiles: 10,
            ruleBreakdown: $ruleBreakdown,
            fileBreakdown: $fileBreakdown,
            typeBreakdown: $typeBreakdown,
            complexityScore: 6.5,
            estimatedFixTime: 240,
        );

        $this->assertEquals(8, $summary->getTotalFindings());
        $this->assertEquals(2, $summary->getCriticalIssues());
        $this->assertEquals(4, $summary->getWarnings());
        $this->assertEquals(1, $summary->getInfoIssues());
        $this->assertEquals(1, $summary->getSuggestions());
        $this->assertEquals(2, $summary->getAffectedFiles());
        $this->assertEquals(10, $summary->getTotalFiles());
        $this->assertEquals($ruleBreakdown, $summary->getRuleBreakdown());
        $this->assertEquals($fileBreakdown, $summary->getFileBreakdown());
        $this->assertEquals($typeBreakdown, $summary->getTypeBreakdown());
        $this->assertEquals(6.5, $summary->getComplexityScore());
        $this->assertEquals(240, $summary->getEstimatedFixTime());
    }

    public function testGetEstimatedFixTimeHours(): void
    {
        $summary = new RectorAnalysisSummary(
            totalFindings: 5,
            criticalIssues: 1,
            warnings: 3,
            infoIssues: 1,
            suggestions: 0,
            affectedFiles: 3,
            totalFiles: 10,
            ruleBreakdown: [],
            fileBreakdown: [],
            typeBreakdown: [],
            complexityScore: 4.0,
            estimatedFixTime: 180, // 3 hours in minutes
        );

        $this->assertEquals(3.0, $summary->getEstimatedFixTimeHours());
    }

    public function testHasBreakingChanges(): void
    {
        $withBreaking = new RectorAnalysisSummary(
            totalFindings: 5,
            criticalIssues: 2,
            warnings: 3,
            infoIssues: 0,
            suggestions: 0,
            affectedFiles: 2,
            totalFiles: 10,
            ruleBreakdown: [],
            fileBreakdown: [],
            typeBreakdown: [],
            complexityScore: 5.0,
            estimatedFixTime: 120,
        );

        $withoutBreaking = new RectorAnalysisSummary(
            totalFindings: 3,
            criticalIssues: 0,
            warnings: 3,
            infoIssues: 0,
            suggestions: 0,
            affectedFiles: 2,
            totalFiles: 10,
            ruleBreakdown: [],
            fileBreakdown: [],
            typeBreakdown: [],
            complexityScore: 3.0,
            estimatedFixTime: 60,
        );

        $this->assertTrue($withBreaking->hasBreakingChanges());
        $this->assertFalse($withoutBreaking->hasBreakingChanges());
    }

    public function testHasDeprecations(): void
    {
        $withDeprecations = new RectorAnalysisSummary(
            totalFindings: 5,
            criticalIssues: 0,
            warnings: 3,
            infoIssues: 2,
            suggestions: 0,
            affectedFiles: 2,
            totalFiles: 10,
            ruleBreakdown: [],
            fileBreakdown: [],
            typeBreakdown: [],
            complexityScore: 4.0,
            estimatedFixTime: 90,
        );

        $withoutDeprecations = new RectorAnalysisSummary(
            totalFindings: 2,
            criticalIssues: 0,
            warnings: 0,
            infoIssues: 2,
            suggestions: 0,
            affectedFiles: 1,
            totalFiles: 10,
            ruleBreakdown: [],
            fileBreakdown: [],
            typeBreakdown: [],
            complexityScore: 2.0,
            estimatedFixTime: 30,
        );

        $this->assertTrue($withDeprecations->hasDeprecations());
        $this->assertFalse($withoutDeprecations->hasDeprecations());
    }

    public function testGetFileImpactPercentage(): void
    {
        $summary = new RectorAnalysisSummary(
            totalFindings: 10,
            criticalIssues: 2,
            warnings: 5,
            infoIssues: 3,
            suggestions: 0,
            affectedFiles: 3,
            totalFiles: 10,
            ruleBreakdown: [],
            fileBreakdown: [],
            typeBreakdown: [],
            complexityScore: 5.5,
            estimatedFixTime: 150,
        );

        $this->assertEquals(30.0, $summary->getFileImpactPercentage());
    }

    public function testGetFileImpactPercentageWithZeroTotalFiles(): void
    {
        $summary = new RectorAnalysisSummary(
            totalFindings: 0,
            criticalIssues: 0,
            warnings: 0,
            infoIssues: 0,
            suggestions: 0,
            affectedFiles: 0,
            totalFiles: 0,
            ruleBreakdown: [],
            fileBreakdown: [],
            typeBreakdown: [],
            complexityScore: 0.0,
            estimatedFixTime: 0,
        );

        $this->assertEquals(0.0, $summary->getFileImpactPercentage());
    }

    public function testGetSeverityDistribution(): void
    {
        $summary = new RectorAnalysisSummary(
            totalFindings: 10,
            criticalIssues: 2,
            warnings: 4,
            infoIssues: 3,
            suggestions: 1,
            affectedFiles: 5,
            totalFiles: 20,
            ruleBreakdown: [],
            fileBreakdown: [],
            typeBreakdown: [],
            complexityScore: 6.0,
            estimatedFixTime: 200,
        );

        $distribution = $summary->getSeverityDistribution();

        $expected = [
            'critical' => 2,
            'warning' => 4,
            'info' => 3,
            'suggestion' => 1,
        ];

        $this->assertEquals($expected, $distribution);
    }

    public function testGetUpgradeReadinessScore(): void
    {
        // Test with no issues - should return high score
        $noIssues = new RectorAnalysisSummary(
            totalFindings: 0,
            criticalIssues: 0,
            warnings: 0,
            infoIssues: 0,
            suggestions: 0,
            affectedFiles: 0,
            totalFiles: 10,
            ruleBreakdown: [],
            fileBreakdown: [],
            typeBreakdown: [],
            complexityScore: 0.0,
            estimatedFixTime: 0,
        );

        $this->assertEquals(10.0, $noIssues->getUpgradeReadinessScore());

        // Test with many critical issues - should return low score
        $manyCritical = new RectorAnalysisSummary(
            totalFindings: 15,
            criticalIssues: 10,
            warnings: 5,
            infoIssues: 0,
            suggestions: 0,
            affectedFiles: 8,
            totalFiles: 10,
            ruleBreakdown: [],
            fileBreakdown: [],
            typeBreakdown: [],
            complexityScore: 8.0,
            estimatedFixTime: 600,
        );

        $score = $manyCritical->getUpgradeReadinessScore();
        $this->assertLessThan(5.0, $score);
        $this->assertGreaterThanOrEqual(1.0, $score);
    }

    public function testGetTopIssuesByFile(): void
    {
        $fileBreakdown = [
            'File1.php' => 8,
            'File2.php' => 5,
            'File3.php' => 3,
            'File4.php' => 1,
        ];

        $summary = new RectorAnalysisSummary(
            totalFindings: 17,
            criticalIssues: 5,
            warnings: 8,
            infoIssues: 3,
            suggestions: 1,
            affectedFiles: 4,
            totalFiles: 10,
            ruleBreakdown: [],
            fileBreakdown: $fileBreakdown,
            typeBreakdown: [],
            complexityScore: 7.0,
            estimatedFixTime: 300,
        );

        $top2 = $summary->getTopIssuesByFile(2);

        $this->assertEquals(['File1.php' => 8, 'File2.php' => 5], $top2);
    }

    public function testGetTopIssuesByRule(): void
    {
        $ruleBreakdown = [
            'Rule1' => 10,
            'Rule2' => 7,
            'Rule3' => 2,
            'Rule4' => 1,
        ];

        $summary = new RectorAnalysisSummary(
            totalFindings: 20,
            criticalIssues: 6,
            warnings: 10,
            infoIssues: 3,
            suggestions: 1,
            affectedFiles: 5,
            totalFiles: 15,
            ruleBreakdown: $ruleBreakdown,
            fileBreakdown: [],
            typeBreakdown: [],
            complexityScore: 6.5,
            estimatedFixTime: 400,
        );

        $top3 = $summary->getTopIssuesByRule(3);

        $this->assertEquals(['Rule1' => 10, 'Rule2' => 7, 'Rule3' => 2], $top3);
    }

    public function testGetSummaryText(): void
    {
        $summary = new RectorAnalysisSummary(
            totalFindings: 8,
            criticalIssues: 2,
            warnings: 4,
            infoIssues: 2,
            suggestions: 0,
            affectedFiles: 3,
            totalFiles: 10,
            ruleBreakdown: [],
            fileBreakdown: [],
            typeBreakdown: [],
            complexityScore: 5.5,
            estimatedFixTime: 180,
        );

        $summaryText = $summary->getSummaryText();

        $this->assertStringContainsString('2 critical issues', $summaryText);
        $this->assertStringContainsString('4 deprecations', $summaryText);
        $this->assertStringContainsString('2 improvements', $summaryText);
        $this->assertStringContainsString('3 files', $summaryText);
        $this->assertStringContainsString('3h', $summaryText);
    }

    public function testToArrayWithCompleteData(): void
    {
        $ruleBreakdown = ['Rule1' => 5, 'Rule2' => 3];
        $fileBreakdown = ['File1.php' => 4, 'File2.php' => 4];
        $typeBreakdown = ['breaking_change' => 2, 'deprecation' => 6];

        $summary = new RectorAnalysisSummary(
            totalFindings: 8,
            criticalIssues: 2,
            warnings: 4,
            infoIssues: 1,
            suggestions: 1,
            affectedFiles: 2,
            totalFiles: 10,
            ruleBreakdown: $ruleBreakdown,
            fileBreakdown: $fileBreakdown,
            typeBreakdown: $typeBreakdown,
            complexityScore: 6.5,
            estimatedFixTime: 240,
        );

        $array = $summary->toArray();

        // Test all expected keys are present
        $expectedKeys = [
            'total_findings',
            'critical_issues',
            'warnings',
            'info_issues',
            'suggestions',
            'affected_files',
            'total_files',
            'rule_breakdown',
            'file_breakdown',
            'type_breakdown',
            'complexity_score',
            'estimated_fix_time',
            'estimated_fix_time_hours',
            'file_impact_percentage',
            'upgrade_readiness_score',
            'risk_level',
            'summary_text',
            'has_breaking_changes',
            'has_deprecations',
            'has_issues',
            'severity_distribution',
            'top_issues_by_file',
            'top_issues_by_rule',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $array, "Array should contain key: {$key}");
        }

        // Test that values match getter methods
        $this->assertEquals($summary->getTotalFindings(), $array['total_findings']);
        $this->assertEquals($summary->getCriticalIssues(), $array['critical_issues']);
        $this->assertEquals($summary->getWarnings(), $array['warnings']);
        $this->assertEquals($summary->getInfoIssues(), $array['info_issues']);
        $this->assertEquals($summary->getSuggestions(), $array['suggestions']);
        $this->assertEquals($summary->getAffectedFiles(), $array['affected_files']);
        $this->assertEquals($summary->getTotalFiles(), $array['total_files']);
        $this->assertEquals($summary->getRuleBreakdown(), $array['rule_breakdown']);
        $this->assertEquals($summary->getFileBreakdown(), $array['file_breakdown']);
        $this->assertEquals($summary->getTypeBreakdown(), $array['type_breakdown']);
        $this->assertEquals($summary->getComplexityScore(), $array['complexity_score']);
        $this->assertEquals($summary->getEstimatedFixTime(), $array['estimated_fix_time']);
        $this->assertEquals($summary->getEstimatedFixTimeHours(), $array['estimated_fix_time_hours']);
        $this->assertEquals($summary->getFileImpactPercentage(), $array['file_impact_percentage']);
        $this->assertEquals($summary->getUpgradeReadinessScore(), $array['upgrade_readiness_score']);
        $this->assertEquals($summary->getRiskLevel(), $array['risk_level']);
        $this->assertEquals($summary->getSummaryText(), $array['summary_text']);
        $this->assertEquals($summary->hasBreakingChanges(), $array['has_breaking_changes']);
        $this->assertEquals($summary->hasDeprecations(), $array['has_deprecations']);
        $this->assertEquals($summary->hasIssues(), $array['has_issues']);
        $this->assertEquals($summary->getSeverityDistribution(), $array['severity_distribution']);
        $this->assertEquals($summary->getTopIssuesByFile(10), $array['top_issues_by_file']);
        $this->assertEquals($summary->getTopIssuesByRule(10), $array['top_issues_by_rule']);
    }

    public function testToArrayWithNoFindings(): void
    {
        $summary = new RectorAnalysisSummary(
            totalFindings: 0,
            criticalIssues: 0,
            warnings: 0,
            infoIssues: 0,
            suggestions: 0,
            affectedFiles: 0,
            totalFiles: 10,
            ruleBreakdown: [],
            fileBreakdown: [],
            typeBreakdown: [],
            complexityScore: 0.0,
            estimatedFixTime: 0,
        );

        $array = $summary->toArray();

        $this->assertEquals(0, $array['total_findings']);
        $this->assertEquals(0, $array['critical_issues']);
        $this->assertEquals(0, $array['warnings']);
        $this->assertEquals(0, $array['info_issues']);
        $this->assertEquals(0, $array['suggestions']);
        $this->assertEquals(0, $array['affected_files']);
        $this->assertEquals(10, $array['total_files']);
        $this->assertEquals([], $array['rule_breakdown']);
        $this->assertEquals([], $array['file_breakdown']);
        $this->assertEquals([], $array['type_breakdown']);
        $this->assertEquals(0.0, $array['complexity_score']);
        $this->assertEquals(0, $array['estimated_fix_time']);
        $this->assertEquals('low', $array['risk_level']);
        $this->assertEquals(false, $array['has_issues']);
        $this->assertEquals(false, $array['has_breaking_changes']);
        $this->assertEquals(false, $array['has_deprecations']);

        // Test computed values
        $this->assertEquals(0.0, $array['estimated_fix_time_hours']);
        $this->assertEquals(0.0, $array['file_impact_percentage']);

        // Test severity distribution
        $expectedSeverity = [
            'critical' => 0,
            'warning' => 0,
            'info' => 0,
            'suggestion' => 0,
        ];
        $this->assertEquals($expectedSeverity, $array['severity_distribution']);
    }

    public function testToArrayWithSomeFindings(): void
    {
        $summary = new RectorAnalysisSummary(
            totalFindings: 5,
            criticalIssues: 1,
            warnings: 2,
            infoIssues: 2,
            suggestions: 0,
            affectedFiles: 3,
            totalFiles: 10,
            ruleBreakdown: ['Rule1' => 3, 'Rule2' => 2],
            fileBreakdown: ['File1.php' => 3, 'File2.php' => 2],
            typeBreakdown: ['breaking_change' => 1, 'deprecation' => 2, 'improvement' => 2],
            complexityScore: 4.0,
            estimatedFixTime: 120,
        );

        $array = $summary->toArray();

        $this->assertEquals(5, $array['total_findings']);
        $this->assertEquals(1, $array['critical_issues']);
        $this->assertEquals(2, $array['warnings']);
        $this->assertEquals(2, $array['info_issues']);
        $this->assertEquals(0, $array['suggestions']);
        $this->assertEquals(3, $array['affected_files']);
        $this->assertEquals(10, $array['total_files']);
        $this->assertEquals(['Rule1' => 3, 'Rule2' => 2], $array['rule_breakdown']);
        $this->assertEquals(['File1.php' => 3, 'File2.php' => 2], $array['file_breakdown']);
        $this->assertEquals(['breaking_change' => 1, 'deprecation' => 2, 'improvement' => 2], $array['type_breakdown']);
        $this->assertEquals(4.0, $array['complexity_score']);
        $this->assertEquals(120, $array['estimated_fix_time']);
        $this->assertEquals('high', $array['risk_level']);
        $this->assertEquals(true, $array['has_issues']);
        $this->assertEquals(true, $array['has_breaking_changes']);
        $this->assertEquals(true, $array['has_deprecations']);

        // Test computed values
        $this->assertEquals(2.0, $array['estimated_fix_time_hours']);
        $this->assertEquals(30.0, $array['file_impact_percentage']);
    }

    public function testToArrayWithManyFindings(): void
    {
        $summary = new RectorAnalysisSummary(
            totalFindings: 25,
            criticalIssues: 10,
            warnings: 10,
            infoIssues: 3,
            suggestions: 2,
            affectedFiles: 8,
            totalFiles: 10,
            ruleBreakdown: ['Rule1' => 15, 'Rule2' => 8, 'Rule3' => 2],
            fileBreakdown: ['File1.php' => 10, 'File2.php' => 8, 'File3.php' => 7],
            typeBreakdown: ['breaking_change' => 10, 'deprecation' => 10, 'improvement' => 5],
            complexityScore: 9.0,
            estimatedFixTime: 600,
        );

        $array = $summary->toArray();

        $this->assertEquals(25, $array['total_findings']);
        $this->assertEquals(10, $array['critical_issues']);
        $this->assertEquals(10, $array['warnings']);
        $this->assertEquals(3, $array['info_issues']);
        $this->assertEquals(2, $array['suggestions']);
        $this->assertEquals(8, $array['affected_files']);
        $this->assertEquals(10, $array['total_files']);
        $this->assertEquals(['Rule1' => 15, 'Rule2' => 8, 'Rule3' => 2], $array['rule_breakdown']);
        $this->assertEquals(['File1.php' => 10, 'File2.php' => 8, 'File3.php' => 7], $array['file_breakdown']);
        $this->assertEquals(['breaking_change' => 10, 'deprecation' => 10, 'improvement' => 5], $array['type_breakdown']);
        $this->assertEquals(9.0, $array['complexity_score']);
        $this->assertEquals(600, $array['estimated_fix_time']);
        $this->assertEquals('critical', $array['risk_level']);
        $this->assertEquals(true, $array['has_issues']);
        $this->assertEquals(true, $array['has_breaking_changes']);
        $this->assertEquals(true, $array['has_deprecations']);

        // Test computed values
        $this->assertEquals(10.0, $array['estimated_fix_time_hours']);
        $this->assertEquals(80.0, $array['file_impact_percentage']);
    }

    public function testToArrayWithZeroTotalFiles(): void
    {
        $summary = new RectorAnalysisSummary(
            totalFindings: 5,
            criticalIssues: 2,
            warnings: 3,
            infoIssues: 0,
            suggestions: 0,
            affectedFiles: 0,
            totalFiles: 0, // Edge case: zero total files
            ruleBreakdown: ['Rule1' => 5],
            fileBreakdown: [],
            typeBreakdown: ['breaking_change' => 2, 'deprecation' => 3],
            complexityScore: 6.0,
            estimatedFixTime: 150,
        );

        $array = $summary->toArray();

        $this->assertEquals(0.0, $array['file_impact_percentage']);
        $this->assertEquals(0, $array['total_files']);
        $this->assertEquals(0, $array['affected_files']);
        $this->assertEquals([], $array['file_breakdown']);
        $this->assertEquals([], $array['top_issues_by_file']);
    }

    public function testToArrayWithEmptyBreakdowns(): void
    {
        $summary = new RectorAnalysisSummary(
            totalFindings: 3,
            criticalIssues: 1,
            warnings: 2,
            infoIssues: 0,
            suggestions: 0,
            affectedFiles: 2,
            totalFiles: 5,
            ruleBreakdown: [], // Empty breakdown
            fileBreakdown: [], // Empty breakdown
            typeBreakdown: [], // Empty breakdown
            complexityScore: 3.0,
            estimatedFixTime: 90,
        );

        $array = $summary->toArray();

        $this->assertEquals([], $array['rule_breakdown']);
        $this->assertEquals([], $array['file_breakdown']);
        $this->assertEquals([], $array['type_breakdown']);
        $this->assertEquals([], $array['top_issues_by_file']);
        $this->assertEquals([], $array['top_issues_by_rule']);
    }

    public function testToArrayComputedValuesAccuracy(): void
    {
        $summary = new RectorAnalysisSummary(
            totalFindings: 12,
            criticalIssues: 3,
            warnings: 5,
            infoIssues: 3,
            suggestions: 1,
            affectedFiles: 4,
            totalFiles: 20,
            ruleBreakdown: ['Rule1' => 7, 'Rule2' => 3, 'Rule3' => 2],
            fileBreakdown: ['File1.php' => 6, 'File2.php' => 4, 'File3.php' => 2],
            typeBreakdown: ['breaking_change' => 3, 'deprecation' => 5, 'improvement' => 4],
            complexityScore: 5.5,
            estimatedFixTime: 187, // 3.1 hours
        );

        $array = $summary->toArray();

        // Test estimated fix time hours (should be rounded to 1 decimal place)
        $this->assertEquals(3.1, $array['estimated_fix_time_hours']);

        // Test file impact percentage (4/20 = 20%)
        $this->assertEquals(20.0, $array['file_impact_percentage']);

        // Test upgrade readiness score is within valid range
        $readinessScore = $array['upgrade_readiness_score'];
        $this->assertGreaterThanOrEqual(1.0, $readinessScore);
        $this->assertLessThanOrEqual(10.0, $readinessScore);

        // Test risk level is valid
        $validRiskLevels = ['low', 'medium', 'high', 'critical'];
        $this->assertContains($array['risk_level'], $validRiskLevels);

        // Test summary text is not empty
        $this->assertNotEmpty($array['summary_text']);
        $this->assertIsString($array['summary_text']);
    }
}
