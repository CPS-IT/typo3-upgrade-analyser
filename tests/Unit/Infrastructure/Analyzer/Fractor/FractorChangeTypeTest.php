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
use PHPUnit\Framework\TestCase;

/**
 * Test case for FractorChangeType enum.
 */
class FractorChangeTypeTest extends TestCase
{
    public function testAllEnumCasesExist(): void
    {
        $expectedCases = [
            'FLEXFORM_MIGRATION',
            'TCA_MIGRATION',
            'TYPOSCRIPT_MIGRATION',
            'FLUID_MIGRATION',
            'CONFIGURATION_UPDATE',
            'TEMPLATE_UPDATE',
            'MODERNIZATION',
            'DEPRECATION_REMOVAL',
        ];

        $actualCases = array_map(static fn (FractorChangeType $case): string => $case->name, FractorChangeType::cases());

        $this->assertEquals($expectedCases, $actualCases);
        $this->assertCount(8, FractorChangeType::cases());
    }

    public function testEnumValues(): void
    {
        $this->assertEquals('flexform_migration', FractorChangeType::FLEXFORM_MIGRATION->value);
        $this->assertEquals('tca_migration', FractorChangeType::TCA_MIGRATION->value);
        $this->assertEquals('typoscript_migration', FractorChangeType::TYPOSCRIPT_MIGRATION->value);
        $this->assertEquals('fluid_migration', FractorChangeType::FLUID_MIGRATION->value);
        $this->assertEquals('configuration_update', FractorChangeType::CONFIGURATION_UPDATE->value);
        $this->assertEquals('template_update', FractorChangeType::TEMPLATE_UPDATE->value);
        $this->assertEquals('modernization', FractorChangeType::MODERNIZATION->value);
        $this->assertEquals('deprecation_removal', FractorChangeType::DEPRECATION_REMOVAL->value);
    }

    public function testGetCategory(): void
    {
        $this->assertEquals('FlexForm Updates', FractorChangeType::FLEXFORM_MIGRATION->getCategory());
        $this->assertEquals('TCA Configuration', FractorChangeType::TCA_MIGRATION->getCategory());
        $this->assertEquals('TypoScript Updates', FractorChangeType::TYPOSCRIPT_MIGRATION->getCategory());
        $this->assertEquals('Fluid Template Updates', FractorChangeType::FLUID_MIGRATION->getCategory());
        $this->assertEquals('Configuration', FractorChangeType::CONFIGURATION_UPDATE->getCategory());
        $this->assertEquals('Templates', FractorChangeType::TEMPLATE_UPDATE->getCategory());
        $this->assertEquals('Code Modernization', FractorChangeType::MODERNIZATION->getCategory());
        $this->assertEquals('Deprecation Fixes', FractorChangeType::DEPRECATION_REMOVAL->getCategory());
    }

    public function testGetDisplayName(): void
    {
        $this->assertEquals('FlexForm Migration', FractorChangeType::FLEXFORM_MIGRATION->getDisplayName());
        $this->assertEquals('TCA Migration', FractorChangeType::TCA_MIGRATION->getDisplayName());
        $this->assertEquals('TypoScript Migration', FractorChangeType::TYPOSCRIPT_MIGRATION->getDisplayName());
        $this->assertEquals('Fluid Migration', FractorChangeType::FLUID_MIGRATION->getDisplayName());
        $this->assertEquals('Configuration Update', FractorChangeType::CONFIGURATION_UPDATE->getDisplayName());
        $this->assertEquals('Template Update', FractorChangeType::TEMPLATE_UPDATE->getDisplayName());
        $this->assertEquals('Code Modernization', FractorChangeType::MODERNIZATION->getDisplayName());
        $this->assertEquals('Deprecation Removal', FractorChangeType::DEPRECATION_REMOVAL->getDisplayName());
    }

    public function testGetEstimatedEffort(): void
    {
        $this->assertEquals(15, FractorChangeType::FLEXFORM_MIGRATION->getEstimatedEffort());
        $this->assertEquals(20, FractorChangeType::TCA_MIGRATION->getEstimatedEffort());
        $this->assertEquals(10, FractorChangeType::TYPOSCRIPT_MIGRATION->getEstimatedEffort());
        $this->assertEquals(5, FractorChangeType::FLUID_MIGRATION->getEstimatedEffort());
        $this->assertEquals(10, FractorChangeType::CONFIGURATION_UPDATE->getEstimatedEffort());
        $this->assertEquals(8, FractorChangeType::TEMPLATE_UPDATE->getEstimatedEffort());
        $this->assertEquals(5, FractorChangeType::MODERNIZATION->getEstimatedEffort());
        $this->assertEquals(3, FractorChangeType::DEPRECATION_REMOVAL->getEstimatedEffort());
    }

