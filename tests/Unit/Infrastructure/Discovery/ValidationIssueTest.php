<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Discovery;

use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ValidationIssue;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ValidationSeverity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ValidationIssue
 */
final class ValidationIssueTest extends TestCase
{
    public function testConstructorSetsAllProperties(): void
    {
        $ruleName = 'composer_json_missing';
        $severity = ValidationSeverity::ERROR;
        $message = 'composer.json file is missing';
        $category = 'structure';
        $context = ['expected_path' => '/var/www/composer.json'];
        $affectedPaths = ['/var/www'];
        $recommendations = ['Create a composer.json file', 'Initialize project with composer init'];

        $issue = new ValidationIssue(
            $ruleName,
            $severity,
            $message,
            $category,
            $context,
            $affectedPaths,
            $recommendations,
        );

        self::assertSame($ruleName, $issue->getRuleName());
        self::assertSame($severity, $issue->getSeverity());
        self::assertSame($message, $issue->getMessage());
        self::assertSame($category, $issue->getCategory());
        self::assertSame($context, $issue->getContext());
        self::assertSame($affectedPaths, $issue->getAffectedPaths());
        self::assertSame($recommendations, $issue->getRecommendations());
    }

    public function testConstructorWithDefaultValues(): void
    {
        $issue = new ValidationIssue(
            'test_rule',
            ValidationSeverity::INFO,
            'Test message',
            'test_category',
        );

        self::assertSame('test_rule', $issue->getRuleName());
        self::assertSame(ValidationSeverity::INFO, $issue->getSeverity());
        self::assertSame('Test message', $issue->getMessage());
        self::assertSame('test_category', $issue->getCategory());
        self::assertSame([], $issue->getContext());
        self::assertSame([], $issue->getAffectedPaths());
        self::assertSame([], $issue->getRecommendations());
    }

    public function testGetContextValue(): void
    {
        $context = [
            'expected_path' => '/var/www/composer.json',
            'found_files' => ['composer.lock'],
            'nested' => ['key' => 'value'],
        ];

        $issue = new ValidationIssue(
            'test_rule',
            ValidationSeverity::WARNING,
            'Test message',
            'test_category',
            $context,
        );

        self::assertSame('/var/www/composer.json', $issue->getContextValue('expected_path'));
        self::assertSame(['composer.lock'], $issue->getContextValue('found_files'));
        self::assertSame(['key' => 'value'], $issue->getContextValue('nested'));
        self::assertNull($issue->getContextValue('nonexistent_key'));
    }

    /**
     * @dataProvider isBlockingAnalysisProvider
     */
    public function testIsBlockingAnalysis(ValidationSeverity $severity, bool $expected): void
    {
        $issue = new ValidationIssue(
            'test_rule',
            $severity,
            'Test message',
            'test_category',
        );

        self::assertSame($expected, $issue->isBlockingAnalysis());
    }

    /**
     * @return array<string, array{ValidationSeverity, bool}>
     */
    public static function isBlockingAnalysisProvider(): array
    {
        return [
            'info' => [ValidationSeverity::INFO, false],
            'warning' => [ValidationSeverity::WARNING, false],
            'error' => [ValidationSeverity::ERROR, true],
            'critical' => [ValidationSeverity::CRITICAL, true],
        ];
    }

    public function testToArray(): void
    {
        $ruleName = 'test_rule';
        $severity = ValidationSeverity::ERROR;
        $message = 'Test error message';
        $category = 'configuration';
        $context = ['key' => 'value'];
        $affectedPaths = ['/path/to/file'];
        $recommendations = ['Fix the issue'];

        $issue = new ValidationIssue(
            $ruleName,
            $severity,
            $message,
            $category,
            $context,
            $affectedPaths,
            $recommendations,
        );

        $array = $issue->toArray();

        $expected = [
            'rule_name' => $ruleName,
            'severity' => $severity->value,
            'severity_display' => $severity->getDisplayName(),
            'message' => $message,
            'category' => $category,
            'context' => $context,
            'affected_paths' => $affectedPaths,
            'recommendations' => $recommendations,
            'blocking_analysis' => true,
        ];

        self::assertSame($expected, $array);
    }

    public function testToArrayWithMinimalData(): void
    {
        $issue = new ValidationIssue(
            'minimal_rule',
            ValidationSeverity::INFO,
            'Minimal message',
            'info',
        );

        $array = $issue->toArray();

        $expected = [
            'rule_name' => 'minimal_rule',
            'severity' => 'info',
            'severity_display' => 'Info',
            'message' => 'Minimal message',
            'category' => 'info',
            'context' => [],
            'affected_paths' => [],
            'recommendations' => [],
            'blocking_analysis' => false,
        ];

        self::assertSame($expected, $array);
    }

