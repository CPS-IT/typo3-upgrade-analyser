<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Integration\Analyzer;

use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorAnalysisSummary;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorExecutionResult;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorResultParser;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\FractorAnalyzer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(FractorAnalyzer::class)]
#[CoversClass(FractorResultParser::class)]
#[CoversClass(FractorAnalysisSummary::class)]
class FractorAnalyzerIntegrationTest extends TestCase
{
    private FractorResultParser $parser;

    protected function setUp(): void
    {
        $this->parser = new FractorResultParser(new NullLogger());
    }

    #[Test]
    public function parserHandlesRealT3eventsReservationOutput(): void
    {
        $output = file_get_contents(__DIR__ . '/../../Fixtures/fractor/t3events_reservation.txt');
        self::assertIsString($output, 'Failed to read fixture file');
        $result = new FractorExecutionResult(0, $output, '', true);

        $summary = $this->parser->parse($result);

        // Verify parsing of real data
        self::assertEquals(7, $summary->filesScanned);
        self::assertEquals(1, $summary->rulesApplied);
        self::assertTrue($summary->successful);
        self::assertTrue($summary->hasFindings());
        self::assertEquals(1, $summary->getTotalIssues());

        // Verify detailed metrics
        self::assertEquals(7, $summary->changeBlocks);
        self::assertEquals(14, $summary->changedLines); // 7 removed + 7 added lines

        // Verify file paths parsing
        self::assertCount(7, $summary->filePaths);
        self::assertStringContainsString('Contact/Edit.html', $summary->filePaths[0]);
        self::assertStringContainsString('Contact/New.html', $summary->filePaths[1]);
        self::assertStringContainsString('Participant/Edit.html', $summary->filePaths[2]);
        self::assertStringContainsString('Reservation/Edit.html', $summary->filePaths[3]);
        self::assertStringContainsString('Reservation/EditBillingAddress.html', $summary->filePaths[4]);
        self::assertStringContainsString('Reservation/New.html', $summary->filePaths[5]);
        self::assertStringContainsString('Reservation/NewBillingAddress.html', $summary->filePaths[6]);

        // Verify applied rules parsing
        self::assertCount(1, $summary->appliedRules);
        self::assertEquals('RemoveNoCacheHashAndUseCacheHashAttributeFluidFractor', $summary->appliedRules[0]);

        // Verify error handling
        self::assertNull($summary->errorMessage);
    }

    #[Test]
    public function parserHandlesComplexNewsExampleStructure(): void
    {
        // Test parsing logic with a simulated complex example
        $complexOutput = $this->getComplexFractorOutput();
        $result = new FractorExecutionResult(0, $complexOutput, '', true);

        $summary = $this->parser->parse($result);

        self::assertEquals(3, $summary->filesScanned);
        self::assertEquals(2, $summary->rulesApplied);
        self::assertTrue($summary->successful);

        // Verify diff parsing
        self::assertEquals(3, $summary->changeBlocks);
        self::assertEquals(8, $summary->changedLines); // First diff: 3- + 1+ = 4, Second diff: 1- + 1+ = 2, Third diff: 1- + 1+ = 2, Total = 8

        // Verify file paths and rules
        self::assertCount(3, $summary->filePaths);
        self::assertCount(2, $summary->appliedRules);
        self::assertContains('RemoveTCEformsFractor', $summary->appliedRules);
        self::assertContains('RemoveNoCacheHashFractor', $summary->appliedRules);
    }

    #[Test]
    public function parserHandlesFailedAnalysisWithErrors(): void
    {
        $errorOutput = 'Fatal error: Configuration file not found at /path/to/config.php';
        $result = new FractorExecutionResult(1, '', $errorOutput, false);

        $summary = $this->parser->parse($result);

        self::assertFalse($summary->successful);
        self::assertEquals(0, $summary->filesScanned);
        self::assertEquals(0, $summary->rulesApplied);
        self::assertEquals($errorOutput, $summary->errorMessage);
        self::assertFalse($summary->hasFindings());
    }

    #[Test]
    public function parserHandlesWarningsWithSuccessfulAnalysis(): void
    {
        $outputWithWarnings = $this->getOutputWithWarnings();
        $result = new FractorExecutionResult(0, $outputWithWarnings, '', true);

        $summary = $this->parser->parse($result);

        self::assertTrue($summary->successful);
        self::assertEquals(2, $summary->filesScanned);
        self::assertEquals(1, $summary->rulesApplied);
        self::assertTrue($summary->hasFindings());
    }

    #[Test]
    public function parserHandlesNoChangesNeeded(): void
    {
        $noChangesOutput = "0 files with changes\n\n[OK] No changes needed - code is already modern\n";
        $result = new FractorExecutionResult(0, $noChangesOutput, '', true);

        $summary = $this->parser->parse($result);

        self::assertTrue($summary->successful);
        self::assertEquals(0, $summary->filesScanned);
        self::assertEquals(0, $summary->rulesApplied);
        self::assertFalse($summary->hasFindings());
        self::assertEquals(0, $summary->getTotalIssues());
    }

