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
        $this->assertEmpty($sets);
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
        $this->assertEquals(count($v11To12Sets), count(array_unique($v11To12Sets)));
        $this->assertEquals(count($v12To13Sets), count(array_unique($v12To13Sets)));
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
        $this->assertEquals(count($allSets), count(array_unique($allSets)));
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
}