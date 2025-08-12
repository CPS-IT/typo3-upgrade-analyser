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
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorFinding;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Rector\RectorRuleSeverity;
use PHPUnit\Framework\TestCase;

/**
 * Test case for RectorFinding value object.
 */
class RectorFindingTest extends TestCase
{
    public function testConstructorAndBasicProperties(): void
    {
        $finding = new RectorFinding(
            file: 'src/Controller/TestController.php',
            line: 42,
            ruleClass: 'Ssch\\TYPO3Rector\\Rector\\v12\\v0\\RemoveTypoScriptConstantsFromTemplateServiceRector',
            message: 'Replace deprecated method call',
            severity: RectorRuleSeverity::WARNING,
            changeType: RectorChangeType::DEPRECATION,
            suggestedFix: 'Replace with new method',
            oldCode: '$template->getTypoScriptConstants()',
            newCode: '$template->getConstants()',
            context: ['rector_version' => '0.15.25'],
        );

        $this->assertEquals('src/Controller/TestController.php', $finding->getFile());
        $this->assertEquals(42, $finding->getLine());
        $this->assertEquals('Ssch\\TYPO3Rector\\Rector\\v12\\v0\\RemoveTypoScriptConstantsFromTemplateServiceRector', $finding->getRuleClass());
        $this->assertEquals('Replace deprecated method call', $finding->getMessage());
        $this->assertEquals(RectorRuleSeverity::WARNING, $finding->getSeverity());
        $this->assertEquals(RectorChangeType::DEPRECATION, $finding->getChangeType());
        $this->assertEquals('Replace with new method', $finding->getSuggestedFix());
        $this->assertEquals('$template->getTypoScriptConstants()', $finding->getOldCode());
        $this->assertEquals('$template->getConstants()', $finding->getNewCode());
        $this->assertEquals(['rector_version' => '0.15.25'], $finding->getContext());
    }

    public function testOptionalParameters(): void
    {
        $finding = new RectorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: RectorRuleSeverity::INFO,
            changeType: RectorChangeType::BEST_PRACTICE,
        );

