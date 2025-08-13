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

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\VersionExtractor;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\VersionStrategyInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;


#[CoversClass(VersionExtractor::class)]
final class VersionExtractorTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private string $testDir;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->testDir = sys_get_temp_dir() . '/typo3-analyzer-test-' . uniqid();
        mkdir($this->testDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testDir)) {
            rmdir($this->testDir);
        }
    }

    public function testConstructorSortsStrategiesByPriority(): void
    {
        $strategy1 = $this->createMockStrategy('Strategy1', 10);
        $strategy2 = $this->createMockStrategy('Strategy2', 100);
        $strategy3 = $this->createMockStrategy('Strategy3', 50);

        $extractor = new VersionExtractor([$strategy1, $strategy2, $strategy3], $this->logger);
        $strategies = $extractor->getStrategies();

        self::assertCount(3, $strategies);
        self::assertSame('Strategy2', $strategies[0]->getName()); // Priority 100
        self::assertSame('Strategy3', $strategies[1]->getName()); // Priority 50
        self::assertSame('Strategy1', $strategies[2]->getName()); // Priority 10
    }

    public function testExtractVersionWithNonExistentDirectory(): void
    {
        $strategy = $this->createMockStrategy('Test Strategy', 50);
        $extractor = new VersionExtractor([$strategy], $this->logger);

        $result = $extractor->extractVersion('/non/existent/path');

        self::assertFalse($result->isSuccessful());
        self::assertNull($result->getVersion());
        self::assertStringContainsString('Installation path does not exist or is not a directory', $result->getErrorMessage());
        self::assertEmpty($result->getAttemptedStrategies());
    }

    public function testExtractVersionWithSuccessfulStrategy(): void
    {
        $version = new Version('12.4.0');
        $strategy = $this->createMockStrategy('Successful Strategy', 80);

        $strategy->expects(self::once())
            ->method('supports')
            ->with($this->testDir)
            ->willReturn(true);

        $strategy->expects(self::once())
            ->method('extractVersion')
            ->with($this->testDir)
            ->willReturn($version);

        $extractor = new VersionExtractor([$strategy], $this->logger);
        $result = $extractor->extractVersion($this->testDir);

        self::assertTrue($result->isSuccessful());
        self::assertSame($version, $result->getVersion());
        self::assertSame($strategy, $result->getSuccessfulStrategy());
        self::assertCount(1, $result->getAttemptedStrategies());

        $attemptedStrategy = $result->getAttemptedStrategies()[0];
        self::assertSame('Successful Strategy', $attemptedStrategy['strategy']);
        self::assertTrue($attemptedStrategy['supported']);
        self::assertSame(80, $attemptedStrategy['priority']);
        self::assertSame(0.8, $attemptedStrategy['reliability']);
    }

    public function testExtractVersionWithUnsupportedStrategy(): void
    {
        $strategy = $this->createMock(VersionStrategyInterface::class);
        $strategy->method('getName')->willReturn('Unsupported Strategy');
        $strategy->method('getPriority')->willReturn(50);
        $strategy->method('getReliabilityScore')->willReturn(0.5);
        $strategy->method('getRequiredFiles')->willReturn(['composer.json', 'vendor']);

        $strategy->expects(self::once())
            ->method('supports')
            ->with($this->testDir)
            ->willReturn(false);

        $strategy->expects(self::never())
            ->method('extractVersion');

        $extractor = new VersionExtractor([$strategy], $this->logger);
        $result = $extractor->extractVersion($this->testDir);

        self::assertFalse($result->isSuccessful());
        self::assertNull($result->getVersion());
        self::assertStringContainsString('No version extraction strategies supported this installation', $result->getErrorMessage());

        $attemptedStrategies = $result->getAttemptedStrategies();
        self::assertCount(1, $attemptedStrategies);
        self::assertSame('Unsupported Strategy', $attemptedStrategies[0]['strategy']);
        self::assertFalse($attemptedStrategies[0]['supported']);
        self::assertStringContainsString('composer.json, vendor', $attemptedStrategies[0]['reason']);
    }

    public function testExtractVersionWithStrategyException(): void
    {
        $strategy = $this->createMockStrategy('Failing Strategy', 60);

        $strategy->expects(self::once())
            ->method('supports')
            ->with($this->testDir)
            ->willReturn(true);

        $exception = new \RuntimeException('Strategy failed');
        $strategy->expects(self::once())
            ->method('extractVersion')
            ->with($this->testDir)
            ->willThrowException($exception);

        $extractor = new VersionExtractor([$strategy], $this->logger);
        $result = $extractor->extractVersion($this->testDir);

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('All supported strategies failed to extract version: Failing Strategy', $result->getErrorMessage());

        $attemptedStrategies = $result->getAttemptedStrategies();
        self::assertCount(1, $attemptedStrategies);
        self::assertSame('error', $attemptedStrategies[0]['result']);
        self::assertSame('Strategy failed', $attemptedStrategies[0]['error']);
    }

    public function testExtractVersionWithStrategyReturningNull(): void
    {
        $strategy = $this->createMockStrategy('Null Strategy', 40);

        $strategy->expects(self::once())
            ->method('supports')
            ->with($this->testDir)
            ->willReturn(true);

        $strategy->expects(self::once())
            ->method('extractVersion')
            ->with($this->testDir)
            ->willReturn(null);

        $extractor = new VersionExtractor([$strategy], $this->logger);
        $result = $extractor->extractVersion($this->testDir);

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('All supported strategies failed to extract version: Null Strategy', $result->getErrorMessage());

        $attemptedStrategies = $result->getAttemptedStrategies();
        self::assertCount(1, $attemptedStrategies);
        self::assertSame('no_version_found', $attemptedStrategies[0]['result']);
    }

    public function testExtractVersionWithMultipleStrategiesFirstSucceeds(): void
    {
        $version = new Version('11.5.0');
        $strategy1 = $this->createMockStrategy('Strategy1', 100);
        $strategy2 = $this->createMockStrategy('Strategy2', 50);

        $strategy1->expects(self::once())
            ->method('supports')
            ->willReturn(true);

        $strategy1->expects(self::once())
            ->method('extractVersion')
            ->willReturn($version);

        // Strategy2 should never be called since Strategy1 succeeds
        $strategy2->expects(self::never())
            ->method('supports');

        $extractor = new VersionExtractor([$strategy1, $strategy2], $this->logger);
        $result = $extractor->extractVersion($this->testDir);

        self::assertTrue($result->isSuccessful());
        self::assertSame($version, $result->getVersion());
        self::assertSame($strategy1, $result->getSuccessfulStrategy());
        self::assertCount(1, $result->getAttemptedStrategies());
    }

    public function testExtractVersionWithMultipleStrategiesSecondSucceeds(): void
    {
        $version = new Version('13.0.0');
        $strategy1 = $this->createMockStrategy('Strategy1', 100);
        $strategy2 = $this->createMockStrategy('Strategy2', 50);

        $strategy1->expects(self::once())
            ->method('supports')
            ->willReturn(true);

        $strategy1->expects(self::once())
            ->method('extractVersion')
            ->willReturn(null);

        $strategy2->expects(self::once())
            ->method('supports')
            ->willReturn(true);

        $strategy2->expects(self::once())
            ->method('extractVersion')
            ->willReturn($version);

        $extractor = new VersionExtractor([$strategy1, $strategy2], $this->logger);
        $result = $extractor->extractVersion($this->testDir);

        self::assertTrue($result->isSuccessful());
        self::assertSame($version, $result->getVersion());
        self::assertSame($strategy2, $result->getSuccessfulStrategy());
        self::assertCount(2, $result->getAttemptedStrategies());
    }

    public function testGetSupportedStrategies(): void
    {
        $strategy1 = $this->createMockStrategy('Strategy1', 100);
        $strategy2 = $this->createMockStrategy('Strategy2', 50);
        $strategy3 = $this->createMockStrategy('Strategy3', 25);

        $strategy1->expects(self::once())
            ->method('supports')
            ->with($this->testDir)
            ->willReturn(true);

        $strategy2->expects(self::once())
            ->method('supports')
            ->with($this->testDir)
            ->willReturn(false);

        $strategy3->expects(self::once())
            ->method('supports')
            ->with($this->testDir)
            ->willReturn(true);

        $extractor = new VersionExtractor([$strategy1, $strategy2, $strategy3], $this->logger);
        $supportedStrategies = $extractor->getSupportedStrategies($this->testDir);

        self::assertCount(2, $supportedStrategies);
        self::assertContains($strategy1, $supportedStrategies);
        self::assertContains($strategy3, $supportedStrategies);
        self::assertNotContains($strategy2, $supportedStrategies);
    }

    public function testCanExtractVersionReturnsTrueWhenStrategiesSupport(): void
    {
        $strategy = $this->createMockStrategy('Strategy', 50);

        $strategy->expects(self::once())
            ->method('supports')
            ->with($this->testDir)
            ->willReturn(true);

        $extractor = new VersionExtractor([$strategy], $this->logger);
        self::assertTrue($extractor->canExtractVersion($this->testDir));
    }

    public function testCanExtractVersionReturnsFalseWhenNoStrategiesSupport(): void
    {
        $strategy = $this->createMockStrategy('Strategy', 50);

        $strategy->expects(self::once())
            ->method('supports')
            ->with($this->testDir)
            ->willReturn(false);

        $extractor = new VersionExtractor([$strategy], $this->logger);
        self::assertFalse($extractor->canExtractVersion($this->testDir));
    }

    public function testGetStrategiesReturnsStrategiesInPriorityOrder(): void
    {
        $strategy1 = $this->createMockStrategy('Low', 10);
        $strategy2 = $this->createMockStrategy('High', 100);
        $strategy3 = $this->createMockStrategy('Medium', 50);

        $extractor = new VersionExtractor([$strategy1, $strategy2, $strategy3], $this->logger);
        $strategies = $extractor->getStrategies();

        self::assertCount(3, $strategies);
        self::assertSame('High', $strategies[0]->getName());
        self::assertSame('Medium', $strategies[1]->getName());
        self::assertSame('Low', $strategies[2]->getName());
    }

    public function testExtractVersionWithEmptyStrategiesArray(): void
    {
        $extractor = new VersionExtractor([], $this->logger);
        $result = $extractor->extractVersion($this->testDir);

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('No version extraction strategies supported this installation', $result->getErrorMessage());
        self::assertEmpty($result->getAttemptedStrategies());
    }

    public function testExtractVersionPreservesAttemptedStrategiesOrder(): void
    {
        $strategy1 = $this->createMockStrategy('First', 100);
        $strategy2 = $this->createMockStrategy('Second', 50);

        $strategy1->expects(self::once())
            ->method('supports')
            ->willReturn(true);
        $strategy1->expects(self::once())
            ->method('extractVersion')
            ->willReturn(null);

        $strategy2->expects(self::once())
            ->method('supports')
            ->willReturn(false);
        $strategy2->expects(self::once())
            ->method('getRequiredFiles')
            ->willReturn([]);

        $extractor = new VersionExtractor([$strategy2, $strategy1], $this->logger);
        $result = $extractor->extractVersion($this->testDir);

        $attemptedStrategies = $result->getAttemptedStrategies();
        self::assertCount(2, $attemptedStrategies);
        self::assertSame('First', $attemptedStrategies[0]['strategy']); // Higher priority tried first
        self::assertSame('Second', $attemptedStrategies[1]['strategy']);
    }

    /**
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    private function createMockStrategy(string $name, int $priority): VersionStrategyInterface&MockObject
    {
        $strategy = $this->createMock(VersionStrategyInterface::class);

        $strategy->method('getName')->willReturn($name);
        $strategy->method('getPriority')->willReturn($priority);
        $strategy->method('getReliabilityScore')->willReturn($priority / 100.0);
        $strategy->method('getRequiredFiles')->willReturn([]);

        return $strategy;
    }
}
