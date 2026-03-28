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

use a9f\Typo3Fractor\Set\Typo3SetList;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorChangeType;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorRuleRegistry;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorRuleSeverity;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Test case for FractorRuleRegistry service.
 */
class FractorRuleRegistryTest extends TestCase
{
    private FractorRuleRegistry $registry;
    private NullLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new NullLogger();
        $this->registry = new FractorRuleRegistry($this->logger);
    }

    public function testGetSetsForVersionUpgradeFrom11To12(): void
    {
        $currentVersion = new Version('11.5.0');
        $targetVersion = new Version('12.0.0');

        $sets = $this->registry->getSetsForVersionUpgrade($currentVersion, $targetVersion);

        $this->assertNotEmpty($sets);

        // Should include TYPO3 12 set
        $this->assertContains(Typo3SetList::TYPO3_12, $sets);
    }

    public function testGetSetsForVersionUpgradeFrom12To13(): void
    {
        $currentVersion = new Version('12.4.0');
        $targetVersion = new Version('13.0.0');

        $sets = $this->registry->getSetsForVersionUpgrade($currentVersion, $targetVersion);

        $this->assertNotEmpty($sets);

        // Should include TYPO3 13 set
        $this->assertContains(Typo3SetList::TYPO3_13, $sets);
    }

    public function testGetSetsForVersionUpgradeMultipleVersions(): void
    {
        $currentVersion = new Version('11.5.0');
        $targetVersion = new Version('13.0.0');

        $sets = $this->registry->getSetsForVersionUpgrade($currentVersion, $targetVersion);

        $this->assertNotEmpty($sets);

        // Should include sets from both 12 and 13 since it's a major version upgrade
        $this->assertContains(Typo3SetList::TYPO3_12, $sets);
        $this->assertContains(Typo3SetList::TYPO3_13, $sets);
    }

    public function testGetSetsForSameVersion(): void
    {
        $currentVersion = new Version('12.4.0');
        $targetVersion = new Version('12.4.0');

        $sets = $this->registry->getSetsForVersionUpgrade($currentVersion, $targetVersion);

        $this->assertEmpty($sets);
    }

    public function testGetSetsForDowngrade(): void
    {
        $currentVersion = new Version('13.0.0');
        $targetVersion = new Version('12.0.0');

        $sets = $this->registry->getSetsForVersionUpgrade($currentVersion, $targetVersion);

        $this->assertEmpty($sets);
    }

    public function testGetSetsForUnsupportedVersion(): void
    {
        $currentVersion = new Version('9.5.0');
        $targetVersion = new Version('11.0.0');

        $sets = $this->registry->getSetsForVersionUpgrade($currentVersion, $targetVersion);

        $this->assertEmpty($sets); // 9.5 is not supported as source version
    }

    public function testGetSetSeverity(): void
    {
        $severity = $this->registry->getSetSeverity(Typo3SetList::TYPO3_12);

        $this->assertEquals(FractorRuleSeverity::CRITICAL, $severity);
    }

    public function testGetSetDescription(): void
    {
        $description = $this->registry->getSetDescription(Typo3SetList::TYPO3_12);

        $this->assertNotEmpty($description);
        $this->assertEquals('TYPO3 12 upgrade rules', $description);
    }

    public function testGetSetDescriptionUnknownSet(): void
    {
        $description = $this->registry->getSetDescription('UnknownSet');

        // Should return default description
        $this->assertEquals('Unknown set', $description);
    }

    public function testGetSupportedVersions(): void
    {
        $versions = $this->registry->getSupportedVersions();

        $this->assertNotEmpty($versions);

        // Should include supported TYPO3 versions
        $this->assertContains('10.0', $versions);
        $this->assertContains('11.0', $versions);
        $this->assertContains('12.0', $versions);
        $this->assertContains('13.0', $versions);
        $this->assertContains('14.0', $versions);
    }

    public function testIsVersionSupported(): void
    {
        $supported = $this->registry->isVersionSupported(new Version('12.4.0'));
        $unsupported = $this->registry->isVersionSupported(new Version('9.5.0'));

        $this->assertTrue($supported);
        $this->assertFalse($unsupported);
    }

    public function testGetVersionSpecificSets(): void
    {
        $v12Sets = $this->registry->getVersionSpecificSets(new Version('12.0.0'));

        $this->assertNotEmpty($v12Sets);

        // Should contain TYPO3 12.0 specific set
        $this->assertContains(Typo3SetList::TYPO3_12, $v12Sets);
    }

    public function testGetVersionSpecificSetsUnsupportedVersion(): void
    {
        $sets = $this->registry->getVersionSpecificSets(new Version('9.5.0'));

        $this->assertEmpty($sets);
    }

    public function testGetSetsStatistics(): void
    {
        $stats = $this->registry->getSetsStatistics();

        $this->assertArrayHasKey('total_sets', $stats);
        $this->assertArrayHasKey('sets_by_version', $stats);
        $this->assertArrayHasKey('sets_by_category', $stats);
        $this->assertArrayHasKey('sets_by_severity', $stats);

        $this->assertIsInt($stats['total_sets']);
        $this->assertGreaterThan(0, $stats['total_sets']);

        $this->assertIsArray($stats['sets_by_version']);
        $this->assertIsArray($stats['sets_by_category']);
        $this->assertIsArray($stats['sets_by_severity']);
    }

    public function testSetsAreUniqueAcrossVersions(): void
    {
        $v11To12Sets = $this->registry->getSetsForVersionUpgrade(new Version('11.5.0'), new Version('12.0.0'));
        $v12To13Sets = $this->registry->getSetsForVersionUpgrade(new Version('12.4.0'), new Version('13.0.0'));

        // Sets should be unique (no duplicates within each set)
        $this->assertEquals(\count($v11To12Sets), \count(array_unique($v11To12Sets)));
        $this->assertEquals(\count($v12To13Sets), \count(array_unique($v12To13Sets)));
    }

    public function testGetAllAvailableSets(): void
    {
        $allSets = $this->registry->getAllAvailableSets();

        $this->assertNotEmpty($allSets);

        // Should contain all defined sets
        $this->assertContains(Typo3SetList::TYPO3_10, $allSets);
        $this->assertContains(Typo3SetList::TYPO3_11, $allSets);
        $this->assertContains(Typo3SetList::TYPO3_12, $allSets);
        $this->assertContains(Typo3SetList::TYPO3_13, $allSets);
        $this->assertContains(Typo3SetList::TYPO3_14, $allSets);

        // Should be unique
        $this->assertEquals(\count($allSets), \count(array_unique($allSets)));
    }

    public function testHasSet(): void
    {
        $this->assertTrue($this->registry->hasSet(Typo3SetList::TYPO3_12));
        $this->assertFalse($this->registry->hasSet('NonExistentSet'));
    }

    public function testGetSetEffort(): void
    {
        $effort = $this->registry->getSetEffort(Typo3SetList::TYPO3_12);

        $this->assertGreaterThan(0, $effort);
        $this->assertEquals(120, $effort); // From metadata
    }

    public function testGetSetsForMajorVersion(): void
    {
        $v12Sets = $this->registry->getSetsForMajorVersion(12);

        $this->assertNotEmpty($v12Sets);
        $this->assertContains(Typo3SetList::TYPO3_12, $v12Sets);
    }

    /**
     * Test rule-based severity determination.
     */
    public function testGetRuleSeverity(): void
    {
        // Test critical severity for newer TYPO3 versions
        $severity = $this->registry->getRuleSeverity('a9f\\Typo3Fractor\\Fractor\\v12\\v0\\SomeRule');
        $this->assertEquals(FractorRuleSeverity::CRITICAL, $severity);

        $severity = $this->registry->getRuleSeverity('a9f\\Typo3Fractor\\Fractor\\v13\\v0\\AnotherRule');
        $this->assertEquals(FractorRuleSeverity::CRITICAL, $severity);

        $severity = $this->registry->getRuleSeverity('a9f\\Typo3Fractor\\Fractor\\v14\\v0\\FutureRule');
        $this->assertEquals(FractorRuleSeverity::CRITICAL, $severity);

        // Explicit critical
        $severity = $this->registry->getRuleSeverity('SomeCriticalRule');
        $this->assertEquals(FractorRuleSeverity::CRITICAL, $severity);

        // Test warning severity for older TYPO3 versions
        $severity = $this->registry->getRuleSeverity('a9f\\Typo3Fractor\\Fractor\\v10\\v0\\DeprecatedRule');
        $this->assertEquals(FractorRuleSeverity::WARNING, $severity);

        $severity = $this->registry->getRuleSeverity('a9f\\Typo3Fractor\\Fractor\\v11\\v0\\OldRule');
        $this->assertEquals(FractorRuleSeverity::WARNING, $severity);

        // Test default warning for unknown patterns
        $severity = $this->registry->getRuleSeverity('Some\\Unknown\\Rule');
        $this->assertEquals(FractorRuleSeverity::WARNING, $severity);
    }

    /**
     * Test rule-based change type determination.
     */
    public function testGetRuleChangeType(): void
    {
        // Test breaking changes for newer TYPO3 versions
        $changeType = $this->registry->getRuleChangeType('a9f\\Typo3Fractor\\Fractor\\v12\\v0\\BreakingRule');
        $this->assertEquals(FractorChangeType::BREAKING_CHANGE, $changeType);

        $changeType = $this->registry->getRuleChangeType('a9f\\Typo3Fractor\\Fractor\\v13\\v0\\AnotherBreaking');
        $this->assertEquals(FractorChangeType::BREAKING_CHANGE, $changeType);

        $changeType = $this->registry->getRuleChangeType('a9f\\Typo3Fractor\\Fractor\\v14\\v0\\FutureBreaking');
        $this->assertEquals(FractorChangeType::BREAKING_CHANGE, $changeType);

        // Test breaking changes for Remove rules
        $changeType = $this->registry->getRuleChangeType('a9f\\Typo3Fractor\\Remove\\SomethingFractor');
        $this->assertEquals(FractorChangeType::BREAKING_CHANGE, $changeType);

        // Test deprecations for older TYPO3 versions
        $changeType = $this->registry->getRuleChangeType('a9f\\Typo3Fractor\\Fractor\\v10\\v0\\DeprecationRule');
        $this->assertEquals(FractorChangeType::DEPRECATION, $changeType);

        $changeType = $this->registry->getRuleChangeType('a9f\\Typo3Fractor\\Fractor\\v11\\v0\\OldRule');
        $this->assertEquals(FractorChangeType::DEPRECATION, $changeType);

        // Test deprecations for Deprecated rules
        $changeType = $this->registry->getRuleChangeType('a9f\\Typo3Fractor\\Deprecat\\SomethingFractor');
        $this->assertEquals(FractorChangeType::DEPRECATION, $changeType);

        // Test default deprecation for unknown patterns
        $changeType = $this->registry->getRuleChangeType('Some\\Unknown\\Rule');
        $this->assertEquals(FractorChangeType::DEPRECATION, $changeType);
    }

    /**
     * Test complete rule information retrieval.
     */
    public function testGetRuleInfo(): void
    {
        // Test critical breaking change rule
        $ruleInfo = $this->registry->getRuleInfo('a9f\\Typo3Fractor\\Fractor\\v12\\v0\\RemoveRule');

        // Verify rule info structure

        $this->assertEquals(FractorRuleSeverity::CRITICAL, $ruleInfo['severity']);
        $this->assertEquals(FractorChangeType::BREAKING_CHANGE, $ruleInfo['changeType']);
        $this->assertEquals(15, $ruleInfo['effort']); // Critical breaking change effort

        // Test deprecation warning rule
        $ruleInfo = $this->registry->getRuleInfo('a9f\\Typo3Fractor\\Fractor\\v10\\v0\\DeprecatedRule');

        $this->assertEquals(FractorRuleSeverity::WARNING, $ruleInfo['severity']);
        $this->assertEquals(FractorChangeType::DEPRECATION, $ruleInfo['changeType']);
        $this->assertEquals(8, $ruleInfo['effort']); // Deprecation warning effort (isKnownRule returns true for v10)
    }

    /**
     * Test known rule pattern recognition.
     */
    public function testIsKnownRule(): void
    {
        // Test TYPO3 Fractor rules
        $this->assertTrue($this->registry->isKnownRule('a9f\\Typo3Fractor\\Fractor\\v12\\v0\\SomeRule'));
        $this->assertTrue($this->registry->isKnownRule('a9f\\SomeOtherRule'));
        $this->assertTrue($this->registry->isKnownRule('SomePackage\\v11\\SomeRule'));

        // Test unknown rules
        $this->assertFalse($this->registry->isKnownRule('Random\\Unknown\\Rule'));
        $this->assertFalse($this->registry->isKnownRule('SimpleRule'));
    }

    /**
     * Test effort calculation consistency between rule patterns.
     */
    public function testRuleEffortConsistency(): void
    {
        $testCases = [
            // Critical breaking changes should have highest effort
            ['a9f\\Typo3Fractor\\Remove\\CriticalRule', 15],
            ['a9f\\Typo3Fractor\\Fractor\\v12\\v0\\BreakingRule', 15],

            // Warning deprecations
            ['a9f\\Typo3Fractor\\Fractor\\v10\\v0\\DeprecationRule', 8],

            // Default effort for unknown patterns (warning/deprecation)
            ['Some\\Unknown\\Rule', 5],
        ];

        foreach ($testCases as [$ruleClass, $expectedEffort]) {
            $ruleInfo = $this->registry->getRuleInfo($ruleClass);
            $this->assertEquals($expectedEffort, $ruleInfo['effort'], "Effort mismatch for rule: {$ruleClass}");
        }
    }
}
