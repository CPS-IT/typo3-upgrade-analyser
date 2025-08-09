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
use PHPUnit\Framework\TestCase;

/**
 * Test case for RectorChangeType enum.
 */
class RectorChangeTypeTest extends TestCase
{
    public function testBreakingChangeProperties(): void
    {
        $changeType = RectorChangeType::BREAKING_CHANGE;

        $this->assertEquals('breaking_change', $changeType->value);
        $this->assertEquals('Breaking Changes', $changeType->getCategory());
        $this->assertEquals(60, $changeType->getEstimatedEffort());
        $this->assertTrue($changeType->requiresManualIntervention());
        $this->assertEquals('Breaking Change', $changeType->getDisplayName());
    }

    public function testDeprecationProperties(): void
    {
        $changeType = RectorChangeType::DEPRECATION;

        $this->assertEquals('deprecation', $changeType->value);
        $this->assertEquals('Deprecations', $changeType->getCategory());
        $this->assertEquals(10, $changeType->getEstimatedEffort());
        $this->assertFalse($changeType->requiresManualIntervention());
        $this->assertEquals('Deprecation', $changeType->getDisplayName());
    }

    public function testMethodSignatureProperties(): void
    {
        $changeType = RectorChangeType::METHOD_SIGNATURE;

        $this->assertEquals('method_signature', $changeType->value);
        $this->assertEquals('API Changes', $changeType->getCategory());
        $this->assertEquals(20, $changeType->getEstimatedEffort());
        $this->assertTrue($changeType->requiresManualIntervention());
        $this->assertEquals('Method Signature Change', $changeType->getDisplayName());
    }

    public function testClassRemovalProperties(): void
    {
        $changeType = RectorChangeType::CLASS_REMOVAL;

        $this->assertEquals('class_removal', $changeType->value);
        $this->assertEquals('Breaking Changes', $changeType->getCategory());
        $this->assertEquals(45, $changeType->getEstimatedEffort());
        $this->assertTrue($changeType->requiresManualIntervention());
        $this->assertEquals('Class Removal', $changeType->getDisplayName());
    }

    public function testInterfaceChangeProperties(): void
    {
        $changeType = RectorChangeType::INTERFACE_CHANGE;

        $this->assertEquals('interface_change', $changeType->value);
        $this->assertEquals('API Changes', $changeType->getCategory());
        $this->assertEquals(30, $changeType->getEstimatedEffort());
        $this->assertTrue($changeType->requiresManualIntervention());
        $this->assertEquals('Interface Change', $changeType->getDisplayName());
    }

    public function testConfigurationChangeProperties(): void
    {
        $changeType = RectorChangeType::CONFIGURATION_CHANGE;

        $this->assertEquals('configuration_change', $changeType->value);
        $this->assertEquals('Configuration', $changeType->getCategory());
        $this->assertEquals(15, $changeType->getEstimatedEffort());
        $this->assertTrue($changeType->requiresManualIntervention());
        $this->assertEquals('Configuration Change', $changeType->getDisplayName());
    }

    public function testBestPracticeProperties(): void
    {
        $changeType = RectorChangeType::BEST_PRACTICE;

        $this->assertEquals('best_practice', $changeType->value);
        $this->assertEquals('Code Quality', $changeType->getCategory());
        $this->assertEquals(8, $changeType->getEstimatedEffort());
        $this->assertFalse($changeType->requiresManualIntervention());
        $this->assertEquals('Best Practice', $changeType->getDisplayName());
    }

    public function testEstimatedEffortRanges(): void
    {
        foreach (RectorChangeType::cases() as $changeType) {
            $effort = $changeType->getEstimatedEffort();
            $this->assertIsInt($effort);
            $this->assertGreaterThan(0, $effort, "Effort should be positive for {$changeType->value}");
            $this->assertLessThanOrEqual(60, $effort, "Effort should not exceed 60 minutes for {$changeType->value}");
        }
    }

    public function testAllChangeTypesHaveValidProperties(): void
    {
        foreach (RectorChangeType::cases() as $changeType) {
            $this->assertIsString($changeType->getCategory());
            $this->assertNotEmpty($changeType->getCategory());

            $this->assertIsInt($changeType->getEstimatedEffort());
            $this->assertGreaterThan(0, $changeType->getEstimatedEffort());

            $this->assertIsBool($changeType->requiresManualIntervention());

            $this->assertIsString($changeType->getDisplayName());
            $this->assertNotEmpty($changeType->getDisplayName());
        }
    }

    public function testManualInterventionRequirements(): void
    {
        $manualInterventionTypes = [
            RectorChangeType::BREAKING_CHANGE,
            RectorChangeType::CLASS_REMOVAL,
            RectorChangeType::INTERFACE_CHANGE,
            RectorChangeType::METHOD_SIGNATURE,
            RectorChangeType::CONFIGURATION_CHANGE,
        ];

        $automaticTypes = [
            RectorChangeType::DEPRECATION,
            RectorChangeType::BEST_PRACTICE,
        ];

        foreach ($manualInterventionTypes as $type) {
            $this->assertTrue($type->requiresManualIntervention(), "{$type->value} should require manual intervention");
        }

        foreach ($automaticTypes as $type) {
            $this->assertFalse($type->requiresManualIntervention(), "{$type->value} should not require manual intervention");
        }
    }
}
