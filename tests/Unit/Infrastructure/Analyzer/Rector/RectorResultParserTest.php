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
    private \PHPUnit\Framework\MockObject\MockObject $ruleRegistry;
    private NullLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new NullLogger();
        $this->ruleRegistry = $this->createMock(RectorRuleRegistry::class);
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

        $categories = $this->parser->categorizeFindings($findings);

        $this->assertArrayHasKey('breaking_changes', $categories);
        $this->assertArrayHasKey('deprecations', $categories);
        $this->assertArrayHasKey('improvements', $categories);
        $this->assertArrayHasKey('by_severity', $categories);
        $this->assertArrayHasKey('by_file', $categories);
        $this->assertArrayHasKey('by_rule', $categories);

        $this->assertCount(1, $categories['breaking_changes']);
        $this->assertCount(1, $categories['deprecations']);
        $this->assertCount(1, $categories['improvements']);

        $this->assertCount(1, $categories['by_severity']['critical']);
        $this->assertCount(1, $categories['by_severity']['warning']);
        $this->assertCount(1, $categories['by_severity']['info']);

        $this->assertArrayHasKey('src/Test1.php', $categories['by_file']);
        $this->assertArrayHasKey('src/Test2.php', $categories['by_file']);
        $this->assertArrayHasKey('src/Test3.php', $categories['by_file']);

        $this->assertArrayHasKey('Rule1', $categories['by_rule']);
        $this->assertArrayHasKey('Rule2', $categories['by_rule']);
        $this->assertArrayHasKey('Rule3', $categories['by_rule']);
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
}
