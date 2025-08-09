<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Analyzer\Rector;

use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorChangeType;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorExecutionResult;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorFinding;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorRuleSeverity;
use PHPUnit\Framework\TestCase;

/**
 * Test case for RectorExecutionResult value object.
 */
class RectorExecutionResultTest extends TestCase
{
    public function testConstructorAndBasicProperties(): void
    {
        $findings = [
            new RectorFinding(
                'src/Test.php',
                10,
                'TestRule',
                'Test message',
                RectorRuleSeverity::WARNING,
                RectorChangeType::DEPRECATION
            )
        ];
        $errors = ['Error 1', 'Error 2'];

        $result = new RectorExecutionResult(
            successful: true,
            findings: $findings,
            errors: $errors,
            executionTime: 2.5,
            exitCode: 0,
            rawOutput: 'raw output',
            processedFileCount: 5
        );

        $this->assertTrue($result->isSuccessful());
        $this->assertEquals($findings, $result->getFindings());
        $this->assertEquals($errors, $result->getErrors());
        $this->assertEquals(2.5, $result->getExecutionTime());
        $this->assertEquals(0, $result->getExitCode());
        $this->assertEquals('raw output', $result->getRawOutput());
        $this->assertEquals(5, $result->getProcessedFileCount());
    }

    public function testHasErrors(): void
    {
        $resultWithErrors = new RectorExecutionResult(
            successful: false,
            findings: [],
            errors: ['Error message'],
            executionTime: 1.0,
            exitCode: 1,
            rawOutput: ''
        );

        $resultWithoutErrors = new RectorExecutionResult(
            successful: true,
            findings: [],
            errors: [],
            executionTime: 1.0,
            exitCode: 0,
            rawOutput: ''
        );

        $this->assertTrue($resultWithErrors->hasErrors());
        $this->assertFalse($resultWithoutErrors->hasErrors());
    }

    public function testGetTotalIssueCount(): void
    {
        $findings = [
            new RectorFinding('src/Test1.php', 10, 'Rule1', 'Message', RectorRuleSeverity::CRITICAL, RectorChangeType::BREAKING_CHANGE),
            new RectorFinding('src/Test2.php', 20, 'Rule2', 'Message', RectorRuleSeverity::WARNING, RectorChangeType::DEPRECATION),
        ];

        $result = new RectorExecutionResult(
            successful: true,
            findings: $findings,
            errors: [],
            executionTime: 1.0,
            exitCode: 0,
            rawOutput: ''
        );

        $this->assertEquals(2, $result->getTotalIssueCount());
    }

    public function testHasFindings(): void
    {
        $resultWithFindings = new RectorExecutionResult(
            successful: true,
            findings: [
                new RectorFinding('src/Test.php', 10, 'Rule', 'Message', RectorRuleSeverity::INFO, RectorChangeType::BEST_PRACTICE)
            ],
            errors: [],
            executionTime: 1.0,
            exitCode: 0,
            rawOutput: ''
        );

        $resultWithoutFindings = new RectorExecutionResult(
            successful: true,
            findings: [],
            errors: [],
            executionTime: 1.0,
            exitCode: 0,
            rawOutput: ''
        );

        $failedResultWithFindings = new RectorExecutionResult(
            successful: false,
            findings: [
                new RectorFinding('src/Test.php', 10, 'Rule', 'Message', RectorRuleSeverity::INFO, RectorChangeType::BEST_PRACTICE)
            ],
            errors: [],
            executionTime: 1.0,
            exitCode: 1,
            rawOutput: ''
        );

        $this->assertTrue($resultWithFindings->hasFindings());
        $this->assertFalse($resultWithoutFindings->hasFindings());
        $this->assertFalse($failedResultWithFindings->hasFindings()); // Not successful
    }

    public function testGetFindingsBySeverity(): void
    {
        $criticalFinding = new RectorFinding('src/Test1.php', 10, 'Rule1', 'Message', RectorRuleSeverity::CRITICAL, RectorChangeType::BREAKING_CHANGE);
        $warningFinding = new RectorFinding('src/Test2.php', 20, 'Rule2', 'Message', RectorRuleSeverity::WARNING, RectorChangeType::DEPRECATION);
        $infoFinding = new RectorFinding('src/Test3.php', 30, 'Rule3', 'Message', RectorRuleSeverity::INFO, RectorChangeType::BEST_PRACTICE);

        $result = new RectorExecutionResult(
            successful: true,
            findings: [$criticalFinding, $warningFinding, $infoFinding],
            errors: [],
            executionTime: 1.0,
            exitCode: 0,
            rawOutput: ''
        );

        $criticalFindings = $result->getFindingsBySeverity(RectorRuleSeverity::CRITICAL);
        $warningFindings = $result->getFindingsBySeverity(RectorRuleSeverity::WARNING);
        $infoFindings = $result->getFindingsBySeverity(RectorRuleSeverity::INFO);
        $suggestionFindings = $result->getFindingsBySeverity(RectorRuleSeverity::SUGGESTION);

        $this->assertCount(1, $criticalFindings);
        $this->assertEquals($criticalFinding, $criticalFindings[0]);

        $this->assertCount(1, $warningFindings);
        $this->assertEquals($warningFinding, $warningFindings[0]);

        $this->assertCount(1, $infoFindings);
        $this->assertEquals($infoFinding, $infoFindings[0]);

        $this->assertCount(0, $suggestionFindings);
    }

