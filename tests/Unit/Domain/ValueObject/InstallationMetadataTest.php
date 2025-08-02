<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Domain\ValueObject;

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\InstallationMetadata;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CPSIT\UpgradeAnalyzer\Domain\ValueObject\InstallationMetadata
 */
final class InstallationMetadataTest extends TestCase
{
    private \DateTimeImmutable $testDate;

    protected function setUp(): void
    {
        $this->testDate = new \DateTimeImmutable('2023-12-01 15:30:00');
    }

    public function testConstructorSetsAllProperties(): void
    {
        $phpVersions = ['required' => '8.1', 'current' => '8.2'];
        $databaseConfig = ['driver' => 'mysqli', 'host' => 'localhost'];
        $enabledFeatures = ['frontend', 'backend', 'install'];
        $customPaths = ['web' => '/var/www/html', 'config' => '/config'];
        $discoveryData = ['source' => 'composer'];

        $metadata = new InstallationMetadata(
            $phpVersions,
            $databaseConfig,
            $enabledFeatures,
            $this->testDate,
            $customPaths,
            $discoveryData
        );

        self::assertSame($phpVersions, $metadata->getPhpVersions());
        self::assertSame($databaseConfig, $metadata->getDatabaseConfig());
        self::assertSame($enabledFeatures, $metadata->getEnabledFeatures());
        self::assertSame($this->testDate, $metadata->getLastModified());
        self::assertSame($customPaths, $metadata->getCustomPaths());
        self::assertSame($discoveryData, $metadata->getDiscoveryData());
    }

    public function testConstructorWithEmptyDiscoveryData(): void
    {
        $metadata = new InstallationMetadata(
            [],
            [],
            [],
            $this->testDate,
            []
        );

        self::assertSame([], $metadata->getDiscoveryData());
    }

    public function testGetRequiredPhpVersion(): void
    {
        $metadata = new InstallationMetadata(
            ['required' => '8.1', 'current' => '8.2'],
            [],
            [],
            $this->testDate,
            []
        );

        self::assertSame('8.1', $metadata->getRequiredPhpVersion());
    }

    public function testGetRequiredPhpVersionReturnsNullWhenNotSet(): void
    {
        $metadata = new InstallationMetadata(
            ['current' => '8.2'],
            [],
            [],
            $this->testDate,
            []
        );

        self::assertNull($metadata->getRequiredPhpVersion());
    }

    public function testGetCurrentPhpVersion(): void
    {
        $metadata = new InstallationMetadata(
            ['required' => '8.1', 'current' => '8.2'],
            [],
            [],
            $this->testDate,
            []
        );

        self::assertSame('8.2', $metadata->getCurrentPhpVersion());
    }

    public function testGetCurrentPhpVersionReturnsNullWhenNotSet(): void
    {
        $metadata = new InstallationMetadata(
            ['required' => '8.1'],
            [],
            [],
            $this->testDate,
            []
        );

        self::assertNull($metadata->getCurrentPhpVersion());
    }

    public function testGetDatabaseDriver(): void
    {
        $metadata = new InstallationMetadata(
            [],
            ['driver' => 'mysqli', 'host' => 'localhost'],
            [],
            $this->testDate,
            []
        );

        self::assertSame('mysqli', $metadata->getDatabaseDriver());
    }

    public function testGetDatabaseDriverReturnsNullWhenNotSet(): void
    {
        $metadata = new InstallationMetadata(
            [],
            ['host' => 'localhost'],
            [],
            $this->testDate,
            []
        );

        self::assertNull($metadata->getDatabaseDriver());
    }

    public function testHasFeature(): void
    {
        $metadata = new InstallationMetadata(
            [],
            [],
            ['frontend', 'backend', 'install'],
            $this->testDate,
            []
        );

        self::assertTrue($metadata->hasFeature('frontend'));
        self::assertTrue($metadata->hasFeature('backend'));
        self::assertTrue($metadata->hasFeature('install'));
        self::assertFalse($metadata->hasFeature('nonexistent'));
        self::assertFalse($metadata->hasFeature(''));
    }

    public function testHasFeatureWithEmptyFeatures(): void
    {
        $metadata = new InstallationMetadata(
            [],
            [],
            [],
            $this->testDate,
            []
        );

        self::assertFalse($metadata->hasFeature('frontend'));
    }

    public function testGetCustomPath(): void
    {
        $metadata = new InstallationMetadata(
            [],
            [],
            [],
            $this->testDate,
            ['web' => '/var/www/html', 'config' => '/config']
        );

        self::assertSame('/var/www/html', $metadata->getCustomPath('web'));
        self::assertSame('/config', $metadata->getCustomPath('config'));
        self::assertNull($metadata->getCustomPath('nonexistent'));
    }

    public function testGetDiscoveryValue(): void
    {
        $metadata = new InstallationMetadata(
            [],
            [],
            [],
            $this->testDate,
            [],
            ['source' => 'composer', 'version' => '12.4', 'nested' => ['key' => 'value']]
        );

        self::assertSame('composer', $metadata->getDiscoveryValue('source'));
        self::assertSame('12.4', $metadata->getDiscoveryValue('version'));
        self::assertSame(['key' => 'value'], $metadata->getDiscoveryValue('nested'));
        self::assertNull($metadata->getDiscoveryValue('nonexistent'));
    }

