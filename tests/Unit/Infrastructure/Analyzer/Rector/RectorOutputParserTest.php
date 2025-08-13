<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Analyzer\Rector;

use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorChangeType;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorOutputParser;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorRuleSeverity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(RectorOutputParser::class)]
class RectorOutputParserTest extends TestCase
{
    private RectorOutputParser $parser;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->parser = new RectorOutputParser($this->logger);
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
                'Failed to parse Rector JSON output',
                $this->callback(function (array $context): bool {
                    return isset($context['error'])
                        && isset($context['output_preview'])
                        && str_contains($context['error'], 'Syntax error');
                }),
            );

        $result = $this->parser->parse($invalidJson);

        $this->assertEquals([], $result->findings);
        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('Failed to parse Rector output:', $result->errors[0]);
        $this->assertEquals(0, $result->processedFiles);
        $this->assertFalse($result->isSuccessful());
    }

    public function testParseNewRectorVersionWithFileDiffs(): void
    {
        $json = json_encode([
            'totals' => [
                'changed_files' => 3,
            ],
            'file_diffs' => [
                [
                    'file' => 'src/Controller/TestController.php',
                    'applied_rectors' => [
                        'Ssch\\TYPO3Rector\\Rector\\v12\\v0\\RemoveTypoScriptConstantsFromTemplateServiceRector',
                        'Ssch\\TYPO3Rector\\Rector\\v11\\v0\\SubstituteGeneralUtilityMkdirDeepRector',
                    ],
                    'diff' => "@@ -10,7 +10,7 @@\n-    \$old = oldMethod();\n+    \$new = newMethod();\n",
                ],
                [
                    'file' => 'src/Model/TestModel.php',
                    'applied_rectors' => [
                        'Rector\\DeadCode\\Rector\\ClassMethod\\RemoveUnusedParameterRector',
                    ],
                ],
            ],
        ]);

        $result = $this->parser->parse($json ?: '{}');

        $this->assertEquals(3, $result->processedFiles);
        $this->assertCount(3, $result->findings);
        $this->assertEquals([], $result->errors);
        $this->assertTrue($result->isSuccessful());
        $this->assertTrue($result->hasFindings());

        $firstFinding = $result->findings[0];
        $this->assertEquals('src/Controller/TestController.php', $firstFinding->getFile());
        $this->assertEquals('Ssch\\TYPO3Rector\\Rector\\v12\\v0\\RemoveTypoScriptConstantsFromTemplateServiceRector', $firstFinding->getRuleClass());
        $this->assertEquals(RectorRuleSeverity::CRITICAL, $firstFinding->getSeverity());
        $this->assertEquals(RectorChangeType::BREAKING_CHANGE, $firstFinding->getChangeType()); // Remove rules default to BREAKING_CHANGE
        $this->assertNotNull($firstFinding->getOldCode());
        $this->assertNotNull($firstFinding->getNewCode());

        $secondFinding = $result->findings[1];
        $this->assertEquals('src/Controller/TestController.php', $secondFinding->getFile());
        $this->assertEquals('Ssch\\TYPO3Rector\\Rector\\v11\\v0\\SubstituteGeneralUtilityMkdirDeepRector', $secondFinding->getRuleClass());
        $this->assertEquals(RectorRuleSeverity::WARNING, $secondFinding->getSeverity());
        $this->assertEquals(RectorChangeType::DEPRECATION, $secondFinding->getChangeType());

        $thirdFinding = $result->findings[2];
        $this->assertEquals('src/Model/TestModel.php', $thirdFinding->getFile());
        $this->assertEquals('Rector\\DeadCode\\Rector\\ClassMethod\\RemoveUnusedParameterRector', $thirdFinding->getRuleClass());
        $this->assertEquals(RectorRuleSeverity::CRITICAL, $thirdFinding->getSeverity());
        $this->assertEquals(RectorChangeType::METHOD_SIGNATURE, $thirdFinding->getChangeType()); // Remove + Method = METHOD_SIGNATURE
    }

    public function testParseOlderRectorVersionWithChangedFiles(): void
    {
        $json = json_encode([
            'totals' => [
                'changed_files' => 2,
            ],
            'changed_files' => [
                [
                    'file' => 'src/Service/TestService.php',
                    'applied_rectors' => [
                        [
                            'class' => 'Ssch\\TYPO3Rector\\Rector\\v12\\v0\\MigrateRequiredFlagRector',
                            'message' => 'Migrate required flag usage',
                            'line' => 25,
                            'old' => '$field->setRequired(true)',
                            'new' => '$field->setAttribute("required", true)',
                        ],
                    ],
                ],
                [
                    'file' => 'src/Utility/Helper.php',
                    'applied_rectors' => [
                        [
                            'class' => 'Rector\\Php80\\Rector\\Class_\\AnnotationToAttributeRector',
                            'message' => 'Convert annotation to attribute',
                            'line' => 15,
                        ],
                    ],
                ],
            ],
        ]);

        $result = $this->parser->parse($json ?: '{}');

        $this->assertEquals(2, $result->processedFiles);
        $this->assertCount(2, $result->findings);
        $this->assertEquals([], $result->errors);
        $this->assertTrue($result->isSuccessful());

        $firstFinding = $result->findings[0];
        $this->assertEquals('src/Service/TestService.php', $firstFinding->getFile());
        $this->assertEquals(25, $firstFinding->getLine());
        $this->assertEquals('Ssch\\TYPO3Rector\\Rector\\v12\\v0\\MigrateRequiredFlagRector', $firstFinding->getRuleClass());
        $this->assertEquals('Migrate required flag usage', $firstFinding->getMessage());
        $this->assertEquals('$field->setRequired(true)', $firstFinding->getOldCode());
        $this->assertEquals('$field->setAttribute("required", true)', $firstFinding->getNewCode());
        $this->assertEquals("Replace '\$field->setRequired(true)' with '\$field->setAttribute(\"required\", true)'", $firstFinding->getSuggestedFix());
        $this->assertEquals(RectorRuleSeverity::WARNING, $firstFinding->getSeverity());
        $this->assertEquals(RectorChangeType::CONFIGURATION_CHANGE, $firstFinding->getChangeType());

        $secondFinding = $result->findings[1];
        $this->assertEquals('src/Utility/Helper.php', $secondFinding->getFile());
        $this->assertEquals(15, $secondFinding->getLine());
        $this->assertEquals('Rector\\Php80\\Rector\\Class_\\AnnotationToAttributeRector', $secondFinding->getRuleClass());
        $this->assertEquals('Convert annotation to attribute', $secondFinding->getMessage());
        $this->assertNull($secondFinding->getOldCode());
        $this->assertNull($secondFinding->getNewCode());
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
        ]);

        $result = $this->parser->parse($json ?: '{}');

        $this->assertEquals(3, $result->processedFiles);
        $this->assertCount(3, $result->findings);
        $this->assertEquals([], $result->errors);
        $this->assertTrue($result->isSuccessful());

        foreach ($result->findings as $finding) {
            $this->assertEquals(0, $finding->getLine());
            $this->assertEquals('Unknown', $finding->getRuleClass());
            $this->assertEquals('Code changes detected in file', $finding->getMessage());
            $this->assertEquals(RectorRuleSeverity::INFO, $finding->getSeverity());
            $this->assertEquals(RectorChangeType::BEST_PRACTICE, $finding->getChangeType());
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
                    'applied_rectors' => ['TestRector'],
                ],
            ],
            'errors' => [
                'PHP Parse error in file.php',
                'Rule execution failed on line 10',
            ],
        ]);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Rector execution errors detected',
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
        $this->assertFalse($result->isSuccessful());
        $this->assertEquals([
            'PHP Parse error in file.php',
            'Rule execution failed on line 10',
        ], $result->errors);
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
        ]);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Rector execution errors detected',
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
        $this->assertFalse($result->isSuccessful());
    }

    public function testParseWithMissingTotals(): void
    {
        $json = json_encode([
            'file_diffs' => [
                [
                    'file' => 'src/Test.php',
                    'applied_rectors' => ['TestRector'],
                ],
            ],
        ]);

        $result = $this->parser->parse($json ?: '{}');

        $this->assertEquals(0, $result->processedFiles); // Default when totals missing
        $this->assertCount(1, $result->findings);
        $this->assertEquals([], $result->errors);
        $this->assertTrue($result->isSuccessful());
    }

    public function testSeverityDeterminationFromRuleClass(): void
    {
        $testCases = [
            ['RemoveDeprecatedMethodRector', RectorRuleSeverity::CRITICAL],
            ['RemoveUnusedClassRector', RectorRuleSeverity::CRITICAL],
            ['BreakingChangeRector', RectorRuleSeverity::CRITICAL],
            ['SubstituteOldMethodRector', RectorRuleSeverity::WARNING],
            ['MigrateConfigRector', RectorRuleSeverity::WARNING],
            ['BestPracticeRector', RectorRuleSeverity::INFO],
            ['UnknownRector', RectorRuleSeverity::INFO],
        ];

        foreach ($testCases as [$ruleClass, $expectedSeverity]) {
            $json = json_encode([
                'file_diffs' => [
                    [
                        'file' => 'src/Test.php',
                        'applied_rectors' => [$ruleClass],
                    ],
                ],
            ]);

            $result = $this->parser->parse($json ?: '{}');
            $finding = $result->findings[0];

            $this->assertEquals($expectedSeverity, $finding->getSeverity(), "Failed for rule class: {$ruleClass}");
        }
    }

    public function testChangeTypeDeterminationFromRuleClass(): void
    {
        $testCases = [
            ['RemoveMethodRector', RectorChangeType::METHOD_SIGNATURE],
            ['RemoveClassRector', RectorChangeType::CLASS_REMOVAL],
            ['RemoveUnusedRector', RectorChangeType::BREAKING_CHANGE], // Default for Remove without Method/Class
            ['SubstituteMethodRector', RectorChangeType::DEPRECATION],
            ['ReplaceOldCallRector', RectorChangeType::DEPRECATION],
            ['MigrateConfigRector', RectorChangeType::CONFIGURATION_CHANGE],
            ['OptimizeCodeRector', RectorChangeType::BEST_PRACTICE],
            ['UnknownRector', RectorChangeType::BEST_PRACTICE],
        ];

        foreach ($testCases as [$ruleClass, $expectedChangeType]) {
            $json = json_encode([
                'file_diffs' => [
                    [
                        'file' => 'src/Test.php',
                        'applied_rectors' => [$ruleClass],
                    ],
                ],
            ]);

            $result = $this->parser->parse($json ?: '{}');
            $finding = $result->findings[0];

            $this->assertEquals($expectedChangeType, $finding->getChangeType(), "Failed for rule class: {$ruleClass}");
        }
    }

    public function testExtractCodeFromDiff(): void
    {
        $json = json_encode([
            'file_diffs' => [
                [
                    'file' => 'src/Test.php',
                    'applied_rectors' => ['TestRector'],
                    'diff' => "--- a/src/Test.php\n+++ b/src/Test.php\n@@ -10,7 +10,7 @@\n     class Test {\n-        public function oldMethod() {\n-            return 'old';\n+        public function newMethod() {\n+            return 'new';\n         }\n     }",
                ],
            ],
        ]);

        $result = $this->parser->parse($json ?: '{}');
        $finding = $result->findings[0];

        $this->assertNotNull($finding->getOldCode());
        $this->assertNotNull($finding->getNewCode());
        $this->assertStringContainsString('oldMethod', $finding->getOldCode());
        $this->assertStringContainsString('newMethod', $finding->getNewCode());
        $this->assertEquals('Update code according to diff', $finding->getSuggestedFix());
    }

    public function testExtractCodeFromDiffWithNoChanges(): void
    {
        $json = json_encode([
            'file_diffs' => [
                [
                    'file' => 'src/Test.php',
                    'applied_rectors' => ['TestRector'],
                    'diff' => "--- a/src/Test.php\n+++ b/src/Test.php\n@@ -10,7 +10,7 @@\n     class Test {\n         // No actual changes\n     }",
                ],
            ],
        ]);

        $result = $this->parser->parse($json ?: '{}');
        $finding = $result->findings[0];

        $this->assertNull($finding->getOldCode());
        $this->assertNull($finding->getNewCode());
        $this->assertNull($finding->getSuggestedFix());
    }

    public function testParseComplexMixedStructure(): void
    {
        $json = json_encode([
            'totals' => [
                'changed_files' => 5,
            ],
            'file_diffs' => [
                [
                    'file' => 'src/NewFormat.php',
                    'applied_rectors' => [
                        'NewFormatRector',
                        'AnotherNewFormatRector',
                    ],
                    'diff' => "@@ -1,3 +1,3 @@\n-old code\n+new code\n",
                ],
            ],
            'changed_files' => [
                'src/SimpleFile.php',
                [
                    'file' => 'src/StructuredFile.php',
                    'applied_rectors' => [
                        [
                            'class' => 'StructuredRector',
                            'message' => 'Structured message',
                            'line' => 100,
                            'old' => 'old_structured',
                            'new' => 'new_structured',
                        ],
                    ],
                ],
            ],
            'errors' => [
                'String error',
                [
                    'message' => 'Structured error',
                ],
            ],
        ]);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Rector execution errors detected', $this->anything());

        $result = $this->parser->parse($json ?: '{}');

        $this->assertEquals(5, $result->processedFiles);
        $this->assertCount(2, $result->findings); // Only 2 from file_diffs (changed_files ignored when file_diffs present)
        $this->assertCount(2, $result->errors);
        $this->assertFalse($result->isSuccessful());

        // Verify we have findings only from file_diffs (changed_files ignored)
        $ruleClasses = array_map(fn ($finding): string => $finding->getRuleClass(), $result->findings);
        $this->assertContains('NewFormatRector', $ruleClasses);
        $this->assertContains('AnotherNewFormatRector', $ruleClasses);
        $this->assertNotContains('Unknown', $ruleClasses); // changed_files ignored when file_diffs present
        $this->assertNotContains('StructuredRector', $ruleClasses);
    }

    public function testParseRealWorldRectorOutput(): void
    {
        // Based on actual Rector output structure
        $json = json_encode([
            'totals' => [
                'changed_files' => 2,
            ],
            'file_diffs' => [
                [
                    'file' => 'Classes/Controller/AbstractController.php',
                    'applied_rectors' => [
                        'Ssch\\TYPO3Rector\\Rector\\v11\\v0\\SubstituteGeneralUtilityMkdirDeepRector',
                    ],
                    'diff' => "--- a/Classes/Controller/AbstractController.php\n+++ b/Classes/Controller/AbstractController.php\n@@ -15,7 +15,7 @@\n \n     protected function createDirectory(\$path): void\n     {\n-        GeneralUtility::mkdir_deep(\$path);\n+        GeneralUtility::mkdir(\$path, 0755, true);\n     }\n }",
                ],
                [
                    'file' => 'Configuration/TCA/tx_myext_domain_model_item.php',
                    'applied_rectors' => [
                        'Ssch\\TYPO3Rector\\Rector\\v12\\v0\\tca\\MigrateRequiredFlagRector',
                    ],
                ],
            ],
        ]);

        $result = $this->parser->parse($json ?: '{}');

        $this->assertEquals(2, $result->processedFiles);
        $this->assertCount(2, $result->findings);
        $this->assertEquals([], $result->errors);
        $this->assertTrue($result->isSuccessful());

        $firstFinding = $result->findings[0];
        $this->assertEquals('Classes/Controller/AbstractController.php', $firstFinding->getFile());
        $this->assertEquals('SubstituteGeneralUtilityMkdirDeepRector', $firstFinding->getRuleName());
        $this->assertEquals(RectorRuleSeverity::WARNING, $firstFinding->getSeverity());
        $this->assertEquals(RectorChangeType::DEPRECATION, $firstFinding->getChangeType());
        $this->assertStringContainsString('mkdir_deep', $firstFinding->getOldCode() ?? '');
        $this->assertStringContainsString('mkdir', $firstFinding->getNewCode() ?? '');

        $secondFinding = $result->findings[1];
        $this->assertEquals('Configuration/TCA/tx_myext_domain_model_item.php', $secondFinding->getFile());
        $this->assertEquals('MigrateRequiredFlagRector', $secondFinding->getRuleName());
        $this->assertEquals(RectorRuleSeverity::WARNING, $secondFinding->getSeverity());
        $this->assertEquals(RectorChangeType::CONFIGURATION_CHANGE, $secondFinding->getChangeType());
    }

    public function testParseEmptyJsonObject(): void
    {
        $result = $this->parser->parse('{}');

        $this->assertEquals([], $result->findings);
        $this->assertEquals([], $result->errors);
        $this->assertEquals(0, $result->processedFiles);
        $this->assertTrue($result->isSuccessful());
        $this->assertFalse($result->hasFindings());
    }

    public function testParseNullValues(): void
    {
        $json = json_encode([
            'totals' => null,
            'file_diffs' => null,
            'changed_files' => null,
            'errors' => null,
        ]);

        $result = $this->parser->parse($json ?: '{}');

        $this->assertEquals([], $result->findings);
        $this->assertEquals([], $result->errors);
        $this->assertEquals(0, $result->processedFiles);
        $this->assertTrue($result->isSuccessful());
    }

    public function testLoggerNotCalledOnSuccessfulParse(): void
    {
        $json = json_encode([
            'totals' => ['changed_files' => 1],
            'file_diffs' => [
                [
                    'file' => 'src/Test.php',
                    'applied_rectors' => ['TestRector'],
                ],
            ],
        ]);

        $this->logger->expects($this->never())
            ->method('warning');

        $result = $this->parser->parse($json ?: '{}');

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(1, $result->findings);
    }
}
