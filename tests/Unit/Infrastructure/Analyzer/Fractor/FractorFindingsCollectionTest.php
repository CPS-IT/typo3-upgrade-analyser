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
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorFinding;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorFindingsCollection;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorRuleSeverity;
use PHPUnit\Framework\TestCase;

/**
 * Test case for FractorFindingsCollection.
 */
class FractorFindingsCollectionTest extends TestCase
{
    private array $findings;
    private FractorFindingsCollection $collection;

    protected function setUp(): void
    {
        $this->findings = [
            new FractorFinding(
                'src/Test1.php',
                10,
                'a9f\\Typo3Fractor\\Remove\\RemoveRule',
                'Critical breaking change',
                FractorRuleSeverity::CRITICAL,
                FractorChangeType::BREAKING_CHANGE,
            ),
            new FractorFinding(
                'src/Test1.php',
                20,
                'a9f\\Typo3Fractor\\Fractor\\v10\\v0\\DeprecatedRule',
                'Warning deprecation',
                FractorRuleSeverity::WARNING,
                FractorChangeType::DEPRECATION,
            ),
            new FractorFinding(
                'src/Test2.php',
                15,
                'a9f\\Typo3Fractor\\CodeQuality\\QualityRule',
                'Code improvement',
                FractorRuleSeverity::INFO,
                FractorChangeType::BEST_PRACTICE,
            ),
            new FractorFinding(
                'src/Test2.php',
                25,
                'a9f\\Typo3Fractor\\General\\GeneralRule',
                'Suggestion',
                FractorRuleSeverity::SUGGESTION,
                FractorChangeType::BEST_PRACTICE,
            ),
        ];

        $this->collection = new FractorFindingsCollection(
            breakingChanges: [$this->findings[0]],
            deprecations: [$this->findings[1]],
            improvements: [$this->findings[2], $this->findings[3]],
            bySeverity: [
                'critical' => [$this->findings[0]],
                'warning' => [$this->findings[1]],
                'info' => [$this->findings[2]],
                'suggestion' => [$this->findings[3]],
            ],
            byFile: [
                'src/Test1.php' => [$this->findings[0], $this->findings[1]],
                'src/Test2.php' => [$this->findings[2], $this->findings[3]],
            ],
            byRule: [
                'a9f\\Typo3Fractor\\Remove\\RemoveRule' => [$this->findings[0]],
                'a9f\\Typo3Fractor\\Fractor\\v10\\v0\\DeprecatedRule' => [$this->findings[1]],
                'a9f\\Typo3Fractor\\CodeQuality\\QualityRule' => [$this->findings[2]],
                'a9f\\Typo3Fractor\\General\\GeneralRule' => [$this->findings[3]],
            ],
        );
    }

    public function testGetBreakingChanges(): void
    {
        $breakingChanges = $this->collection->getBreakingChanges();

        $this->assertCount(1, $breakingChanges);
        $this->assertEquals($this->findings[0], $breakingChanges[0]);
    }

    public function testGetDeprecations(): void
    {
        $deprecations = $this->collection->getDeprecations();

        $this->assertCount(1, $deprecations);
        $this->assertEquals($this->findings[1], $deprecations[0]);
    }

    public function testGetImprovements(): void
    {
        $improvements = $this->collection->getImprovements();

        $this->assertCount(2, $improvements);
        $this->assertEquals($this->findings[2], $improvements[0]);
        $this->assertEquals($this->findings[3], $improvements[1]);
    }

    public function testGetBySeverity(): void
    {
        $bySeverity = $this->collection->getBySeverity();

        $this->assertArrayHasKey('critical', $bySeverity);
        $this->assertArrayHasKey('warning', $bySeverity);
        $this->assertArrayHasKey('info', $bySeverity);
        $this->assertArrayHasKey('suggestion', $bySeverity);

        $this->assertCount(1, $bySeverity['critical']);
        $this->assertCount(1, $bySeverity['warning']);
        $this->assertCount(1, $bySeverity['info']);
        $this->assertCount(1, $bySeverity['suggestion']);
    }

