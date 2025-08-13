<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Discovery;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Installation;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\InstallationMode;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\DetectionStrategyInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\InstallationDiscoveryResult;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ValidationIssue;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ValidationSeverity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;


#[CoversClass(InstallationDiscoveryResult::class)]
final class InstallationDiscoveryResultTest extends TestCase
{
    private Installation $installation;
    private DetectionStrategyInterface&MockObject $strategy;
    private array $attemptedStrategies;

    protected function setUp(): void
    {
        $this->installation = new Installation('/test/path', new Version('12.4.8'));
        $this->installation->setMode(InstallationMode::COMPOSER);

        $this->strategy = $this->createMock(DetectionStrategyInterface::class);
        $this->strategy->method('getName')->willReturn('Test Strategy');

        $this->attemptedStrategies = [
            [
                'strategy' => 'Test Strategy',
                'supported' => true,
                'result' => 'success',
                'priority' => 100,
            ],
        ];
    }

    public function testSuccessfulResult(): void
    {
        $result = InstallationDiscoveryResult::success(
            $this->installation,
            $this->strategy,
            [],
            $this->attemptedStrategies,
        );

        self::assertTrue($result->isSuccessful());
        self::assertSame($this->installation, $result->getInstallation());
        self::assertSame('', $result->getErrorMessage());
        self::assertSame($this->strategy, $result->getSuccessfulStrategy());
        self::assertEmpty($result->getValidationIssues());
        self::assertSame($this->attemptedStrategies, $result->getAttemptedStrategies());
    }

    public function testFailedResult(): void
    {
        $errorMessage = 'Installation discovery failed';
        $result = InstallationDiscoveryResult::failed($errorMessage, $this->attemptedStrategies);

        self::assertFalse($result->isSuccessful());
        self::assertNull($result->getInstallation());
        self::assertSame($errorMessage, $result->getErrorMessage());
        self::assertNull($result->getSuccessfulStrategy());
        self::assertEmpty($result->getValidationIssues());
        self::assertSame($this->attemptedStrategies, $result->getAttemptedStrategies());
    }

    public function testSuccessWithValidationIssues(): void
    {
        $issues = [
            new ValidationIssue('Rule 1', ValidationSeverity::WARNING, 'Warning message', 'test'),
            new ValidationIssue('Rule 2', ValidationSeverity::ERROR, 'Error message', 'test'),
        ];

        $result = InstallationDiscoveryResult::success(
            $this->installation,
            $this->strategy,
            $issues,
            $this->attemptedStrategies,
        );

        self::assertTrue($result->isSuccessful());
        self::assertTrue($result->hasValidationIssues());
        self::assertCount(2, $result->getValidationIssues());
        self::assertSame($issues, $result->getValidationIssues());
    }

    public function testGetValidationIssuesBySeverity(): void
    {
        $warningIssue = new ValidationIssue('Rule 1', ValidationSeverity::WARNING, 'Warning', 'test');
        $errorIssue = new ValidationIssue('Rule 2', ValidationSeverity::ERROR, 'Error', 'test');
        $criticalIssue = new ValidationIssue('Rule 3', ValidationSeverity::CRITICAL, 'Critical', 'test');

        $result = InstallationDiscoveryResult::success(
            $this->installation,
            $this->strategy,
            [$warningIssue, $errorIssue, $criticalIssue],
        );

        $warnings = $result->getValidationIssuesBySeverity(ValidationSeverity::WARNING);
        $errors = $result->getValidationIssuesBySeverity(ValidationSeverity::ERROR);
        $criticals = $result->getValidationIssuesBySeverity(ValidationSeverity::CRITICAL);
        $infos = $result->getValidationIssuesBySeverity(ValidationSeverity::INFO);

        self::assertCount(1, $warnings);
        self::assertContains($warningIssue, $warnings);

        self::assertCount(1, $errors);
        self::assertContains($errorIssue, $errors);

        self::assertCount(1, $criticals);
        self::assertContains($criticalIssue, $criticals);

        self::assertEmpty($infos);
    }

    public function testHasBlockingIssues(): void
    {
        $warningIssue = new ValidationIssue('Rule 1', ValidationSeverity::WARNING, 'Warning', 'test');

        $resultWithoutBlocking = InstallationDiscoveryResult::success(
            $this->installation,
            $this->strategy,
            [$warningIssue],
        );

        self::assertFalse($resultWithoutBlocking->hasBlockingIssues());

        $errorIssue = new ValidationIssue('Rule 2', ValidationSeverity::ERROR, 'Error', 'test');

        $resultWithBlocking = InstallationDiscoveryResult::success(
            $this->installation,
            $this->strategy,
            [$warningIssue, $errorIssue],
        );

        self::assertTrue($resultWithBlocking->hasBlockingIssues());
    }

