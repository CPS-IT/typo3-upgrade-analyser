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

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\ExtensionType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExtensionType::class)]
final class ExtensionTypeTest extends TestCase
{
    public function testEnumCases(): void
    {
        $cases = ExtensionType::cases();

        self::assertContains(ExtensionType::SYSTEM, $cases);
        self::assertContains(ExtensionType::LOCAL, $cases);
        self::assertContains(ExtensionType::COMPOSER, $cases);
    }

    #[DataProvider('isSystemExtensionProvider')]
    public function testIsSystemExtension(ExtensionType $type, bool $expected): void
    {
        self::assertSame($expected, $type->isSystemExtension());
    }

    /**
     * @return array<string, array{ExtensionType, bool}>
     */
    public static function isSystemExtensionProvider(): array
    {
        return [
            'system' => [ExtensionType::SYSTEM, true],
            'local' => [ExtensionType::LOCAL, false],
            'composer' => [ExtensionType::COMPOSER, false],
        ];
    }

    #[DataProvider('isLocalExtensionProvider')]
    public function testIsLocalExtension(ExtensionType $type, bool $expected): void
    {
        self::assertSame($expected, $type->isLocalExtension());
    }

    /**
     * @return array<string, array{ExtensionType, bool}>
     */
    public static function isLocalExtensionProvider(): array
    {
        return [
            'system' => [ExtensionType::SYSTEM, false],
            'local' => [ExtensionType::LOCAL, true],
            'composer' => [ExtensionType::COMPOSER, false],
        ];
    }

    #[DataProvider('isComposerExtensionProvider')]
    public function testIsComposerExtension(ExtensionType $type, bool $expected): void
    {
        self::assertSame($expected, $type->isComposerExtension());
    }

    /**
     * @return array<string, array{ExtensionType, bool}>
     */
    public static function isComposerExtensionProvider(): array
    {
        return [
            'system' => [ExtensionType::SYSTEM, false],
            'local' => [ExtensionType::LOCAL, false],
            'composer' => [ExtensionType::COMPOSER, true],
        ];
    }

    #[DataProvider('getDisplayNameProvider')]
    public function testGetDisplayName(ExtensionType $type, string $expected): void
    {
        self::assertSame($expected, $type->getDisplayName());
    }

    /**
     * @return array<string, array{ExtensionType, string}>
     */
    public static function getDisplayNameProvider(): array
    {
        return [
            'system' => [ExtensionType::SYSTEM, 'System Extension'],
            'local' => [ExtensionType::LOCAL, 'Local Extension'],
            'composer' => [ExtensionType::COMPOSER, 'Composer Extension'],
        ];
    }

    #[DataProvider('getDescriptionProvider')]
    public function testGetDescription(ExtensionType $type, string $expected): void
    {
        self::assertSame($expected, $type->getDescription());
    }

    /**
     * @return array<string, array{ExtensionType, string}>
     *
     * @noinspection SpellCheckingInspection
     */
    public static function getDescriptionProvider(): array
    {
        return [
            'system' => [ExtensionType::SYSTEM, 'Core TYPO3 system extension (typo3/sysext/)'],
            'local' => [ExtensionType::LOCAL, 'Local extension installed in typo3conf/ext/'],
            'composer' => [ExtensionType::COMPOSER, 'Extension managed via Composer (vendor/)'],
        ];
    }

    #[DataProvider('getTypicalPathProvider')]
    public function testGetTypicalPath(ExtensionType $type, string $basePath, string $expected): void
    {
        self::assertSame($expected, $type->getTypicalPath($basePath));
    }

    /**
     * @return array<string, array{ExtensionType, string, string}>
     *
     * @noinspection SpellCheckingInspection
     */
    public static function getTypicalPathProvider(): array
    {
        return [
            'system with empty base path' => [ExtensionType::SYSTEM, '', '/typo3/sysext/'],
            'system with base path' => [ExtensionType::SYSTEM, '/var/www/html', '/var/www/html/typo3/sysext/'],
            'local with empty base path' => [ExtensionType::LOCAL, '', '/typo3conf/ext/'],
            'local with base path' => [ExtensionType::LOCAL, '/var/www/html', '/var/www/html/typo3conf/ext/'],
            'composer with empty base path' => [ExtensionType::COMPOSER, '', '/vendor/'],
            'composer with base path' => [ExtensionType::COMPOSER, '/var/www/html', '/var/www/html/vendor/'],
        ];
    }

    public function testGetTypicalPathWithDefaultBasePath(): void
    {
        /* @noinspection SpellCheckingInspection */
        self::assertSame('/typo3/sysext/', ExtensionType::SYSTEM->getTypicalPath());
        self::assertSame('/typo3conf/ext/', ExtensionType::LOCAL->getTypicalPath());
        self::assertSame('/vendor/', ExtensionType::COMPOSER->getTypicalPath());
    }

    #[DataProvider('fromValueProvider')]
    public function testFromValue(string $value, ExtensionType $expected): void
    {
        self::assertSame($expected, ExtensionType::from($value));
    }

    /**
     * @return array<string, array{string, ExtensionType}>
     */
    public static function fromValueProvider(): array
    {
        return [
            'system' => ['system', ExtensionType::SYSTEM],
            'local' => ['local', ExtensionType::LOCAL],
            'composer' => ['composer', ExtensionType::COMPOSER],
        ];
    }

    #[DataProvider('tryFromValueProvider')]
    public function testTryFromValue(string $value, ?ExtensionType $expected): void
    {
        self::assertSame($expected, ExtensionType::tryFrom($value));
    }

    /**
     * @return array<string, array{string, ?ExtensionType}>
     */
    public static function tryFromValueProvider(): array
    {
        return [
            'system' => ['system', ExtensionType::SYSTEM],
            'local' => ['local', ExtensionType::LOCAL],
            'composer' => ['composer', ExtensionType::COMPOSER],
            'invalid' => ['invalid', null],
            'empty' => ['', null],
        ];
    }

    public function testEnumSerialization(): void
    {
        foreach (ExtensionType::cases() as $type) {
            $serialized = serialize($type);
            $unserialized = unserialize($serialized);

            self::assertSame($type, $unserialized);
            self::assertSame($type->value, $unserialized->value);
        }
    }

    public function testMatchExpression(): void
    {
        foreach (ExtensionType::cases() as $type) {
            $result = match ($type) {
                ExtensionType::SYSTEM => 'system',
                ExtensionType::LOCAL => 'local',
                ExtensionType::COMPOSER => 'composer',
            };

            self::assertSame($type->value, $result);
        }
    }
}
