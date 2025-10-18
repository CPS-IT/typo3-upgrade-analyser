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

use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Shared\AnalyzerFindingHelperInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Shared\AnalyzerFindingHelperTrait;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Shared\AnalyzerFindingInterface;
use PHPUnit\Framework\TestCase;

/**
 * Test case for the AnalyzerFindingHelperTrait.
 */
class AnalyzerFindingHelperTraitTest extends TestCase
{
    private TestAnalyzerFinding $finding;

    protected function setUp(): void
    {
        $this->finding = new TestAnalyzerFinding(
            file: '/path/to/file.php',
            line: 42,
            ruleClass: 'Some\\Rector\\Rule\\TestRule',
            ruleName: 'Test Rule',
            message: 'Test finding message',
            severityValue: 'warning',
            priorityScore: 0.8,
            estimatedEffort: 15,
            isBreakingChange: false,
            isDeprecation: true,
            isImprovement: false,
        );
    }

    public function testGetBasePriorityScore(): void
    {
        // Test the base priority calculation with default values
        $score = $this->finding->getBasePriorityScore();

        self::assertIsFloat($score);
        self::assertGreaterThan(0.0, $score);
        self::assertLessThanOrEqual(1.0, $score);
    }

    public function testGetBasePriorityScoreForCriticalSeverity(): void
    {
        $criticalFinding = new TestAnalyzerFinding(
            file: '/path/to/file.php',
            line: 42,
            ruleClass: 'Some\\Rule',
            ruleName: 'Critical Rule',
            message: 'Critical issue',
            severityValue: 'critical',
            priorityScore: 1.0,
            estimatedEffort: 5,
            isBreakingChange: true,
            isDeprecation: false,
            isImprovement: false,
        );

        $score = $criticalFinding->getBasePriorityScore();

        // Critical severity should get the highest weight (1.0)
        self::assertGreaterThan(0.8, $score);
    }

    public function testGetBasePriorityScoreForHighEffort(): void
    {
        $highEffortFinding = new TestAnalyzerFinding(
            file: '/path/to/file.php',
            line: 42,
            ruleClass: 'Some\\Rule',
            ruleName: 'High Effort Rule',
            message: 'High effort issue',
            severityValue: 'warning',
            priorityScore: 0.5,
            estimatedEffort: 120, // 2 hours
            isBreakingChange: false,
            isDeprecation: false,
            isImprovement: false,
        );

        $lowEffortFinding = new TestAnalyzerFinding(
            file: '/path/to/file.php',
            line: 42,
            ruleClass: 'Some\\Rule',
            ruleName: 'Low Effort Rule',
            message: 'Low effort issue',
            severityValue: 'warning',
            priorityScore: 0.5,
            estimatedEffort: 5,
            isBreakingChange: false,
            isDeprecation: false,
            isImprovement: false,
        );

        $highEffortScore = $highEffortFinding->getBasePriorityScore();
        $lowEffortScore = $lowEffortFinding->getBasePriorityScore();

        // Lower effort should result in higher priority
        self::assertLessThan($lowEffortScore, $highEffortScore);
    }

    public function testGetFileLocation(): void
    {
        $location = $this->finding->getFileLocation();

        self::assertSame('file.php:42', $location);
    }

    public function testRequiresImmediateActionForCriticalSeverity(): void
    {
        $criticalFinding = new TestAnalyzerFinding(
            file: '/path/to/file.php',
            line: 42,
            ruleClass: 'Some\\Rule',
            ruleName: 'Critical Rule',
            message: 'Critical issue',
            severityValue: 'critical',
            priorityScore: 1.0,
            estimatedEffort: 5,
            isBreakingChange: false,
            isDeprecation: false,
            isImprovement: false,
        );

        self::assertTrue($criticalFinding->requiresImmediateAction());
    }

    public function testRequiresImmediateActionForBreakingChange(): void
    {
        $breakingFinding = new TestAnalyzerFinding(
            file: '/path/to/file.php',
            line: 42,
            ruleClass: 'Some\\Rule',
            ruleName: 'Breaking Rule',
            message: 'Breaking change',
            severityValue: 'warning',
            priorityScore: 0.8,
            estimatedEffort: 10,
            isBreakingChange: true,
            isDeprecation: false,
            isImprovement: false,
        );

        self::assertTrue($breakingFinding->requiresImmediateAction());
    }

