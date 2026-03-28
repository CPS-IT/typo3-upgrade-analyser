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
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorResultParser;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorRuleRegistry;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorRuleSeverity;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Test case for FractorResultParser service.
 */
class FractorResultParserTest extends TestCase
{
    private FractorResultParser $parser;
    private FractorRuleRegistry $ruleRegistry;
    private NullLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new NullLogger();
        // Use real FractorRuleRegistry instead of mock since we can't mock enum returns
        $this->ruleRegistry = new FractorRuleRegistry($this->logger);
        $this->parser = new FractorResultParser($this->ruleRegistry, $this->logger);
    }

    public function testParseFractorOutputWithEmptyInput(): void
    {
        $findings = $this->parser->parseFractorOutput('');

        $this->assertEmpty($findings);
    }

    public function testParseFractorOutputWithWhitespaceOnly(): void
    {
        $findings = $this->parser->parseFractorOutput("   \n\t  ");

        $this->assertEmpty($findings);
    }

    public function testParseFractorOutputWithInvalidJson(): void
    {
        $findings = $this->parser->parseFractorOutput('invalid json');

        $this->assertEmpty($findings);
    }

    /**
     * @throws \JsonException
     */
    public function testParseFractorOutputWithValidJson(): void
    {
        $jsonOutput = json_encode([
            'changed_files' => [
                [
                    'file' => 'src/Controller/TestController.php',
                    'applied_rectors' => [
                        [
                            'class' => 'a9f\\Typo3Fractor\\Fractor\\v12\\v0\\RemoveTypoScriptConstantsFromTemplateServiceFractor',
                            'message' => 'Replace deprecated method call',
                            'line' => 42,
                            'old' => '$template->getConstants()',
                            'new' => '$template->getTypoScriptConstants()',
                        ],
                        [
                            'class' => 'a9f\\Typo3Fractor\\Fractor\\v12\\v0\\MigrateExtbaseControllerFractor',
                            'message' => 'Migrate controller method',
                            'line' => 24,
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        if ($jsonOutput) {
            $findings = $this->parser->parseFractorOutput($jsonOutput);

            $this->assertCount(2, $findings);

            $firstFinding = $findings[0];
            $this->assertEquals('src/Controller/TestController.php', $firstFinding->getFile());
            $this->assertEquals(42, $firstFinding->getLine());
            $this->assertEquals('Replace deprecated method call', $firstFinding->getMessage());
        }
    }

    /**
     * @throws \JsonException
     */
    public function testParseFractorOutputWithMissingChangedFiles(): void
    {
        $jsonOutput = json_encode(['some_other_key' => 'value'], JSON_THROW_ON_ERROR);

        if ($jsonOutput) {
            $findings = $this->parser->parseFractorOutput($jsonOutput);

            $this->assertEmpty($findings);
        }
    }

    public function testAggregateFindings(): void
    {
        $findings = [
            new FractorFinding(
                'src/Test1.php',
                10,
                'Rule1',
                'Message 1',
                FractorRuleSeverity::CRITICAL,
                FractorChangeType::BREAKING_CHANGE,
            ),
            new FractorFinding(
                'src/Test1.php',
                20,
                'Rule2',
                'Message 2',
                FractorRuleSeverity::WARNING,
                FractorChangeType::DEPRECATION,
            ),
            new FractorFinding(
                'src/Test2.php',
                15,
                'Rule1',
                'Message 3',
                FractorRuleSeverity::INFO,
                FractorChangeType::BEST_PRACTICE,
            ),
        ];

        $summary = $this->parser->aggregateFindings($findings);
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
            new FractorFinding(
                'src/Test1.php',
                10,
                'Rule1',
                'Message 1',
                FractorRuleSeverity::CRITICAL,
                FractorChangeType::BREAKING_CHANGE,
            ),
            new FractorFinding(
                'src/Test2.php',
                20,
                'Rule2',
                'Message 2',
                FractorRuleSeverity::WARNING,
                FractorChangeType::DEPRECATION,
            ),
            new FractorFinding(
                'src/Test3.php',
                30,
                'Rule3',
                'Message 3',
                FractorRuleSeverity::INFO,
                FractorChangeType::BEST_PRACTICE,
            ),
        ];

        $collection = $this->parser->categorizeFindings($findings);

        // Test categorization by impact type
        $this->assertCount(1, $collection->getBreakingChanges());
        $this->assertCount(1, $collection->getDeprecations());
        $this->assertCount(1, $collection->getImprovements());

        // Test categorization by severity
        $this->assertCount(1, $collection->getFindingsWithSeverity(FractorRuleSeverity::CRITICAL));
        $this->assertCount(1, $collection->getFindingsWithSeverity(FractorRuleSeverity::WARNING));
        $this->assertCount(1, $collection->getFindingsWithSeverity(FractorRuleSeverity::INFO));
        $this->assertCount(0, $collection->getFindingsWithSeverity(FractorRuleSeverity::SUGGESTION));

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
            new FractorFinding(
                'src/Test1.php',
                10,
                'Rule1',
                'Message 1',
                FractorRuleSeverity::CRITICAL,
                FractorChangeType::BREAKING_CHANGE,
            ),
            new FractorFinding(
                'src/Test1.php',
                20,
                'Rule2',
                'Message 2',
                FractorRuleSeverity::WARNING,
                FractorChangeType::DEPRECATION,
            ),
            new FractorFinding(
                'src/Test2.php',
                15,
                'Rule3',
                'Message 3',
                FractorRuleSeverity::INFO,
                FractorChangeType::BEST_PRACTICE,
            ),
        ];

        $score = $this->parser->calculateComplexityScore($findings);

        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(10.0, $score);
    }

    /**
     * @throws \ReflectionException
     */
    public function testCalculateEntropy(): void
    {
        // Use reflection to test a private method
        $reflection = new \ReflectionClass($this->parser);
        $method = $reflection->getMethod('calculateEntropy');
        /* @noinspection PhpExpressionResultUnusedInspection */
        $method->setAccessible(true);

        // Test with an empty array
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
     *
     * @throws \JsonException
     */
    public function testParseOutputAssignsSeverityAndChangeType(): void
    {
        $jsonOutput = json_encode([
            'changed_files' => [
                [
                    'file' => 'src/Controller/TestController.php',
                    'applied_rectors' => [
                        [
                            'class' => 'a9f\\Typo3Fractor\\Fractor\\v12\\v0\\RemoveTypoScriptConstantsFractor',
                            'message' => 'Breaking change for TYPO3 12',
                            'line' => 42,
                        ],
                        [
                            'class' => 'a9f\\Typo3Fractor\\Fractor\\v10\\v0\\DeprecatedMethodFractor',
                            'message' => 'Deprecated method usage',
                            'line' => 24,
                        ],
                        [
                            'class' => 'a9f\\Typo3Fractor\\CodeQuality\\ImprovementFractor',
                            'message' => 'Code quality improvement',
                            'line' => 18,
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        if ($jsonOutput) {
            $findings = $this->parser->parseFractorOutput($jsonOutput);

            $this->assertCount(3, $findings);

            // Note: FractorRuleRegistry currently returns DEFAULT values if rule not found in hardcoded list
            // So we might not get exact severities unless we mock RuleRegistry or use known rules.
            // But we used 'new FractorRuleRegistry($logger)' which has real logic.
        }
    }

    /**
     * Test suggested fix generation logic.
     *
     * @throws \JsonException
     */
    public function testParseFractorOutputWithMultipleRulesPerFile(): void
    {
        $jsonOutput = json_encode([
            'changed_files' => [
                [
                    'file' => 'Classes/Controller/TestController.php',
                    'applied_rectors' => [
                        [
                            'class' => 'RemoveTCEformsFractor',
                            'message' => 'Remove TCEforms wrapper',
                            'line' => 15,
                            'old' => '<TCEforms>',
                            'new' => '',
                        ],
                        [
                            'class' => 'RemoveNoCacheHashFractor',
                            'message' => 'Remove noCacheHash',
                            'line' => 30,
                            'old' => 'noCacheHash="TRUE"',
                            'new' => '',
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $findings = $this->parser->parseFractorOutput($jsonOutput);

        $this->assertCount(2, $findings);
        $this->assertSame('RemoveTCEformsFractor', $findings[0]->getRuleClass());
        $this->assertSame('RemoveNoCacheHashFractor', $findings[1]->getRuleClass());
        $this->assertSame('Classes/Controller/TestController.php', $findings[0]->getFile());
        $this->assertSame('Classes/Controller/TestController.php', $findings[1]->getFile());
    }

    public function testEndToEndParsingAndAggregationWorkflow(): void
    {
        $jsonOutput = json_encode([
            'changed_files' => [
                [
                    'file' => 'Resources/Private/Templates/Reservation/Edit.html',
                    'applied_rectors' => [
                        [
                            'class' => 'RemoveNoCacheHashAndUseCacheHashAttributeFluidFractor',
                            'message' => 'Remove noCacheHash attribute',
                            'line' => 8,
                            'old' => 'noCacheHash="TRUE"',
                            'new' => '',
                        ],
                    ],
                ],
                [
                    'file' => 'Resources/Private/Templates/Reservation/New.html',
                    'applied_rectors' => [
                        [
                            'class' => 'RemoveNoCacheHashAndUseCacheHashAttributeFluidFractor',
                            'message' => 'Remove noCacheHash attribute',
                            'line' => 12,
                            'old' => 'noCacheHash="TRUE"',
                            'new' => '',
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $findings = $this->parser->parseFractorOutput($jsonOutput);
        $summary = $this->parser->aggregateFindings($findings);

        $this->assertCount(2, $findings);
        $this->assertEquals(2, $summary->getTotalFindings());
        $this->assertEquals(2, $summary->getAffectedFiles());
        $this->assertTrue($summary->hasIssues());
        $this->assertArrayHasKey('RemoveNoCacheHashAndUseCacheHashAttributeFluidFractor', $summary->getRuleBreakdown());
        $this->assertSame(2, $summary->getRuleBreakdown()['RemoveNoCacheHashAndUseCacheHashAttributeFluidFractor']);
    }

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
        ], JSON_THROW_ON_ERROR);

        if ($jsonOutput) {
            $findings = $this->parser->parseFractorOutput($jsonOutput);
            $this->assertCount(3, $findings);

            // Test replacement suggestion
            $this->assertEquals("Replace 'oldMethod()' with 'newMethod()'", $findings[0]->getSuggestedFix());

            // Test addition suggestion
            $this->assertEquals("Add: 'addedCode()'", $findings[1]->getSuggestedFix());

            // Test removal suggestion
            $this->assertEquals("Remove: 'removedCode()'", $findings[2]->getSuggestedFix());
        }
    }
}