    public function testRequiresManualIntervention(): void
    {
        // Only FlexForm and TCA migrations require manual intervention
        $this->assertTrue(FractorChangeType::FLEXFORM_MIGRATION->requiresManualIntervention());
        $this->assertTrue(FractorChangeType::TCA_MIGRATION->requiresManualIntervention());

        // All others should not require manual intervention
        $this->assertFalse(FractorChangeType::TYPOSCRIPT_MIGRATION->requiresManualIntervention());
        $this->assertFalse(FractorChangeType::FLUID_MIGRATION->requiresManualIntervention());
        $this->assertFalse(FractorChangeType::CONFIGURATION_UPDATE->requiresManualIntervention());
        $this->assertFalse(FractorChangeType::TEMPLATE_UPDATE->requiresManualIntervention());
        $this->assertFalse(FractorChangeType::MODERNIZATION->requiresManualIntervention());
        $this->assertFalse(FractorChangeType::DEPRECATION_REMOVAL->requiresManualIntervention());
    }

    public function testGetPriority(): void
    {
        $this->assertEquals(10, FractorChangeType::DEPRECATION_REMOVAL->getPriority());
        $this->assertEquals(8, FractorChangeType::FLEXFORM_MIGRATION->getPriority());
        $this->assertEquals(8, FractorChangeType::TCA_MIGRATION->getPriority());
        $this->assertEquals(6, FractorChangeType::CONFIGURATION_UPDATE->getPriority());
        $this->assertEquals(4, FractorChangeType::TYPOSCRIPT_MIGRATION->getPriority());
        $this->assertEquals(4, FractorChangeType::FLUID_MIGRATION->getPriority());
        $this->assertEquals(2, FractorChangeType::TEMPLATE_UPDATE->getPriority());
        $this->assertEquals(2, FractorChangeType::MODERNIZATION->getPriority());
    }

    public function testPriorityOrdering(): void
    {
        $changeTypes = FractorChangeType::cases();
        $priorities = array_map(static fn (FractorChangeType $type): int => $type->getPriority(), $changeTypes);

        // Deprecation removal should have the highest priority
        $this->assertEquals(10, max($priorities));
        $this->assertEquals(10, FractorChangeType::DEPRECATION_REMOVAL->getPriority());

        // Verify priorities are in logical order
        $this->assertGreaterThan(FractorChangeType::FLEXFORM_MIGRATION->getPriority(), FractorChangeType::DEPRECATION_REMOVAL->getPriority());
        $this->assertGreaterThan(FractorChangeType::CONFIGURATION_UPDATE->getPriority(), FractorChangeType::FLEXFORM_MIGRATION->getPriority());
        $this->assertGreaterThan(FractorChangeType::TYPOSCRIPT_MIGRATION->getPriority(), FractorChangeType::CONFIGURATION_UPDATE->getPriority());
        $this->assertGreaterThan(FractorChangeType::MODERNIZATION->getPriority(), FractorChangeType::TYPOSCRIPT_MIGRATION->getPriority());
    }

    public function testEffortEstimationReasonableness(): void
    {
        foreach (FractorChangeType::cases() as $changeType) {
            $effort = $changeType->getEstimatedEffort();

            // All effort estimates should be reasonable (between 1 minute and 1 hour)
            $this->assertGreaterThan(0, $effort, "Effort for {$changeType->name} should be positive");
            $this->assertLessThanOrEqual(60, $effort, "Effort for {$changeType->name} should not exceed 60 minutes");
            $this->assertIsInt($effort, "Effort for {$changeType->name} should be an integer");
        }

        // TCA should have the highest effort (most complex)
        $efforts = array_map(static fn (FractorChangeType $type): int => $type->getEstimatedEffort(), FractorChangeType::cases());
        $this->assertEquals(20, max($efforts));
        $this->assertEquals(20, FractorChangeType::TCA_MIGRATION->getEstimatedEffort());

        // Deprecation removal should have low effort (usually simple changes)
        $this->assertEquals(3, FractorChangeType::DEPRECATION_REMOVAL->getEstimatedEffort());
    }