    public function testRequiresImmediateActionForNormalFinding(): void
    {
        self::assertFalse($this->finding->requiresImmediateAction());
    }

    public function testGetAnalyzerTypeForRectorRule(): void
    {
        $rectorFinding = new TestAnalyzerFinding(
            file: '/path/to/file.php',
            line: 42,
            ruleClass: 'Rector\\Typo3\\Rule\\SomeRule',
            ruleName: 'Rector Rule',
            message: 'Rector issue',
            severityValue: 'warning',
            priorityScore: 0.5,
            estimatedEffort: 10,
            isBreakingChange: false,
            isDeprecation: false,
            isImprovement: false,
        );

        self::assertSame('rector', $rectorFinding->getAnalyzerType());
    }

    public function testGetAnalyzerTypeForFractorRule(): void
    {
        $fractorFinding = new TestAnalyzerFinding(
            file: '/path/to/file.php',
            line: 42,
            ruleClass: 'Fractor\\Typo3\\Rule\\SomeRule',
            ruleName: 'Fractor Rule',
            message: 'Fractor issue',
            severityValue: 'warning',
            priorityScore: 0.5,
            estimatedEffort: 10,
            isBreakingChange: false,
            isDeprecation: false,
            isImprovement: false,
        );

        self::assertSame('fractor', $fractorFinding->getAnalyzerType());
    }

    public function testGetAnalyzerTypeForUnknownRule(): void
    {
        $unknownFinding = new TestAnalyzerFinding(
            file: '/path/to/file.php',
            line: 42,
            ruleClass: 'SomeOther\\Unknown\\Rule',
            ruleName: 'Unknown Rule',
            message: 'Unknown issue',
            severityValue: 'warning',
            priorityScore: 0.5,
            estimatedEffort: 10,
            isBreakingChange: false,
            isDeprecation: false,
            isImprovement: false,
        );

        self::assertSame('unknown', $unknownFinding->getAnalyzerType());
    }

    public function testComparePriority(): void
    {
        $highPriorityFinding = new TestAnalyzerFinding(
            file: '/path/to/file.php',
            line: 42,
            ruleClass: 'Some\\Rule',
            ruleName: 'High Priority',
            message: 'High priority issue',
            severityValue: 'critical',
            priorityScore: 0.9,
            estimatedEffort: 5,
            isBreakingChange: false,
            isDeprecation: false,
            isImprovement: false,
        );

        $lowPriorityFinding = new TestAnalyzerFinding(
            file: '/path/to/file.php',
            line: 42,
            ruleClass: 'Some\\Rule',
            ruleName: 'Low Priority',
            message: 'Low priority issue',
            severityValue: 'info',
            priorityScore: 0.3,
            estimatedEffort: 5,
            isBreakingChange: false,
            isDeprecation: false,
            isImprovement: false,
        );

        // comparePriority should return negative when this has higher priority than other
        $result = $highPriorityFinding->comparePriority($lowPriorityFinding);
        self::assertLessThan(0, $result);

        // And positive when this has lower priority than other
        $result = $lowPriorityFinding->comparePriority($highPriorityFinding);
        self::assertGreaterThan(0, $result);

        // And zero when priorities are equal
        $result = $this->finding->comparePriority($this->finding);
        self::assertSame(0, $result);
    }

    public function testGetSeverityDisplay(): void
    {
        self::assertSame('Warning', $this->finding->getSeverityDisplay());

        $criticalFinding = new TestAnalyzerFinding(
            file: '/path/to/file.php',
            line: 42,
            ruleClass: 'Some\\Rule',
            ruleName: 'Critical Rule',
            message: 'Critical issue',
            severityValue: 'critical',
            priorityScore: 1.0,
            estimatedEffort: 5,
            isBreakingChange: false,
            isDeprecation: false,
            isImprovement: false,
        );

        self::assertSame('Critical', $criticalFinding->getSeverityDisplay());
    }

