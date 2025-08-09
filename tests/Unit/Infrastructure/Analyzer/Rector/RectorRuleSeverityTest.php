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

use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorRuleSeverity;
use PHPUnit\Framework\TestCase;

/**
 * Test case for RectorRuleSeverity enum.
 */
class RectorRuleSeverityTest extends TestCase
{
    public function testCriticalSeverityProperties(): void
    {
        $severity = RectorRuleSeverity::CRITICAL;

        $this->assertEquals('critical', $severity->value);
        $this->assertEquals(1.0, $severity->getRiskWeight());
        $this->assertEquals('Critical', $severity->getDisplayName());
        $this->assertStringContainsString('Breaking changes', $severity->getDescription());
    }

    public function testWarningSeverityProperties(): void
    {
        $severity = RectorRuleSeverity::WARNING;

        $this->assertEquals('warning', $severity->value);
        $this->assertEquals(0.6, $severity->getRiskWeight());
        $this->assertEquals('Warning', $severity->getDisplayName());
        $this->assertStringContainsString('Deprecated', $severity->getDescription());
    }

    public function testInfoSeverityProperties(): void
    {
        $severity = RectorRuleSeverity::INFO;

        $this->assertEquals('info', $severity->value);
        $this->assertEquals(0.2, $severity->getRiskWeight());
        $this->assertEquals('Info', $severity->getDisplayName());
        $this->assertStringContainsString('improvements', $severity->getDescription());
    }

    public function testSuggestionSeverityProperties(): void
    {
        $severity = RectorRuleSeverity::SUGGESTION;

        $this->assertEquals('suggestion', $severity->value);
        $this->assertEquals(0.1, $severity->getRiskWeight());
        $this->assertEquals('Suggestion', $severity->getDisplayName());
        $this->assertStringContainsString('optimizations', $severity->getDescription());
    }

    public function testRiskWeightOrdering(): void
    {
        $severities = [
            RectorRuleSeverity::CRITICAL,
            RectorRuleSeverity::WARNING,
            RectorRuleSeverity::INFO,
            RectorRuleSeverity::SUGGESTION,
        ];

        $weights = array_map(fn ($s): float => $s->getRiskWeight(), $severities);
        $sortedWeights = $weights;
        rsort($sortedWeights);

        $this->assertEquals($sortedWeights, $weights, 'Risk weights should be in descending order');
    }

    public function testAllSeveritiesHaveValidProperties(): void
    {
        foreach (RectorRuleSeverity::cases() as $severity) {
            $this->assertIsFloat($severity->getRiskWeight());
            $this->assertGreaterThan(0, $severity->getRiskWeight());
            $this->assertLessThanOrEqual(1.0, $severity->getRiskWeight());

            $this->assertIsString($severity->getDisplayName());
            $this->assertNotEmpty($severity->getDisplayName());

            $this->assertIsString($severity->getDescription());
            $this->assertNotEmpty($severity->getDescription());
        }
    }
}
