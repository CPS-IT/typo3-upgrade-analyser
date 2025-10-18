<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Domain\ValueObject;

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\InstallationMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(InstallationMode::class)]
final class InstallationModeTest extends TestCase
{
    public function testEnumCases(): void
    {
        $cases = InstallationMode::cases();

        self::assertContains(InstallationMode::COMPOSER, $cases);
    }

    public function testIsComposerMode(): void
    {
        self::assertTrue(InstallationMode::COMPOSER->isComposerMode());
    }

    public function testGetDisplayName(): void
    {
        self::assertSame('Composer Installation', InstallationMode::COMPOSER->getDisplayName());
    }

    public function testGetDescription(): void
    {
        $expectedDescription = 'Modern TYPO3 installation managed via Composer with vendor directory and composer.json';
        self::assertSame($expectedDescription, InstallationMode::COMPOSER->getDescription());
    }

    /**
     * @return array<string, array{string, InstallationMode}>
     */
    public static function fromValueProvider(): array
    {
        return [
            'composer' => ['composer', InstallationMode::COMPOSER],
        ];
    }

    #[DataProvider('tryFromValueProvider')]
    public function testTryFromValue(string $value, ?InstallationMode $expected): void
    {
        self::assertSame($expected, InstallationMode::tryFrom($value));
    }

    /**
     * @return array<string, array{string, ?InstallationMode}>
     */
    public static function tryFromValueProvider(): array
    {
        return [
            'composer' => ['composer', InstallationMode::COMPOSER],
            'invalid' => ['invalid', null],
            'empty' => ['', null],
        ];
    }

    public function testEnumSerialization(): void
    {
        $mode = InstallationMode::COMPOSER;
        $serialized = serialize($mode);
        $unserialized = unserialize($serialized);

        self::assertSame($mode, $unserialized);
        self::assertNotEmpty($unserialized->value);
    }
}
