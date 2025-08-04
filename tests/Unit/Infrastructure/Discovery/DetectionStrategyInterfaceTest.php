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

use CPSIT\UpgradeAnalyzer\Domain\Entity\Installation;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\DetectionStrategyInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests the DetectionStrategyInterface contract with a mock implementation.
 *
 * @coversNothing
 */
final class DetectionStrategyInterfaceTest extends TestCase
{
    public function testInterfaceContractsWorkCorrectly(): void
    {
        $strategy = new TestDetectionStrategy();

        // Test interface method contracts
        self::assertIsString($strategy->getName());
        self::assertIsString($strategy->getDescription());
        self::assertIsInt($strategy->getPriority());
        self::assertIsArray($strategy->getRequiredIndicators());

        // Test behavior with valid path
        $validPath = '/valid/path';
        self::assertTrue($strategy->supports($validPath));

        $installation = $strategy->detect($validPath);
        self::assertInstanceOf(Installation::class, $installation);
        self::assertSame($validPath, $installation->getPath());

        // Test behavior with invalid path
        $invalidPath = '/does/not/exist';
        self::assertFalse($strategy->supports($invalidPath));
        self::assertNull($strategy->detect($invalidPath));
    }

    public function testInterfaceMethodReturnTypes(): void
    {
        $strategy = new TestDetectionStrategy();

        // Test all methods return correct types
        self::assertIsString($strategy->getName());
        self::assertIsString($strategy->getDescription());
        self::assertIsInt($strategy->getPriority());
        self::assertIsArray($strategy->getRequiredIndicators());

        foreach ($strategy->getRequiredIndicators() as $indicator) {
            self::assertIsString($indicator);
        }

        // Test supports method with various inputs
        self::assertIsBool($strategy->supports('/some/path'));
        self::assertIsBool($strategy->supports(''));
        self::assertIsBool($strategy->supports('/'));

        // Test detect method returns Installation or null
        $result = $strategy->detect('/valid/path');
        self::assertTrue($result instanceof Installation || null === $result);
    }

    public function testMultipleStrategiesWithDifferentPriorities(): void
    {
        $highPriorityStrategy = new TestDetectionStrategy('high', 100);
        $lowPriorityStrategy = new TestDetectionStrategy('low', 10);

        self::assertGreaterThan(
            $lowPriorityStrategy->getPriority(),
            $highPriorityStrategy->getPriority(),
        );

        // Strategies should be sortable by priority
        $strategies = [$lowPriorityStrategy, $highPriorityStrategy];
        usort($strategies, fn ($a, $b) => $b->getPriority() <=> $a->getPriority());

        self::assertSame($highPriorityStrategy, $strategies[0]);
        self::assertSame($lowPriorityStrategy, $strategies[1]);
    }

    public function testRequiredIndicatorsAreUsableForFiltering(): void
    {
        $strategy = new TestDetectionStrategy();
        $indicators = $strategy->getRequiredIndicators();

        self::assertNotEmpty($indicators);

        // All indicators should be valid file/directory names
        foreach ($indicators as $indicator) {
            self::assertIsString($indicator);
            self::assertNotEmpty($indicator);
            // Should not contain path separators (just filenames)
            self::assertStringNotContainsString('/', $indicator);
            self::assertStringNotContainsString('\\', $indicator);
        }
    }
}

/**
 * Test implementation of DetectionStrategyInterface for testing the interface contract.
 */
class TestDetectionStrategy implements DetectionStrategyInterface
{
    public function __construct(
        private readonly string $name = 'Test Strategy',
        private readonly int $priority = 50,
    ) {
    }

    public function detect(string $path): ?Installation
    {
        if (!$this->supports($path)) {
            return null;
        }

        return new Installation($path, new Version('12.4.0'), 'composer');
    }

    public function supports(string $path): bool
    {
        // Simple test: supports paths containing 'valid'
        return str_contains($path, 'valid');
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getRequiredIndicators(): array
    {
        return ['composer.json', 'vendor'];
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return 'Test implementation for unit testing the DetectionStrategyInterface';
    }
}