    public function testGetEffortCategory(): void
    {
        // Test with 15 minutes - should be "Medium Effort"
        self::assertSame('Medium Effort', $this->finding->getEffortCategory());

        // Test with 5 minutes - should be "Quick Fix"
        $quickFinding = new TestAnalyzerFinding(
            file: '/path/to/file.php',
            line: 42,
            ruleClass: 'Some\\Rule',
            ruleName: 'Quick Rule',
            message: 'Quick issue',
            severityValue: 'info',
            priorityScore: 0.3,
            estimatedEffort: 5,
            isBreakingChange: false,
            isDeprecation: false,
            isImprovement: false,
        );

        self::assertSame('Quick Fix', $quickFinding->getEffortCategory());

        // Test with 25 minutes - should be "High Effort"
        $highEffortFinding = new TestAnalyzerFinding(
            file: '/path/to/file.php',
            line: 42,
            ruleClass: 'Some\\Rule',
            ruleName: 'High Effort Rule',
            message: 'High effort issue',
            severityValue: 'warning',
            priorityScore: 0.5,
            estimatedEffort: 25,
            isBreakingChange: false,
            isDeprecation: false,
            isImprovement: false,
        );

        self::assertSame('High Effort', $highEffortFinding->getEffortCategory());

        // Test with 60 minutes - should be "Complex Fix"
        $complexFinding = new TestAnalyzerFinding(
            file: '/path/to/file.php',
            line: 42,
            ruleClass: 'Some\\Rule',
            ruleName: 'Complex Rule',
            message: 'Complex issue',
            severityValue: 'critical',
            priorityScore: 1.0,
            estimatedEffort: 60,
            isBreakingChange: true,
            isDeprecation: false,
            isImprovement: false,
        );

        self::assertSame('Complex Fix', $complexFinding->getEffortCategory());
    }

    public function testGetBaseArrayData(): void
    {
        $data = $this->finding->getBaseArrayDataPublic();

        self::assertIsArray($data);

        // Verify core interface fields are present
        self::assertSame('/path/to/file.php', $data['file']);
        self::assertSame(42, $data['line']);
        self::assertSame('Some\\Rector\\Rule\\TestRule', $data['rule_class']);
        self::assertSame('Test Rule', $data['rule_name']);
        self::assertSame('Test finding message', $data['message']);
        self::assertSame('warning', $data['severity']);
        self::assertSame(0.8, $data['priority_score']);
        self::assertSame(15, $data['estimated_effort']);

        // Verify helper fields are present
        self::assertSame('file.php:42', $data['file_location']);
        self::assertSame('Warning', $data['severity_display']);
        self::assertSame('Medium Effort', $data['effort_category']);
        self::assertSame('rector', $data['analyzer_type']);
        self::assertFalse($data['requires_immediate_action']);

        // Verify interface boolean fields
        self::assertFalse($data['is_breaking_change']);
        self::assertTrue($data['is_deprecation']);
        self::assertFalse($data['is_improvement']);
    }
}

/**
 * Test implementation of AnalyzerFindingInterface for testing the trait.
 */
class TestAnalyzerFinding implements AnalyzerFindingInterface, AnalyzerFindingHelperInterface
{
    use AnalyzerFindingHelperTrait;

    public function __construct(
        private readonly string $file,
        private readonly int $line,
        private readonly string $ruleClass,
        private readonly string $ruleName,
        private readonly string $message,
        private readonly string $severityValue,
        private readonly float $priorityScore,
        private readonly int $estimatedEffort,
        private readonly bool $isBreakingChange,
        private readonly bool $isDeprecation,
        private readonly bool $isImprovement,
    ) {
    }

    public function getFile(): string
    {
        return $this->file;
    }

    public function getLine(): int
    {
        return $this->line;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getSeverityValue(): string
    {
        return $this->severityValue;
    }

    public function getPriorityScore(): float
    {
        return $this->priorityScore;
    }

    public function getRuleClass(): string
    {
        return $this->ruleClass;
    }

    public function getRuleName(): string
    {
        return $this->ruleName;
    }

    public function getEstimatedEffort(): int
    {
        return $this->estimatedEffort;
    }

    public function isBreakingChange(): bool
    {
        return $this->isBreakingChange;
    }

    public function isDeprecation(): bool
    {
        return $this->isDeprecation;
    }

    public function isImprovement(): bool
    {
        return $this->isImprovement;
    }

    public function hasCodeChange(): bool
    {
        // For testing purposes, return a fixed value
        return true;
    }

    public function hasDocumentation(): bool
    {
        // For testing purposes, return a fixed value
        return false;
    }

    public function toArray(): array
    {
        return $this->getBaseArrayData();
    }

    /**
     * Public wrapper to test protected getBaseArrayData method.
     */
    public function getBaseArrayDataPublic(): array
    {
        return $this->getBaseArrayData();
    }
}