    public function testGetFindingsWithSeverity(): void
    {
        $critical = $this->collection->getFindingsWithSeverity(FractorRuleSeverity::CRITICAL);
        $warning = $this->collection->getFindingsWithSeverity(FractorRuleSeverity::WARNING);
        $info = $this->collection->getFindingsWithSeverity(FractorRuleSeverity::INFO);
        $suggestion = $this->collection->getFindingsWithSeverity(FractorRuleSeverity::SUGGESTION);

        $this->assertCount(1, $critical);
        $this->assertCount(1, $warning);
        $this->assertCount(1, $info);
        $this->assertCount(1, $suggestion);

        $this->assertEquals($this->findings[0], $critical[0]);
        $this->assertEquals($this->findings[1], $warning[0]);
        $this->assertEquals($this->findings[2], $info[0]);
        $this->assertEquals($this->findings[3], $suggestion[0]);
    }

    public function testGetByFile(): void
    {
        $byFile = $this->collection->getByFile();

        $this->assertArrayHasKey('src/Test1.php', $byFile);
        $this->assertArrayHasKey('src/Test2.php', $byFile);

        $this->assertCount(2, $byFile['src/Test1.php']);
        $this->assertCount(2, $byFile['src/Test2.php']);
    }

    public function testGetFindingsInFile(): void
    {
        $test1Findings = $this->collection->getFindingsInFile('src/Test1.php');
        $test2Findings = $this->collection->getFindingsInFile('src/Test2.php');
        $nonexistentFindings = $this->collection->getFindingsInFile('src/Nonexistent.php');

        $this->assertCount(2, $test1Findings);
        $this->assertCount(2, $test2Findings);
        $this->assertCount(0, $nonexistentFindings);

        $this->assertEquals($this->findings[0], $test1Findings[0]);
        $this->assertEquals($this->findings[1], $test1Findings[1]);
        $this->assertEquals($this->findings[2], $test2Findings[0]);
        $this->assertEquals($this->findings[3], $test2Findings[1]);
    }

    public function testGetByRule(): void
    {
        $byRule = $this->collection->getByRule();

        $this->assertArrayHasKey('a9f\\Typo3Fractor\\Remove\\RemoveRule', $byRule);
        $this->assertArrayHasKey('a9f\\Typo3Fractor\\Fractor\\v10\\v0\\DeprecatedRule', $byRule);
        $this->assertArrayHasKey('a9f\\Typo3Fractor\\CodeQuality\\QualityRule', $byRule);
        $this->assertArrayHasKey('a9f\\Typo3Fractor\\General\\GeneralRule', $byRule);

        $this->assertCount(1, $byRule['a9f\\Typo3Fractor\\Remove\\RemoveRule']);
        $this->assertCount(1, $byRule['a9f\\Typo3Fractor\\Fractor\\v10\\v0\\DeprecatedRule']);
        $this->assertCount(1, $byRule['a9f\\Typo3Fractor\\CodeQuality\\QualityRule']);
        $this->assertCount(1, $byRule['a9f\\Typo3Fractor\\General\\GeneralRule']);
    }

    public function testGetFindingsForRule(): void
    {
        $removeFindings = $this->collection->getFindingsForRule('a9f\\Typo3Fractor\\Remove\\RemoveRule');
        $deprecatedFindings = $this->collection->getFindingsForRule('a9f\\Typo3Fractor\\Fractor\\v10\\v0\\DeprecatedRule');
        $nonexistentFindings = $this->collection->getFindingsForRule('NonexistentRule');

        $this->assertCount(1, $removeFindings);
        $this->assertCount(1, $deprecatedFindings);
        $this->assertCount(0, $nonexistentFindings);

        $this->assertEquals($this->findings[0], $removeFindings[0]);
        $this->assertEquals($this->findings[1], $deprecatedFindings[0]);
    }

    public function testHasBreakingChanges(): void
    {
        $this->assertTrue($this->collection->hasBreakingChanges());

        $emptyCollection = new FractorFindingsCollection([], [], [], [], [], []);
        $this->assertFalse($emptyCollection->hasBreakingChanges());
    }

