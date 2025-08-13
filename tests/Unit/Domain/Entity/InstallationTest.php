<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Domain\Entity;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Installation;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\InstallationMetadata;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\InstallationMode;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Installation::class)]
class InstallationTest extends TestCase
{
    private Installation $installation;
    private Version $version;

    protected function setUp(): void
    {
        $this->version = new Version('12.4.8');
        $this->installation = new Installation('/path/to/typo3', $this->version, 'composer');
    }

    public function testConstructorSetsProperties(): void
    {
        self::assertEquals('/path/to/typo3', $this->installation->getPath());
        self::assertSame($this->version, $this->installation->getVersion());
        self::assertEquals('composer', $this->installation->getType());
    }

    public function testDefaultTypeIsComposer(): void
    {
        $installation = new Installation('/path/to/typo3', $this->version);
        self::assertEquals('composer', $installation->getType());
    }

    public function testIsComposerMode(): void
    {
        self::assertTrue($this->installation->isComposerMode());

        $legacyInstallation = new Installation('/path/to/typo3', $this->version, 'legacy');
        self::assertFalse($legacyInstallation->isComposerMode());
    }

    public function testIsLegacyMode(): void
    {
        self::assertFalse($this->installation->isLegacyMode());

        $legacyInstallation = new Installation('/path/to/typo3', $this->version, 'legacy');
        self::assertTrue($legacyInstallation->isLegacyMode());
    }

    public function testAddAndGetExtension(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'));

        $this->installation->addExtension($extension);

        self::assertTrue($this->installation->hasExtension('test_ext'));
        self::assertSame($extension, $this->installation->getExtension('test_ext'));
    }

    public function testGetExtensionReturnsNullForNonExistentExtension(): void
    {
        self::assertNull($this->installation->getExtension('non_existent'));
        self::assertFalse($this->installation->hasExtension('non_existent'));
    }

    public function testGetExtensions(): void
    {
        $extension1 = new Extension('ext1', 'Extension 1', new Version('1.0.0'));
        $extension2 = new Extension('ext2', 'Extension 2', new Version('2.0.0'));

        $this->installation->addExtension($extension1);
        $this->installation->addExtension($extension2);

        // But individual extensions can still be retrieved
        self::assertSame($extension1, $this->installation->getExtension('ext1'));
        self::assertSame($extension2, $this->installation->getExtension('ext2'));
    }

    public function testSetAndGetConfiguration(): void
    {
        $config = [
            'DB' => [
                'Connections' => [
                    'Default' => [
                        'driver' => 'mysqli',
                        'host' => 'localhost',
                    ],
                ],
            ],
            'MAIL' => [
                'transport' => 'smtp',
            ],
        ];

        $this->installation->setConfiguration($config);

        self::assertEquals($config, $this->installation->getConfiguration());
    }

    public function testGetConfigurationValue(): void
    {
        $config = [
            'DB' => [
                'Connections' => [
                    'Default' => [
                        'driver' => 'mysqli',
                        'host' => 'localhost',
                    ],
                ],
            ],
            'MAIL' => [
                'transport' => 'smtp',
            ],
        ];

        $this->installation->setConfiguration($config);

        // Test nested path access
        self::assertEquals('mysqli', $this->installation->getConfigurationValue('DB.Connections.Default.driver'));
        self::assertEquals('localhost', $this->installation->getConfigurationValue('DB.Connections.Default.host'));
        self::assertEquals('smtp', $this->installation->getConfigurationValue('MAIL.transport'));

        // Test non-existent path returns default
        self::assertNull($this->installation->getConfigurationValue('NON.EXISTENT.PATH'));
        self::assertEquals('default', $this->installation->getConfigurationValue('NON.EXISTENT.PATH', 'default'));

        // Test partial path that exists
        self::assertEquals(['transport' => 'smtp'], $this->installation->getConfigurationValue('MAIL'));
    }

    public function testGetConfigurationValueWithEmptyConfiguration(): void
    {
        self::assertNull($this->installation->getConfigurationValue('ANY.PATH'));
        self::assertEquals('default', $this->installation->getConfigurationValue('ANY.PATH', 'default'));
    }

    public function testGetConfigurationValueWithNonArrayValue(): void
    {
        $this->installation->setConfiguration(['key' => 'value']);

        // Trying to access nested path on non-array value should return default
        self::assertNull($this->installation->getConfigurationValue('key.nested'));
        self::assertEquals('default', $this->installation->getConfigurationValue('key.nested', 'default'));
    }

    public function testAddExtensionOverwritesExistingExtension(): void
    {
        $extension1 = new Extension('test_ext', 'Test Extension 1', new Version('1.0.0'));
        $extension2 = new Extension('test_ext', 'Test Extension 2', new Version('2.0.0'));

        $this->installation->addExtension($extension1);
        $this->installation->addExtension($extension2);

        // But the second extension should overwrite the first
        self::assertSame($extension2, $this->installation->getExtension('test_ext'));
    }

    public function testEmptyConfigurationReturnsEmptyArray(): void
    {
        self::assertEquals([], $this->installation->getConfiguration());
    }

    // Tests for new discovery system methods
    public function testSetAndGetMode(): void
    {
        self::assertNull($this->installation->getMode());

        $mode = InstallationMode::COMPOSER;
        $this->installation->setMode($mode);

        self::assertSame($mode, $this->installation->getMode());
    }

    public function testSetAndGetMetadata(): void
    {
        self::assertNull($this->installation->getMetadata());

        $metadata = new InstallationMetadata(
            ['required' => '8.1'],
            ['driver' => 'mysqli'],
            ['frontend'],
            new \DateTimeImmutable(),
            [],
        );

        $this->installation->setMetadata($metadata);

        self::assertSame($metadata, $this->installation->getMetadata());
    }

