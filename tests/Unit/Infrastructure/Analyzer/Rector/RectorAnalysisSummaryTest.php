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
}
