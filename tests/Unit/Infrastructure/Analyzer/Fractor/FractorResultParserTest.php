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
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorExecutionResult;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorResultParser;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorRuleSeverity;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Test case for FractorResultParser.
 */
class FractorResultParserTest extends TestCase
{
    private FractorResultParser $parser;

    protected function setUp(): void
    {
        $this->parser = new FractorResultParser(new NullLogger());
    }

    public function testParseEmptyOutput(): void
    {
        $result = new FractorExecutionResult(0, '', '', true);
        $summary = $this->parser->parse($result);

        $this->assertEquals(0, $summary->filesScanned);
        $this->assertEquals(0, $summary->rulesApplied);
        $this->assertEmpty($summary->findings);
        $this->assertTrue($summary->successful);
        $this->assertNull($summary->errorMessage);
    }

    public function testParseWithErrorOutput(): void
    {
        $result = new FractorExecutionResult(
            1,
            '',
            'Error: Configuration file not found',
            false,
        );
        $summary = $this->parser->parse($result);

        $this->assertFalse($summary->successful);
        $this->assertEquals('Error: Configuration file not found', $summary->errorMessage);
    }

    /**
     * @throws \JsonException
     */
    public function testParseJsonOutput(): void
    {
        $jsonOutput = json_encode([
            'files_changed' => 3,
            'rules_applied' => 2,
            'findings' => [],
        ], JSON_THROW_ON_ERROR);

        self::assertIsString($jsonOutput);
        $result = new FractorExecutionResult(0, $jsonOutput, '', true);
        $summary = $this->parser->parse($result);

        $this->assertEquals(3, $summary->filesScanned);
        $this->assertEquals(2, $summary->rulesApplied);
        $this->assertTrue($summary->successful);
    }

    public function testParseInvalidJsonOutput(): void
    {
        $invalidJson = '{"invalid": json}';

        $result = new FractorExecutionResult(0, $invalidJson, '', true);
        $summary = $this->parser->parse($result);

        // Should fall back to defaults when JSON parsing fails
        $this->assertEquals(0, $summary->filesScanned);
        $this->assertEquals(0, $summary->rulesApplied);
        $this->assertEmpty($summary->findings);
    }

    public function testParseTextOutputWithSingleFile(): void
    {
        $textOutput = <<<OUTPUT
            1 files with changes

            1) Configuration/FlexForm/contact_form.xml:15
            ---------- begin diff ----------
            -<type>select</type>
            +<type>single</type>
            ----------- end diff -----------
            * RemoveNoCacheHashFractor (https://docs.typo3.org/changelog)
            OUTPUT;

        $result = new FractorExecutionResult(0, $textOutput, '', true);
        $summary = $this->parser->parse($result);

        $this->assertEquals(1, $summary->filesScanned);
        $this->assertEquals(1, $summary->rulesApplied);
        $this->assertCount(1, $summary->findings);
        $this->assertEquals(1, $summary->changeBlocks);
        $this->assertEquals(2, $summary->changedLines);
        $this->assertContains('Configuration/FlexForm/contact_form.xml:15', $summary->filePaths);
        $this->assertContains('RemoveNoCacheHashFractor', $summary->appliedRules);

        $finding = $summary->findings[0];
        $this->assertEquals('Configuration/FlexForm/contact_form.xml', $finding->getFilePath());
        $this->assertEquals(15, $finding->getLineNumber());
        $this->assertStringContainsString('RemoveNoCacheHashFractor', $finding->getRuleClass());
        $this->assertEquals(FractorRuleSeverity::WARNING, $finding->getSeverity());
        $this->assertEquals(FractorChangeType::MODERNIZATION, $finding->getChangeType()); // RemoveNoCacheHashFractor doesn't contain 'FlexForm'
        $this->assertEquals('<type>select</type>', $finding->getCodeBefore());
        $this->assertEquals('<type>single</type>', $finding->getCodeAfter());
        $this->assertStringContainsString('-<type>select</type>', $finding->getDiff());
        $this->assertStringContainsString('+<type>single</type>', $finding->getDiff());
        $this->assertEquals('https://docs.typo3.org/changelog', $finding->getDocumentationUrl());
    }