    public function testWithAdditionalContext(): void
    {
        $originalContext = ['original' => 'value'];
        $issue = new ValidationIssue(
            'test_rule',
            ValidationSeverity::WARNING,
            'Test message',
            'test_category',
            $originalContext,
        );

        $additionalContext = ['additional' => 'data', 'more' => 'info'];
        $newIssue = $issue->withAdditionalContext($additionalContext);

        // Original should be unchanged
        self::assertSame($originalContext, $issue->getContext());

        // New should have merged context
        $expectedMerged = ['original' => 'value', 'additional' => 'data', 'more' => 'info'];
        self::assertSame($expectedMerged, $newIssue->getContext());

        // Should be different instances
        self::assertNotSame($issue, $newIssue);

        // Other properties should be the same
        self::assertSame($issue->getRuleName(), $newIssue->getRuleName());
        self::assertSame($issue->getSeverity(), $newIssue->getSeverity());
        self::assertSame($issue->getMessage(), $newIssue->getMessage());
        self::assertSame($issue->getCategory(), $newIssue->getCategory());
        self::assertSame($issue->getAffectedPaths(), $newIssue->getAffectedPaths());
        self::assertSame($issue->getRecommendations(), $newIssue->getRecommendations());
    }

    public function testWithAdditionalContextOverwritesExistingKeys(): void
    {
        $issue = new ValidationIssue(
            'test_rule',
            ValidationSeverity::WARNING,
            'Test message',
            'test_category',
            ['key1' => 'original', 'key2' => 'unchanged'],
        );

        $newIssue = $issue->withAdditionalContext(['key1' => 'overwritten', 'key3' => 'new']);

        $expected = ['key1' => 'overwritten', 'key2' => 'unchanged', 'key3' => 'new'];
        self::assertSame($expected, $newIssue->getContext());
    }

    public function testWithAdditionalContextWithEmptyArray(): void
    {
        $originalContext = ['key' => 'value'];
        $issue = new ValidationIssue(
            'test_rule',
            ValidationSeverity::WARNING,
            'Test message',
            'test_category',
            $originalContext,
        );

        $newIssue = $issue->withAdditionalContext([]);

        self::assertSame($originalContext, $newIssue->getContext());
        self::assertNotSame($issue, $newIssue);
    }

    public function testWithAdditionalRecommendations(): void
    {
        $originalRecommendations = ['Fix original issue'];
        $issue = new ValidationIssue(
            'test_rule',
            ValidationSeverity::ERROR,
            'Test message',
            'test_category',
            [],
            [],
            $originalRecommendations,
        );

        $additionalRecommendations = ['Additional fix', 'Another solution'];
        $newIssue = $issue->withAdditionalRecommendations($additionalRecommendations);

        // Original should be unchanged
        self::assertSame($originalRecommendations, $issue->getRecommendations());

        // New should have merged recommendations
        $expectedMerged = ['Fix original issue', 'Additional fix', 'Another solution'];
        self::assertSame($expectedMerged, $newIssue->getRecommendations());

        // Should be different instances
        self::assertNotSame($issue, $newIssue);

        // Other properties should be the same
        self::assertSame($issue->getRuleName(), $newIssue->getRuleName());
        self::assertSame($issue->getSeverity(), $newIssue->getSeverity());
        self::assertSame($issue->getMessage(), $newIssue->getMessage());
        self::assertSame($issue->getCategory(), $newIssue->getCategory());
        self::assertSame($issue->getContext(), $newIssue->getContext());
        self::assertSame($issue->getAffectedPaths(), $newIssue->getAffectedPaths());
    }

    public function testWithAdditionalRecommendationsWithEmptyArray(): void
    {
        $originalRecommendations = ['Original recommendation'];
        $issue = new ValidationIssue(
            'test_rule',
            ValidationSeverity::ERROR,
            'Test message',
            'test_category',
            [],
            [],
            $originalRecommendations,
        );

        $newIssue = $issue->withAdditionalRecommendations([]);

        self::assertSame($originalRecommendations, $newIssue->getRecommendations());
        self::assertNotSame($issue, $newIssue);
    }

    public function testImmutability(): void
    {
        $context = ['key' => 'value'];
        $affectedPaths = ['/path/to/file'];
        $recommendations = ['Fix it'];

        $issue = new ValidationIssue(
            'test_rule',
            ValidationSeverity::WARNING,
            'Test message',
            'test_category',
            $context,
            $affectedPaths,
            $recommendations,
        );

        // Modify original arrays
        $context['new_key'] = 'new_value';
        $affectedPaths[] = '/another/path';
        $recommendations[] = 'Another fix';

        // Issue should be unchanged
        self::assertSame(['key' => 'value'], $issue->getContext());
        self::assertSame(['/path/to/file'], $issue->getAffectedPaths());
        self::assertSame(['Fix it'], $issue->getRecommendations());
    }

    public function testChainingMethodCalls(): void
    {
        $issue = new ValidationIssue(
            'test_rule',
            ValidationSeverity::WARNING,
            'Test message',
            'test_category',
            ['original' => 'context'],
            [],
            ['original' => 'recommendation'],
        );

        $newIssue = $issue
            ->withAdditionalContext(['new' => 'context'])
            ->withAdditionalRecommendations(['new' => 'recommendation']);

        self::assertSame(
            ['original' => 'context', 'new' => 'context'],
            $newIssue->getContext(),
        );
        self::assertSame(
            ['original' => 'recommendation', 'new' => 'recommendation'],
            $newIssue->getRecommendations(),
        );

        // Original should be unchanged
        self::assertSame(['original' => 'context'], $issue->getContext());
        self::assertSame(['original' => 'recommendation'], $issue->getRecommendations());
    }
}
