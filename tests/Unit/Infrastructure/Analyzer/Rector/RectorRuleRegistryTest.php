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

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorChangeType;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorRuleRegistry;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorRuleSeverity;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ssch\TYPO3Rector\Set\Typo3SetList;

/**
 * Test case for RectorRuleRegistry service.
 */
class RectorRuleRegistryTest extends TestCase
{
    private RectorRuleRegistry $registry;
    private NullLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new NullLogger();
        $this->registry = new RectorRuleRegistry($this->logger);
    }

    public function testGetSetsForVersionUpgradeFrom11To12(): void
    {
        $currentVersion = new Version('11.5.0');
        $targetVersion = new Version('12.0.0');

        $sets = $this->registry->getSetsForVersionUpgrade($currentVersion, $targetVersion);

        $this->assertIsArray($sets);
        $this->assertNotEmpty($sets);

        // Should include TYPO3 12 set
        $this->assertContains(Typo3SetList::TYPO3_12, $sets);
        // Should also include general sets
        $this->assertContains(Typo3SetList::GENERAL, $sets);
    }

    public function testGetSetsForVersionUpgradeFrom12To13(): void
    {
        $currentVersion = new Version('12.4.0');
        $targetVersion = new Version('13.0.0');

        $sets = $this->registry->getSetsForVersionUpgrade($currentVersion, $targetVersion);

        $this->assertIsArray($sets);
        $this->assertNotEmpty($sets);

        // Should include TYPO3 13 set
        $this->assertContains(Typo3SetList::TYPO3_13, $sets);
    }

    public function testGetSetsForVersionUpgradeMultipleVersions(): void
    {
        $currentVersion = new Version('11.5.0');
        $targetVersion = new Version('13.0.0');

        $sets = $this->registry->getSetsForVersionUpgrade($currentVersion, $targetVersion);

        $this->assertIsArray($sets);
        $this->assertNotEmpty($sets);

        // Should include sets from both 12 and 13 since it's a major version upgrade
        $this->assertContains(Typo3SetList::TYPO3_12, $sets);
        $this->assertContains(Typo3SetList::TYPO3_13, $sets);
        $this->assertContains(Typo3SetList::CODE_QUALITY, $sets); // Included for major version upgrades
    }

    public function testGetSetsForSameVersion(): void
    {
        $currentVersion = new Version('12.4.0');
        $targetVersion = new Version('12.4.0');

        $sets = $this->registry->getSetsForVersionUpgrade($currentVersion, $targetVersion);

        $this->assertIsArray($sets);
        // Same version analysis should return general sets for code quality analysis
        $this->assertNotEmpty($sets);
        $this->assertContains(Typo3SetList::GENERAL, $sets);
        $this->assertContains(Typo3SetList::CODE_QUALITY, $sets);
    }

    public function testGetSetsForDowngrade(): void
    {
        $currentVersion = new Version('13.0.0');
        $targetVersion = new Version('12.0.0');

        $sets = $this->registry->getSetsForVersionUpgrade($currentVersion, $targetVersion);

        $this->assertIsArray($sets);
        $this->assertEmpty($sets);
    }

    public function testGetSetsForUnsupportedVersion(): void
    {
        $currentVersion = new Version('9.5.0');
        $targetVersion = new Version('11.0.0');

        $sets = $this->registry->getSetsForVersionUpgrade($currentVersion, $targetVersion);

        $this->assertIsArray($sets);
        $this->assertEmpty($sets); // 9.5 is not supported as source version
    }

    public function testGetSetsByCategory(): void
    {
        $generalSets = $this->registry->getSetsByCategory('general');

        $this->assertIsArray($generalSets);
        $this->assertNotEmpty($generalSets);
        $this->assertContains(Typo3SetList::GENERAL, $generalSets);
    }

    public function testGetSetsByCategoryInvalidCategory(): void
    {
        $sets = $this->registry->getSetsByCategory('invalid_category');

        $this->assertIsArray($sets);
        $this->assertEmpty($sets);
    }

    public function testGetSetSeverity(): void
    {
        $severity = $this->registry->getSetSeverity(Typo3SetList::TYPO3_12);

        $this->assertInstanceOf(RectorRuleSeverity::class, $severity);
        $this->assertEquals(RectorRuleSeverity::CRITICAL, $severity);
    }

    public function testGetSetSeverityUnknownSet(): void
    {
        $severity = $this->registry->getSetSeverity('UnknownSet');

        // Should return default severity based on pattern matching
        $this->assertInstanceOf(RectorRuleSeverity::class, $severity);
    }

    public function testGetSetChangeType(): void
    {
        $changeType = $this->registry->getSetChangeType(Typo3SetList::TYPO3_12);

        $this->assertInstanceOf(RectorChangeType::class, $changeType);
        $this->assertEquals(RectorChangeType::BREAKING_CHANGE, $changeType);
    }

    public function testGetSetChangeTypeUnknownSet(): void
    {
        $changeType = $this->registry->getSetChangeType('UnknownSet');

        // Should return default change type
        $this->assertInstanceOf(RectorChangeType::class, $changeType);
    }

    public function testGetSetDescription(): void
    {
        $description = $this->registry->getSetDescription(Typo3SetList::TYPO3_12);

        $this->assertIsString($description);
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

        $this->assertIsArray($versions);
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

        $this->assertIsArray($v12Sets);
        $this->assertNotEmpty($v12Sets);

        // Should contain TYPO3 12.0 specific set
        $this->assertContains(Typo3SetList::TYPO3_12, $v12Sets);
    }

    public function testGetVersionSpecificSetsUnsupportedVersion(): void
    {
        $sets = $this->registry->getVersionSpecificSets(new Version('9.5.0'));

        $this->assertIsArray($sets);
        $this->assertEmpty($sets);
    }

    public function testGetSetsStatistics(): void
    {
        $stats = $this->registry->getSetsStatistics();

        $this->assertIsArray($stats);
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

        $this->assertIsArray($allSets);
        $this->assertNotEmpty($allSets);

        // Should contain all defined sets
        $this->assertContains(Typo3SetList::TYPO3_10, $allSets);
        $this->assertContains(Typo3SetList::TYPO3_11, $allSets);
        $this->assertContains(Typo3SetList::TYPO3_12, $allSets);
        $this->assertContains(Typo3SetList::TYPO3_13, $allSets);
        $this->assertContains(Typo3SetList::TYPO3_14, $allSets);
        $this->assertContains(Typo3SetList::GENERAL, $allSets);
        $this->assertContains(Typo3SetList::CODE_QUALITY, $allSets);

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

        $this->assertIsInt($effort);
        $this->assertGreaterThan(0, $effort);
        $this->assertEquals(120, $effort); // From metadata
    }

    public function testGetSetsForMajorVersion(): void
    {
        $v12Sets = $this->registry->getSetsForMajorVersion(12);

        $this->assertIsArray($v12Sets);
        $this->assertNotEmpty($v12Sets);
        $this->assertContains(Typo3SetList::TYPO3_12, $v12Sets);
    }

    /**
     * Test rule-based severity determination.
     */
    public function testGetRuleSeverity(): void
    {
        // Test critical severity for newer TYPO3 versions
        $severity = $this->registry->getRuleSeverity('Ssch\\TYPO3Rector\\Rector\\v12\\v0\\SomeRule');
        $this->assertEquals(RectorRuleSeverity::CRITICAL, $severity);

        $severity = $this->registry->getRuleSeverity('Ssch\\TYPO3Rector\\Rector\\v13\\v0\\AnotherRule');
        $this->assertEquals(RectorRuleSeverity::CRITICAL, $severity);

        $severity = $this->registry->getRuleSeverity('Ssch\\TYPO3Rector\\Rector\\v14\\v0\\FutureRule');
        $this->assertEquals(RectorRuleSeverity::CRITICAL, $severity);

        // Test warning severity for older TYPO3 versions
        $severity = $this->registry->getRuleSeverity('Ssch\\TYPO3Rector\\Rector\\v10\\v0\\DeprecatedRule');
        $this->assertEquals(RectorRuleSeverity::WARNING, $severity);

        $severity = $this->registry->getRuleSeverity('Ssch\\TYPO3Rector\\Rector\\v11\\v0\\OldRule');
        $this->assertEquals(RectorRuleSeverity::WARNING, $severity);

        // Test info severity for code quality rules
        $severity = $this->registry->getRuleSeverity('Ssch\\TYPO3Rector\\CodeQuality\\SomeQualityRule');
        $this->assertEquals(RectorRuleSeverity::INFO, $severity);

        $severity = $this->registry->getRuleSeverity('Ssch\\TYPO3Rector\\General\\GeneralRule');
        $this->assertEquals(RectorRuleSeverity::INFO, $severity);

        // Test default warning for unknown patterns
        $severity = $this->registry->getRuleSeverity('Some\\Unknown\\Rule');
        $this->assertEquals(RectorRuleSeverity::WARNING, $severity);
    }

    /**
     * Test rule-based change type determination.
     */
    public function testGetRuleChangeType(): void
    {
        // Test breaking changes for newer TYPO3 versions
        $changeType = $this->registry->getRuleChangeType('Ssch\\TYPO3Rector\\Rector\\v12\\v0\\BreakingRule');
        $this->assertEquals(RectorChangeType::BREAKING_CHANGE, $changeType);

        $changeType = $this->registry->getRuleChangeType('Ssch\\TYPO3Rector\\Rector\\v13\\v0\\AnotherBreaking');
        $this->assertEquals(RectorChangeType::BREAKING_CHANGE, $changeType);

        $changeType = $this->registry->getRuleChangeType('Ssch\\TYPO3Rector\\Rector\\v14\\v0\\FutureBreaking');
        $this->assertEquals(RectorChangeType::BREAKING_CHANGE, $changeType);

        // Test breaking changes for Remove rules
        $changeType = $this->registry->getRuleChangeType('Ssch\\TYPO3Rector\\Remove\\SomethingRector');
        $this->assertEquals(RectorChangeType::BREAKING_CHANGE, $changeType);

        // Test deprecations for older TYPO3 versions
        $changeType = $this->registry->getRuleChangeType('Ssch\\TYPO3Rector\\Rector\\v10\\v0\\DeprecationRule');
        $this->assertEquals(RectorChangeType::DEPRECATION, $changeType);

        $changeType = $this->registry->getRuleChangeType('Ssch\\TYPO3Rector\\Rector\\v11\\v0\\OldRule');
        $this->assertEquals(RectorChangeType::DEPRECATION, $changeType);

        // Test deprecations for Deprecated rules
        $changeType = $this->registry->getRuleChangeType('Ssch\\TYPO3Rector\\Deprecat\\SomethingRector');
        $this->assertEquals(RectorChangeType::DEPRECATION, $changeType);

        // Test best practice for code quality rules
        $changeType = $this->registry->getRuleChangeType('Ssch\\TYPO3Rector\\CodeQuality\\QualityRule');
        $this->assertEquals(RectorChangeType::BEST_PRACTICE, $changeType);

        $changeType = $this->registry->getRuleChangeType('Ssch\\TYPO3Rector\\General\\GeneralRule');
        $this->assertEquals(RectorChangeType::BEST_PRACTICE, $changeType);

        // Test default deprecation for unknown patterns
        $changeType = $this->registry->getRuleChangeType('Some\\Unknown\\Rule');
        $this->assertEquals(RectorChangeType::DEPRECATION, $changeType);
    }

    /**
     * Test complete rule information retrieval.
     */
    public function testGetRuleInfo(): void
    {
        // Test critical breaking change rule
        $ruleInfo = $this->registry->getRuleInfo('Ssch\\TYPO3Rector\\Rector\\v12\\v0\\RemoveRule');
        
        $this->assertIsArray($ruleInfo);
        $this->assertArrayHasKey('severity', $ruleInfo);
        $this->assertArrayHasKey('changeType', $ruleInfo);
        $this->assertArrayHasKey('effort', $ruleInfo);
        
        $this->assertEquals(RectorRuleSeverity::CRITICAL, $ruleInfo['severity']);
        $this->assertEquals(RectorChangeType::BREAKING_CHANGE, $ruleInfo['changeType']);
        $this->assertEquals(15, $ruleInfo['effort']); // Critical breaking change effort
        
        // Test code quality rule
        $ruleInfo = $this->registry->getRuleInfo('Ssch\\TYPO3Rector\\CodeQuality\\ImprovementRule');
        
        $this->assertEquals(RectorRuleSeverity::INFO, $ruleInfo['severity']);
        $this->assertEquals(RectorChangeType::BEST_PRACTICE, $ruleInfo['changeType']);
        $this->assertEquals(3, $ruleInfo['effort']); // Best practice effort
        
        // Test deprecation warning rule
        $ruleInfo = $this->registry->getRuleInfo('Ssch\\TYPO3Rector\\Rector\\v10\\v0\\DeprecatedRule');
        
        $this->assertEquals(RectorRuleSeverity::WARNING, $ruleInfo['severity']);
        $this->assertEquals(RectorChangeType::DEPRECATION, $ruleInfo['changeType']);
        $this->assertEquals(8, $ruleInfo['effort']); // Deprecation warning effort
    }

    /**
     * Test known rule pattern recognition.
     */
    public function testIsKnownRule(): void
    {
        // Test TYPO3 Rector rules
        $this->assertTrue($this->registry->isKnownRule('Ssch\\TYPO3Rector\\Rector\\v12\\v0\\SomeRule'));
        $this->assertTrue($this->registry->isKnownRule('TYPO3Rector\\CodeQuality\\QualityRule'));
        $this->assertTrue($this->registry->isKnownRule('Ssch\\SomeOtherRule'));
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
            ['Ssch\\TYPO3Rector\\Remove\\CriticalRule', 15],
            ['Ssch\\TYPO3Rector\\Rector\\v12\\v0\\BreakingRule', 15],
            
            // Non-critical breaking changes
            ['Ssch\\TYPO3Rector\\Remove\\SomeRule', 10], // Remove but not critical
            
            // Warning deprecations
            ['Ssch\\TYPO3Rector\\Rector\\v10\\v0\\DeprecationRule', 8],
            
            // Best practices should have lowest effort
            ['Ssch\\TYPO3Rector\\CodeQuality\\QualityRule', 3],
            ['Ssch\\TYPO3Rector\\General\\GeneralRule', 3],
            
            // Default effort for unknown patterns
            ['Some\\Unknown\\Rule', 5],
        ];
        
        foreach ($testCases as [$ruleClass, $expectedEffort]) {
            $ruleInfo = $this->registry->getRuleInfo($ruleClass);
            $this->assertEquals($expectedEffort, $ruleInfo['effort'], "Effort mismatch for rule: {$ruleClass}");
        }
    }
}
