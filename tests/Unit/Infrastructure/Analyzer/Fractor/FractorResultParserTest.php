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

use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorExecutionResult;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorResultParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(FractorResultParser::class)]
class FractorResultParserTest extends TestCase
{
    private FractorResultParser $parser;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->parser = new FractorResultParser($this->logger);
    }

    #[Test]
    public function parseHandlesEmptyOutput(): void
    {
        $result = new FractorExecutionResult(0, '', '', true);
        $summary = $this->parser->parse($result);

        self::assertEquals(0, $summary->filesScanned);
        self::assertEquals(0, $summary->rulesApplied);
        self::assertEquals([], $summary->findings);
        self::assertTrue($summary->successful);
        self::assertEquals(0, $summary->changeBlocks);
        self::assertEquals(0, $summary->changedLines);
        self::assertEquals([], $summary->filePaths);
        self::assertEquals([], $summary->appliedRules);
        self::assertNull($summary->errorMessage);
    }

    #[Test]
    public function parseHandlesErrorOutputOnly(): void
    {
        $errorOutput = 'Fatal error: Configuration file not found';
        $result = new FractorExecutionResult(1, '', $errorOutput, false);

        $summary = $this->parser->parse($result);

        self::assertEquals(0, $summary->filesScanned);
        self::assertEquals(0, $summary->rulesApplied);
        self::assertEquals([], $summary->findings);
        self::assertFalse($summary->successful);
        self::assertEquals($errorOutput, $summary->errorMessage);
    }

    #[Test]
    public function parseHandlesT3eventsReservationExample(): void
    {
        // Real example from t3events_reservation.txt
        $output = file_get_contents(__DIR__ . '/../../../../Fixtures/fractor/t3events_reservation.txt');
        self::assertIsString($output, 'Failed to read fixture file');
        $result = new FractorExecutionResult(0, $output, '', true);

        $summary = $this->parser->parse($result);

        self::assertEquals(7, $summary->filesScanned);
        self::assertEquals(1, $summary->rulesApplied);
        self::assertTrue($summary->successful);
        self::assertEquals(7, $summary->changeBlocks);
        self::assertEquals(14, $summary->changedLines); // 7 removed + 7 added lines
        self::assertCount(7, $summary->filePaths);
        self::assertCount(1, $summary->appliedRules);
        self::assertEquals('RemoveNoCacheHashAndUseCacheHashAttributeFluidFractor', $summary->appliedRules[0]);

        // Check specific file paths
        self::assertStringContainsString('Contact/Edit.html', $summary->filePaths[0]);
        self::assertStringContainsString('Contact/New.html', $summary->filePaths[1]);
        self::assertStringContainsString('Participant/Edit.html', $summary->filePaths[2]);
    }

    #[Test]
    public function parseHandlesNewsExampleWithMultipleRules(): void
    {
        // Sample from news.txt with multiple files and rules
        $output = $this->getNewsExampleSample();
        $result = new FractorExecutionResult(0, $output, '', true);

        $summary = $this->parser->parse($result);

        self::assertEquals(22, $summary->filesScanned);
        self::assertGreaterThan(0, $summary->rulesApplied);
        self::assertTrue($summary->successful);
        self::assertGreaterThan(0, $summary->changeBlocks);
        self::assertGreaterThan(0, $summary->changedLines);
        self::assertNotEmpty($summary->filePaths);
        self::assertNotEmpty($summary->appliedRules);
    }

    #[Test]
    public function parseHandlesJsonOutput(): void
    {
        $jsonOutput = json_encode([
            'files_changed' => 5,
            'rules_applied' => 3,
            'findings' => ['finding1', 'finding2'],
        ]);
        self::assertIsString($jsonOutput, 'Failed to encode JSON');
        $result = new FractorExecutionResult(0, $jsonOutput, '', true);

        $summary = $this->parser->parse($result);

        self::assertEquals(5, $summary->filesScanned);
        self::assertEquals(3, $summary->rulesApplied);
        self::assertEquals(['finding1', 'finding2'], $summary->findings);
        self::assertTrue($summary->successful);
    }

    #[Test]
    public function parseHandlesInvalidJsonOutput(): void
    {
        $invalidJson = '{"invalid": json}';
        $result = new FractorExecutionResult(0, $invalidJson, '', true);

        $this->logger
            ->expects(self::once())
            ->method('warning')
            ->with('Failed to parse Fractor JSON output');

        $summary = $this->parser->parse($result);

        self::assertEquals(0, $summary->filesScanned);
        self::assertEquals(0, $summary->rulesApplied);
        self::assertEquals([], $summary->findings);
    }

    #[Test]
    public function parseDeterminesSuccessFromExitCodeAndOutput(): void
    {
        $output = "5 files with changes\n\nProcessed successfully";
        $result = new FractorExecutionResult(1, $output, '', false);

        $summary = $this->parser->parse($result);

        // Should be considered successful despite non-zero exit code due to positive indicators
        self::assertTrue($summary->successful);
        self::assertEquals(5, $summary->filesScanned);
    }

    #[Test]
    public function parseHandlesSuccessfulAnalysisWithNoChanges(): void
    {
        $output = "0 files with changes\n[OK] Analysis completed";
        $result = new FractorExecutionResult(0, $output, '', true);

        $summary = $this->parser->parse($result);

        self::assertTrue($summary->successful);
        self::assertEquals(0, $summary->filesScanned);
        self::assertEquals(0, $summary->rulesApplied);
        self::assertFalse($summary->hasFindings());
    }

    #[Test]
    public function parseDetectsFailureFromFatalErrors(): void
    {
        $errorOutput = 'Fatal error: No such file or directory';
        $result = new FractorExecutionResult(1, '', $errorOutput, false);

        $summary = $this->parser->parse($result);

        self::assertFalse($summary->successful);
        self::assertEquals($errorOutput, $summary->errorMessage);
    }

    #[Test]
    public function parseHandlesWarningsInOutput(): void
    {
        $output = "Warning: Some warning message\n\n3 files with changes\n=====================";
        $result = new FractorExecutionResult(0, $output, '', true);

        $summary = $this->parser->parse($result);

        self::assertTrue($summary->successful);
        self::assertEquals(3, $summary->filesScanned);
    }

    #[Test]
    public function parseParsesFilePathsWithLineNumbers(): void
    {
        $output = "2 files with changes\n====================\n\n";
        $output .= "1) ../path/to/file1.xml:10\n";
        $output .= "2) ../path/to/file2.html:25\n";

        $result = new FractorExecutionResult(0, $output, '', true);
        $summary = $this->parser->parse($result);

        self::assertEquals(2, $summary->filesScanned);
        self::assertCount(2, $summary->filePaths);
        self::assertEquals('../path/to/file1.xml:10', $summary->filePaths[0]);
        self::assertEquals('../path/to/file2.html:25', $summary->filePaths[1]);
    }

    #[Test]
    public function parseParsesDiffSections(): void
    {
        $output = "1 files with changes\n====================\n\n";
        $output .= "1) file.xml:10\n\n";
        $output .= "    ---------- begin diff ----------\n";
        $output .= "-    <old>content</old>\n";
        $output .= "+    <new>content</new>\n";
        $output .= "    ----------- end diff -----------\n";

        $result = new FractorExecutionResult(0, $output, '', true);
        $summary = $this->parser->parse($result);

        self::assertEquals(1, $summary->filesScanned);
        self::assertEquals(1, $summary->changeBlocks);
        self::assertEquals(2, $summary->changedLines); // One - line, one + line
    }

    #[Test]
    public function parseParsesAppliedRules(): void
    {
        $output = "1 files with changes\n====================\n\n";
        $output .= "Applied rules:\n";
        $output .= " * SomeRuleFractor (https://example.com)\n";
        $output .= " * AnotherRuleFractor (https://example.com)\n";

        $result = new FractorExecutionResult(0, $output, '', true);
        $summary = $this->parser->parse($result);

        self::assertEquals(1, $summary->filesScanned);
        self::assertEquals(2, $summary->rulesApplied);
        self::assertCount(2, $summary->appliedRules);
        self::assertContains('SomeRuleFractor', $summary->appliedRules);
        self::assertContains('AnotherRuleFractor', $summary->appliedRules);
    }

    #[Test]
    public function parseLimitsFilePathsToPreventExcessiveData(): void
    {
        $output = "15 files with changes\n====================\n\n";
        for ($i = 1; $i <= 15; ++$i) {
            $output .= "{$i}) file{$i}.xml:10\n";
        }

        $result = new FractorExecutionResult(0, $output, '', true);
        $summary = $this->parser->parse($result);

        self::assertEquals(15, $summary->filesScanned);
        self::assertCount(10, $summary->filePaths); // Limited to 10
    }

    #[Test]
    public function parseHandlesComplexFractorOutput(): void
    {
        $output = "Warning: Some XML namespace warning\n\n";
        $output .= "5 files with changes\n=====================\n\n";
        $output .= "1) file1.xml:8\n\n";
        $output .= "    ---------- begin diff ----------\n";
        $output .= "@@ @@\n";
        $output .= "-                <TCEforms>\n";
        $output .= "-                    <sheetTitle>Title</sheetTitle>\n";
        $output .= "-                </TCEforms>\n";
        $output .= "+                <sheetTitle>Title</sheetTitle>\n";
        $output .= "    ----------- end diff -----------\n\n";
        $output .= "Applied rules:\n";
        $output .= " * RemoveTCEformsFractor (https://example.com)\n\n";
        $output .= "2) file2.html:15\n";
        $output .= "    ---------- begin diff ----------\n";
        $output .= "-            noCacheHash=\"TRUE\">\n";
        $output .= "+           >\n";
        $output .= "    ----------- end diff -----------\n\n";
        $output .= "Applied rules:\n";
        $output .= " * RemoveNoCacheHashFractor (https://example.com)\n\n";
        $output .= " [OK] 5 files would have been changed (dry-run) by Fractor\n";

        $result = new FractorExecutionResult(0, $output, '', true);
        $summary = $this->parser->parse($result);

        self::assertTrue($summary->successful);
        self::assertEquals(5, $summary->filesScanned);
        self::assertEquals(2, $summary->rulesApplied);
        self::assertEquals(2, $summary->changeBlocks);
        self::assertEquals(6, $summary->changedLines); // 3 - lines + 1 + line from first diff, 1 - and 1 + from second = 6 total
        self::assertCount(2, $summary->filePaths);
        self::assertContains('RemoveTCEformsFractor', $summary->appliedRules);
        self::assertContains('RemoveNoCacheHashFractor', $summary->appliedRules);
    }

    private function getNewsExampleSample(): string
    {
        // Simulated sample from news.txt (the full file is too large to include)
        return "Warning: DOMDocument::loadXML(): Namespace prefix f on for is not defined\n\n" .
               "22 files with changes\n" .
               "=====================\n\n" .
               "1) ../news/Configuration/FlexForms/flexform_category_list.xml:8\n\n" .
               "    ---------- begin diff ----------\n" .
               "@@ @@\n" .
               "-                <TCEforms>\n" .
               "-                    <sheetTitle>Title</sheetTitle>\n" .
               "-                </TCEforms>\n" .
               "+                <sheetTitle>Title</sheetTitle>\n" .
               "    ----------- end diff -----------\n\n" .
               "Applied rules:\n" .
               " * RemoveTCEformsFractor (https://example.com)\n\n" .
               " [OK] 22 files would have been changed (dry-run) by Fractor\n";
    }
}
