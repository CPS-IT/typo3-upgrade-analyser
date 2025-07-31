<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Domain\Entity;

use PHPUnit\Framework\TestCase;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Installation;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;

/**
 * Test case for the Installation entity
 *
 * @covers \CPSIT\UpgradeAnalyzer\Domain\Entity\Installation
 */
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
        
        $extensions = $this->installation->getExtensions();
        
        self::assertCount(2, $extensions);
        self::assertArrayHasKey('ext1', $extensions);
        self::assertArrayHasKey('ext2', $extensions);
        self::assertSame($extension1, $extensions['ext1']);
        self::assertSame($extension2, $extensions['ext2']);
    }

    public function testSetAndGetConfiguration(): void
    {
        $config = [
            'DB' => [
                'Connections' => [
                    'Default' => [
                        'driver' => 'mysqli',
                        'host' => 'localhost',
                    ]
                ]
            ],
            'MAIL' => [
                'transport' => 'smtp'
            ]
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
                    ]
                ]
            ],
            'MAIL' => [
                'transport' => 'smtp'
            ]
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
        
        self::assertCount(1, $this->installation->getExtensions());
        self::assertSame($extension2, $this->installation->getExtension('test_ext'));
    }

    public function testEmptyConfigurationReturnsEmptyArray(): void
    {
        self::assertEquals([], $this->installation->getConfiguration());
    }
}