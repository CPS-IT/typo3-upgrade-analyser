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

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\VersionExtractionResult;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\VersionStrategyInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\VersionExtractionResult
 */
final class VersionExtractionResultTest extends TestCase
{
    private Version $testVersion;
    private VersionStrategyInterface $testStrategy;

    protected function setUp(): void
    {
        $this->testVersion = new Version('12.4.8');
        $this->testStrategy = $this->createMockStrategy('Test Strategy', 0.8);
    }

    public function testSuccessfulResultCreation(): void
    {
        $attemptedStrategies = [
            ['strategy' => 'test', 'supported' => true, 'result' => 'success'],
        ];

        $result = VersionExtractionResult::success(
            $this->testVersion,
            $this->testStrategy,
            $attemptedStrategies,
        );

        self::assertTrue($result->isSuccessful());
        self::assertSame($this->testVersion, $result->getVersion());
        self::assertSame($this->testStrategy, $result->getSuccessfulStrategy());
        self::assertSame($attemptedStrategies, $result->getAttemptedStrategies());
        self::assertSame('', $result->getErrorMessage());
        self::assertSame(0.8, $result->getReliabilityScore());
    }

    public function testSuccessfulResultWithoutAttemptedStrategies(): void
    {
        $result = VersionExtractionResult::success($this->testVersion, $this->testStrategy);

        self::assertTrue($result->isSuccessful());
        self::assertSame($this->testVersion, $result->getVersion());
        self::assertSame($this->testStrategy, $result->getSuccessfulStrategy());
        self::assertSame([], $result->getAttemptedStrategies());
        self::assertSame('', $result->getErrorMessage());
    }

    public function testFailedResultCreation(): void
    {
        $errorMessage = 'No TYPO3 version found';
        $attemptedStrategies = [
            ['strategy' => 'composer', 'supported' => false, 'error' => 'No composer.lock'],
            ['strategy' => 'source', 'supported' => true, 'error' => 'Version file not found'],
        ];

        $result = VersionExtractionResult::failed($errorMessage, $attemptedStrategies);

        self::assertFalse($result->isSuccessful());
        self::assertNull($result->getVersion());
        self::assertNull($result->getSuccessfulStrategy());
        self::assertSame($errorMessage, $result->getErrorMessage());
        self::assertSame($attemptedStrategies, $result->getAttemptedStrategies());
        self::assertNull($result->getReliabilityScore());
    }

    public function testFailedResultWithoutAttemptedStrategies(): void
    {
        $errorMessage = 'Unknown error';
        $result = VersionExtractionResult::failed($errorMessage);

        self::assertFalse($result->isSuccessful());
        self::assertNull($result->getVersion());
        self::assertNull($result->getSuccessfulStrategy());
        self::assertSame($errorMessage, $result->getErrorMessage());
        self::assertSame([], $result->getAttemptedStrategies());
    }

    public function testGetSummaryForSuccessfulResult(): void
    {
        $result = VersionExtractionResult::success($this->testVersion, $this->testStrategy);
        $summary = $result->getSummary();

        self::assertStringContainsString('12.4.8', $summary);
        self::assertStringContainsString('Test Strategy', $summary);
        self::assertStringContainsString('80.0%', $summary);
        self::assertStringContainsString('extracted using', $summary);
    }

    public function testGetSummaryForFailedResult(): void
    {
        $attemptedStrategies = [
            ['strategy' => 'composer', 'supported' => false],
            ['strategy' => 'source', 'supported' => true],
            ['strategy' => 'legacy', 'supported' => true],
        ];

        $result = VersionExtractionResult::failed('No version found', $attemptedStrategies);
        $summary = $result->getSummary();

        self::assertStringContainsString('Version extraction failed', $summary);
        self::assertStringContainsString('No version found', $summary);
        self::assertStringContainsString('attempted 3 strategies', $summary);
        self::assertStringContainsString('2 supported', $summary);
    }

    public function testGetSummaryForFailedResultWithoutStrategies(): void
    {
        $result = VersionExtractionResult::failed('Configuration error');
        $summary = $result->getSummary();

        self::assertStringContainsString('Version extraction failed', $summary);
        self::assertStringContainsString('Configuration error', $summary);
        self::assertStringContainsString('attempted 0 strategies', $summary);
        self::assertStringContainsString('0 supported', $summary);
    }

