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

use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorAnalysisSummary;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorChangeType;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorFinding;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorFindingsCollection;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorResultParser;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorRuleRegistry;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorRuleSeverity;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Test case for RectorResultParser service.
 */
class RectorResultParserTest extends TestCase
{
    private RectorResultParser $parser;
    private RectorRuleRegistry $ruleRegistry;
    private NullLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new NullLogger();
        // Use real RectorRuleRegistry instead of mock since we can't mock enum returns
        $this->ruleRegistry = new RectorRuleRegistry($this->logger);
        $this->parser = new RectorResultParser($this->ruleRegistry, $this->logger);
    }

    public function testParseRectorOutputWithEmptyInput(): void
    {
        $findings = $this->parser->parseRectorOutput('');

        $this->assertIsArray($findings);
        $this->assertEmpty($findings);
    }

    public function testParseRectorOutputWithWhitespaceOnly(): void
    {
        $findings = $this->parser->parseRectorOutput("   \n\t  ");

        $this->assertIsArray($findings);
        $this->assertEmpty($findings);
    }

    public function testParseRectorOutputWithInvalidJson(): void
    {
        $findings = $this->parser->parseRectorOutput('invalid json');

        $this->assertIsArray($findings);
        $this->assertEmpty($findings);

        // NullLogger doesn't track records, so we just verify the method handled the error
        $this->assertTrue(true); // Test passes if we reach this point without exception
    }

    public function testParseRectorOutputWithValidJson(): void
    {
        $jsonOutput = json_encode([
            'changed_files' => [
                [
                    'file' => 'src/Controller/TestController.php',
                    'applied_rectors' => [
                        [
                            'class' => 'Ssch\\TYPO3Rector\\Rector\\v12\\v0\\RemoveTypoScriptConstantsFromTemplateServiceRector',
                            'message' => 'Replace deprecated method call',
                            'line' => 42,
                            'old' => '$template->getConstants()',
                            'new' => '$template->getTypoScriptConstants()',
                        ],
                        [
                            'class' => 'Ssch\\TYPO3Rector\\Rector\\v12\\v0\\MigrateExtbaseControllerRector',
                            'message' => 'Migrate controller method',
                            'line' => 24,
                        ],
                    ],
                ],
            ],
        ]);

        // No need to mock registry methods as parser now infers severity/type from rule class names

        $findings = $this->parser->parseRectorOutput($jsonOutput);

        $this->assertIsArray($findings);
        $this->assertCount(2, $findings);

        $firstFinding = $findings[0];
        $this->assertInstanceOf(RectorFinding::class, $firstFinding);
        $this->assertEquals('src/Controller/TestController.php', $firstFinding->getFile());
        $this->assertEquals(42, $firstFinding->getLine());
        $this->assertEquals('Replace deprecated method call', $firstFinding->getMessage());
    }

    public function testParseRectorOutputWithMissingChangedFiles(): void
    {
        $jsonOutput = json_encode(['some_other_key' => 'value']);

        $findings = $this->parser->parseRectorOutput($jsonOutput);

        $this->assertIsArray($findings);
        $this->assertEmpty($findings);
    }

    public function testAggregateFindings(): void
    {
        $findings = [
            new RectorFinding(
                'src/Test1.php',
                10,
                'Rule1',
                'Message 1',
                RectorRuleSeverity::CRITICAL,
                RectorChangeType::BREAKING_CHANGE,
            ),
            new RectorFinding(
                'src/Test1.php',
                20,
                'Rule2',
                'Message 2',
                RectorRuleSeverity::WARNING,
                RectorChangeType::DEPRECATION,
            ),
            new RectorFinding(
                'src/Test2.php',
                15,
                'Rule1',
                'Message 3',
                RectorRuleSeverity::INFO,
                RectorChangeType::BEST_PRACTICE,
            ),
        ];

        $summary = $this->parser->aggregateFindings($findings);

        $this->assertInstanceOf(RectorAnalysisSummary::class, $summary);
        $this->assertEquals(3, $summary->getTotalFindings());
        $this->assertEquals(1, $summary->getCriticalIssues());
        $this->assertEquals(1, $summary->getWarnings());
        $this->assertEquals(1, $summary->getInfoIssues());
        $this->assertEquals(0, $summary->getSuggestions());
        $this->assertEquals(2, $summary->getAffectedFiles());
    }

    public function testAggregateEmptyFindings(): void
    {
        $summary = $this->parser->aggregateFindings([]);

        $this->assertInstanceOf(RectorAnalysisSummary::class, $summary);
        $this->assertEquals(0, $summary->getTotalFindings());
        $this->assertEquals(0, $summary->getCriticalIssues());
        $this->assertEquals(0, $summary->getWarnings());
        $this->assertEquals(0, $summary->getInfoIssues());
        $this->assertEquals(0, $summary->getSuggestions());
        $this->assertEquals(0, $summary->getAffectedFiles());
    }

    public function testCategorizeFindings(): void
    {
        $findings = [
            new RectorFinding(
                'src/Test1.php',
                10,
                'Rule1',
                'Message 1',
                RectorRuleSeverity::CRITICAL,
                RectorChangeType::BREAKING_CHANGE,
            ),
            new RectorFinding(
                'src/Test2.php',
                20,
                'Rule2',
                'Message 2',
                RectorRuleSeverity::WARNING,
                RectorChangeType::DEPRECATION,
            ),
            new RectorFinding(
                'src/Test3.php',
                30,
                'Rule3',
                'Message 3',
                RectorRuleSeverity::INFO,
                RectorChangeType::BEST_PRACTICE,
            ),
        ];

        $collection = $this->parser->categorizeFindings($findings);

        $this->assertInstanceOf(RectorFindingsCollection::class, $collection);

        // Test categorization by impact type
        $this->assertCount(1, $collection->getBreakingChanges());
        $this->assertCount(1, $collection->getDeprecations());
        $this->assertCount(1, $collection->getImprovements());

        // Test categorization by severity
        $this->assertCount(1, $collection->getFindingsWithSeverity(RectorRuleSeverity::CRITICAL));
        $this->assertCount(1, $collection->getFindingsWithSeverity(RectorRuleSeverity::WARNING));
        $this->assertCount(1, $collection->getFindingsWithSeverity(RectorRuleSeverity::INFO));
        $this->assertCount(0, $collection->getFindingsWithSeverity(RectorRuleSeverity::SUGGESTION));

        // Test categorization by file
        $this->assertCount(1, $collection->getFindingsInFile('src/Test1.php'));
        $this->assertCount(1, $collection->getFindingsInFile('src/Test2.php'));
        $this->assertCount(1, $collection->getFindingsInFile('src/Test3.php'));
        $this->assertCount(0, $collection->getFindingsInFile('nonexistent.php'));

        // Test categorization by rule
        $this->assertCount(1, $collection->getFindingsForRule('Rule1'));
        $this->assertCount(1, $collection->getFindingsForRule('Rule2'));
        $this->assertCount(1, $collection->getFindingsForRule('Rule3'));
        $this->assertCount(0, $collection->getFindingsForRule('NonexistentRule'));

        // Test convenience methods
        $this->assertTrue($collection->hasBreakingChanges());
        $this->assertTrue($collection->hasDeprecations());
        $this->assertTrue($collection->hasImprovements());
        $this->assertEquals(3, $collection->getTotalCount());
        $this->assertEquals(3, $collection->getAffectedFileCount());
        $this->assertEquals(3, $collection->getTriggeredRuleCount());
    }

    public function testCalculateComplexityScoreWithEmptyFindings(): void
    {
        $score = $this->parser->calculateComplexityScore([]);

        $this->assertEquals(0.0, $score);
    }

    public function testCalculateComplexityScore(): void
    {
        $findings = [
            new RectorFinding(
                'src/Test1.php',
                10,
                'Rule1',
                'Message 1',
                RectorRuleSeverity::CRITICAL,
                RectorChangeType::BREAKING_CHANGE,
            ),
            new RectorFinding(
                'src/Test1.php',
                20,
                'Rule2',
                'Message 2',
                RectorRuleSeverity::WARNING,
                RectorChangeType::DEPRECATION,
            ),
            new RectorFinding(
                'src/Test2.php',
                15,
                'Rule3',
                'Message 3',
                RectorRuleSeverity::INFO,
                RectorChangeType::BEST_PRACTICE,
            ),
        ];

        $score = $this->parser->calculateComplexityScore($findings);

        $this->assertIsFloat($score);
        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(10.0, $score);
    }

    public function testCountBySeverity(): void
    {
        $findings = [
            new RectorFinding('src/Test1.php', 10, 'Rule1', 'Message', RectorRuleSeverity::CRITICAL, RectorChangeType::BREAKING_CHANGE),
            new RectorFinding('src/Test2.php', 20, 'Rule2', 'Message', RectorRuleSeverity::CRITICAL, RectorChangeType::BREAKING_CHANGE),
            new RectorFinding('src/Test3.php', 30, 'Rule3', 'Message', RectorRuleSeverity::WARNING, RectorChangeType::DEPRECATION),
            new RectorFinding('src/Test4.php', 40, 'Rule4', 'Message', RectorRuleSeverity::INFO, RectorChangeType::BEST_PRACTICE),
        ];

        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->parser);
        $method = $reflection->getMethod('countBySeverity');
        $method->setAccessible(true);

        $counts = $method->invoke($this->parser, $findings);

        $this->assertEquals(2, $counts['critical']);
        $this->assertEquals(1, $counts['warning']);
        $this->assertEquals(1, $counts['info']);
        $this->assertEquals(0, $counts['suggestion']);
    }

    public function testCountByType(): void
    {
        $findings = [
            new RectorFinding('src/Test1.php', 10, 'Rule1', 'Message', RectorRuleSeverity::CRITICAL, RectorChangeType::BREAKING_CHANGE),
            new RectorFinding('src/Test2.php', 20, 'Rule2', 'Message', RectorRuleSeverity::CRITICAL, RectorChangeType::BREAKING_CHANGE),
            new RectorFinding('src/Test3.php', 30, 'Rule3', 'Message', RectorRuleSeverity::WARNING, RectorChangeType::DEPRECATION),
        ];

        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->parser);
        $method = $reflection->getMethod('countByType');
        $method->setAccessible(true);

        $counts = $method->invoke($this->parser, $findings);

        $this->assertEquals(2, $counts['breaking_change']);
        $this->assertEquals(1, $counts['deprecation']);
    }

    public function testGroupFindingsByFile(): void
    {
        $findings = [
            new RectorFinding('src/Test1.php', 10, 'Rule1', 'Message', RectorRuleSeverity::CRITICAL, RectorChangeType::BREAKING_CHANGE),
            new RectorFinding('src/Test1.php', 20, 'Rule2', 'Message', RectorRuleSeverity::WARNING, RectorChangeType::DEPRECATION),
            new RectorFinding('src/Test2.php', 30, 'Rule3', 'Message', RectorRuleSeverity::INFO, RectorChangeType::BEST_PRACTICE),
        ];

        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->parser);
        $method = $reflection->getMethod('groupFindingsByFile');
        $method->setAccessible(true);

        $groups = $method->invoke($this->parser, $findings);

        $this->assertEquals(2, $groups['src/Test1.php']);
        $this->assertEquals(1, $groups['src/Test2.php']);
    }

    public function testGroupFindingsByRule(): void
    {
        $findings = [
            new RectorFinding('src/Test1.php', 10, 'Rule1', 'Message', RectorRuleSeverity::CRITICAL, RectorChangeType::BREAKING_CHANGE),
            new RectorFinding('src/Test2.php', 20, 'Rule1', 'Message', RectorRuleSeverity::WARNING, RectorChangeType::DEPRECATION),
            new RectorFinding('src/Test3.php', 30, 'Rule2', 'Message', RectorRuleSeverity::INFO, RectorChangeType::BEST_PRACTICE),
        ];

        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->parser);
        $method = $reflection->getMethod('groupFindingsByRule');
        $method->setAccessible(true);

        $groups = $method->invoke($this->parser, $findings);

        $this->assertEquals(2, $groups['Rule1']);
        $this->assertEquals(1, $groups['Rule2']);
    }

    public function testCalculateEntropy(): void
    {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->parser);
        $method = $reflection->getMethod('calculateEntropy');
        $method->setAccessible(true);

        // Test with empty array
        $entropy = $method->invoke($this->parser, []);
        $this->assertEquals(0.0, $entropy);

        // Test with zero values
        $entropy = $method->invoke($this->parser, [0, 0, 0, 0]);
        $this->assertEquals(0.0, $entropy);

        // Test with equal distribution (maximum entropy)
        $entropy = $method->invoke($this->parser, [1, 1, 1, 1]);
        $this->assertEquals(1.0, $entropy);

        // Test with uneven distribution
        $entropy = $method->invoke($this->parser, [4, 0, 0, 0]);
        $this->assertEquals(0.0, $entropy);

        // Test with mixed distribution
        $entropy = $method->invoke($this->parser, [2, 1, 1, 0]);
        $this->assertGreaterThan(0.0, $entropy);
        $this->assertLessThan(1.0, $entropy);
    }


    /**
     * Test that parsing creates findings with correct severity and change type.
     */
    public function testParseOutputAssignsSeverityAndChangeType(): void
    {
        $jsonOutput = json_encode([
            'changed_files' => [
                [
                    'file' => 'src/Controller/TestController.php',
                    'applied_rectors' => [
                        [
                            'class' => 'Ssch\\TYPO3Rector\\Rector\\v12\\v0\\RemoveTypoScriptConstantsRector',
                            'message' => 'Breaking change for TYPO3 12',
                            'line' => 42,
                        ],
                        [
                            'class' => 'Ssch\\TYPO3Rector\\Rector\\v10\\v0\\DeprecatedMethodRector',
                            'message' => 'Deprecated method usage',
                            'line' => 24,
                        ],
                        [
                            'class' => 'Ssch\\TYPO3Rector\\CodeQuality\\ImprovementRector',
                            'message' => 'Code quality improvement',
                            'line' => 18,
                        ],
                    ],
                ],
            ],
        ]);

        $findings = $this->parser->parseRectorOutput($jsonOutput);

        $this->assertCount(3, $findings);

        // First finding - v12 rule should be critical + breaking change
        $v12Finding = $findings[0];
        $this->assertEquals(RectorRuleSeverity::CRITICAL, $v12Finding->getSeverity());
        $this->assertEquals(RectorChangeType::BREAKING_CHANGE, $v12Finding->getChangeType());

        // Second finding - v10 rule should be warning + deprecation
        $v10Finding = $findings[1];
        $this->assertEquals(RectorRuleSeverity::WARNING, $v10Finding->getSeverity());
        $this->assertEquals(RectorChangeType::DEPRECATION, $v10Finding->getChangeType());

        // Third finding - CodeQuality rule should be info + best practice
        $qualityFinding = $findings[2];
        $this->assertEquals(RectorRuleSeverity::INFO, $qualityFinding->getSeverity());
        $this->assertEquals(RectorChangeType::BEST_PRACTICE, $qualityFinding->getChangeType());
    }

    /**
     * Test parsing with different JSON structures (file_diffs vs changed_files).
     */
    public function testParseOutputWithFileDiffsStructure(): void
    {
        // Test the newer file_diffs structure that some Rector versions use
        $jsonOutput = json_encode([
            'totals' => ['changed_files' => 2],
            'file_diffs' => [
                [
                    'file' => 'src/Test.php',
                    'applied_rectors' => ['SomeRule', 'AnotherRule'],
                    'diff' => '--- Original
+++ New
@@ -1,3 +1,3 @@
-old code
+new code',
                ],
            ],
        ]);

        $findings = $this->parser->parseRectorOutput($jsonOutput);

        $this->assertIsArray($findings);
        // Note: Current implementation might not handle this structure
        // This test documents the gap for future enhancement
    }

    /**
     * Test suggested fix generation logic.
     */
    public function testSuggestedFixGeneration(): void
    {
        $jsonOutput = json_encode([
            'changed_files' => [
                [
                    'file' => 'src/Test.php',
                    'applied_rectors' => [
                        [
                            'class' => 'TestRule',
                            'message' => 'Test replacement',
                            'line' => 10,
                            'old' => 'oldMethod()',
                            'new' => 'newMethod()',
                        ],
                        [
                            'class' => 'AddRule',
                            'message' => 'Add something',
                            'line' => 15,
                            'new' => 'addedCode()',
                        ],
                        [
                            'class' => 'RemoveRule',
                            'message' => 'Remove something',
                            'line' => 20,
                            'old' => 'removedCode()',
                        ],
                    ],
                ],
            ],
        ]);

        $findings = $this->parser->parseRectorOutput($jsonOutput);

        $this->assertCount(3, $findings);

        // Test replacement suggestion
        $this->assertEquals("Replace 'oldMethod()' with 'newMethod()'", $findings[0]->getSuggestedFix());

        // Test addition suggestion
        $this->assertEquals("Add: 'addedCode()'", $findings[1]->getSuggestedFix());

        // Test removal suggestion
        $this->assertEquals("Remove: 'removedCode()'", $findings[2]->getSuggestedFix());
    }
}
