<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Analyzer\Shared;

use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorAnalysisSummary;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorChangeType;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorFinding;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorRuleSeverity;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorChangeType;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorFinding;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorRuleSeverity;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Shared\AnalyzerFindingInterface;
use PHPUnit\Framework\TestCase;

/**
 * Test case to verify that existing classes properly implement the generic interfaces.
 */
class InterfaceComplianceTest extends TestCase
{
    public function testFractorFindingImplementsGenericInterfaces(): void
    {
        $finding = new FractorFinding(
            filePath: '/path/to/file.php',
            lineNumber: 42,
            ruleClass: 'Fractor\\Rule\\TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::WARNING,
            changeType: FractorChangeType::DEPRECATION_REMOVAL,
            codeBefore: 'old code',
            codeAfter: 'new code',
            diff: 'diff content',
        );

        // Test interface methods work correctly
        self::assertSame('/path/to/file.php', $finding->getFile());
        self::assertSame(42, $finding->getLine());
        self::assertSame('Test message', $finding->getMessage());
        self::assertSame('Fractor\\Rule\\TestRule', $finding->getRuleClass());
        self::assertIsString($finding->getSeverityValue());
        self::assertIsFloat($finding->getPriorityScore());
        self::assertIsInt($finding->getEstimatedEffort());
        self::assertIsBool($finding->isBreakingChange());
        self::assertIsBool($finding->isDeprecation());
        self::assertIsBool($finding->isImprovement());

        // Test helper methods from trait
        self::assertIsString($finding->getFileLocation());
        self::assertIsBool($finding->requiresImmediateAction());
        self::assertSame('fractor', $finding->getAnalyzerType());
        self::assertIsString($finding->getSeverityDisplay());
        self::assertIsString($finding->getEffortCategory());

        // Test toArray method
        $array = $finding->toArray();
        self::assertIsArray($array);
        self::assertArrayHasKey('file', $array);
        self::assertArrayHasKey('line', $array);
        self::assertArrayHasKey('message', $array);
        self::assertArrayHasKey('severity', $array);
        self::assertArrayHasKey('analyzer_type', $array);
        self::assertArrayHasKey('requires_immediate_action', $array);
    }

    public function testRectorFindingImplementsGenericInterfaces(): void
    {
        $finding = new RectorFinding(
            file: '/path/to/file.php',
            line: 24,
            ruleClass: 'Rector\\Rule\\TestRule',
            message: 'Rector message',
            severity: RectorRuleSeverity::CRITICAL,
            changeType: RectorChangeType::BREAKING_CHANGE,
            suggestedFix: 'suggested fix',
        );

        // Test interface methods work correctly
        self::assertSame('/path/to/file.php', $finding->getFile());
        self::assertSame(24, $finding->getLine());
        self::assertSame('Rector message', $finding->getMessage());
        self::assertSame('Rector\\Rule\\TestRule', $finding->getRuleClass());
        self::assertIsString($finding->getSeverityValue());
        self::assertIsFloat($finding->getPriorityScore());
        self::assertIsInt($finding->getEstimatedEffort());
        self::assertIsBool($finding->isBreakingChange());
        self::assertIsBool($finding->isDeprecation());
        self::assertIsBool($finding->isImprovement());

        // Test helper methods from trait
        self::assertIsString($finding->getFileLocation());
        self::assertIsBool($finding->requiresImmediateAction());
        self::assertSame('rector', $finding->getAnalyzerType());
        self::assertIsString($finding->getSeverityDisplay());
        self::assertIsString($finding->getEffortCategory());

        // Test toArray method
        $array = $finding->toArray();
        self::assertIsArray($array);
        self::assertArrayHasKey('file', $array);
        self::assertArrayHasKey('line', $array);
        self::assertArrayHasKey('message', $array);
        self::assertArrayHasKey('severity', $array);
        self::assertArrayHasKey('analyzer_type', $array);
        self::assertArrayHasKey('requires_immediate_action', $array);
    }

    public function testFractorAnalysisSummaryImplementsFindingsSummaryInterface(): void
    {
        $summary = new FractorAnalysisSummary(
            filesScanned: 10,
            rulesApplied: 3,
            findings: [],
            successful: true,
            errorMessage: null,
        );

        // Test interface methods work correctly
        self::assertSame(0, $summary->getTotalFindings());
        self::assertSame(10, $summary->getFilesScanned());
        self::assertSame(3, $summary->getRulesApplied());
        self::assertTrue($summary->isSuccessful());
        self::assertTrue($summary->hasFindings()); // hasFindings() returns true when filesScanned > 0
        self::assertNull($summary->getErrorMessage());

        // Test toArray method
        $array = $summary->toArray();
        self::assertIsArray($array);
        self::assertArrayHasKey('total_findings', $array);
        self::assertArrayHasKey('files_scanned', $array);
        self::assertArrayHasKey('rules_applied', $array);
        self::assertArrayHasKey('successful', $array);
        self::assertArrayHasKey('has_findings', $array);
        self::assertArrayHasKey('error_message', $array);
    }