    public function testGetFindingsByChangeType(): void
    {
        $breakingFinding = new RectorFinding('src/Test1.php', 10, 'Rule1', 'Message', RectorRuleSeverity::CRITICAL, RectorChangeType::BREAKING_CHANGE);
        $deprecationFinding = new RectorFinding('src/Test2.php', 20, 'Rule2', 'Message', RectorRuleSeverity::WARNING, RectorChangeType::DEPRECATION);
        $bestPracticeFinding = new RectorFinding('src/Test3.php', 30, 'Rule3', 'Message', RectorRuleSeverity::INFO, RectorChangeType::BEST_PRACTICE);

        $result = new RectorExecutionResult(
            successful: true,
            findings: [$breakingFinding, $deprecationFinding, $bestPracticeFinding],
            errors: [],
            executionTime: 1.0,
            exitCode: 0,
            rawOutput: ''
        );

        $breakingFindings = $result->getFindingsByChangeType(RectorChangeType::BREAKING_CHANGE);
        $deprecationFindings = $result->getFindingsByChangeType(RectorChangeType::DEPRECATION);
        $bestPracticeFindings = $result->getFindingsByChangeType(RectorChangeType::BEST_PRACTICE);
        $methodSignatureFindings = $result->getFindingsByChangeType(RectorChangeType::METHOD_SIGNATURE);

        $this->assertCount(1, $breakingFindings);
        $this->assertEquals($breakingFinding, $breakingFindings[0]);

        $this->assertCount(1, $deprecationFindings);
        $this->assertEquals($deprecationFinding, $deprecationFindings[0]);

        $this->assertCount(1, $bestPracticeFindings);
        $this->assertEquals($bestPracticeFinding, $bestPracticeFindings[0]);

        $this->assertCount(0, $methodSignatureFindings);
    }

    public function testGetSummaryStats(): void
    {
        $findings = [
            new RectorFinding('src/Test1.php', 10, 'Rule1', 'Message', RectorRuleSeverity::CRITICAL, RectorChangeType::BREAKING_CHANGE),
            new RectorFinding('src/Test1.php', 20, 'Rule2', 'Message', RectorRuleSeverity::WARNING, RectorChangeType::DEPRECATION),
            new RectorFinding('src/Test2.php', 30, 'Rule1', 'Message', RectorRuleSeverity::INFO, RectorChangeType::BEST_PRACTICE),
        ];

        $result = new RectorExecutionResult(
            successful: true,
            findings: $findings,
            errors: [],
            executionTime: 2.5,
            exitCode: 0,
            rawOutput: '',
            processedFileCount: 5
        );

        $stats = $result->getSummaryStats();

        $this->assertArrayHasKey('total_findings', $stats);
        $this->assertArrayHasKey('processed_files', $stats);
        $this->assertArrayHasKey('affected_files', $stats);
        $this->assertArrayHasKey('execution_time', $stats);
        $this->assertArrayHasKey('severity_counts', $stats);
        $this->assertArrayHasKey('type_counts', $stats);
        $this->assertArrayHasKey('file_counts', $stats);

        $this->assertEquals(3, $stats['total_findings']);
        $this->assertEquals(5, $stats['processed_files']);
        $this->assertEquals(2, $stats['affected_files']);
        $this->assertEquals(2.5, $stats['execution_time']);

        $this->assertEquals(1, $stats['severity_counts']['critical']);
        $this->assertEquals(1, $stats['severity_counts']['warning']);
        $this->assertEquals(1, $stats['severity_counts']['info']);
        $this->assertEquals(0, $stats['severity_counts']['suggestion']);

        $this->assertEquals(1, $stats['type_counts']['breaking_change']);
        $this->assertEquals(1, $stats['type_counts']['deprecation']);
        $this->assertEquals(1, $stats['type_counts']['best_practice']);

        $this->assertEquals(2, $stats['file_counts']['src/Test1.php']);
        $this->assertEquals(1, $stats['file_counts']['src/Test2.php']);
    }

    public function testDefaultProcessedFileCount(): void
    {
        $result = new RectorExecutionResult(
            successful: true,
            findings: [],
            errors: [],
            executionTime: 1.0,
            exitCode: 0,
            rawOutput: ''
            // processedFileCount defaults to 0
        );

        $this->assertEquals(0, $result->getProcessedFileCount());
    }
}