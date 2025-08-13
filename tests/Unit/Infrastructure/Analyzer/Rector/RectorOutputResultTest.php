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

use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorChangeType;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorFinding;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorOutputResult;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorRuleSeverity;
use PHPUnit\Framework\TestCase;

/**
 * Test case for RectorOutputResult value object.
 */
class RectorOutputResultTest extends TestCase
{
    public function testConstructorWithEmptyData(): void
    {
        $result = new RectorOutputResult(
            findings: [],
            errors: [],
            processedFiles: 0,
        );

        $this->assertEquals([], $result->findings);
        $this->assertEquals([], $result->errors);
        $this->assertEquals(0, $result->processedFiles);
    }

    public function testConstructorWithValidData(): void
    {
        $findings = [
            new RectorFinding(
                file: 'src/Test.php',
                line: 10,
                ruleClass: 'TestRule',
                message: 'Test message',
                severity: RectorRuleSeverity::WARNING,
                changeType: RectorChangeType::DEPRECATION,
            ),
            new RectorFinding(
                file: 'src/Another.php',
                line: 20,
                ruleClass: 'AnotherRule',
                message: 'Another message',
                severity: RectorRuleSeverity::CRITICAL,
                changeType: RectorChangeType::BREAKING_CHANGE,
            ),
        ];

        $errors = [
            'Parse error in file.php',
            'Rule execution failed',
        ];

        $result = new RectorOutputResult(
            findings: $findings,
            errors: $errors,
            processedFiles: 5,
        );

        $this->assertEquals($findings, $result->findings);
        $this->assertEquals($errors, $result->errors);
        $this->assertEquals(5, $result->processedFiles);
    }

    public function testGetFindingsCountWithEmptyFindings(): void
    {
        $result = new RectorOutputResult(
            findings: [],
            errors: [],
            processedFiles: 0,
        );

        $this->assertEquals(0, $result->getFindingsCount());
    }

    public function testGetFindingsCountWithMultipleFindings(): void
    {
        $findings = [
            new RectorFinding(
                file: 'src/Test1.php',
                line: 10,
                ruleClass: 'TestRule1',
                message: 'Test message 1',
                severity: RectorRuleSeverity::WARNING,
                changeType: RectorChangeType::DEPRECATION,
            ),
            new RectorFinding(
                file: 'src/Test2.php',
                line: 20,
                ruleClass: 'TestRule2',
                message: 'Test message 2',
                severity: RectorRuleSeverity::INFO,
                changeType: RectorChangeType::BEST_PRACTICE,
            ),
            new RectorFinding(
                file: 'src/Test3.php',
                line: 30,
                ruleClass: 'TestRule3',
                message: 'Test message 3',
                severity: RectorRuleSeverity::CRITICAL,
                changeType: RectorChangeType::BREAKING_CHANGE,
            ),
        ];

        $result = new RectorOutputResult(
            findings: $findings,
            errors: [],
            processedFiles: 3,
        );

        $this->assertEquals(3, $result->getFindingsCount());
    }

    public function testGetErrorsCountWithEmptyErrors(): void
    {
        $result = new RectorOutputResult(
            findings: [],
            errors: [],
            processedFiles: 0,
        );

        $this->assertEquals(0, $result->getErrorsCount());
    }

    public function testGetErrorsCountWithMultipleErrors(): void
    {
        $errors = [
            'First error message',
            'Second error message',
            'Third error message',
            'Fourth error message',
        ];

        $result = new RectorOutputResult(
            findings: [],
            errors: $errors,
            processedFiles: 0,
        );

        $this->assertEquals(4, $result->getErrorsCount());
    }

    public function testIsSuccessfulWithNoErrors(): void
    {
        $result = new RectorOutputResult(
            findings: [
                new RectorFinding(
                    file: 'src/Test.php',
                    line: 10,
                    ruleClass: 'TestRule',
                    message: 'Test message',
                    severity: RectorRuleSeverity::INFO,
                    changeType: RectorChangeType::BEST_PRACTICE,
                ),
            ],
            errors: [],
            processedFiles: 1,
        );

        $this->assertTrue($result->isSuccessful());
    }

    public function testIsSuccessfulWithErrors(): void
    {
        $result = new RectorOutputResult(
            findings: [],
            errors: ['Parse error'],
            processedFiles: 0,
        );

        $this->assertFalse($result->isSuccessful());
    }

    public function testIsSuccessfulWithMultipleErrors(): void
    {
        $result = new RectorOutputResult(
            findings: [
                new RectorFinding(
                    file: 'src/Test.php',
                    line: 10,
                    ruleClass: 'TestRule',
                    message: 'Test message',
                    severity: RectorRuleSeverity::INFO,
                    changeType: RectorChangeType::BEST_PRACTICE,
                ),
            ],
            errors: ['Error 1', 'Error 2', 'Error 3'],
            processedFiles: 1,
        );

        $this->assertFalse($result->isSuccessful());
    }

