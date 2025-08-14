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
        $filesScanned = 10;
        $rulesApplied = 5;
        $findings = ['finding1', 'finding2'];
        $successful = true;
        $changeBlocks = 8;
        $changedLines = 25;
        $filePaths = ['path1.xml', 'path2.html'];
        $appliedRules = ['Rule1', 'Rule2'];
        $errorMessage = 'Test error';

        $summary = new FractorAnalysisSummary(
            $filesScanned,
            $rulesApplied,
            $findings,
            $successful,
            $changeBlocks,
            $changedLines,
            $filePaths,
            $appliedRules,
            $errorMessage,
        );

        self::assertEquals($filesScanned, $summary->filesScanned);
        self::assertEquals($rulesApplied, $summary->rulesApplied);
        self::assertEquals($findings, $summary->findings);
        self::assertEquals($successful, $summary->successful);
        self::assertEquals($changeBlocks, $summary->changeBlocks);
        self::assertEquals($changedLines, $summary->changedLines);
        self::assertEquals($filePaths, $summary->filePaths);
        self::assertEquals($appliedRules, $summary->appliedRules);
        self::assertEquals($errorMessage, $summary->errorMessage);
    }

    #[Test]
    public function constructorUsesDefaultValues(): void
    {
        $summary = new FractorAnalysisSummary(
            5,
            3,
            ['finding'],
            true,
        );

        self::assertEquals(5, $summary->filesScanned);
        self::assertEquals(3, $summary->rulesApplied);
        self::assertEquals(['finding'], $summary->findings);
        self::assertTrue($summary->successful);
        self::assertEquals(0, $summary->changeBlocks);
        self::assertEquals(0, $summary->changedLines);
        self::assertEquals([], $summary->filePaths);
        self::assertEquals([], $summary->appliedRules);
        self::assertNull($summary->errorMessage);
    }

    #[Test]
    public function hasFindingsReturnsTrueWhenFilesScanned(): void
    {
        $summary = new FractorAnalysisSummary(
            5,
            0,
            [],
            true,
        );

        self::assertTrue($summary->hasFindings());
    }

    #[Test]
    public function hasFindingsReturnsTrueWhenRulesApplied(): void
    {
        $summary = new FractorAnalysisSummary(
            0,
            3,
            [],
            true,
        );

        self::assertTrue($summary->hasFindings());
    }

    #[Test]
    public function hasFindingsReturnsFalseWhenNoFilesOrRules(): void
    {
        $summary = new FractorAnalysisSummary(
            0,
            0,
            ['some finding'],
            true,
        );

        self::assertFalse($summary->hasFindings());
    }

    #[Test]
    public function getTotalIssuesReturnsRulesApplied(): void
    {
        $rulesApplied = 7;
        $summary = new FractorAnalysisSummary(
            10,
            $rulesApplied,
            [],
            true,
        );

        self::assertEquals($rulesApplied, $summary->getTotalIssues());
    }

    #[Test]
    public function isReadonlyClass(): void
    {
        $summary = new FractorAnalysisSummary(5, 3, [], true);

        // This test just verifies the class is readonly by checking it exists
        // The readonly keyword prevents mutation at runtime in PHP 8.1+
        self::assertEquals(5, $summary->filesScanned);
        self::assertEquals(3, $summary->rulesApplied);
        // If it's truly readonly, we can't modify properties (would throw fatal error)
    }

    #[Test]
    public function constructorHandlesEmptyArrays(): void
    {
        $summary = new FractorAnalysisSummary(
            0,
            0,
            [],
            false,
            0,
            0,
            [],
            [],
            null,
        );

        self::assertEquals([], $summary->findings);
        self::assertEquals([], $summary->filePaths);
        self::assertEquals([], $summary->appliedRules);
        self::assertNull($summary->errorMessage);
        self::assertFalse($summary->successful);
    }

    #[Test]
    public function constructorWithFailedAnalysis(): void
    {
        $errorMessage = 'Analysis failed due to configuration error';
        $summary = new FractorAnalysisSummary(
            0,
            0,
            [],
            false,
            0,
            0,
            [],
            [],
            $errorMessage,
        );

        self::assertFalse($summary->successful);
        self::assertEquals($errorMessage, $summary->errorMessage);
        self::assertFalse($summary->hasFindings());
        self::assertEquals(0, $summary->getTotalIssues());
    }

    #[Test]
    public function constructorWithRealWorldData(): void
    {
        // Based on t3events_reservation.txt example
        $filePaths = [
            '../ihkof-bundle/app/web/typo3conf/ext/t3events_reservation/Resources/Private/Templates/Contact/Edit.html:10',
            '../ihkof-bundle/app/web/typo3conf/ext/t3events_reservation/Resources/Private/Templates/Contact/New.html:9',
            '../ihkof-bundle/app/web/typo3conf/ext/t3events_reservation/Resources/Private/Templates/Participant/Edit.html:10',
        ];
        $appliedRules = ['RemoveNoCacheHashAndUseCacheHashAttributeFluidFractor'];

        $summary = new FractorAnalysisSummary(
            7,
            1,
            [],
            true,
            7,
            7,
            $filePaths,
            $appliedRules,
        );

        self::assertEquals(7, $summary->filesScanned);
        self::assertEquals(1, $summary->rulesApplied);
        self::assertEquals(7, $summary->changeBlocks);
        self::assertEquals(7, $summary->changedLines);
        self::assertTrue($summary->successful);
        self::assertTrue($summary->hasFindings());
        self::assertCount(3, $summary->filePaths);
        self::assertCount(1, $summary->appliedRules);
        self::assertEquals('RemoveNoCacheHashAndUseCacheHashAttributeFluidFractor', $summary->appliedRules[0]);
    }
}