    #[Test]
    public function parserDeterminesSuccessFromPositiveIndicators(): void
    {
        // Test case where exit code is non-zero but analysis was successful
        $output = "3 files with changes\n\n[OK] Analysis completed successfully\n";
        $result = new FractorExecutionResult(1, $output, '', false);

        $summary = $this->parser->parse($result);

        // Should be considered successful due to positive indicators
        self::assertTrue($summary->successful);
        self::assertEquals(3, $summary->filesScanned);
    }

    #[Test]
    public function parserHandlesLargeFileListCorrectly(): void
    {
        $largeOutput = $this->getLargeFileListOutput();
        $result = new FractorExecutionResult(0, $largeOutput, '', true);

        $summary = $this->parser->parse($result);

        self::assertEquals(15, $summary->filesScanned);
        // File paths should be limited to 10 to prevent excessive data
        self::assertLessThanOrEqual(10, \count($summary->filePaths));
    }

    #[Test]
    public function endToEndAnalysisWorkflow(): void
    {
        // This test simulates the complete workflow using real parser output
        $realOutput = file_get_contents(__DIR__ . '/../../Fixtures/fractor/t3events_reservation.txt');
        self::assertIsString($realOutput, 'Failed to read fixture file');
        $executionResult = new FractorExecutionResult(0, $realOutput, '', true);

        // Parse the result
        $summary = $this->parser->parse($executionResult);

        // Verify complete analysis summary
        self::assertTrue($summary->successful);
        self::assertTrue($summary->hasFindings());

        // Verify all metrics are populated
        self::assertGreaterThan(0, $summary->filesScanned);
        self::assertGreaterThan(0, $summary->rulesApplied);
        self::assertGreaterThan(0, $summary->changeBlocks);
        self::assertGreaterThan(0, $summary->changedLines);
        self::assertNotEmpty($summary->filePaths);
        self::assertNotEmpty($summary->appliedRules);

        // Verify metric relationships
        self::assertEquals($summary->rulesApplied, $summary->getTotalIssues());
        self::assertEquals($summary->filesScanned, \count($summary->filePaths));

        // Verify specific content from real data
        self::assertStringContainsString('noCacheHash', $realOutput);
        self::assertStringContainsString('RemoveNoCacheHashAndUseCacheHashAttributeFluidFractor', $realOutput);
        self::assertStringContainsString('[OK]', $realOutput);
    }

    #[Test]
    public function analysisHandlesDifferentOutputFormats(): void
    {
        // Test various output format variations that might occur
        $formats = [
            // Standard format
            "5 files with changes\n===================\n1) file.xml:10\n",
            // Alternative format without equals
            "3 files with changes\n\n1) another.html:5\n",
            // With additional whitespace
            "\n\n  2 files with changes  \n\n  1) spaced.php:15  \n",
        ];

        foreach ($formats as $format) {
            $result = new FractorExecutionResult(0, $format, '', true);
            $summary = $this->parser->parse($result);

            self::assertTrue($summary->successful, 'Failed to parse format: ' . substr($format, 0, 50));
            self::assertGreaterThan(0, $summary->filesScanned, 'No files detected in format: ' . substr($format, 0, 50));
        }
    }

    private function getComplexFractorOutput(): string
    {
        return "Warning: Some XML parsing warning\n\n" .
               "3 files with changes\n" .
               "====================\n\n" .
               "1) ../path/to/file1.xml:8\n\n" .
               "    ---------- begin diff ----------\n" .
               "@@ @@\n" .
               "-                <TCEforms>\n" .
               "-                    <sheetTitle>Title</sheetTitle>\n" .
               "-                </TCEforms>\n" .
               "+                <sheetTitle>Title</sheetTitle>\n" .
               "    ----------- end diff -----------\n\n" .
               "Applied rules:\n" .
               " * RemoveTCEformsFractor (https://example.com)\n\n" .
               "2) ../path/to/file2.html:15\n\n" .
               "    ---------- begin diff ----------\n" .
               "-            noCacheHash=\"TRUE\">\n" .
               "+           >\n" .
               "    ----------- end diff -----------\n\n" .
               "Applied rules:\n" .
               " * RemoveNoCacheHashFractor (https://example.com)\n\n" .
               "3) ../path/to/file3.php:20\n\n" .
               "    ---------- begin diff ----------\n" .
               "-    // Old comment\n" .
               "+    // New comment\n" .
               "    ----------- end diff -----------\n\n" .
               " [OK] 3 files would have been changed (dry-run) by Fractor\n";
    }

    private function getOutputWithWarnings(): string
    {
        return "Warning: DOMDocument::loadXML(): Namespace prefix f on for is not defined\n" .
               "Warning: Another parsing warning\n\n" .
               "2 files with changes\n" .
               "====================\n\n" .
               "1) file1.xml:10\n" .
               "2) file2.html:15\n\n" .
               "Applied rules:\n" .
               " * SomeRuleFractor (https://example.com)\n\n" .
               "[OK] Analysis completed\n";
    }

    private function getLargeFileListOutput(): string
    {
        $output = "15 files with changes\n=====================\n\n";

        for ($i = 1; $i <= 15; ++$i) {
            $output .= "{$i}) ../path/to/file{$i}.xml:10\n";
        }

        $output .= "\nApplied rules:\n";
        $output .= " * TestRuleFractor (https://example.com)\n\n";
        $output .= "[OK] 15 files would have been changed\n";

        return $output;
    }
}
