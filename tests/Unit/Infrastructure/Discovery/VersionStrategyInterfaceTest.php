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
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\VersionStrategyInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests the VersionStrategyInterface contract with a mock implementation.
 *
 * @coversNothing
 */
final class VersionStrategyInterfaceTest extends TestCase
{
    public function testInterfaceContractsWorkCorrectly(): void
    {
        $strategy = new TestVersionStrategy();

        // Test interface method contracts
        self::assertIsString($strategy->getName());
        self::assertIsInt($strategy->getPriority());
        self::assertIsArray($strategy->getRequiredFiles());
        self::assertIsFloat($strategy->getReliabilityScore());

        // Test reliability score is within valid range
        $reliability = $strategy->getReliabilityScore();
        self::assertGreaterThanOrEqual(0.0, $reliability);
        self::assertLessThanOrEqual(1.0, $reliability);

        // Test behavior with supported path
        $supportedPath = '/composer/installation';
        self::assertTrue($strategy->supports($supportedPath));

        $version = $strategy->extractVersion($supportedPath);
        self::assertInstanceOf(Version::class, $version);

        // Test behavior with unsupported path
        $unsupportedPath = '/legacy/installation';
        self::assertFalse($strategy->supports($unsupportedPath));
        self::assertNull($strategy->extractVersion($unsupportedPath));
    }

    public function testInterfaceMethodReturnTypes(): void
    {
        $strategy = new TestVersionStrategy();

        // Test all methods return correct types
        self::assertIsString($strategy->getName());
        self::assertIsInt($strategy->getPriority());
        self::assertIsArray($strategy->getRequiredFiles());
        self::assertIsFloat($strategy->getReliabilityScore());

        foreach ($strategy->getRequiredFiles() as $file) {
            self::assertIsString($file);
        }

        // Test supports method with various inputs
        self::assertIsBool($strategy->supports('/some/path'));
        self::assertIsBool($strategy->supports(''));
        self::assertIsBool($strategy->supports('/'));

        // Test extractVersion method returns Version or null
        $result = $strategy->extractVersion('/composer/installation');
        self::assertTrue($result instanceof Version || null === $result);
    }

    public function testMultipleStrategiesWithDifferentPriorities(): void
    {
        $highPriorityStrategy = new TestVersionStrategy('High Priority', 100, 0.9);
        $lowPriorityStrategy = new TestVersionStrategy('Low Priority', 10, 0.5);

        self::assertGreaterThan(
            $lowPriorityStrategy->getPriority(),
            $highPriorityStrategy->getPriority(),
        );

        // Strategies should be sortable by priority
        $strategies = [$lowPriorityStrategy, $highPriorityStrategy];
        usort($strategies, fn ($a, $b): int => $b->getPriority() <=> $a->getPriority());

        self::assertSame($highPriorityStrategy, $strategies[0]);
        self::assertSame($lowPriorityStrategy, $strategies[1]);
    }

    public function testReliabilityScoreRangeValidation(): void
    {
        $strategies = [
            new TestVersionStrategy('Perfect', 100, 1.0),
            new TestVersionStrategy('Good', 80, 0.8),
            new TestVersionStrategy('Poor', 20, 0.2),
            new TestVersionStrategy('Unreliable', 10, 0.0),
        ];

        foreach ($strategies as $strategy) {
            $reliability = $strategy->getReliabilityScore();
            self::assertGreaterThanOrEqual(
                0.0,
                $reliability,
                "Reliability score must be >= 0.0 for {$strategy->getName()}",
            );
            self::assertLessThanOrEqual(
                1.0,
                $reliability,
                "Reliability score must be <= 1.0 for {$strategy->getName()}",
            );
        }
    }

    public function testRequiredFilesAreUsableForChecking(): void
    {
        $strategy = new TestVersionStrategy();
        $requiredFiles = $strategy->getRequiredFiles();

        self::assertNotEmpty($requiredFiles);

        // All required files should be valid relative paths
        foreach ($requiredFiles as $file) {
            self::assertIsString($file);
            self::assertNotEmpty($file);
            // Should be relative paths, not absolute
            self::assertStringNotContainsString('\\', $file);
        }
    }

    public function testStrategiesCanBeSortedByReliabilityAndPriority(): void
    {
        $strategies = [
            new TestVersionStrategy('Low Priority High Reliability', 10, 0.9),
            new TestVersionStrategy('High Priority Low Reliability', 90, 0.1),
            new TestVersionStrategy('Medium Priority Medium Reliability', 50, 0.5),
        ];

        // Sort by priority (descending)
        $byPriority = [...$strategies];
        usort($byPriority, fn ($a, $b): int => $b->getPriority() <=> $a->getPriority());

        self::assertSame('High Priority Low Reliability', $byPriority[0]->getName());
        self::assertSame('Medium Priority Medium Reliability', $byPriority[1]->getName());
        self::assertSame('Low Priority High Reliability', $byPriority[2]->getName());

        // Sort by reliability (descending)
        $byReliability = [...$strategies];
        usort($byReliability, fn ($a, $b): int => $b->getReliabilityScore() <=> $a->getReliabilityScore());

        self::assertSame('Low Priority High Reliability', $byReliability[0]->getName());
        self::assertSame('Medium Priority Medium Reliability', $byReliability[1]->getName());
        self::assertSame('High Priority Low Reliability', $byReliability[2]->getName());
    }
}

/**
 * Test implementation of VersionStrategyInterface for testing the interface contract.
 */
class TestVersionStrategy implements VersionStrategyInterface
{
    public function __construct(
        private readonly string $name = 'Test Version Strategy',
        private readonly int $priority = 50,
        private readonly float $reliability = 0.8,
    ) {
    }

    public function extractVersion(string $installationPath): ?Version
    {
        if (!$this->supports($installationPath)) {
            return null;
        }

        // Return a test version
        return new Version('12.4.8');
    }

    public function supports(string $installationPath): bool
    {
        // Simple test: supports paths containing 'composer'
        return str_contains($installationPath, 'composer');
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getRequiredFiles(): array
    {
        return ['composer.lock', 'vendor/composer/installed.json'];
    }

    public function getReliabilityScore(): float
    {
        return $this->reliability;
    }
}