    public function testToArrayForSuccessfulResult(): void
    {
        $attemptedStrategies = [['strategy' => 'test', 'supported' => true]];
        $result = VersionExtractionResult::success($this->testVersion, $this->testStrategy, $attemptedStrategies);

        $array = $result->toArray();

        self::assertTrue($array['successful']);
        $expectedVersionArray = [
            'major' => $this->testVersion->getMajor(),
            'minor' => $this->testVersion->getMinor(),
            'patch' => $this->testVersion->getPatch(),
            'suffix' => $this->testVersion->getSuffix(),
            'string' => $this->testVersion->toString(),
        ];
        self::assertSame($expectedVersionArray, $array['version']);
        self::assertSame('12.4.8', $array['version_string']);
        self::assertSame('', $array['error_message']);
        self::assertSame('Test Strategy', $array['successful_strategy']);
        self::assertSame(0.8, $array['reliability_score']);
        self::assertSame($attemptedStrategies, $array['attempted_strategies']);
        self::assertIsString($array['summary']);
        self::assertStringContainsString('12.4.8', $array['summary']);
    }

    public function testToArrayForFailedResult(): void
    {
        $attemptedStrategies = [['strategy' => 'test', 'supported' => false]];
        $result = VersionExtractionResult::failed('Test error', $attemptedStrategies);

        $array = $result->toArray();

        self::assertFalse($array['successful']);
        self::assertNull($array['version']);
        self::assertNull($array['version_string']);
        self::assertSame('Test error', $array['error_message']);
        self::assertNull($array['successful_strategy']);
        self::assertNull($array['reliability_score']);
        self::assertSame($attemptedStrategies, $array['attempted_strategies']);
        self::assertIsString($array['summary']);
        self::assertStringContainsString('Test error', $array['summary']);
    }

    public function testReliabilityScoreFromStrategy(): void
    {
        $highReliabilityStrategy = $this->createMockStrategy('High Reliability', 0.95);
        $lowReliabilityStrategy = $this->createMockStrategy('Low Reliability', 0.3);

        $highResult = VersionExtractionResult::success($this->testVersion, $highReliabilityStrategy);
        $lowResult = VersionExtractionResult::success($this->testVersion, $lowReliabilityStrategy);

        self::assertSame(0.95, $highResult->getReliabilityScore());
        self::assertSame(0.3, $lowResult->getReliabilityScore());
    }

    public function testSuccessfulResultMustHaveStrategyAndVersion(): void
    {
        $result = VersionExtractionResult::success($this->testVersion, $this->testStrategy);

        self::assertNotNull($result->getVersion());
        self::assertNotNull($result->getSuccessfulStrategy());
        self::assertNotNull($result->getReliabilityScore());
    }

    public function testFailedResultMustNotHaveStrategyOrVersion(): void
    {
        $result = VersionExtractionResult::failed('Test error');

        self::assertNull($result->getVersion());
        self::assertNull($result->getSuccessfulStrategy());
        self::assertNull($result->getReliabilityScore());
        self::assertNotEmpty($result->getErrorMessage());
    }

    public function testAttemptedStrategiesStructure(): void
    {
        $attemptedStrategies = [
            [
                'strategy' => 'composer_lock',
                'name' => 'Composer Lock Strategy',
                'supported' => true,
                'success' => false,
                'error' => 'File not found',
                'reliability' => 0.9,
            ],
            [
                'strategy' => 'version_file',
                'name' => 'Version File Strategy',
                'supported' => true,
                'success' => true,
                'reliability' => 0.7,
            ],
        ];

        $result = VersionExtractionResult::success($this->testVersion, $this->testStrategy, $attemptedStrategies);

        self::assertSame($attemptedStrategies, $result->getAttemptedStrategies());

        // Verify structure is preserved in toArray
        $array = $result->toArray();
        self::assertSame($attemptedStrategies, $array['attempted_strategies']);
    }

    public function testImmutability(): void
    {
        $attemptedStrategies = [['strategy' => 'test']];
        $result = VersionExtractionResult::success($this->testVersion, $this->testStrategy, $attemptedStrategies);

        // Modify original array
        $attemptedStrategies[0]['modified'] = true;
        $attemptedStrategies[] = ['new_strategy' => 'added'];

        // Result should be unchanged
        $resultStrategies = $result->getAttemptedStrategies();
        self::assertCount(1, $resultStrategies);
        self::assertArrayNotHasKey('modified', $resultStrategies[0]);
        self::assertArrayNotHasKey('new_strategy', $resultStrategies[1] ?? []);
    }

    private function createMockStrategy(string $name, float $reliability): VersionStrategyInterface
    {
        $strategy = $this->createMock(VersionStrategyInterface::class);
        $strategy->method('getName')->willReturn($name);
        $strategy->method('getReliabilityScore')->willReturn($reliability);

        return $strategy;
    }
}
