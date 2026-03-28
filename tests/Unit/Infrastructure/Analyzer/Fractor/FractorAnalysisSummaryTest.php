<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Analyzer\Fractor;

use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorAnalysisSummary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FractorAnalysisSummary::class)]
class FractorAnalysisSummaryTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $totalFindings = 10;
        $criticalIssues = 2;
        $warnings = 3;
        $infoIssues = 4;
        $suggestions = 1;
        $affectedFiles = 5;
        $totalFiles = 20;
        $ruleBreakdown = ['Rule1' => 5, 'Rule2' => 5];
        $fileBreakdown = ['file1' => 2, 'file2' => 8];
        $typeBreakdown = ['breaking' => 2, 'deprecation' => 8];
        $complexityScore = 5.5;
        $estimatedFixTime = 120;

        $summary = new FractorAnalysisSummary(
            $totalFindings,
            $criticalIssues,
            $warnings,
            $infoIssues,
            $suggestions,
            $affectedFiles,
            $totalFiles,
            $ruleBreakdown,
            $fileBreakdown,
            $typeBreakdown,
            $complexityScore,
            $estimatedFixTime,
        );

        self::assertEquals($totalFindings, $summary->getTotalFindings());
        self::assertEquals($criticalIssues, $summary->getCriticalIssues());
        self::assertEquals($warnings, $summary->getWarnings());
        self::assertEquals($infoIssues, $summary->getInfoIssues());
        self::assertEquals($suggestions, $summary->getSuggestions());
        self::assertEquals($affectedFiles, $summary->getAffectedFiles());
        self::assertEquals($totalFiles, $summary->getTotalFiles());
        self::assertEquals($ruleBreakdown, $summary->getRuleBreakdown());
        self::assertEquals($fileBreakdown, $summary->getFileBreakdown());
        self::assertEquals($typeBreakdown, $summary->getTypeBreakdown());
        self::assertEquals($complexityScore, $summary->getComplexityScore());
        self::assertEquals($estimatedFixTime, $summary->getEstimatedFixTime());
    }

    #[Test]
    public function hasFindingsReturnsTrueWhenTotalFindingsGreaterThanZero(): void
    {
        $summary = new FractorAnalysisSummary(
            1,
            0,
            0,
            0,
            0,
            1,
            10,
            [],
            [],
            [],
            0.0,
            0,
        );

        self::assertTrue($summary->hasIssues());
    }

    #[Test]
    public function hasFindingsReturnsFalseWhenTotalFindingsIsZero(): void
    {
        $summary = new FractorAnalysisSummary(
            0,
            0,
            0,
            0,
            0,
            0,
            10,
            [],
            [],
            [],
            0.0,
            0,
        );

        self::assertFalse($summary->hasIssues());
    }
}
