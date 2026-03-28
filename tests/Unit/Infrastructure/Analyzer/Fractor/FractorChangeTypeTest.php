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
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorRuleSeverity;
use PHPUnit\Framework\TestCase;

/**
 * Test case for FractorChangeType enum.
 */
class FractorChangeTypeTest extends TestCase
{
    public function testBreakingChangeProperties(): void
    {
        $changeType = FractorChangeType::BREAKING_CHANGE;

        $this->assertEquals('breaking_change', $changeType->value);
        $this->assertEquals('Breaking Changes', $changeType->getCategory());
        $this->assertEquals(60, $changeType->getEstimatedEffort());
        $this->assertTrue($changeType->requiresManualIntervention());
        $this->assertEquals('Breaking Change', $changeType->getDisplayName());
        $this->assertEquals(FractorRuleSeverity::CRITICAL, $changeType->getSeverity());
        $this->assertEquals('#dc3545', $changeType->getColorCode());
    }

    public function testDeprecationProperties(): void
    {
        $changeType = FractorChangeType::DEPRECATION;

        $this->assertEquals('deprecation', $changeType->value);
        $this->assertEquals('Deprecations', $changeType->getCategory());
        $this->assertEquals(10, $changeType->getEstimatedEffort());
        $this->assertFalse($changeType->requiresManualIntervention());
        $this->assertEquals('Deprecation', $changeType->getDisplayName());
        $this->assertEquals(FractorRuleSeverity::WARNING, $changeType->getSeverity());
        $this->assertEquals('#ffc107', $changeType->getColorCode());
    }

    public function testConfigurationChangeProperties(): void
    {
        $changeType = FractorChangeType::CONFIGURATION_CHANGE;

        $this->assertEquals('configuration_change', $changeType->value);
        $this->assertEquals('Configuration', $changeType->getCategory());
        $this->assertEquals(15, $changeType->getEstimatedEffort());
        $this->assertTrue($changeType->requiresManualIntervention());
        $this->assertEquals('Configuration Change', $changeType->getDisplayName());
        $this->assertEquals(FractorRuleSeverity::INFO, $changeType->getSeverity());
        $this->assertEquals('#17a2b8', $changeType->getColorCode());
    }

    public function testBestPracticeProperties(): void
    {
        $changeType = FractorChangeType::BEST_PRACTICE;

        $this->assertEquals('best_practice', $changeType->value);
        $this->assertEquals('Code Quality', $changeType->getCategory());
        $this->assertEquals(8, $changeType->getEstimatedEffort());
        $this->assertFalse($changeType->requiresManualIntervention());
        $this->assertEquals('Best Practice', $changeType->getDisplayName());
        $this->assertEquals(FractorRuleSeverity::SUGGESTION, $changeType->getSeverity());
        $this->assertEquals('#28a745', $changeType->getColorCode());
    }

    public function testPerformanceProperties(): void
    {
        $changeType = FractorChangeType::PERFORMANCE;

        $this->assertEquals('performance', $changeType->value);
        $this->assertEquals('Performance', $changeType->getCategory());
        $this->assertEquals(12, $changeType->getEstimatedEffort());
        $this->assertFalse($changeType->requiresManualIntervention());
        $this->assertEquals('Performance Optimization', $changeType->getDisplayName());
        $this->assertEquals(FractorRuleSeverity::SUGGESTION, $changeType->getSeverity());
        $this->assertEquals('#28a745', $changeType->getColorCode());
    }

    public function testSecurityProperties(): void
    {
        $changeType = FractorChangeType::SECURITY;

        $this->assertEquals('security', $changeType->value);
        $this->assertEquals('Security', $changeType->getCategory());
        $this->assertEquals(25, $changeType->getEstimatedEffort());
        $this->assertFalse($changeType->requiresManualIntervention());
        $this->assertEquals('Security Improvement', $changeType->getDisplayName());
        $this->assertEquals(FractorRuleSeverity::INFO, $changeType->getSeverity());
        $this->assertEquals('#dc3545', $changeType->getColorCode());
    }

    public function testCodeStyleProperties(): void
    {
        $changeType = FractorChangeType::CODE_STYLE;

        $this->assertEquals('code_style', $changeType->value);
        $this->assertEquals('Code Quality', $changeType->getCategory());
        $this->assertEquals(3, $changeType->getEstimatedEffort());
        $this->assertFalse($changeType->requiresManualIntervention());
        $this->assertEquals('Code Style', $changeType->getDisplayName());
        $this->assertEquals(FractorRuleSeverity::SUGGESTION, $changeType->getSeverity());
        $this->assertEquals('#28a745', $changeType->getColorCode());
    }

    public function testEstimatedEffortRanges(): void
    {
        foreach (FractorChangeType::cases() as $changeType) {
            $effort = $changeType->getEstimatedEffort();
            $this->assertGreaterThan(0, $effort, "Effort should be positive for {$changeType->value}");
            // Some efforts (like BREAKING_CHANGE) are 60, so use LessThanOrEqual
            $this->assertLessThanOrEqual(60, $effort, "Effort should not exceed 60 minutes for {$changeType->value}");
        }
    }

    public function testAllChangeTypesHaveValidProperties(): void
    {
        foreach (FractorChangeType::cases() as $changeType) {
            $this->assertNotEmpty($changeType->getCategory());
            $this->assertGreaterThan(0, $changeType->getEstimatedEffort());
            $this->assertNotEmpty($changeType->getDisplayName());
            $this->assertNotEmpty($changeType->getColorCode());
        }
    }

    public function testManualInterventionRequirements(): void
    {
        $manualInterventionTypes = [
            FractorChangeType::BREAKING_CHANGE,
            FractorChangeType::CONFIGURATION_CHANGE,
        ];

        $automaticTypes = [
            FractorChangeType::DEPRECATION,
            FractorChangeType::BEST_PRACTICE,
            FractorChangeType::PERFORMANCE,
            FractorChangeType::SECURITY,
            FractorChangeType::CODE_STYLE,
        ];

        foreach ($manualInterventionTypes as $type) {
            $this->assertTrue($type->requiresManualIntervention(), "{$type->value} should require manual intervention");
        }

        foreach ($automaticTypes as $type) {
            $this->assertFalse($type->requiresManualIntervention(), "{$type->value} should not require manual intervention");
        }
    }
}
