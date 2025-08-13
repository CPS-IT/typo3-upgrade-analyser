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

use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ValidationSeverity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValidationSeverity::class)]
final class ValidationSeverityTest extends TestCase
{
    public function testEnumCases(): void
    {
        $cases = ValidationSeverity::cases();

        self::assertCount(4, $cases);
        self::assertContains(ValidationSeverity::INFO, $cases);
        self::assertContains(ValidationSeverity::WARNING, $cases);
        self::assertContains(ValidationSeverity::ERROR, $cases);
        self::assertContains(ValidationSeverity::CRITICAL, $cases);
    }

    public function testEnumValues(): void
    {
        self::assertSame('info', ValidationSeverity::INFO->value);
        self::assertSame('warning', ValidationSeverity::WARNING->value);
        self::assertSame('error', ValidationSeverity::ERROR->value);
        self::assertSame('critical', ValidationSeverity::CRITICAL->value);
    }

    #[DataProvider('getDisplayNameProvider')]
    public function testGetDisplayName(ValidationSeverity $severity, string $expected): void
    {
        self::assertSame($expected, $severity->getDisplayName());
    }

    /**
     * @return array<string, array{ValidationSeverity, string}>
     */
    public static function getDisplayNameProvider(): array
    {
        return [
            'info' => [ValidationSeverity::INFO, 'Info'],
            'warning' => [ValidationSeverity::WARNING, 'Warning'],
            'error' => [ValidationSeverity::ERROR, 'Error'],
            'critical' => [ValidationSeverity::CRITICAL, 'Critical'],
        ];
    }

    #[DataProvider('getDescriptionProvider')]
    public function testGetDescription(ValidationSeverity $severity, string $expected): void
    {
        self::assertSame($expected, $severity->getDescription());
    }

    /**
     * @return array<string, array{ValidationSeverity, string}>
     */
    public static function getDescriptionProvider(): array
    {
        return [
            'info' => [ValidationSeverity::INFO, 'Informational message, no action required'],
            'warning' => [ValidationSeverity::WARNING, 'Potential issue that should be reviewed'],
            'error' => [ValidationSeverity::ERROR, 'Issue that may prevent proper analysis'],
            'critical' => [ValidationSeverity::CRITICAL, 'Critical issue that prevents analysis'],
        ];
    }

    #[DataProvider('getNumericValueProvider')]
    public function testGetNumericValue(ValidationSeverity $severity, int $expected): void
    {
        self::assertSame($expected, $severity->getNumericValue());
    }

    /**
     * @return array<string, array{ValidationSeverity, int}>
     */
    public static function getNumericValueProvider(): array
    {
        return [
            'info' => [ValidationSeverity::INFO, 1],
            'warning' => [ValidationSeverity::WARNING, 2],
            'error' => [ValidationSeverity::ERROR, 3],
            'critical' => [ValidationSeverity::CRITICAL, 4],
        ];
    }

    #[DataProvider('isBlockingAnalysisProvider')]
    public function testIsBlockingAnalysis(ValidationSeverity $severity, bool $expected): void
    {
        self::assertSame($expected, $severity->isBlockingAnalysis());
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

    #[DataProvider('fromNumericValueProvider')]
    public function testFromNumericValue(int $value, ValidationSeverity $expected): void
    {
        self::assertSame($expected, ValidationSeverity::fromNumericValue($value));
    }

    /**
     * @return array<string, array{int, ValidationSeverity}>
     */
    public static function fromNumericValueProvider(): array
    {
        return [
            '1 -> info' => [1, ValidationSeverity::INFO],
            '2 -> warning' => [2, ValidationSeverity::WARNING],
            '3 -> error' => [3, ValidationSeverity::ERROR],
            '4 -> critical' => [4, ValidationSeverity::CRITICAL],
        ];
    }

    #[DataProvider('fromNumericValueInvalidProvider')]
    public function testFromNumericValueWithInvalidValue(int $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid severity value: {$value}");

        ValidationSeverity::fromNumericValue($value);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function fromNumericValueInvalidProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'too high' => [5],
            'much too high' => [100],
        ];
    }

    public function testFromValueWithValidValues(): void
    {
        self::assertSame(ValidationSeverity::INFO, ValidationSeverity::from('info'));
        self::assertSame(ValidationSeverity::WARNING, ValidationSeverity::from('warning'));
        self::assertSame(ValidationSeverity::ERROR, ValidationSeverity::from('error'));
        self::assertSame(ValidationSeverity::CRITICAL, ValidationSeverity::from('critical'));
    }

    public function testTryFromValue(): void
    {
        self::assertSame(ValidationSeverity::INFO, ValidationSeverity::tryFrom('info'));
        self::assertSame(ValidationSeverity::WARNING, ValidationSeverity::tryFrom('warning'));
        self::assertSame(ValidationSeverity::ERROR, ValidationSeverity::tryFrom('error'));
        self::assertSame(ValidationSeverity::CRITICAL, ValidationSeverity::tryFrom('critical'));
    }

    public function testEnumSerialization(): void
    {
        foreach (ValidationSeverity::cases() as $severity) {
            $serialized = serialize($severity);
            $unserialized = unserialize($serialized);

            self::assertSame($severity, $unserialized);
            self::assertSame($severity->value, $unserialized->value);
            self::assertSame($severity->getNumericValue(), $unserialized->getNumericValue());
        }
    }

    public function testSeverityOrdering(): void
    {
        $severities = [
            ValidationSeverity::INFO,
            ValidationSeverity::WARNING,
            ValidationSeverity::ERROR,
            ValidationSeverity::CRITICAL,
        ];

        for ($i = 0; $i < \count($severities) - 1; ++$i) {
            $current = $severities[$i];
            $next = $severities[$i + 1];

            self::assertLessThan(
                $next->getNumericValue(),
                $current->getNumericValue(),
                "Severity {$current->value} should have lower numeric value than {$next->value}",
            );
        }
    }

    public function testMatchExpression(): void
    {
        foreach (ValidationSeverity::cases() as $severity) {
            $result = match ($severity) {
                ValidationSeverity::INFO => 'info_matched',
                ValidationSeverity::WARNING => 'warning_matched',
                ValidationSeverity::ERROR => 'error_matched',
                ValidationSeverity::CRITICAL => 'critical_matched',
            };

            self::assertSame($severity->value . '_matched', $result);
        }
    }

    public function testComparisonMethods(): void
    {
        self::assertTrue(ValidationSeverity::INFO->getNumericValue() < ValidationSeverity::WARNING->getNumericValue());
        self::assertTrue(ValidationSeverity::WARNING->getNumericValue() < ValidationSeverity::ERROR->getNumericValue());
        self::assertTrue(ValidationSeverity::ERROR->getNumericValue() < ValidationSeverity::CRITICAL->getNumericValue());
    }
}
