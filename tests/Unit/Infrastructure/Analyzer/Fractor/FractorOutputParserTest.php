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
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorOutputParser;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorRuleSeverity;
use CPSIT\UpgradeAnalyzer\Shared\Utility\DiffProcessor;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(FractorOutputParser::class)]
class FractorOutputParserTest extends TestCase
{
    private FractorOutputParser $parser;
    private LoggerInterface&MockObject $logger;
    private DiffProcessor&MockObject $diffProcessor;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->diffProcessor = $this->createMock(DiffProcessor::class);
        $this->parser = new FractorOutputParser($this->logger, $this->diffProcessor);
    }

    public function testParseEmptyOutput(): void
    {
        $result = $this->parser->parse('');

        $this->assertEquals([], $result->findings);
        $this->assertEquals([], $result->errors);
        $this->assertEquals(0, $result->processedFiles);
        $this->assertEquals(0, $result->getFindingsCount());
        $this->assertEquals(0, $result->getErrorsCount());
        $this->assertTrue($result->isSuccessful());
        $this->assertFalse($result->hasFindings());
    }

    public function testParseWhitespaceOnlyOutput(): void
    {
        $result = $this->parser->parse("   \n\t  \r\n  ");

        $this->assertEquals([], $result->findings);
        $this->assertEquals([], $result->errors);
        $this->assertEquals(0, $result->processedFiles);
        $this->assertTrue($result->isSuccessful());
        $this->assertFalse($result->hasFindings());
    }

    public function testParseInvalidJson(): void
    {
        $invalidJson = '{"invalid": json, "missing": quote}';

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Failed to parse Fractor JSON output',
                $this->callback(function (array $context): bool {
                    return isset($context['error'])
                        && isset($context['output_preview'])
                        && str_contains($context['error'], 'Syntax error');
                }),
            );

        $result = $this->parser->parse($invalidJson);

        $this->assertEquals([], $result->findings);
        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('Failed to parse Fractor output:', $result->errors[0]);
        $this->assertEquals(0, $result->processedFiles);
        $this->assertFalse($result->isSuccessful());
    }

    public function testParseNewFractorVersionWithFileDiffs(): void
    {
        $json = json_encode([
            'totals' => [
                'changed_files' => 3,
            ],
            'file_diffs' => [
                [
                    'file' => 'src/Controller/TestController.php',
                    'applied_rules' => [
                        'a9f\\Typo3Fractor\\Fractor\\v12\\v0\\RemoveTypoScriptConstantsFractor',
                        'a9f\\Typo3Fractor\\Fractor\\v11\\v0\\SubstituteGeneralUtilityMkdirDeepFractor',
                    ],
                    'diff' => "@@ -10,7 +10,7 @@\n-    \$old = oldMethod();\n+    \$new = newMethod();\n",
                ],
                [
                    'file' => 'src/Model/TestModel.php',
                    'applied_rules' => [
                        'a9f\\Typo3Fractor\\CodeQuality\\RemoveUnusedParameterFractor',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->diffProcessor->expects($this->exactly(2))
            ->method('extractDiff')
            ->willReturnArgument(0); // Return the input diff as is for this test

        $result = $this->parser->parse($json ?: '{}');

        $this->assertEquals(3, $result->processedFiles);
        $this->assertCount(3, $result->findings);
        $this->assertEquals([], $result->errors);
        $this->assertTrue($result->isSuccessful());
        $this->assertTrue($result->hasFindings());

        $firstFinding = $result->findings[0];
        $this->assertEquals('src/Controller/TestController.php', $firstFinding->getFile());
        $this->assertEquals('a9f\\Typo3Fractor\\Fractor\\v12\\v0\\RemoveTypoScriptConstantsFractor', $firstFinding->getRuleClass());
        $this->assertEquals(FractorRuleSeverity::CRITICAL, $firstFinding->getSeverity());
        $this->assertEquals(FractorChangeType::BREAKING_CHANGE, $firstFinding->getChangeType());
        $this->assertNotNull($firstFinding->getDiff());
        $this->assertEquals("@@ -10,7 +10,7 @@\n-    \$old = oldMethod();\n+    \$new = newMethod();\n", $firstFinding->getDiff());

        $secondFinding = $result->findings[1];
        $this->assertEquals('src/Controller/TestController.php', $secondFinding->getFile());
        $this->assertEquals('a9f\\Typo3Fractor\\Fractor\\v11\\v0\\SubstituteGeneralUtilityMkdirDeepFractor', $secondFinding->getRuleClass());
        $this->assertEquals(FractorRuleSeverity::WARNING, $secondFinding->getSeverity());
        $this->assertEquals(FractorChangeType::DEPRECATION, $secondFinding->getChangeType());
        $this->assertNotNull($secondFinding->getDiff());

        $thirdFinding = $result->findings[2];
        $this->assertEquals('src/Model/TestModel.php', $thirdFinding->getFile());
        $this->assertEquals('a9f\\Typo3Fractor\\CodeQuality\\RemoveUnusedParameterFractor', $thirdFinding->getRuleClass());
        $this->assertEquals(FractorRuleSeverity::SUGGESTION, $thirdFinding->getSeverity()); // CodeQuality = SUGGESTION
        $this->assertEquals(FractorChangeType::BREAKING_CHANGE, $thirdFinding->getChangeType()); // RemoveUnused = BREAKING_CHANGE because it contains "Remove"
        $this->assertNull($thirdFinding->getDiff());
    }

    public function testParseOlderFractorVersionWithChangedFiles(): void
    {
        $json = json_encode([
            'totals' => [
                'changed_files' => 2,
            ],
            'changed_files' => [
                [
                    'file' => 'src/Service/TestService.php',
                    'applied_rules' => [
                        [
                            'class' => 'a9f\\Typo3Fractor\\Fractor\\v12\\v0\\MigrateRequiredFlagFractor',
                            'message' => 'Migrate required flag usage',
                            'line' => 25,
                            'diff' => 'some diff content',
                        ],
                    ],
                ],
                [
                    'file' => 'src/Utility/Helper.php',
                    'applied_rules' => [
                        [
                            'class' => 'a9f\\Typo3Fractor\\CodeQuality\\AnnotationToAttributeFractor',
                            'message' => 'Convert annotation to attribute',
                            'line' => 15,
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->diffProcessor->expects($this->once())
            ->method('extractDiff')
            ->with('some diff content')
            ->willReturn('cleaned diff content');

        $result = $this->parser->parse($json ?: '{}');

        $this->assertEquals(2, $result->processedFiles);
        $this->assertCount(2, $result->findings);
        $this->assertEquals([], $result->errors);
        $this->assertTrue($result->isSuccessful());

        $firstFinding = $result->findings[0];
        $this->assertEquals('src/Service/TestService.php', $firstFinding->getFile());
        $this->assertEquals(25, $firstFinding->getLine());
        $this->assertEquals('a9f\\Typo3Fractor\\Fractor\\v12\\v0\\MigrateRequiredFlagFractor', $firstFinding->getRuleClass());
        $this->assertEquals('Migrate required flag usage', $firstFinding->getMessage());
        $this->assertEquals('cleaned diff content', $firstFinding->getDiff());
        $this->assertEquals('Apply Fractor changes', $firstFinding->getSuggestedFix());
        $this->assertEquals(FractorRuleSeverity::WARNING, $firstFinding->getSeverity());
        $this->assertEquals(FractorChangeType::CONFIGURATION_CHANGE, $firstFinding->getChangeType());

        $secondFinding = $result->findings[1];
        $this->assertEquals('src/Utility/Helper.php', $secondFinding->getFile());
        $this->assertEquals(15, $secondFinding->getLine());
        $this->assertEquals('a9f\\Typo3Fractor\\CodeQuality\\AnnotationToAttributeFractor', $secondFinding->getRuleClass());
        $this->assertEquals('Convert annotation to attribute', $secondFinding->getMessage());
        $this->assertNull($secondFinding->getDiff());
        $this->assertNull($secondFinding->getSuggestedFix());
    }

    public function testParseSimpleChangedFilesList(): void
    {
        $json = json_encode([
            'totals' => [
                'changed_files' => 3,
            ],
            'changed_files' => [
                'src/Controller/UserController.php',
                'src/Model/User.php',
                'src/Repository/UserRepository.php',
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json ?: '{}');

        $this->assertEquals(3, $result->processedFiles);
        $this->assertCount(3, $result->findings);
        $this->assertEquals([], $result->errors);
        $this->assertTrue($result->isSuccessful());

        foreach ($result->findings as $finding) {
            $this->assertEquals(0, $finding->getLine());
            $this->assertEquals('Unknown', $finding->getRuleClass());
            $this->assertEquals('Code changes detected in file', $finding->getMessage());
            $this->assertEquals(FractorRuleSeverity::INFO, $finding->getSeverity());
            $this->assertEquals(FractorChangeType::BEST_PRACTICE, $finding->getChangeType());
            $this->assertEquals('Review changes in file', $finding->getSuggestedFix());
        }

        $this->assertEquals('src/Controller/UserController.php', $result->findings[0]->getFile());
        $this->assertEquals('src/Model/User.php', $result->findings[1]->getFile());
        $this->assertEquals('src/Repository/UserRepository.php', $result->findings[2]->getFile());
    }

    public function testParseWithStringErrors(): void
    {
        $json = json_encode([
            'totals' => [
                'changed_files' => 1,
            ],
            'file_diffs' => [
                [
                    'file' => 'src/Test.php',
                    'applied_rules' => ['TestFractor'],
                ],
            ],
            'errors' => [
                'PHP Parse error in file.php',
                'Rule execution failed on line 10',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Fractor execution errors detected',
                [
                    'error_count' => 2,
                    'errors' => [
                        'PHP Parse error in file.php',
                        'Rule execution failed on line 10',
                    ],
                ],
            );

        $result = $this->parser->parse($json ?: '{}');

        $this->assertEquals(1, $result->processedFiles);
        $this->assertCount(1, $result->findings);
        $this->assertCount(2, $result->errors);
    }

    public function testParseWithStructuredErrors(): void
    {
        $json = json_encode([
            'totals' => [
                'changed_files' => 0,
            ],
            'errors' => [
                [
                    'message' => 'Syntax error detected',
                    'file' => 'broken.php',
                    'line' => 42,
                ],
                [
                    'error' => 'Memory limit exceeded',
                    'details' => 'Processing large file',
                ],
                [
                    'unknown_format' => 'This has no standard fields',
                    'data' => ['complex' => 'structure'],
                ],
                12345, // Non-string/array error
                new \stdClass(), // Object error
            ],
        ], JSON_THROW_ON_ERROR);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Fractor execution errors detected',
                $this->callback(function (array $context): bool {
                    return 5 === $context['error_count']
                        && \is_array($context['errors'])
                        && 5 === \count($context['errors']);
                }),
            );

        $result = $this->parser->parse($json ?: '{}');

        $this->assertCount(5, $result->errors);
        $this->assertEquals('Syntax error detected', $result->errors[0]);
        $this->assertEquals('Memory limit exceeded', $result->errors[1]);
        $this->assertStringContainsString('"unknown_format"', $result->errors[2]);
        $this->assertEquals('12345', $result->errors[3]);
        $this->assertEquals('[]', $result->errors[4]); // stdClass converts to string representation
    }

    public function testParseWithMissingTotals(): void
    {
        $json = json_encode([
            'file_diffs' => [
                [
                    'file' => 'src/Test.php',
                    'applied_rules' => ['TestFractor'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($json ?: '{}');

        $this->assertEquals(0, $result->processedFiles); // Default when totals missing
        $this->assertCount(1, $result->findings);
        $this->assertEquals([], $result->errors);
    }

    public function testSeverityDeterminationFromRuleClass(): void
    {
        $testCases = [
            ['RemoveDeprecatedMethodFractor', FractorRuleSeverity::CRITICAL],
            ['BreakingChangeFractor', FractorRuleSeverity::CRITICAL],
            ['SubstituteOldMethodFractor', FractorRuleSeverity::WARNING],
            ['MigrateConfigFractor', FractorRuleSeverity::WARNING],
            ['CodeQualityFractor', FractorRuleSeverity::SUGGESTION],
            ['BestPracticeFractor', FractorRuleSeverity::INFO],
            ['UnknownFractor', FractorRuleSeverity::INFO],
        ];

        foreach ($testCases as [$ruleClass, $expectedSeverity]) {
            $json = json_encode([
                'file_diffs' => [
                    [
                        'file' => 'src/Test.php',
                        'applied_rules' => [$ruleClass],
                    ],
                ],
            ], JSON_THROW_ON_ERROR);

            $result = $this->parser->parse($json ?: '{}');
            $finding = $result->findings[0];

            $this->assertEquals($expectedSeverity, $finding->getSeverity(), "Failed for rule class: {$ruleClass}");
        }
    }

    public function testChangeTypeDeterminationFromRuleClass(): void
    {
        $testCases = [
            ['RemoveMethodFractor', FractorChangeType::BREAKING_CHANGE],
            ['SubstituteMethodFractor', FractorChangeType::DEPRECATION],
            ['ReplaceOldCallFractor', FractorChangeType::DEPRECATION],
            ['MigrateConfigFractor', FractorChangeType::CONFIGURATION_CHANGE],
            ['OptimizeCodeFractor', FractorChangeType::BEST_PRACTICE],
            ['UnknownFractor', FractorChangeType::BEST_PRACTICE],
        ];

        foreach ($testCases as [$ruleClass, $expectedChangeType]) {
            $json = json_encode([
                'file_diffs' => [
                    [
                        'file' => 'src/Test.php',
                        'applied_rules' => [$ruleClass],
                    ],
                ],
            ], JSON_THROW_ON_ERROR);

            $result = $this->parser->parse($json ?: '{}');
            $finding = $result->findings[0];

            $this->assertEquals($expectedChangeType, $finding->getChangeType(), "Failed for rule class: {$ruleClass}");
        }
    }
}