    public function testParseTextOutputWithMultipleFiles(): void
    {
        $textOutput = <<<OUTPUT
            3 files with changes

            1) Configuration/TCA/tx_example.php:25
            ---------- begin diff ----------
            -'type' => 'input'
            +'type' => 'text'
            ----------- end diff -----------
            * TCAMigrationFractor (https://docs.typo3.org/tca)

            2) Resources/Private/Templates/Form.html:42
            ---------- begin diff ----------
            -<f:form.noCacheHash>
            +<!-- noCacheHash removed -->
            ----------- end diff -----------
            * FluidTemplateFractor

            3) TypoScript/setup.typoscript:10
            ---------- begin diff ----------
            -config.no_cache = 1
            +config.cache = 0
            ----------- end diff -----------
            * TypoScriptMigrationFractor (https://docs.typo3.org/typoscript)
            OUTPUT;

        $result = new FractorExecutionResult(0, $textOutput, '', true);
        $summary = $this->parser->parse($result);

        $this->assertEquals(3, $summary->filesScanned);
        $this->assertEquals(3, $summary->rulesApplied);
        $this->assertCount(3, $summary->findings);
        $this->assertEquals(3, $summary->changeBlocks);
        $this->assertEquals(6, $summary->changedLines);

        // Test first finding (TCA)
        $tcaFinding = $summary->findings[0];
        $this->assertEquals('Configuration/TCA/tx_example.php', $tcaFinding->getFilePath());
        $this->assertEquals(25, $tcaFinding->getLineNumber());
        $this->assertEquals(FractorChangeType::TCA_MIGRATION, $tcaFinding->getChangeType());
        $this->assertEquals(FractorRuleSeverity::WARNING, $tcaFinding->getSeverity()); // TCAMigrationFractor defaults to WARNING

        // Test second finding (Fluid)
        $fluidFinding = $summary->findings[1];
        $this->assertEquals('Resources/Private/Templates/Form.html', $fluidFinding->getFilePath());
        $this->assertEquals(42, $fluidFinding->getLineNumber());
        $this->assertEquals(FractorChangeType::FLUID_MIGRATION, $fluidFinding->getChangeType());
        $this->assertNull($fluidFinding->getDocumentationUrl());

        // Test third finding (TypoScript)
        $typoscriptFinding = $summary->findings[2];
        $this->assertEquals('TypoScript/setup.typoscript', $typoscriptFinding->getFilePath());
        $this->assertEquals(10, $typoscriptFinding->getLineNumber());
        $this->assertEquals(FractorChangeType::TYPOSCRIPT_MIGRATION, $typoscriptFinding->getChangeType());
        $this->assertEquals('https://docs.typo3.org/typoscript', $typoscriptFinding->getDocumentationUrl());
    }

    public function testParseTextOutputWithComplexDiff(): void
    {
        $textOutput = <<<OUTPUT
            1 files with changes

            1) Configuration/FlexForm/complex_form.xml:10
            ---------- begin diff ----------
            -    <config>
            -        <type>select</type>
            -        <renderMode>checkbox</renderMode>
            -    </config>
            +    <config>
            +        <type>single</type>
            +        <renderMode>default</renderMode>
            +    </config>
            ----------- end diff -----------
            * ComplexFlexFormMigrationFractor (https://docs.typo3.org/flexform)
            OUTPUT;

        $result = new FractorExecutionResult(0, $textOutput, '', true);
        $summary = $this->parser->parse($result);

        $this->assertCount(1, $summary->findings);

        $finding = $summary->findings[0];

        // The parser strips the leading '-' and '+' but adds newlines, then trims the result
        $expectedCodeBefore = "<config>\n        <type>select</type>\n        <renderMode>checkbox</renderMode>\n    </config>";
        $expectedCodeAfter = "<config>\n        <type>single</type>\n        <renderMode>default</renderMode>\n    </config>";

        $this->assertEquals($expectedCodeBefore, $finding->getCodeBefore());
        $this->assertEquals($expectedCodeAfter, $finding->getCodeAfter());
        $this->assertEquals(8, $summary->changedLines); // 4 removed + 4 added
    }

    public function testParseTextOutputWithoutDiff(): void
    {
        $textOutput = <<<OUTPUT
            1 files with changes

            1) Configuration/Services.yaml:5
            * ConfigurationUpdateFractor
            OUTPUT;

        $result = new FractorExecutionResult(0, $textOutput, '', true);
        $summary = $this->parser->parse($result);

        $this->assertCount(1, $summary->findings);

        $finding = $summary->findings[0];
        $this->assertEquals('Configuration/Services.yaml', $finding->getFilePath());
        $this->assertEquals(5, $finding->getLineNumber());
        $this->assertEquals('', $finding->getCodeBefore());
        $this->assertEquals('', $finding->getCodeAfter());
        $this->assertEquals('', $finding->getDiff());
        $this->assertEquals(FractorChangeType::CONFIGURATION_UPDATE, $finding->getChangeType());
    }

    public function testDetermineSeverityFromRuleName(): void
    {
        // Test critical severity for removal rules with deprecation
        $criticalOutput = <<<OUTPUT
            1 files with changes

            1) test.php:1
            * RemoveDeprecatedMethodFractor
            OUTPUT;

        $result = new FractorExecutionResult(0, $criticalOutput, '', true);
        $summary = $this->parser->parse($result);

        $finding = $summary->findings[0];
        $this->assertEquals(FractorRuleSeverity::CRITICAL, $finding->getSeverity());

        // Test warning severity for general removal/replace rules
        $warningOutput = <<<OUTPUT
            1 files with changes

            1) test.php:1
            * ReplaceLegacyCodeFractor
            OUTPUT;

        $result = new FractorExecutionResult(0, $warningOutput, '', true);
        $summary = $this->parser->parse($result);

        $finding = $summary->findings[0];
        $this->assertEquals(FractorRuleSeverity::WARNING, $finding->getSeverity());

        // Test info severity for migration rules
        $infoOutput = <<<OUTPUT
            1 files with changes

            1) test.php:1
            * MigrateConfigurationFractor
            OUTPUT;

        $result = new FractorExecutionResult(0, $infoOutput, '', true);
        $summary = $this->parser->parse($result);

        $finding = $summary->findings[0];
        $this->assertEquals(FractorRuleSeverity::INFO, $finding->getSeverity());
    }

    public function testDetermineChangeTypeFromRuleName(): void
    {
        $testCases = [
            ['FlexFormMigrationFractor', FractorChangeType::FLEXFORM_MIGRATION],
            ['TCAUpdateFractor', FractorChangeType::TCA_MIGRATION],
            ['TypoScriptMigrationFractor', FractorChangeType::TYPOSCRIPT_MIGRATION],
            ['FluidTemplateFractor', FractorChangeType::FLUID_MIGRATION],
            ['TemplateUpdateFractor', FractorChangeType::TEMPLATE_UPDATE],
            ['ConfigurationFractor', FractorChangeType::CONFIGURATION_UPDATE],
            ['RemoveDeprecatedMethodFractor', FractorChangeType::DEPRECATION_REMOVAL],
            ['ModernizeCodeFractor', FractorChangeType::MODERNIZATION],
        ];

        foreach ($testCases as [$ruleName, $expectedChangeType]) {
            $output = <<<OUTPUT
                1 files with changes

                1) test.php:1
                * {$ruleName}
                OUTPUT;

            $result = new FractorExecutionResult(0, $output, '', true);
            $summary = $this->parser->parse($result);

            $finding = $summary->findings[0];
            $this->assertEquals($expectedChangeType, $finding->getChangeType(), "Failed for rule: {$ruleName}");
        }
    }

    public function testGenerateFindingMessage(): void
    {
        $testCases = [
            ['RemoveNoCacheHashFractor', 'Remove deprecated noCacheHash attribute from form ViewHelper'],
            ['FlexFormMigrationFractor', 'Migrate FlexForm configuration to current TYPO3 standards'],
            ['TCAUpdateFractor', 'Update TCA configuration for TYPO3 compatibility'],
            ['TypoScriptMigrationFractor', 'Modernize TypoScript configuration'],
            ['UnknownRuleFractor', 'Apply UnknownRuleFractor rule to modernize code'],
        ];

        foreach ($testCases as [$ruleName, $expectedMessage]) {
            $output = <<<OUTPUT
                1 files with changes

                1) test.php:1
                * {$ruleName}
                OUTPUT;

            $result = new FractorExecutionResult(0, $output, '', true);
            $summary = $this->parser->parse($result);

            $finding = $summary->findings[0];
            $this->assertStringContainsString($expectedMessage, $finding->getMessage(), "Failed for rule: {$ruleName}");
        }
    }

    public function testDetermineAnalysisSuccessWithExitCodes(): void
    {
        // Test successful execution
        $result = new FractorExecutionResult(0, 'No changes needed', '', true);
        $summary = $this->parser->parse($result);
        $this->assertTrue($summary->successful);

        // Test with findings but non-zero exit code (should still be successful)
        $output = '1 files with changes\n1) test.php:1\n* TestFractor';
        $result = new FractorExecutionResult(1, $output, '', false);
        $summary = $this->parser->parse($result);
        $this->assertTrue($summary->successful);

        // Test fatal error
        $result = new FractorExecutionResult(
            2,
            '',
            'Fatal Error: Configuration file not found',
            false,
        );
        $summary = $this->parser->parse($result);
        $this->assertFalse($summary->successful);

        // Test with positive indicators in output
        $result = new FractorExecutionResult(
            1,
            'Analysis completed successfully. 5 files processed.',
            '',
            false,
        );
        $summary = $this->parser->parse($result);
        $this->assertTrue($summary->successful);
    }

    public function testParseOutputWithMultipleRulesPerFile(): void
    {
        $textOutput = <<<OUTPUT
            1 files with changes

            1) Configuration/FlexForm/test.xml:10
            ---------- begin diff ----------
            -<type>select</type>
            +<type>single</type>
            ----------- end diff -----------
            * FlexFormTypeFractor (https://docs.typo3.org/flexform)
            * ModernizeFlexFormFractor (https://docs.typo3.org/modern)
            OUTPUT;

        $result = new FractorExecutionResult(0, $textOutput, '', true);
        $summary = $this->parser->parse($result);

        $this->assertEquals(1, $summary->filesScanned);
        $this->assertEquals(2, $summary->rulesApplied);
        $this->assertCount(1, $summary->findings); // One finding with multiple rules

        $finding = $summary->findings[0];
        $context = $finding->getContext();

        $this->assertArrayHasKey('applied_rules', $context);
        $this->assertArrayHasKey('documentation_urls', $context);
        $this->assertContains('FlexFormTypeFractor', $context['applied_rules']);
        $this->assertContains('ModernizeFlexFormFractor', $context['applied_rules']);
        $this->assertContains('https://docs.typo3.org/flexform', $context['documentation_urls']);
        $this->assertContains('https://docs.typo3.org/modern', $context['documentation_urls']);
    }

    public function testParseOutputHandlesEdgeCases(): void
    {
        // Test with empty lines and extra whitespace
        $textOutput = <<<OUTPUT

            2 files with changes


            1) test.php:1

            ---------- begin diff ----------

            -old
            +new

            ----------- end diff -----------

            * TestRule


            2) test2.php:2
            * AnotherRule

            OUTPUT;

        $result = new FractorExecutionResult(0, $textOutput, '', true);
        $summary = $this->parser->parse($result);

        $this->assertEquals(2, $summary->filesScanned);
        $this->assertCount(2, $summary->findings);

        // Verify first finding parsed correctly despite extra whitespace
        $firstFinding = $summary->findings[0];
        $this->assertEquals('test.php', $firstFinding->getFilePath());
        $this->assertEquals(1, $firstFinding->getLineNumber());
        $this->assertEquals('old', $firstFinding->getCodeBefore());
        $this->assertEquals('new', $firstFinding->getCodeAfter());
    }

    public function testParseOutputLimitsFilePaths(): void
    {
        // Create output with more than 10 files to test the limit
        $output = "15 files with changes\n\n";
        for ($i = 1; $i <= 15; ++$i) {
            $output .= "{$i}) file{$i}.php:1\n* TestRule\n\n";
        }

        $result = new FractorExecutionResult(0, $output, '', true);
        $summary = $this->parser->parse($result);

        // Should limit file paths to 10 entries
        $this->assertLessThanOrEqual(10, \count($summary->filePaths));
        $this->assertEquals(15, \count($summary->findings));
    }
}
