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
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorRuleSeverity;
use PHPUnit\Framework\TestCase;

/**
 * Test case for FractorFinding value object.
 */
class FractorFindingTest extends TestCase
{
    public function testConstructorAndBasicGetters(): void
    {
        $finding = new FractorFinding(
            filePath: 'src/Configuration/FlexForm/flexform.xml',
            lineNumber: 15,
            ruleClass: 'CPSIT\\Fractor\\Rule\\FlexFormMigrationRule',
            message: 'Migrate FlexForm configuration to TYPO3 v12 format',
            severity: FractorRuleSeverity::WARNING,
            changeType: FractorChangeType::FLEXFORM_MIGRATION,
            codeBefore: '<config><type>select</type></config>',
            codeAfter: '<config><type>single</type></config>',
            diff: '-<config><type>select</type></config>\n+<config><type>single</type></config>',
            documentationUrl: 'https://docs.typo3.org/c/typo3/cms-core/main/en-us/Changelog/12.0/Deprecation-98479-FlexFormFieldTypeSelect.html',
            context: ['fractor_version' => '1.0.0', 'rule_set' => 'typo3-12'],
        );

        $this->assertEquals('src/Configuration/FlexForm/flexform.xml', $finding->getFilePath());
        $this->assertEquals('src/Configuration/FlexForm/flexform.xml', $finding->getFile());
        $this->assertEquals(15, $finding->getLineNumber());
        $this->assertEquals(15, $finding->getLine());
        $this->assertEquals('CPSIT\\Fractor\\Rule\\FlexFormMigrationRule', $finding->getRuleClass());
        $this->assertEquals('FlexFormMigrationRule', $finding->getRuleName());
        $this->assertEquals('Migrate FlexForm configuration to TYPO3 v12 format', $finding->getMessage());
        $this->assertEquals(FractorRuleSeverity::WARNING, $finding->getSeverity());
        $this->assertEquals(FractorChangeType::FLEXFORM_MIGRATION, $finding->getChangeType());
        $this->assertEquals('<config><type>select</type></config>', $finding->getCodeBefore());
        $this->assertEquals('<config><type>single</type></config>', $finding->getCodeAfter());
        $this->assertEquals('-<config><type>select</type></config>\n+<config><type>single</type></config>', $finding->getDiff());
        $this->assertEquals('https://docs.typo3.org/c/typo3/cms-core/main/en-us/Changelog/12.0/Deprecation-98479-FlexFormFieldTypeSelect.html', $finding->getDocumentationUrl());
        $this->assertEquals(['fractor_version' => '1.0.0', 'rule_set' => 'typo3-12'], $finding->getContext());
    }

    public function testOptionalParameters(): void
    {
        $finding = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::MODERNIZATION,
            codeBefore: '',
            codeAfter: '',
            diff: '',
        );

