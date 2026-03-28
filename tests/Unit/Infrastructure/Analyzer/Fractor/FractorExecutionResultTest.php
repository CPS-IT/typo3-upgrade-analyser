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

use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorChangeType;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorExecutionResult;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorFinding;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorRuleSeverity;
use PHPUnit\Framework\TestCase;

/**
 * Test case for FractorExecutionResult value object.
 */
class FractorExecutionResultTest extends TestCase
{
    public function testConstructorAndBasicProperties(): void
    {
        $findings = [
            new FractorFinding(
                'src/Test.php',
                10,
                'TestRule',
                'Test message',
                FractorRuleSeverity::WARNING,
                FractorChangeType::DEPRECATION,
            ),
        ];
        $errors = ['Error 1', 'Error 2'];

        $result = new FractorExecutionResult(
            successful: true,
            findings: $findings,
            errors: $errors,
            executionTime: 2.5,
            exitCode: 0,
            rawOutput: 'raw output',
            processedFileCount: 5,
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
        $resultWithErrors = new FractorExecutionResult(
            successful: false,
            findings: [],
            errors: ['Error message'],
            executionTime: 1.0,
            exitCode: 1,
            rawOutput: '',
        );

        $resultWithoutErrors = new FractorExecutionResult(
            successful: true,
            findings: [],
            errors: [],
            executionTime: 1.0,
            exitCode: 0,
            rawOutput: '',
        );

        $this->assertTrue($resultWithErrors->hasErrors());
        $this->assertFalse($resultWithoutErrors->hasErrors());
    }

    public function testGetTotalIssueCount(): void
    {
        $findings = [
            new FractorFinding('src/Test1.php', 10, 'Rule1', 'Message', FractorRuleSeverity::CRITICAL, FractorChangeType::BREAKING_CHANGE),
            new FractorFinding('src/Test2.php', 20, 'Rule2', 'Message', FractorRuleSeverity::WARNING, FractorChangeType::DEPRECATION),
        ];

        $result = new FractorExecutionResult(
            successful: true,
            findings: $findings,
            errors: [],
            executionTime: 1.0,
            exitCode: 0,
            rawOutput: '',
        );

        $this->assertEquals(2, $result->getTotalIssueCount());
    }

    public function testHasFindings(): void
    {
        $resultWithFindings = new FractorExecutionResult(
            successful: true,
            findings: [
                new FractorFinding('src/Test.php', 10, 'Rule', 'Message', FractorRuleSeverity::INFO, FractorChangeType::BEST_PRACTICE),
            ],
            errors: [],
            executionTime: 1.0,
            exitCode: 0,
            rawOutput: '',
        );

        $resultWithoutFindings = new FractorExecutionResult(
            successful: true,
            findings: [],
            errors: [],
            executionTime: 1.0,
            exitCode: 0,
            rawOutput: '',
        );

        $failedResultWithFindings = new FractorExecutionResult(
            successful: false,
            findings: [
                new FractorFinding('src/Test.php', 10, 'Rule', 'Message', FractorRuleSeverity::INFO, FractorChangeType::BEST_PRACTICE),
            ],
            errors: [],
            executionTime: 1.0,
            exitCode: 1,
            rawOutput: '',
        );

        $this->assertTrue($resultWithFindings->hasFindings());
        $this->assertFalse($resultWithoutFindings->hasFindings());
        $this->assertFalse($failedResultWithFindings->hasFindings()); // Not successful
    }

    public function testGetFindingsBySeverity(): void
    {
        $criticalFinding = new FractorFinding('src/Test1.php', 10, 'Rule1', 'Message', FractorRuleSeverity::CRITICAL, FractorChangeType::BREAKING_CHANGE);
        $warningFinding = new FractorFinding('src/Test2.php', 20, 'Rule2', 'Message', FractorRuleSeverity::WARNING, FractorChangeType::DEPRECATION);
        $infoFinding = new FractorFinding('src/Test3.php', 30, 'Rule3', 'Message', FractorRuleSeverity::INFO, FractorChangeType::BEST_PRACTICE);

        $result = new FractorExecutionResult(
            successful: true,
            findings: [$criticalFinding, $warningFinding, $infoFinding],
            errors: [],
            executionTime: 1.0,
            exitCode: 0,
            rawOutput: '',
        );

        $criticalFindings = $result->getFindingsBySeverity(FractorRuleSeverity::CRITICAL);
        $warningFindings = $result->getFindingsBySeverity(FractorRuleSeverity::WARNING);
        $infoFindings = $result->getFindingsBySeverity(FractorRuleSeverity::INFO);
        $suggestionFindings = $result->getFindingsBySeverity(FractorRuleSeverity::SUGGESTION);

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
        $breakingFinding = new FractorFinding('src/Test1.php', 10, 'Rule1', 'Message', FractorRuleSeverity::CRITICAL, FractorChangeType::BREAKING_CHANGE);
        $deprecationFinding = new FractorFinding('src/Test2.php', 20, 'Rule2', 'Message', FractorRuleSeverity::WARNING, FractorChangeType::DEPRECATION);
        $bestPracticeFinding = new FractorFinding('src/Test3.php', 30, 'Rule3', 'Message', FractorRuleSeverity::INFO, FractorChangeType::BEST_PRACTICE);

        $result = new FractorExecutionResult(
            successful: true,
            findings: [$breakingFinding, $deprecationFinding, $bestPracticeFinding],
            errors: [],
            executionTime: 1.0,
            exitCode: 0,
            rawOutput: '',
        );

        $breakingFindings = $result->getFindingsByChangeType(FractorChangeType::BREAKING_CHANGE);
        $deprecationFindings = $result->getFindingsByChangeType(FractorChangeType::DEPRECATION);
        $bestPracticeFindings = $result->getFindingsByChangeType(FractorChangeType::BEST_PRACTICE);
        $securityFindings = $result->getFindingsByChangeType(FractorChangeType::SECURITY);

        $this->assertCount(1, $breakingFindings);
        $this->assertEquals($breakingFinding, $breakingFindings[0]);

        $this->assertCount(1, $deprecationFindings);
        $this->assertEquals($deprecationFinding, $deprecationFindings[0]);

        $this->assertCount(1, $bestPracticeFindings);
        $this->assertEquals($bestPracticeFinding, $bestPracticeFindings[0]);

        $this->assertCount(0, $securityFindings);
    }

    public function testGetSummaryStats(): void
    {
        $findings = [
            new FractorFinding('src/Test1.php', 10, 'Rule1', 'Message', FractorRuleSeverity::CRITICAL, FractorChangeType::BREAKING_CHANGE),
            new FractorFinding('src/Test1.php', 20, 'Rule2', 'Message', FractorRuleSeverity::WARNING, FractorChangeType::DEPRECATION),
            new FractorFinding('src/Test2.php', 30, 'Rule1', 'Message', FractorRuleSeverity::INFO, FractorChangeType::BEST_PRACTICE),
        ];

        $result = new FractorExecutionResult(
            successful: true,
            findings: $findings,
            errors: [],
            executionTime: 2.5,
            exitCode: 0,
            rawOutput: '',
            processedFileCount: 5,
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
        $result = new FractorExecutionResult(
            successful: true,
            findings: [],
            errors: [],
            executionTime: 1.0,
            exitCode: 0,
            rawOutput: '',
            // processedFileCount defaults to 0
        );

        $this->assertEquals(0, $result->getProcessedFileCount());
    }
}