    public function testGetValidationIssuesByCategory(): void
    {
        $structureIssue = new ValidationIssue('Rule 1', ValidationSeverity::ERROR, 'Structure error', 'structure');
        $permissionIssue = new ValidationIssue('Rule 2', ValidationSeverity::WARNING, 'Permission warning', 'permissions');
        $anotherStructureIssue = new ValidationIssue('Rule 3', ValidationSeverity::INFO, 'Structure info', 'structure');

        $result = InstallationDiscoveryResult::success(
            $this->installation,
            $this->strategy,
            [$structureIssue, $permissionIssue, $anotherStructureIssue],
        );

        $grouped = $result->getValidationIssuesByCategory();

        self::assertArrayHasKey('structure', $grouped);
        self::assertArrayHasKey('permissions', $grouped);

        self::assertCount(2, $grouped['structure']);
        self::assertCount(1, $grouped['permissions']);

        self::assertContains($structureIssue, $grouped['structure']);
        self::assertContains($anotherStructureIssue, $grouped['structure']);
        self::assertContains($permissionIssue, $grouped['permissions']);
    }

    public function testGetSummaryForFailedResult(): void
    {
        $attemptedStrategies = [
            ['strategy' => 'Strategy 1', 'supported' => true],
            ['strategy' => 'Strategy 2', 'supported' => false],
            ['strategy' => 'Strategy 3', 'supported' => true],
        ];

        $result = InstallationDiscoveryResult::failed(
            'No installation found',
            $attemptedStrategies,
        );

        $summary = $result->getSummary();

        self::assertStringContainsString('Installation discovery failed: No installation found', $summary);
        self::assertStringContainsString('attempted 3 strategies', $summary);
        self::assertStringContainsString('2 supported', $summary);
    }

    public function testGetSummaryForSuccessfulResult(): void
    {
        $result = InstallationDiscoveryResult::success(
            $this->installation,
            $this->strategy,
        );

        $summary = $result->getSummary();

        self::assertStringContainsString('TYPO3 12.4.8 installation discovered', $summary);
        self::assertStringContainsString('using Test Strategy', $summary);
        self::assertStringContainsString('composer mode', $summary);
    }

    public function testGetSummaryForSuccessfulResultWithValidationIssues(): void
    {
        $issues = [
            new ValidationIssue('Rule 1', ValidationSeverity::WARNING, 'Warning', 'test'),
            new ValidationIssue('Rule 2', ValidationSeverity::ERROR, 'Error', 'test'),
            new ValidationIssue('Rule 3', ValidationSeverity::INFO, 'Info', 'test'),
        ];

        $result = InstallationDiscoveryResult::success(
            $this->installation,
            $this->strategy,
            $issues,
        );

        $summary = $result->getSummary();

        self::assertStringContainsString('3 validation issues found', $summary);
        self::assertStringContainsString('(1 blocking)', $summary);
    }

    public function testGetStatisticsForFailedResult(): void
    {
        $attemptedStrategies = [
            ['strategy' => 'Strategy 1', 'supported' => true],
            ['strategy' => 'Strategy 2', 'supported' => false],
        ];

        $result = InstallationDiscoveryResult::failed('Failed', $attemptedStrategies);
        $stats = $result->getStatistics();

        self::assertFalse($stats['successful']);
        self::assertSame(2, $stats['attempted_strategies']);
        self::assertSame(1, $stats['supported_strategies']);
        self::assertSame(0, $stats['validation_issues']);
        self::assertSame(0, $stats['blocking_issues']);

        self::assertArrayNotHasKey('installation_path', $stats);
        self::assertArrayNotHasKey('typo3_version', $stats);
    }

    public function testGetStatisticsForSuccessfulResult(): void
    {
        // Add an extension to test extension counting
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'));
        $this->installation->addExtension($extension);

        $issues = [
            new ValidationIssue('Rule 1', ValidationSeverity::WARNING, 'Warning', 'test'),
            new ValidationIssue('Rule 2', ValidationSeverity::ERROR, 'Error', 'test'),
        ];

        $result = InstallationDiscoveryResult::success(
            $this->installation,
            $this->strategy,
            $issues,
            $this->attemptedStrategies,
        );

        $stats = $result->getStatistics();

        self::assertTrue($stats['successful']);
        self::assertSame(1, $stats['attempted_strategies']);
        self::assertSame(1, $stats['supported_strategies']);
        self::assertSame(2, $stats['validation_issues']);
        self::assertSame(1, $stats['blocking_issues']);

        self::assertSame('/test/path', $stats['installation_path']);
        self::assertSame('12.4.8', $stats['typo3_version']);
        self::assertSame('composer', $stats['installation_mode']);
        self::assertSame('Test Strategy', $stats['successful_strategy']);
        // Extensions are managed separately by ExtensionDiscoveryService
        self::assertArrayNotHasKey('extensions_count', $stats);
    }

