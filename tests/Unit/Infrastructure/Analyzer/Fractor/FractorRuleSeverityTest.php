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

use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorRuleSeverity;
use PHPUnit\Framework\TestCase;

/**
 * Test case for FractorRuleSeverity enum.
 */
class FractorRuleSeverityTest extends TestCase
{
    public function testAllEnumCasesExist(): void
    {
        $expectedCases = ['CRITICAL', 'WARNING', 'INFO', 'SUGGESTION'];
        $actualCases = array_map(static fn (FractorRuleSeverity $case): string => $case->name, FractorRuleSeverity::cases());

        $this->assertEquals($expectedCases, $actualCases);
        $this->assertCount(4, FractorRuleSeverity::cases());
    }

    public function testEnumValues(): void
    {
        $this->assertEquals('critical', FractorRuleSeverity::CRITICAL->value);
        $this->assertEquals('warning', FractorRuleSeverity::WARNING->value);
        $this->assertEquals('info', FractorRuleSeverity::INFO->value);
        $this->assertEquals('suggestion', FractorRuleSeverity::SUGGESTION->value);
    }

    public function testGetRiskWeight(): void
    {
        $this->assertEquals(1.0, FractorRuleSeverity::CRITICAL->getRiskWeight());
        $this->assertEquals(0.6, FractorRuleSeverity::WARNING->getRiskWeight());
        $this->assertEquals(0.2, FractorRuleSeverity::INFO->getRiskWeight());
        $this->assertEquals(0.1, FractorRuleSeverity::SUGGESTION->getRiskWeight());
    }

    public function testGetDisplayName(): void
    {
        $this->assertEquals('Critical', FractorRuleSeverity::CRITICAL->getDisplayName());
        $this->assertEquals('Warning', FractorRuleSeverity::WARNING->getDisplayName());
        $this->assertEquals('Info', FractorRuleSeverity::INFO->getDisplayName());
        $this->assertEquals('Suggestion', FractorRuleSeverity::SUGGESTION->getDisplayName());
    }

    public function testGetDescription(): void
    {
        $criticalDesc = FractorRuleSeverity::CRITICAL->getDescription();
        $warningDesc = FractorRuleSeverity::WARNING->getDescription();
        $infoDesc = FractorRuleSeverity::INFO->getDescription();
        $suggestionDesc = FractorRuleSeverity::SUGGESTION->getDescription();

        $this->assertStringContainsString('Breaking changes', $criticalDesc);
        $this->assertStringContainsString('must be fixed', $criticalDesc);
        $this->assertStringContainsString('before upgrade', $criticalDesc);

        $this->assertStringContainsString('Deprecated patterns', $warningDesc);
        $this->assertStringContainsString('should be updated', $warningDesc);

        $this->assertStringContainsString('Code modernization', $infoDesc);
        $this->assertStringContainsString('improvements', $infoDesc);

        $this->assertStringContainsString('Optional', $suggestionDesc);
        $this->assertStringContainsString('quality enhancements', $suggestionDesc);

        // Ensure descriptions are not empty
        $this->assertNotEmpty($criticalDesc);
        $this->assertNotEmpty($warningDesc);
        $this->assertNotEmpty($infoDesc);
        $this->assertNotEmpty($suggestionDesc);
    }

    public function testRequiresImmediateAction(): void
    {
        $this->assertTrue(FractorRuleSeverity::CRITICAL->requiresImmediateAction());
        $this->assertFalse(FractorRuleSeverity::WARNING->requiresImmediateAction());
        $this->assertFalse(FractorRuleSeverity::INFO->requiresImmediateAction());
        $this->assertFalse(FractorRuleSeverity::SUGGESTION->requiresImmediateAction());
    }

    public function testIsDeprecation(): void
    {
        $this->assertFalse(FractorRuleSeverity::CRITICAL->isDeprecation());
        $this->assertTrue(FractorRuleSeverity::WARNING->isDeprecation());
        $this->assertFalse(FractorRuleSeverity::INFO->isDeprecation());
        $this->assertFalse(FractorRuleSeverity::SUGGESTION->isDeprecation());
    }

    public function testRiskWeightOrdering(): void
    {
        $severities = [
            FractorRuleSeverity::CRITICAL,
            FractorRuleSeverity::WARNING,
            FractorRuleSeverity::INFO,
            FractorRuleSeverity::SUGGESTION,
        ];

        $weights = array_map(static fn (FractorRuleSeverity $s): float => $s->getRiskWeight(), $severities);
        $sortedWeights = $weights;
        rsort($sortedWeights);

        $this->assertEquals($sortedWeights, $weights, 'Risk weights should be in descending order');
    }

    public function testAllSeveritiesHaveValidRiskWeights(): void
    {
        foreach (FractorRuleSeverity::cases() as $severity) {
            $weight = $severity->getRiskWeight();
            $this->assertGreaterThan(0, $weight, "Risk weight for {$severity->name} should be greater than 0");
            $this->assertLessThanOrEqual(1.0, $weight, "Risk weight for {$severity->name} should be <= 1.0");
            $this->assertIsFloat($weight, "Risk weight for {$severity->name} should be a float");
        }
    }

    public function testAllSeveritiesHaveValidDisplayNames(): void
    {
        foreach (FractorRuleSeverity::cases() as $severity) {
            $displayName = $severity->getDisplayName();
            $this->assertNotEmpty($displayName, "Display name for {$severity->name} should not be empty");
            $this->assertIsString($displayName, "Display name for {$severity->name} should be a string");
            // Display names should be capitalized
            $this->assertEquals(ucfirst(strtolower($severity->value)), $displayName, 'Display name should be properly capitalized');
        }
    }

    public function testAllSeveritiesHaveValidDescriptions(): void
    {
        foreach (FractorRuleSeverity::cases() as $severity) {
            $description = $severity->getDescription();
            $this->assertNotEmpty($description, "Description for {$severity->name} should not be empty");
            $this->assertIsString($description, "Description for {$severity->name} should be a string");
            $this->assertGreaterThan(10, \strlen($description), "Description for {$severity->name} should be meaningful");
        }
    }

    public function testBooleanMethodsConsistency(): void
    {
        // Only CRITICAL should require immediate action
        $immediateActionCases = array_filter(
            FractorRuleSeverity::cases(),
            static fn (FractorRuleSeverity $s): bool => $s->requiresImmediateAction(),
        );
        $this->assertCount(1, $immediateActionCases);
        $this->assertTrue(FractorRuleSeverity::CRITICAL->requiresImmediateAction());

        // Only WARNING should be deprecation
        $deprecationCases = array_filter(
            FractorRuleSeverity::cases(),
            static fn (FractorRuleSeverity $s): bool => $s->isDeprecation(),
        );
        $this->assertCount(1, $deprecationCases);
        $this->assertTrue(FractorRuleSeverity::WARNING->isDeprecation());
    }

    public function testRiskWeightDistribution(): void
    {
        $weights = array_map(
            static fn (FractorRuleSeverity $s): float => $s->getRiskWeight(),
            FractorRuleSeverity::cases(),
        );

        // Test that weights are distributed properly
        $this->assertGreaterThan(0.9, max($weights), 'Maximum weight should be close to 1.0');
        $this->assertLessThan(0.2, min($weights), 'Minimum weight should be small but positive');

        // Test no duplicate weights
        $this->assertEquals(\count($weights), \count(array_unique($weights, SORT_NUMERIC)), 'All risk weights should be unique');
    }
}