    public function testGetSystemExtensions(): void
    {
        $systemExt = new Extension('core', 'Core', new Version('12.4.0'), 'system');
        $localExt = new Extension('my_ext', 'My Extension', new Version('1.0.0'), 'local');
        $composerExt = new Extension('vendor_ext', 'Vendor Extension', new Version('2.0.0'), 'composer', 'vendor/extension');

        $this->installation->addExtension($systemExt);
        $this->installation->addExtension($localExt);
        $this->installation->addExtension($composerExt);

        $systemExtensions = $this->installation->getSystemExtensions();

        self::assertCount(1, $systemExtensions);
        self::assertContains($systemExt, $systemExtensions);
        self::assertNotContains($localExt, $systemExtensions);
        self::assertNotContains($composerExt, $systemExtensions);
    }

    public function testGetLocalExtensions(): void
    {
        $systemExt = new Extension('core', 'Core', new Version('12.4.0'), 'system');
        $localExt = new Extension('my_ext', 'My Extension', new Version('1.0.0'), 'local');
        $localExt2 = new Extension('my_ext2', 'My Extension 2', new Version('1.0.0'), 'local');
        $composerExt = new Extension('vendor_ext', 'Vendor Extension', new Version('2.0.0'), 'composer', 'vendor/extension');

        $this->installation->addExtension($systemExt);
        $this->installation->addExtension($localExt);
        $this->installation->addExtension($localExt2);
        $this->installation->addExtension($composerExt);

        $localExtensions = $this->installation->getLocalExtensions();

        self::assertCount(2, $localExtensions);
        self::assertContains($localExt, $localExtensions);
        self::assertContains($localExt2, $localExtensions);
        self::assertNotContains($systemExt, $localExtensions);
        self::assertNotContains($composerExt, $localExtensions);
    }

    public function testGetComposerExtensions(): void
    {
        $systemExt = new Extension('core', 'Core', new Version('12.4.0'), 'system');
        $localExt = new Extension('my_ext', 'My Extension', new Version('1.0.0'), 'local');
        $composerExt = new Extension('vendor_ext', 'Vendor Extension', new Version('2.0.0'), 'composer', 'vendor/extension');
        $composerExt2 = new Extension('another_ext', 'Another Extension', new Version('1.5.0'), 'local', 'vendor/another');

        $this->installation->addExtension($systemExt);
        $this->installation->addExtension($localExt);
        $this->installation->addExtension($composerExt);
        $this->installation->addExtension($composerExt2);

        $composerExtensions = $this->installation->getComposerExtensions();

        self::assertCount(2, $composerExtensions);
        self::assertContains($composerExt, $composerExtensions);
        self::assertContains($composerExt2, $composerExtensions);
        self::assertNotContains($systemExt, $composerExtensions);
        self::assertNotContains($localExt, $composerExtensions);
    }

    public function testIsMixedModeWithBothLocalAndComposerExtensions(): void
    {
        $localExt = new Extension('my_ext', 'My Extension', new Version('1.0.0'), 'local');
        $composerExt = new Extension('vendor_ext', 'Vendor Extension', new Version('2.0.0'), 'composer', 'vendor/extension');

        $this->installation->addExtension($localExt);
        $this->installation->addExtension($composerExt);

        self::assertTrue($this->installation->isMixedMode());
    }

    public function testIsMixedModeWithOnlyLocalExtensions(): void
    {
        $localExt = new Extension('my_ext', 'My Extension', new Version('1.0.0'), 'local');

        $this->installation->addExtension($localExt);

        self::assertFalse($this->installation->isMixedMode());
    }

    public function testIsMixedModeWithOnlyComposerExtensions(): void
    {
        $composerExt = new Extension('vendor_ext', 'Vendor Extension', new Version('2.0.0'), 'composer', 'vendor/extension');

        $this->installation->addExtension($composerExt);

        self::assertFalse($this->installation->isMixedMode());
    }

    public function testIsMixedModeWithNoExtensions(): void
    {
        self::assertFalse($this->installation->isMixedMode());
    }

    public function testMarkAsInvalid(): void
    {
        self::assertTrue($this->installation->isValid());
        self::assertEmpty($this->installation->getValidationErrors());

        $this->installation->markAsInvalid('Missing composer.json');

        self::assertFalse($this->installation->isValid());
        self::assertSame(['Missing composer.json'], $this->installation->getValidationErrors());
    }

    public function testAddValidationError(): void
    {
        self::assertTrue($this->installation->isValid());

        $this->installation->addValidationError('First error');

        self::assertFalse($this->installation->isValid());
        self::assertSame(['First error'], $this->installation->getValidationErrors());

        $this->installation->addValidationError('Second error');

        self::assertFalse($this->installation->isValid());
        self::assertSame(['First error', 'Second error'], $this->installation->getValidationErrors());
    }

    public function testClearValidationErrors(): void
    {
        $this->installation->addValidationError('Some error');
        $this->installation->addValidationError('Another error');

        self::assertFalse($this->installation->isValid());
        self::assertCount(2, $this->installation->getValidationErrors());

        $this->installation->clearValidationErrors();

        self::assertTrue($this->installation->isValid());
        self::assertEmpty($this->installation->getValidationErrors());
    }

    public function testAddValidationErrorWhenAlreadyInvalid(): void
    {
        $this->installation->markAsInvalid('First error');
        $this->installation->addValidationError('Second error');

        self::assertFalse($this->installation->isValid());
        self::assertSame(['First error', 'Second error'], $this->installation->getValidationErrors());
    }
}
