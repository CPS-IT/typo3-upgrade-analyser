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
    public function testConstructorAndBasicProperties(): void
    {
        $finding = new FractorFinding(
            file: 'src/Controller/TestController.php',
            line: 42,
            ruleClass: 'a9f\\Typo3Fractor\\Fractor\\v12\\v0\\RemoveTypoScriptConstantsFromTemplateServiceFractor',
            message: 'Replace deprecated method call',
            severity: FractorRuleSeverity::WARNING,
            changeType: FractorChangeType::DEPRECATION,
            suggestedFix: 'Replace with new method',
            diff: 'some diff content',
            context: ['fractor_version' => '1.0.0'],
        );

        $this->assertEquals('src/Controller/TestController.php', $finding->getFile());
        $this->assertEquals(42, $finding->getLine());
        $this->assertEquals('a9f\\Typo3Fractor\\Fractor\\v12\\v0\\RemoveTypoScriptConstantsFromTemplateServiceFractor', $finding->getRuleClass());
        $this->assertEquals('Replace deprecated method call', $finding->getMessage());
        $this->assertEquals(FractorRuleSeverity::WARNING, $finding->getSeverity());
        $this->assertEquals(FractorChangeType::DEPRECATION, $finding->getChangeType());
        $this->assertEquals('Replace with new method', $finding->getSuggestedFix());
        $this->assertEquals('some diff content', $finding->getDiff());
        $this->assertEquals(['fractor_version' => '1.0.0'], $finding->getContext());
    }

    public function testOptionalParameters(): void
    {
        $finding = new FractorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::BEST_PRACTICE,
        );

        $this->assertNull($finding->getSuggestedFix());
        $this->assertNull($finding->getDiff());
        $this->assertEquals([], $finding->getContext());
    }

    public function testGetRuleName(): void
    {
        $finding = new FractorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'a9f\\Typo3Fractor\\Fractor\\v12\\v0\\RemoveTypoScriptConstantsFromTemplateServiceFractor',
            message: 'Test message',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::BEST_PRACTICE,
        );

        $this->assertEquals('RemoveTypoScriptConstantsFromTemplateServiceFractor', $finding->getRuleName());
    }

    public function testGetRuleNameWithShortClass(): void
    {
        $finding = new FractorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'SimpleRule',
            message: 'Test message',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::BEST_PRACTICE,
        );

        $this->assertEquals('SimpleRule', $finding->getRuleName());
    }

    public function testGetEstimatedEffort(): void
    {
        $finding = new FractorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::CRITICAL,
            changeType: FractorChangeType::BREAKING_CHANGE,
        );

        $this->assertEquals(60, $finding->getEstimatedEffort());
    }

    public function testGetPriorityScore(): void
    {
        $criticalFinding = new FractorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::CRITICAL,
            changeType: FractorChangeType::BREAKING_CHANGE,
        );

        $infoFinding = new FractorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::BEST_PRACTICE,
        );

        $this->assertGreaterThan($infoFinding->getPriorityScore(), $criticalFinding->getPriorityScore());
    }

    public function testIsBreakingChange(): void
    {
        $breakingFinding = new FractorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::CRITICAL,
            changeType: FractorChangeType::BREAKING_CHANGE,
        );

        $deprecationFinding = new FractorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::WARNING,
            changeType: FractorChangeType::DEPRECATION,
        );

        $this->assertTrue($breakingFinding->isBreakingChange());
        $this->assertFalse($deprecationFinding->isBreakingChange());
    }

    public function testIsDeprecation(): void
    {
        $deprecationFinding = new FractorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::WARNING,
            changeType: FractorChangeType::DEPRECATION,
        );

        $breakingFinding = new FractorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::CRITICAL,
            changeType: FractorChangeType::BREAKING_CHANGE,
        );

        $this->assertTrue($deprecationFinding->isDeprecation());
        $this->assertFalse($breakingFinding->isDeprecation());
    }

    public function testRequiresManualIntervention(): void
    {
        $manualFinding = new FractorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::CRITICAL,
            changeType: FractorChangeType::BREAKING_CHANGE,
        );

        $automaticFinding = new FractorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::BEST_PRACTICE,
        );

        $this->assertTrue($manualFinding->requiresManualIntervention());
        $this->assertFalse($automaticFinding->requiresManualIntervention());
    }

    public function testHasSuggestedFix(): void
    {
        $withFix = new FractorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::BEST_PRACTICE,
            suggestedFix: 'Use new method',
        );

        $withoutFix = new FractorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::BEST_PRACTICE,
        );

        $this->assertTrue($withFix->hasSuggestedFix());
        $this->assertFalse($withoutFix->hasSuggestedFix());
    }

    public function testHasCodeChange(): void
    {
        $withCodeChange = new FractorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::BEST_PRACTICE,
            diff: 'some diff content',
        );

        $withoutCodeChange = new FractorFinding(
            file: 'src/Test.php',
            line: 10,
            ruleClass: 'TestRule',
            message: 'Test message',
            severity: FractorRuleSeverity::INFO,
            changeType: FractorChangeType::BEST_PRACTICE,
        );

        $this->assertTrue($withCodeChange->hasCodeChange());
        $this->assertFalse($withoutCodeChange->hasCodeChange());
    }

    public function testToArray(): void
    {
        $finding = new FractorFinding(
            file: 'src/Controller/TestController.php',
            line: 42,
            ruleClass: 'a9f\\Typo3Fractor\\Fractor\\v12\\v0\\RemoveTypoScriptConstantsFromTemplateServiceFractor',
            message: 'Replace deprecated method call',
            severity: FractorRuleSeverity::WARNING,
            changeType: FractorChangeType::DEPRECATION,
            suggestedFix: 'Replace with new method',
            diff: 'some diff content',
            context: ['fractor_version' => '1.0.0'],
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
        $this->assertArrayHasKey('diff', $array);
        $this->assertArrayHasKey('context', $array);
        $this->assertArrayHasKey('priority_score', $array);
        $this->assertArrayHasKey('estimated_effort', $array);

        $this->assertEquals('src/Controller/TestController.php', $array['file']);
        $this->assertEquals(42, $array['line']);
        $this->assertEquals('warning', $array['severity']);
        $this->assertEquals('deprecation', $array['change_type']);
    }
}