    public function testHasDeprecations(): void
    {
        $this->assertTrue($this->collection->hasDeprecations());

        $emptyCollection = new FractorFindingsCollection([], [], [], [], [], []);
        $this->assertFalse($emptyCollection->hasDeprecations());
    }

    public function testHasImprovements(): void
    {
        $this->assertTrue($this->collection->hasImprovements());

        $emptyCollection = new FractorFindingsCollection([], [], [], [], [], []);
        $this->assertFalse($emptyCollection->hasImprovements());
    }

    public function testGetTotalCount(): void
    {
        $this->assertEquals(4, $this->collection->getTotalCount());

        $emptyCollection = new FractorFindingsCollection([], [], [], [], [], []);
        $this->assertEquals(0, $emptyCollection->getTotalCount());
    }

    public function testGetSeverityCounts(): void
    {
        $severityCounts = $this->collection->getSeverityCounts();

        $this->assertEquals([
            'critical' => 1,
            'warning' => 1,
            'info' => 1,
            'suggestion' => 1,
        ], $severityCounts);
    }

    public function testGetFileCounts(): void
    {
        $fileCounts = $this->collection->getFileCounts();

        $this->assertEquals([
            'src/Test1.php' => 2,
            'src/Test2.php' => 2,
        ], $fileCounts);
    }

    public function testGetRuleCounts(): void
    {
        $ruleCounts = $this->collection->getRuleCounts();

        $expected = [
            'a9f\\Typo3Fractor\\Remove\\RemoveRule' => 1,
            'a9f\\Typo3Fractor\\Fractor\\v10\\v0\\DeprecatedRule' => 1,
            'a9f\\Typo3Fractor\\CodeQuality\\QualityRule' => 1,
            'a9f\\Typo3Fractor\\General\\GeneralRule' => 1,
        ];

        $this->assertEquals($expected, $ruleCounts);
    }

    public function testGetTopAffectedFiles(): void
    {
        $topFiles = $this->collection->getTopAffectedFiles();

        $this->assertCount(2, $topFiles);
        $this->assertEquals([
            'src/Test1.php' => 2,
            'src/Test2.php' => 2,
        ], $topFiles);

        // Test limit functionality
        $topFileWithLimit = $this->collection->getTopAffectedFiles(1);
        $this->assertCount(1, $topFileWithLimit);
    }

    public function testGetTopTriggeredRules(): void
    {
        $topRules = $this->collection->getTopTriggeredRules();

        $this->assertCount(4, $topRules);
        $this->assertArrayHasKey('a9f\\Typo3Fractor\\Remove\\RemoveRule', $topRules);
        $this->assertArrayHasKey('a9f\\Typo3Fractor\\Fractor\\v10\\v0\\DeprecatedRule', $topRules);
        $this->assertArrayHasKey('a9f\\Typo3Fractor\\CodeQuality\\QualityRule', $topRules);
        $this->assertArrayHasKey('a9f\\Typo3Fractor\\General\\GeneralRule', $topRules);

        // Test limit functionality
        $topRulesWithLimit = $this->collection->getTopTriggeredRules(2);
        $this->assertCount(2, $topRulesWithLimit);
    }

    public function testGetAffectedFileCount(): void
    {
        $this->assertEquals(2, $this->collection->getAffectedFileCount());

        $emptyCollection = new FractorFindingsCollection([], [], [], [], [], []);
        $this->assertEquals(0, $emptyCollection->getAffectedFileCount());
    }

    public function testGetTriggeredRuleCount(): void
    {
        $this->assertEquals(4, $this->collection->getTriggeredRuleCount());

        $emptyCollection = new FractorFindingsCollection([], [], [], [], [], []);
        $this->assertEquals(0, $emptyCollection->getTriggeredRuleCount());
    }

    public function testGetTypeCounts(): void
    {
        $typeCounts = $this->collection->getTypeCounts();

        $expected = [
            'breaking_change' => 1,
            'deprecation' => 1,
            'best_practice' => 2,
        ];

        $this->assertEquals($expected, $typeCounts);
    }