    public function testInterfaceInteroperability(): void
    {
        // Create findings from both analyzers
        $fractorFinding = new FractorFinding(
            filePath: '/path/to/fractor.php',
            lineNumber: 10,
            ruleClass: 'Fractor\\Rule\\TestRule',
            message: 'Fractor issue',
            severity: FractorRuleSeverity::WARNING,
            changeType: FractorChangeType::MODERNIZATION,
            codeBefore: 'old',
            codeAfter: 'new',
            diff: 'diff',
        );

        $rectorFinding = new RectorFinding(
            file: '/path/to/rector.php',
            line: 20,
            ruleClass: 'Rector\\Rule\\TestRule',
            message: 'Rector issue',
            severity: RectorRuleSeverity::CRITICAL,
            changeType: RectorChangeType::BREAKING_CHANGE,
        );

        // Test that we can treat them generically
        $findings = [$fractorFinding, $rectorFinding];

        foreach ($findings as $finding) {
            // All findings should provide these methods
            self::assertIsString($finding->getFile());
            self::assertIsInt($finding->getLine());
            self::assertIsString($finding->getMessage());
            self::assertIsString($finding->getRuleClass());
            self::assertIsString($finding->getRuleName());
            self::assertIsString($finding->getSeverityValue());
            self::assertIsFloat($finding->getPriorityScore());
            self::assertIsInt($finding->getEstimatedEffort());

            // Helper methods from trait
            self::assertIsString($finding->getFileLocation());
            self::assertIsBool($finding->requiresImmediateAction());
            self::assertIsString($finding->getAnalyzerType());
            self::assertIsString($finding->getSeverityDisplay());
            self::assertIsString($finding->getEffortCategory());

            // Array conversion
            $array = $finding->toArray();
            self::assertIsArray($array);
            self::assertNotEmpty($array);
        }
    }

    public function testComparePriorityAcrossDifferentAnalyzers(): void
    {
        $criticalRectorFinding = new RectorFinding(
            file: '/path/to/rector.php',
            line: 20,
            ruleClass: 'Rector\\Rule\\CriticalRule',
            message: 'Critical rector issue',
            severity: RectorRuleSeverity::CRITICAL,
            changeType: RectorChangeType::BREAKING_CHANGE,
        );

        $warningFractorFinding = new FractorFinding(
            filePath: '/path/to/fractor.php',
            lineNumber: 10,
            ruleClass: 'Fractor\\Rule\\WarningRule',
            message: 'Warning fractor issue',
            severity: FractorRuleSeverity::WARNING,
            changeType: FractorChangeType::MODERNIZATION,
            codeBefore: 'old',
            codeAfter: 'new',
            diff: 'diff',
        );

        // Critical finding should have higher priority than warning
        $result = $criticalRectorFinding->comparePriority($warningFractorFinding);
        self::assertLessThan(0, $result, 'Critical finding should have higher priority');

        $result = $warningFractorFinding->comparePriority($criticalRectorFinding);
        self::assertGreaterThan(0, $result, 'Warning finding should have lower priority');
    }

    public function testSortingFindingsByPriority(): void
    {
        // Create findings with different priorities
        $findings = [
            new FractorFinding(
                filePath: '/path/to/low.php',
                lineNumber: 1,
                ruleClass: 'Fractor\\Rule\\InfoRule',
                message: 'Info issue',
                severity: FractorRuleSeverity::INFO,
                changeType: FractorChangeType::MODERNIZATION,
                codeBefore: 'old',
                codeAfter: 'new',
                diff: 'diff',
            ),
            new RectorFinding(
                file: '/path/to/high.php',
                line: 2,
                ruleClass: 'Rector\\Rule\\CriticalRule',
                message: 'Critical issue',
                severity: RectorRuleSeverity::CRITICAL,
                changeType: RectorChangeType::DEPRECATION, // Use a lower-effort change type
            ),
            new FractorFinding(
                filePath: '/path/to/medium.php',
                lineNumber: 3,
                ruleClass: 'Fractor\\Rule\\WarningRule',
                message: 'Warning issue',
                severity: FractorRuleSeverity::WARNING,
                changeType: FractorChangeType::DEPRECATION_REMOVAL,
                codeBefore: 'old',
                codeAfter: 'new',
                diff: 'diff',
            ),
        ];

        // Sort by priority (highest first)
        usort($findings, fn (AnalyzerFindingInterface $a, AnalyzerFindingInterface $b): int => $a->comparePriority($b));

        // With similar effort levels, critical severity should come first
        self::assertSame('Critical issue', $findings[0]->getMessage());
        self::assertSame('Warning issue', $findings[1]->getMessage());
        self::assertSame('Info issue', $findings[2]->getMessage());
    }
}