        $this->assertNull($finding->getSuggestedFix());
        $this->assertNull($finding->getOldCode());
        $this->assertNull($finding->getNewCode());
        $this->assertEquals([], $finding->getContext());
    }

    public function testGetRuleName(): void
    {
        $finding = new RectorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'Ssch\\TYPO3Rector\\Rector\\v12\\v0\\RemoveTypoScriptConstantsFromTemplateServiceRector',
            message: 'Test message',
            severity: RectorRuleSeverity::INFO,
            changeType: RectorChangeType::BEST_PRACTICE,
        );

        $this->assertEquals('RemoveTypoScriptConstantsFromTemplateServiceRector', $finding->getRuleName());
    }

    public function testGetRuleNameWithShortClass(): void
    {
        $finding = new RectorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'SimpleRule',
            message: 'Test message',
            severity: RectorRuleSeverity::INFO,
            changeType: RectorChangeType::BEST_PRACTICE,
        );

        $this->assertEquals('SimpleRule', $finding->getRuleName());
    }

    public function testGetEstimatedEffort(): void
    {
        $finding = new RectorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: RectorRuleSeverity::CRITICAL,
            changeType: RectorChangeType::BREAKING_CHANGE,
        );

        $this->assertEquals(60, $finding->getEstimatedEffort());
    }

    public function testGetPriorityScore(): void
    {
        $criticalFinding = new RectorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: RectorRuleSeverity::CRITICAL,
            changeType: RectorChangeType::BREAKING_CHANGE,
        );

        $infoFinding = new RectorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: RectorRuleSeverity::INFO,
            changeType: RectorChangeType::BEST_PRACTICE,
        );

        $this->assertGreaterThan($infoFinding->getPriorityScore(), $criticalFinding->getPriorityScore());
    }

    public function testIsBreakingChange(): void
    {
        $breakingFinding = new RectorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: RectorRuleSeverity::CRITICAL,
            changeType: RectorChangeType::BREAKING_CHANGE,
        );

        $deprecationFinding = new RectorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: RectorRuleSeverity::WARNING,
            changeType: RectorChangeType::DEPRECATION,
        );

        $this->assertTrue($breakingFinding->isBreakingChange());
        $this->assertFalse($deprecationFinding->isBreakingChange());
    }

    public function testIsDeprecation(): void
    {
        $deprecationFinding = new RectorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: RectorRuleSeverity::WARNING,
            changeType: RectorChangeType::DEPRECATION,
        );

        $breakingFinding = new RectorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: RectorRuleSeverity::CRITICAL,
            changeType: RectorChangeType::BREAKING_CHANGE,
        );

        $this->assertTrue($deprecationFinding->isDeprecation());
        $this->assertFalse($breakingFinding->isDeprecation());
    }

    public function testRequiresManualIntervention(): void
    {
        $manualFinding = new RectorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: RectorRuleSeverity::CRITICAL,
            changeType: RectorChangeType::METHOD_SIGNATURE,
        );

        $automaticFinding = new RectorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: RectorRuleSeverity::INFO,
            changeType: RectorChangeType::BEST_PRACTICE,
        );

        $this->assertTrue($manualFinding->requiresManualIntervention());
        $this->assertFalse($automaticFinding->requiresManualIntervention());
    }

    public function testHasSuggestedFix(): void
    {
        $withFix = new RectorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: RectorRuleSeverity::INFO,
            changeType: RectorChangeType::BEST_PRACTICE,
            suggestedFix: 'Use new method',
        );

        $withoutFix = new RectorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: RectorRuleSeverity::INFO,
            changeType: RectorChangeType::BEST_PRACTICE,
        );

        $this->assertTrue($withFix->hasSuggestedFix());
        $this->assertFalse($withoutFix->hasSuggestedFix());
    }

    public function testHasCodeChange(): void
    {
        $withCodeChange = new RectorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: RectorRuleSeverity::INFO,
            changeType: RectorChangeType::BEST_PRACTICE,
            oldCode: 'old code',
            newCode: 'new code',
        );

        $withoutCodeChange = new RectorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: RectorRuleSeverity::INFO,
            changeType: RectorChangeType::BEST_PRACTICE,
        );

        $this->assertTrue($withCodeChange->hasCodeChange());
        $this->assertFalse($withoutCodeChange->hasCodeChange());
    }

    public function testToArray(): void
    {
        $finding = new RectorFinding(
            file: 'src/Controller/TestController.php',
            line: 42,
            ruleClass: 'Ssch\\TYPO3Rector\\Rector\\v12\\v0\\RemoveTypoScriptConstantsFromTemplateServiceRector',
            message: 'Replace deprecated method call',
            severity: RectorRuleSeverity::WARNING,
            changeType: RectorChangeType::DEPRECATION,
            suggestedFix: 'Replace with new method',
            oldCode: '$template->getTypoScriptConstants()',
            newCode: '$template->getConstants()',
            context: ['rector_version' => '0.15.25'],
        );

        $array = $finding->toArray();

        $this->assertArrayHasKey('file', $array);
        $this->assertArrayHasKey('line', $array);
        $this->assertArrayHasKey('rule_class', $array);
        $this->assertArrayHasKey('rule_name', $array);
        $this->assertArrayHasKey('message', $array);
        $this->assertArrayHasKey('severity', $array);
        $this->assertArrayHasKey('change_type', $array);
        $this->assertArrayHasKey('suggested_fix', $array);
        $this->assertArrayHasKey('old_code', $array);
        $this->assertArrayHasKey('new_code', $array);
        $this->assertArrayHasKey('context', $array);
        $this->assertArrayHasKey('priority_score', $array);
        $this->assertArrayHasKey('estimated_effort', $array);

        $this->assertEquals('src/Controller/TestController.php', $array['file']);
        $this->assertEquals(42, $array['line']);
        $this->assertEquals('warning', $array['severity']);
        $this->assertEquals('deprecation', $array['change_type']);
    }
}