        $this->assertNull($finding->getDocumentationUrl());
        $this->assertEquals([], $finding->getContext());
        $this->assertEquals('', $finding->getCodeBefore());
        $this->assertEquals('', $finding->getCodeAfter());
        $this->assertEquals('', $finding->getDiff());
    }

    public function testGetRuleNameExtractionFromFullClassName(): void
    {
        $finding = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'CPSIT\\Fractor\\Rule\\v12\\RemoveNoCacheHashFlexFormRule',
            message: 'Test message',
            severity: FractorRuleSeverity::WARNING,
            changeType: FractorChangeType::FLEXFORM_MIGRATION,
            codeBefore: '',
            codeAfter: '',
            diff: '',
        );

        $this->assertEquals('RemoveNoCacheHashFlexFormRule', $finding->getRuleName());
    }

    public function testGetRuleNameWithSimpleClassName(): void
    {
        $finding = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'SimpleRule',
            message: 'Test message',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::MODERNIZATION,
            codeBefore: '',
            codeAfter: '',
            diff: '',
        );

        $this->assertEquals('SimpleRule', $finding->getRuleName());
    }

    public function testIsBreakingChange(): void
    {
        // Critical severity should be breaking change
        $criticalFinding = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'Critical issue',
            severity: FractorRuleSeverity::CRITICAL,
            changeType: FractorChangeType::DEPRECATION_REMOVAL,
            codeBefore: '',
            codeAfter: '',
            diff: '',
        );

        // Manual intervention required should be breaking change
        $manualFinding = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'Manual change needed',
            severity: FractorRuleSeverity::WARNING,
            changeType: FractorChangeType::FLEXFORM_MIGRATION,
            codeBefore: '',
            codeAfter: '',
            diff: '',
        );

        // Non-breaking change
        $infoFinding = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'Info message',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::MODERNIZATION,
            codeBefore: '',
            codeAfter: '',
            diff: '',
        );

        $this->assertTrue($criticalFinding->isBreakingChange());
        $this->assertTrue($manualFinding->isBreakingChange());
        $this->assertFalse($infoFinding->isBreakingChange());
    }

    public function testIsDeprecation(): void
    {
        // Deprecation removal change type
        $deprecationRemovalFinding = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'Remove deprecated code',
            severity: FractorRuleSeverity::CRITICAL,
            changeType: FractorChangeType::DEPRECATION_REMOVAL,
            codeBefore: '',
            codeAfter: '',
            diff: '',
        );

        // Warning severity
        $warningFinding = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'Warning message',
            severity: FractorRuleSeverity::WARNING,
            changeType: FractorChangeType::FLEXFORM_MIGRATION,
            codeBefore: '',
            codeAfter: '',
            diff: '',
        );

        // Non-deprecation
        $infoFinding = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'Info message',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::MODERNIZATION,
            codeBefore: '',
            codeAfter: '',
            diff: '',
        );

        $this->assertTrue($deprecationRemovalFinding->isDeprecation());
        $this->assertTrue($warningFinding->isDeprecation());
        $this->assertFalse($infoFinding->isDeprecation());
    }

    public function testIsImprovement(): void
    {
        // INFO severity should be improvement
        $infoFinding = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'Info message',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::CONFIGURATION_UPDATE,
            codeBefore: '',
            codeAfter: '',
            diff: '',
        );

        // SUGGESTION severity should be improvement
        $suggestionFinding = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'Suggestion message',
            severity: FractorRuleSeverity::SUGGESTION,
            changeType: FractorChangeType::CONFIGURATION_UPDATE,
            codeBefore: '',
            codeAfter: '',
            diff: '',
        );

        // MODERNIZATION change type should be improvement
        $modernizationFinding = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'Modernization message',
            severity: FractorRuleSeverity::CRITICAL,
            changeType: FractorChangeType::MODERNIZATION,
            codeBefore: '',
            codeAfter: '',
            diff: '',
        );

        // Non-improvement
        $criticalFinding = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'Critical message',
            severity: FractorRuleSeverity::CRITICAL,
            changeType: FractorChangeType::FLEXFORM_MIGRATION,
            codeBefore: '',
            codeAfter: '',
            diff: '',
        );

        $this->assertTrue($infoFinding->isImprovement());
        $this->assertTrue($suggestionFinding->isImprovement());
        $this->assertTrue($modernizationFinding->isImprovement());
        $this->assertFalse($criticalFinding->isImprovement());
    }

    public function testGetEstimatedEffort(): void
    {
        $flexFormFinding = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'FlexForm change',
            severity: FractorRuleSeverity::WARNING,
            changeType: FractorChangeType::FLEXFORM_MIGRATION,
            codeBefore: '',
            codeAfter: '',
            diff: '',
        );

        $modernizationFinding = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'Modernization change',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::MODERNIZATION,
            codeBefore: '',
            codeAfter: '',
            diff: '',
        );

        $this->assertEquals(15, $flexFormFinding->getEstimatedEffort());
        $this->assertEquals(5, $modernizationFinding->getEstimatedEffort());
    }

    public function testRequiresManualIntervention(): void
    {
        // FlexForm migrations require manual intervention
        $flexFormFinding = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'FlexForm change',
            severity: FractorRuleSeverity::WARNING,
            changeType: FractorChangeType::FLEXFORM_MIGRATION,
            codeBefore: '',
            codeAfter: '',
            diff: '',
        );

        // TCA migrations require manual intervention
        $tcaFinding = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'TCA change',
            severity: FractorRuleSeverity::WARNING,
            changeType: FractorChangeType::TCA_MIGRATION,
            codeBefore: '',
            codeAfter: '',
            diff: '',
        );

        // Modernization doesn't require manual intervention
        $modernizationFinding = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'Modernization change',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::MODERNIZATION,
            codeBefore: '',
            codeAfter: '',
            diff: '',
        );

        $this->assertTrue($flexFormFinding->requiresManualIntervention());
        $this->assertTrue($tcaFinding->requiresManualIntervention());
        $this->assertFalse($modernizationFinding->requiresManualIntervention());
    }

    public function testGetFileLocation(): void
    {
        $finding = new FractorFinding(
            filePath: '/var/www/html/src/Configuration/TCA/tx_example.php',
            lineNumber: 42,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::WARNING,
            changeType: FractorChangeType::TCA_MIGRATION,
            codeBefore: '',
            codeAfter: '',
            diff: '',
        );

        $this->assertEquals('tx_example.php:42', $finding->getFileLocation());
    }

    public function testHasDiff(): void
    {
        $withDiff = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::MODERNIZATION,
            codeBefore: 'old',
            codeAfter: 'new',
            diff: '-old\n+new',
        );

        $withoutDiff = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::MODERNIZATION,
            codeBefore: '',
            codeAfter: '',
            diff: '',
        );

        $this->assertTrue($withDiff->hasDiff());
        $this->assertFalse($withoutDiff->hasDiff());
    }

    public function testGetPriorityScore(): void
    {
        $criticalFinding = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'Critical issue',
            severity: FractorRuleSeverity::CRITICAL,
            changeType: FractorChangeType::DEPRECATION_REMOVAL,
            codeBefore: '',
            codeAfter: '',
            diff: '',
        );

        $infoFinding = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'Info issue',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::MODERNIZATION,
            codeBefore: '',
            codeAfter: '',
            diff: '',
        );

        $criticalScore = $criticalFinding->getPriorityScore();
        $infoScore = $infoFinding->getPriorityScore();

        $this->assertIsFloat($criticalScore);
        $this->assertIsFloat($infoScore);
        $this->assertGreaterThan($infoScore, $criticalScore);
        $this->assertGreaterThan(0, $criticalScore);
        $this->assertGreaterThan(0, $infoScore);
    }

    public function testHasDocumentation(): void
    {
        $withDocumentation = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::MODERNIZATION,
            codeBefore: '',
            codeAfter: '',
            diff: '',
            documentationUrl: 'https://docs.typo3.org/changelog',
        );

        $withEmptyDocumentation = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::MODERNIZATION,
            codeBefore: '',
            codeAfter: '',
            diff: '',
            documentationUrl: '',
        );

        $withoutDocumentation = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::MODERNIZATION,
            codeBefore: '',
            codeAfter: '',
            diff: '',
        );

        $this->assertTrue($withDocumentation->hasDocumentation());
        $this->assertFalse($withEmptyDocumentation->hasDocumentation());
        $this->assertFalse($withoutDocumentation->hasDocumentation());
    }

    public function testHasCodeChange(): void
    {
        $withCodeChange = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::MODERNIZATION,
            codeBefore: 'old code',
            codeAfter: 'new code',
            diff: '',
        );

        $withOnlyBefore = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::MODERNIZATION,
            codeBefore: 'old code',
            codeAfter: '',
            diff: '',
        );

        $withoutCodeChange = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::MODERNIZATION,
            codeBefore: '',
            codeAfter: '',
            diff: '',
        );

        $this->assertTrue($withCodeChange->hasCodeChange());
        $this->assertFalse($withOnlyBefore->hasCodeChange());
        $this->assertFalse($withoutCodeChange->hasCodeChange());
    }

    public function testHasContext(): void
    {
        $withContext = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::MODERNIZATION,
            codeBefore: '',
            codeAfter: '',
            diff: '',
            context: ['rule_set' => 'typo3-12'],
        );

        $withEmptyContext = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::MODERNIZATION,
            codeBefore: '',
            codeAfter: '',
            diff: '',
            context: [],
        );

        $withoutContext = new FractorFinding(
            filePath: 'test.php',
            lineNumber: 1,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::MODERNIZATION,
            codeBefore: '',
            codeAfter: '',
            diff: '',
        );

        $this->assertTrue($withContext->hasContext());
        $this->assertFalse($withEmptyContext->hasContext());
        $this->assertFalse($withoutContext->hasContext());
    }

    public function testToArray(): void
    {
        $finding = new FractorFinding(
            filePath: 'src/Configuration/TCA/tx_example.php',
            lineNumber: 25,
            ruleClass: 'CPSIT\\Fractor\\Rule\\TCAMigrationRule',
            message: 'Update TCA configuration format',
            severity: FractorRuleSeverity::WARNING,
            changeType: FractorChangeType::TCA_MIGRATION,
            codeBefore: "'type' => 'input'",
            codeAfter: "'type' => 'text'",
            diff: "-'type' => 'input'\n+'type' => 'text'",
            documentationUrl: 'https://docs.typo3.org/tca',
            context: ['fractor_version' => '1.0.0', 'target_version' => '12.4'],
        );

        $array = $finding->toArray();

        // Test required array keys
        $expectedKeys = [
            'file_path',
            'line_number',
            'rule_class',
            'rule_name',
            'message',
            'severity',
            'change_type',
            'code_before',
            'code_after',
            'diff',
            'documentation_url',
            'context',
            'priority_score',
            'estimated_effort',
            'requires_manual_intervention',
            'is_breaking_change',
            'is_deprecation',
            'is_improvement',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $array, "Array should contain key: {$key}");
        }

        // Test specific values
        $this->assertEquals('src/Configuration/TCA/tx_example.php', $array['file_path']);
        $this->assertEquals(25, $array['line_number']);
        $this->assertEquals('CPSIT\\Fractor\\Rule\\TCAMigrationRule', $array['rule_class']);
        $this->assertEquals('TCAMigrationRule', $array['rule_name']);
        $this->assertEquals('Update TCA configuration format', $array['message']);
        $this->assertEquals('warning', $array['severity']);
        $this->assertEquals('tca_migration', $array['change_type']);
        $this->assertEquals("'type' => 'input'", $array['code_before']);
        $this->assertEquals("'type' => 'text'", $array['code_after']);
        $this->assertEquals("-'type' => 'input'\n+'type' => 'text'", $array['diff']);
        $this->assertEquals('https://docs.typo3.org/tca', $array['documentation_url']);
        $this->assertEquals(['fractor_version' => '1.0.0', 'target_version' => '12.4'], $array['context']);

        // Test computed values
        $this->assertIsFloat($array['priority_score']);
        $this->assertIsInt($array['estimated_effort']);
        $this->assertIsBool($array['requires_manual_intervention']);
        $this->assertIsBool($array['is_breaking_change']);
        $this->assertIsBool($array['is_deprecation']);
        $this->assertIsBool($array['is_improvement']);

        // Test specific computed values for TCA migration
        $this->assertEquals(20, $array['estimated_effort']); // TCA_MIGRATION effort
        $this->assertTrue($array['requires_manual_intervention']); // TCA requires manual intervention
        $this->assertTrue($array['is_breaking_change']); // TCA + manual intervention = breaking
        $this->assertTrue($array['is_deprecation']); // WARNING severity = deprecation
        $this->assertFalse($array['is_improvement']); // WARNING + TCA_MIGRATION ≠ improvement
    }
}