    public function testToArrayForFailedResult(): void
    {
        $attemptedStrategies = [
            ['strategy' => 'Strategy 1', 'supported' => true],
        ];

        $result = InstallationDiscoveryResult::failed('Test error', $attemptedStrategies);
        $array = $result->toArray();

        self::assertFalse($array['successful']);
        self::assertSame('Test error', $array['error_message']);
        self::assertNull($array['installation']);
        self::assertNull($array['successful_strategy']);
        self::assertEmpty($array['validation_issues']);
        self::assertSame($attemptedStrategies, $array['attempted_strategies']);

        self::assertArrayHasKey('validation_summary', $array);
        self::assertArrayHasKey('statistics', $array);
        self::assertArrayHasKey('summary', $array);
    }

    public function testToArrayForSuccessfulResult(): void
    {
        $issues = [
            new ValidationIssue('Rule 1', ValidationSeverity::WARNING, 'Warning', 'test'),
            new ValidationIssue('Rule 2', ValidationSeverity::ERROR, 'Error', 'test'),
        ];

        $result = InstallationDiscoveryResult::success(
            $this->installation,
            $this->strategy,
            $issues,
            $this->attemptedStrategies,
        );

        $array = $result->toArray();

        self::assertTrue($array['successful']);
        self::assertSame('', $array['error_message']);
        self::assertIsArray($array['installation']);
        self::assertSame('Test Strategy', $array['successful_strategy']);
        self::assertCount(2, $array['validation_issues']);

        $validationSummary = $array['validation_summary'];
        self::assertSame(2, $validationSummary['total_issues']);
        self::assertTrue($validationSummary['has_blocking_issues']);
        self::assertSame(1, $validationSummary['by_severity']['warning']);
        self::assertSame(1, $validationSummary['by_severity']['error']);
        self::assertSame(0, $validationSummary['by_severity']['info']);
        self::assertSame(0, $validationSummary['by_severity']['critical']);
    }

    public function testValidationIssueCountsBySeverity(): void
    {
        $issues = [
            new ValidationIssue('Rule 1', ValidationSeverity::INFO, 'Info', 'test'),
            new ValidationIssue('Rule 2', ValidationSeverity::WARNING, 'Warning 1', 'test'),
            new ValidationIssue('Rule 3', ValidationSeverity::WARNING, 'Warning 2', 'test'),
            new ValidationIssue('Rule 4', ValidationSeverity::ERROR, 'Error', 'test'),
            new ValidationIssue('Rule 5', ValidationSeverity::CRITICAL, 'Critical', 'test'),
        ];

        $result = InstallationDiscoveryResult::success(
            $this->installation,
            $this->strategy,
            $issues,
        );

        $array = $result->toArray();
        $counts = $array['validation_summary']['by_severity'];

        self::assertSame(1, $counts['info']);
        self::assertSame(2, $counts['warning']);
        self::assertSame(1, $counts['error']);
        self::assertSame(1, $counts['critical']);
    }

    public function testHasValidationIssues(): void
    {
        $resultWithoutIssues = InstallationDiscoveryResult::success(
            $this->installation,
            $this->strategy,
        );

        $resultWithIssues = InstallationDiscoveryResult::success(
            $this->installation,
            $this->strategy,
            [new ValidationIssue('Rule 1', ValidationSeverity::INFO, 'Info', 'test')],
        );

        self::assertFalse($resultWithoutIssues->hasValidationIssues());
        self::assertTrue($resultWithIssues->hasValidationIssues());
    }

    public function testFailedResultWithEmptyAttemptedStrategies(): void
    {
        $result = InstallationDiscoveryResult::failed('No strategies available');

        self::assertFalse($result->isSuccessful());
        self::assertEmpty($result->getAttemptedStrategies());

        $summary = $result->getSummary();
        self::assertStringContainsString('attempted 0 strategies', $summary);
        self::assertStringContainsString('0 supported', $summary);
    }
}