    public function testHasFindingsWithNoFindings(): void
    {
        $result = new RectorOutputResult(
            findings: [],
            errors: [],
            processedFiles: 0,
        );

        $this->assertFalse($result->hasFindings());
    }

    public function testHasFindingsWithSingleFinding(): void
    {
        $result = new RectorOutputResult(
            findings: [
                new RectorFinding(
                    file: 'src/Test.php',
                    line: 10,
                    ruleClass: 'TestRule',
                    message: 'Test message',
                    severity: RectorRuleSeverity::INFO,
                    changeType: RectorChangeType::BEST_PRACTICE,
                ),
            ],
            errors: [],
            processedFiles: 1,
        );

        $this->assertTrue($result->hasFindings());
    }

    public function testHasFindingsWithMultipleFindings(): void
    {
        $findings = [
            new RectorFinding(
                file: 'src/Test1.php',
                line: 10,
                ruleClass: 'TestRule1',
                message: 'Test message 1',
                severity: RectorRuleSeverity::WARNING,
                changeType: RectorChangeType::DEPRECATION,
            ),
            new RectorFinding(
                file: 'src/Test2.php',
                line: 20,
                ruleClass: 'TestRule2',
                message: 'Test message 2',
                severity: RectorRuleSeverity::CRITICAL,
                changeType: RectorChangeType::BREAKING_CHANGE,
            ),
        ];

        $result = new RectorOutputResult(
            findings: $findings,
            errors: [],
            processedFiles: 2,
        );

        $this->assertTrue($result->hasFindings());
    }

    public function testReadOnlyPropertiesCannotBeModified(): void
    {
        $originalFindings = [
            new RectorFinding(
                file: 'src/Test.php',
                line: 10,
                ruleClass: 'TestRule',
                message: 'Test message',
                severity: RectorRuleSeverity::INFO,
                changeType: RectorChangeType::BEST_PRACTICE,
            ),
        ];

        $result = new RectorOutputResult(
            findings: $originalFindings,
            errors: ['Original error'],
            processedFiles: 1,
        );

        // Verify that the properties return the original values
        $this->assertEquals($originalFindings, $result->findings);
        $this->assertEquals(['Original error'], $result->errors);
        $this->assertEquals(1, $result->processedFiles);

        // The readonly properties should prevent modification
        // (This is enforced at the language level in PHP 8.1+)
        $this->assertSame($originalFindings, $result->findings);
    }

    public function testResultWithMixedSeverityFindings(): void
    {
        $findings = [
            new RectorFinding(
                file: 'src/Critical.php',
                line: 1,
                ruleClass: 'CriticalRule',
                message: 'Critical issue',
                severity: RectorRuleSeverity::CRITICAL,
                changeType: RectorChangeType::BREAKING_CHANGE,
            ),
            new RectorFinding(
                file: 'src/Warning.php',
                line: 2,
                ruleClass: 'WarningRule',
                message: 'Warning issue',
                severity: RectorRuleSeverity::WARNING,
                changeType: RectorChangeType::DEPRECATION,
            ),
            new RectorFinding(
                file: 'src/Info.php',
                line: 3,
                ruleClass: 'InfoRule',
                message: 'Info issue',
                severity: RectorRuleSeverity::INFO,
                changeType: RectorChangeType::BEST_PRACTICE,
            ),
            new RectorFinding(
                file: 'src/Suggestion.php',
                line: 4,
                ruleClass: 'SuggestionRule',
                message: 'Suggestion issue',
                severity: RectorRuleSeverity::SUGGESTION,
                changeType: RectorChangeType::CODE_STYLE,
            ),
        ];

        $result = new RectorOutputResult(
            findings: $findings,
            errors: [],
            processedFiles: 4,
        );

        $this->assertEquals(4, $result->getFindingsCount());
        $this->assertTrue($result->hasFindings());
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals(0, $result->getErrorsCount());
    }

    public function testResultWithMixedFindingsAndErrors(): void
    {
        $findings = [
            new RectorFinding(
                file: 'src/Test.php',
                line: 10,
                ruleClass: 'TestRule',
                message: 'Test message',
                severity: RectorRuleSeverity::WARNING,
                changeType: RectorChangeType::DEPRECATION,
            ),
        ];

        $errors = [
            'Parser failed on file X',
            'Rule Y threw exception',
        ];

        $result = new RectorOutputResult(
            findings: $findings,
            errors: $errors,
            processedFiles: 3,
        );

        $this->assertEquals(1, $result->getFindingsCount());
        $this->assertEquals(2, $result->getErrorsCount());
        $this->assertTrue($result->hasFindings());
        $this->assertFalse($result->isSuccessful());
        $this->assertEquals(3, $result->processedFiles);
    }
}
