<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Discovery;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Installation;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ValidationIssue;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ValidationRuleInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ValidationSeverity;
use PHPUnit\Framework\TestCase;

/**
 * Tests the ValidationRuleInterface contract with a mock implementation.
 *
 * @coversNothing
 */
final class ValidationRuleInterfaceTest extends TestCase
{
    private Installation $testInstallation;

    protected function setUp(): void
    {
        $this->testInstallation = new Installation(
            '/test/installation',
            new Version('12.4.0'),
            'composer',
        );
    }

    public function testInterfaceContractsWorkCorrectly(): void
    {
        $rule = new TestValidationRule();

        // Test interface method contracts
        self::assertIsString($rule->getName());
        self::assertIsString($rule->getDescription());
        self::assertIsString($rule->getCategory());
        self::assertInstanceOf(ValidationSeverity::class, $rule->getSeverity());

        // Test appliesTo method
        self::assertIsBool($rule->appliesTo($this->testInstallation));

        // Test validate method returns array of ValidationIssue objects
        $issues = $rule->validate($this->testInstallation);
        self::assertIsArray($issues);

        foreach ($issues as $issue) {
            self::assertInstanceOf(ValidationIssue::class, $issue);
        }
    }

    public function testValidationWithApplicableRule(): void
    {
        $rule = new TestValidationRule(true, true); // applies to installation, has issues

        self::assertTrue($rule->appliesTo($this->testInstallation));

        $issues = $rule->validate($this->testInstallation);
        self::assertNotEmpty($issues);
        self::assertCount(1, $issues);

        $issue = $issues[0];
        self::assertSame($rule->getName(), $issue->getRuleName());
        self::assertSame($rule->getSeverity(), $issue->getSeverity());
        self::assertSame($rule->getCategory(), $issue->getCategory());
    }

    public function testValidationWithNonApplicableRule(): void
    {
        $rule = new TestValidationRule(false, true); // doesn't apply to installation

        self::assertFalse($rule->appliesTo($this->testInstallation));

        // Rule should still be able to validate, but may return empty array
        $issues = $rule->validate($this->testInstallation);
        self::assertIsArray($issues);
    }

    public function testValidationWithNoIssues(): void
    {
        $rule = new TestValidationRule(true, false); // applies but no issues

        self::assertTrue($rule->appliesTo($this->testInstallation));

        $issues = $rule->validate($this->testInstallation);
        self::assertEmpty($issues);
    }

    public function testRuleMetadataConsistency(): void
    {
        $rule = new TestValidationRule();

        // Rule metadata should be consistent
        self::assertNotEmpty($rule->getName());
        self::assertNotEmpty($rule->getDescription());
        self::assertNotEmpty($rule->getCategory());

        // Category should be a reasonable validation category
        $category = $rule->getCategory();
        $validCategories = ['structure', 'permissions', 'integrity', 'performance', 'configuration', 'test'];
        self::assertContains($category, $validCategories);
    }

    public function testMultipleRulesWithDifferentSeverities(): void
    {
        $infoRule = new TestValidationRule(true, true, ValidationSeverity::INFO);
        $warningRule = new TestValidationRule(true, true, ValidationSeverity::WARNING);
        $errorRule = new TestValidationRule(true, true, ValidationSeverity::ERROR);
        $criticalRule = new TestValidationRule(true, true, ValidationSeverity::CRITICAL);

        $rules = [$infoRule, $warningRule, $errorRule, $criticalRule];

        foreach ($rules as $rule) {
            $issues = $rule->validate($this->testInstallation);
            self::assertNotEmpty($issues);

            $issue = $issues[0];
            self::assertSame($rule->getSeverity(), $issue->getSeverity());
        }

        // Rules can be sorted by severity
        usort($rules, fn ($a, $b): int => $b->getSeverity()->getNumericValue() <=> $a->getSeverity()->getNumericValue());

        self::assertSame(ValidationSeverity::CRITICAL, $rules[0]->getSeverity());
        self::assertSame(ValidationSeverity::ERROR, $rules[1]->getSeverity());
        self::assertSame(ValidationSeverity::WARNING, $rules[2]->getSeverity());
        self::assertSame(ValidationSeverity::INFO, $rules[3]->getSeverity());
    }

    public function testRulesCanBeGroupedByCategory(): void
    {
        $structureRule = new TestValidationRule(true, false, ValidationSeverity::ERROR, 'structure');
        $permissionRule = new TestValidationRule(true, false, ValidationSeverity::WARNING, 'permissions');
        $integrityRule = new TestValidationRule(true, false, ValidationSeverity::CRITICAL, 'integrity');

        $rules = [$structureRule, $permissionRule, $integrityRule];

        // Group rules by category
        $groupedRules = [];
        foreach ($rules as $rule) {
            $groupedRules[$rule->getCategory()][] = $rule;
        }

        self::assertArrayHasKey('structure', $groupedRules);
        self::assertArrayHasKey('permissions', $groupedRules);
        self::assertArrayHasKey('integrity', $groupedRules);

        self::assertCount(1, $groupedRules['structure']);
        self::assertCount(1, $groupedRules['permissions']);
        self::assertCount(1, $groupedRules['integrity']);
    }

    public function testValidationIssuesAreWellFormed(): void
    {
        $rule = new TestValidationRule(true, true);
        $issues = $rule->validate($this->testInstallation);

        self::assertNotEmpty($issues);

        foreach ($issues as $issue) {
            // Issue should have proper structure
            self::assertNotEmpty($issue->getRuleName());
            self::assertNotEmpty($issue->getMessage());
            self::assertNotEmpty($issue->getCategory());
            self::assertInstanceOf(ValidationSeverity::class, $issue->getSeverity());

            // Arrays should be arrays
            self::assertIsArray($issue->getContext());
            self::assertIsArray($issue->getAffectedPaths());
            self::assertIsArray($issue->getRecommendations());
        }
    }
}

/**
 * Test implementation of ValidationRuleInterface for testing the interface contract.
 */
class TestValidationRule implements ValidationRuleInterface
{
    public function __construct(
        private readonly bool $applies = true,
        private readonly bool $hasIssues = false,
        private readonly ValidationSeverity $severity = ValidationSeverity::WARNING,
        private readonly string $category = 'test',
    ) {
    }

    public function validate(Installation $installation): array
    {
        if (!$this->hasIssues) {
            return [];
        }

        return [
            new ValidationIssue(
                $this->getName(),
                $this->severity,
                'Test validation issue found',
                $this->category,
                ['installation_path' => $installation->getPath()],
                [$installation->getPath()],
                ['Fix the test issue'],
            ),
        ];
    }

    public function getName(): string
    {
        return 'test_validation_rule';
    }

    public function getSeverity(): ValidationSeverity
    {
        return $this->severity;
    }

    public function getDescription(): string
    {
        return 'Test validation rule for unit testing the ValidationRuleInterface';
    }

    public function appliesTo(Installation $installation): bool
    {
        return $this->applies;
    }

    public function getCategory(): string
    {
        return $this->category;
    }
}