    public function testManualInterventionLogic(): void
    {
        $manualTypes = array_filter(
            FractorChangeType::cases(),
            static fn (FractorChangeType $type): bool => $type->requiresManualIntervention(),
        );

        // Only 2 types should require manual intervention
        $this->assertCount(2, $manualTypes);

        $manualTypeNames = array_map(static fn (FractorChangeType $type): string => $type->name, $manualTypes);
        $this->assertContains('FLEXFORM_MIGRATION', $manualTypeNames);
        $this->assertContains('TCA_MIGRATION', $manualTypeNames);

        // Types that require manual intervention should generally have higher effort
        foreach ($manualTypes as $manualType) {
            $this->assertGreaterThanOrEqual(15, $manualType->getEstimatedEffort(), 'Manual intervention types should have higher effort');
        }
    }

    public function testAllChangeTypesHaveValidProperties(): void
    {
        foreach (FractorChangeType::cases() as $changeType) {
            // Test category
            $category = $changeType->getCategory();
            $this->assertNotEmpty($category, "Category for {$changeType->name} should not be empty");
            $this->assertIsString($category, "Category for {$changeType->name} should be a string");

            // Test display name
            $displayName = $changeType->getDisplayName();
            $this->assertNotEmpty($displayName, "Display name for {$changeType->name} should not be empty");
            $this->assertIsString($displayName, "Display name for {$changeType->name} should be a string");

            // Test effort
            $effort = $changeType->getEstimatedEffort();
            $this->assertIsInt($effort, "Effort for {$changeType->name} should be an integer");
            $this->assertGreaterThan(0, $effort, "Effort for {$changeType->name} should be positive");

            // Test priority
            $priority = $changeType->getPriority();
            $this->assertIsInt($priority, "Priority for {$changeType->name} should be an integer");
            $this->assertGreaterThan(0, $priority, "Priority for {$changeType->name} should be positive");

            // Test manual intervention
            $manual = $changeType->requiresManualIntervention();
            $this->assertIsBool($manual, "Manual intervention for {$changeType->name} should be a boolean");
        }
    }

    public function testCategoriesAreGrouped(): void
    {
        $categories = array_map(static fn (FractorChangeType $type): string => $type->getCategory(), FractorChangeType::cases());
        $uniqueCategories = array_unique($categories);

        // Should have meaningful categorization
        $this->assertGreaterThan(1, \count($uniqueCategories), 'Change types should be grouped into multiple categories');
        $this->assertGreaterThan(0, \count($uniqueCategories), 'Should have at least one category');

        // Verify specific category groupings
        $this->assertEquals('FlexForm Updates', FractorChangeType::FLEXFORM_MIGRATION->getCategory());
        $this->assertEquals('TCA Configuration', FractorChangeType::TCA_MIGRATION->getCategory());
        $this->assertEquals('Configuration', FractorChangeType::CONFIGURATION_UPDATE->getCategory());

        // Each change type should have a meaningful, descriptive category
        foreach (FractorChangeType::cases() as $changeType) {
            $category = $changeType->getCategory();
            $this->assertNotEmpty($category, "Category for {$changeType->name} should not be empty");
            $this->assertGreaterThan(5, \strlen($category), "Category for {$changeType->name} should be descriptive");
        }
    }

    public function testEffortVsPriorityCorrelation(): void
    {
        // Higher priority items should generally not have the highest effort
        // (quick wins should be prioritized)
        $deprecationRemoval = FractorChangeType::DEPRECATION_REMOVAL;
        $tcaMigration = FractorChangeType::TCA_MIGRATION;

        $this->assertGreaterThan($tcaMigration->getPriority(), $deprecationRemoval->getPriority());
        $this->assertLessThan($tcaMigration->getEstimatedEffort(), $deprecationRemoval->getEstimatedEffort());
    }

    public function testPriorityRangeIsReasonable(): void
    {
        $priorities = array_map(static fn (FractorChangeType $type): int => $type->getPriority(), FractorChangeType::cases());

        $minPriority = min($priorities);
        $maxPriority = max($priorities);

        $this->assertGreaterThan(0, $minPriority, 'Minimum priority should be positive');
        $this->assertLessThanOrEqual(10, $maxPriority, 'Maximum priority should not exceed 10');
        $this->assertGreaterThan($minPriority, $maxPriority, 'Should have meaningful priority range');
    }
}