    public function testWithDiscoveryData(): void
    {
        $originalData = ['source' => 'composer'];
        $metadata = new InstallationMetadata(
            [],
            [],
            [],
            $this->testDate,
            [],
            $originalData
        );

        $additionalData = ['version' => '12.4', 'mode' => 'production'];
        $newMetadata = $metadata->withDiscoveryData($additionalData);

        // Original should be unchanged
        self::assertSame($originalData, $metadata->getDiscoveryData());
        
        // New should have merged data
        $expectedMerged = ['source' => 'composer', 'version' => '12.4', 'mode' => 'production'];
        self::assertSame($expectedMerged, $newMetadata->getDiscoveryData());
        
        // Should be different instances
        self::assertNotSame($metadata, $newMetadata);
    }

    public function testWithDiscoveryDataOverwritesExistingKeys(): void
    {
        $metadata = new InstallationMetadata(
            [],
            [],
            [],
            $this->testDate,
            [],
            ['source' => 'composer', 'version' => '12.4']
        );

        $newMetadata = $metadata->withDiscoveryData(['version' => '13.0', 'new_key' => 'new_value']);

        $expected = ['source' => 'composer', 'version' => '13.0', 'new_key' => 'new_value'];
        self::assertSame($expected, $newMetadata->getDiscoveryData());
    }

    public function testWithDiscoveryDataWithEmptyArray(): void
    {
        $originalData = ['source' => 'composer'];
        $metadata = new InstallationMetadata(
            [],
            [],
            [],
            $this->testDate,
            [],
            $originalData
        );

        $newMetadata = $metadata->withDiscoveryData([]);

        self::assertSame($originalData, $newMetadata->getDiscoveryData());
        self::assertNotSame($metadata, $newMetadata);
    }

    public function testToArray(): void
    {
        $phpVersions = ['required' => '8.1', 'current' => '8.2'];
        $databaseConfig = ['driver' => 'mysqli', 'host' => 'localhost'];
        $enabledFeatures = ['frontend', 'backend'];
        $customPaths = ['web' => '/var/www/html'];
        $discoveryData = ['source' => 'composer'];

        $metadata = new InstallationMetadata(
            $phpVersions,
            $databaseConfig,
            $enabledFeatures,
            $this->testDate,
            $customPaths,
            $discoveryData
        );

        $array = $metadata->toArray();

        $expected = [
            'php_versions' => $phpVersions,
            'database_config' => $databaseConfig,
            'enabled_features' => $enabledFeatures,
            'last_modified' => $this->testDate->format(\DateTimeInterface::ATOM),
            'custom_paths' => $customPaths,
            'discovery_data' => $discoveryData,
        ];

        self::assertSame($expected, $array);
    }

    public function testToArrayWithComplexData(): void
    {
        $complexDiscoveryData = [
            'nested' => ['level1' => ['level2' => 'value']],
            'array' => [1, 2, 3],
            'boolean' => true,
            'null' => null,
        ];

        $metadata = new InstallationMetadata(
            [],
            [],
            [],
            $this->testDate,
            [],
            $complexDiscoveryData
        );

        $array = $metadata->toArray();

        self::assertSame($complexDiscoveryData, $array['discovery_data']);
    }

    public function testImmutability(): void
    {
        $phpVersions = ['required' => '8.1'];
        $databaseConfig = ['driver' => 'mysqli'];
        $enabledFeatures = ['frontend'];
        $customPaths = ['web' => '/var/www'];
        $discoveryData = ['source' => 'composer'];

        $metadata = new InstallationMetadata(
            $phpVersions,
            $databaseConfig,
            $enabledFeatures,
            $this->testDate,
            $customPaths,
            $discoveryData
        );

        // Modify original arrays
        $phpVersions['current'] = '8.2';
        $databaseConfig['host'] = 'localhost';
        $enabledFeatures[] = 'backend';
        $customPaths['config'] = '/config';
        $discoveryData['version'] = '12.4';

        // Metadata should be unchanged
        self::assertSame(['required' => '8.1'], $metadata->getPhpVersions());
        self::assertSame(['driver' => 'mysqli'], $metadata->getDatabaseConfig());
        self::assertSame(['frontend'], $metadata->getEnabledFeatures());
        self::assertSame(['web' => '/var/www'], $metadata->getCustomPaths());
        self::assertSame(['source' => 'composer'], $metadata->getDiscoveryData());
    }

    public function testDateTimeImmutability(): void
    {
        $date = new \DateTimeImmutable('2023-12-01 15:30:00');
        $metadata = new InstallationMetadata([], [], [], $date, []);

        $retrievedDate = $metadata->getLastModified();
        
        self::assertInstanceOf(\DateTimeImmutable::class, $retrievedDate);
        self::assertEquals($date, $retrievedDate);
        self::assertSame($date, $retrievedDate); // Should be the same instance
    }
}