    public function testGetTypeCountsWithEmptyCollection(): void
    {
        $emptyCollection = new FractorFindingsCollection([], [], [], [], [], []);
        $typeCounts = $emptyCollection->getTypeCounts();

        $this->assertEmpty($typeCounts);
    }

    public function testJsonSerialize(): void
    {
        $serialized = $this->collection->jsonSerialize();

        $expected = [
            'total_count' => 4,
            'breaking_changes_count' => 1,
            'deprecations_count' => 1,
            'improvements_count' => 2,
            'severity_counts' => [
                'critical' => 1,
                'warning' => 1,
                'info' => 1,
                'suggestion' => 1,
            ],
            'file_counts' => [
                'src/Test1.php' => 2,
                'src/Test2.php' => 2,
            ],
            'rule_counts' => [
                'a9f\\Typo3Fractor\\Remove\\RemoveRule' => 1,
                'a9f\\Typo3Fractor\\Fractor\\v10\\v0\\DeprecatedRule' => 1,
                'a9f\\Typo3Fractor\\CodeQuality\\QualityRule' => 1,
                'a9f\\Typo3Fractor\\General\\GeneralRule' => 1,
            ],
            'type_counts' => [
                'breaking_change' => 1,
                'deprecation' => 1,
                'best_practice' => 2,
            ],
            'affected_file_count' => 2,
            'triggered_rule_count' => 4,
            'top_affected_files' => [
                'src/Test1.php' => 2,
                'src/Test2.php' => 2,
            ],
            'top_triggered_rules' => [
                'a9f\\Typo3Fractor\\Remove\\RemoveRule' => 1,
                'a9f\\Typo3Fractor\\Fractor\\v10\\v0\\DeprecatedRule' => 1,
                'a9f\\Typo3Fractor\\CodeQuality\\QualityRule' => 1,
                'a9f\\Typo3Fractor\\General\\GeneralRule' => 1,
            ],
        ];

        $this->assertEquals($expected, $serialized);

        // Verify it can be JSON encoded without issues
        $json = json_encode($serialized, JSON_THROW_ON_ERROR);
        $this->assertIsString($json);
        $this->assertJson($json);
    }

    public function testJsonSerializeWithEmptyCollection(): void
    {
        $emptyCollection = new FractorFindingsCollection([], [], [], [], [], []);
        $serialized = $emptyCollection->jsonSerialize();

        $expected = [
            'total_count' => 0,
            'breaking_changes_count' => 0,
            'deprecations_count' => 0,
            'improvements_count' => 0,
            'severity_counts' => [],
            'file_counts' => [],
            'rule_counts' => [],
            'type_counts' => [],
            'affected_file_count' => 0,
            'triggered_rule_count' => 0,
            'top_affected_files' => [],
            'top_triggered_rules' => [],
        ];

        $this->assertEquals($expected, $serialized);

        // Verify empty collection can also be JSON encoded
        $json = json_encode($serialized, JSON_THROW_ON_ERROR);
        $this->assertIsString($json);
        $this->assertJson($json);
    }

    public function testEmptyCollectionMethods(): void
    {
        $emptyCollection = new FractorFindingsCollection([], [], [], [], [], []);

        $this->assertEmpty($emptyCollection->getBreakingChanges());
        $this->assertEmpty($emptyCollection->getDeprecations());
        $this->assertEmpty($emptyCollection->getImprovements());
        $this->assertEmpty($emptyCollection->getBySeverity());
        $this->assertEmpty($emptyCollection->getByFile());
        $this->assertEmpty($emptyCollection->getByRule());

        $this->assertFalse($emptyCollection->hasBreakingChanges());
        $this->assertFalse($emptyCollection->hasDeprecations());
        $this->assertFalse($emptyCollection->hasImprovements());

        $this->assertEquals(0, $emptyCollection->getTotalCount());
        $this->assertEquals(0, $emptyCollection->getAffectedFileCount());
        $this->assertEquals(0, $emptyCollection->getTriggeredRuleCount());

        $this->assertEmpty($emptyCollection->getTopAffectedFiles());
        $this->assertEmpty($emptyCollection->getTopTriggeredRules());
        $this->assertEmpty($emptyCollection->getTypeCounts());
    }
}
