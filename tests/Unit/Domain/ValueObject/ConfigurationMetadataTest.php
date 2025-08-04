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

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\ConfigurationMetadata;
use PHPUnit\Framework\TestCase;

/**
 * Test case for ConfigurationMetadata value object.
 *
 * @covers \CPSIT\UpgradeAnalyzer\Domain\ValueObject\ConfigurationMetadata
 */
class ConfigurationMetadataTest extends TestCase
{
    private array $sampleParseStatistics;
    private array $sampleConfigurationKeys;
    private array $sampleCustomData;
    private \DateTimeImmutable $sampleLastModified;
    private \DateTimeImmutable $sampleParsedAt;

    protected function setUp(): void
    {
        $this->sampleParseStatistics = [
            'parse_duration_seconds' => 0.025,
            'lines_processed' => 150,
            'tokens_found' => 45,
            'memory_usage_bytes' => 2048,
        ];

        $this->sampleConfigurationKeys = [
            'DB',
            'SYS',
            'EXTENSIONS',
            'FE',
            'BE',
        ];

        $this->sampleCustomData = [
            'extension_count' => 25,
            'custom_config_detected' => true,
            'security_warnings' => 2,
        ];

        $this->sampleLastModified = new \DateTimeImmutable('2023-01-01 12:00:00');
        $this->sampleParsedAt = new \DateTimeImmutable('2023-01-02 10:30:00');
    }

    public function testBasicConfigurationMetadataCreation(): void
    {
        $metadata = new ConfigurationMetadata(
            '/var/www/config/LocalConfiguration.php',
            'LocalConfiguration.php',
            'php',
            2048,
            $this->sampleLastModified,
            $this->sampleParsedAt,
            'PhpConfigurationParser',
        );

        self::assertSame('/var/www/config/LocalConfiguration.php', $metadata->getFilePath());
        self::assertSame('LocalConfiguration.php', $metadata->getFileName());
        self::assertSame('php', $metadata->getFormat());
        self::assertSame(2048, $metadata->getFileSize());
        self::assertSame($this->sampleLastModified, $metadata->getLastModified());
        self::assertSame($this->sampleParsedAt, $metadata->getParsedAt());
        self::assertSame('PhpConfigurationParser', $metadata->getParser());
        self::assertSame([], $metadata->getParseStatistics());
        self::assertSame([], $metadata->getConfigurationKeys());
        self::assertNull($metadata->getTypo3Version());
        self::assertNull($metadata->getPhpVersion());
        self::assertSame([], $metadata->getCustomData());
    }

    public function testConfigurationMetadataWithAllParameters(): void
    {
        $metadata = new ConfigurationMetadata(
            '/var/www/config/Services.yaml',
            'Services.yaml',
            'yaml',
            4096,
            $this->sampleLastModified,
            $this->sampleParsedAt,
            'YamlConfigurationParser',
            $this->sampleParseStatistics,
            $this->sampleConfigurationKeys,
            '12.4.0',
            '8.1.0',
            $this->sampleCustomData,
        );

        self::assertSame('/var/www/config/Services.yaml', $metadata->getFilePath());
        self::assertSame('Services.yaml', $metadata->getFileName());
        self::assertSame('yaml', $metadata->getFormat());
        self::assertSame(4096, $metadata->getFileSize());
        self::assertSame($this->sampleLastModified, $metadata->getLastModified());
        self::assertSame($this->sampleParsedAt, $metadata->getParsedAt());
        self::assertSame('YamlConfigurationParser', $metadata->getParser());
        self::assertSame($this->sampleParseStatistics, $metadata->getParseStatistics());
        self::assertSame($this->sampleConfigurationKeys, $metadata->getConfigurationKeys());
        self::assertSame('12.4.0', $metadata->getTypo3Version());
        self::assertSame('8.1.0', $metadata->getPhpVersion());
        self::assertSame($this->sampleCustomData, $metadata->getCustomData());
    }

    public function testHasConfigurationKey(): void
    {
        $metadata = new ConfigurationMetadata(
            '/path/to/config.php',
            'config.php',
            'php',
            1024,
            $this->sampleLastModified,
            $this->sampleParsedAt,
            'PhpConfigurationParser',
            [],
            $this->sampleConfigurationKeys,
        );

        self::assertTrue($metadata->hasConfigurationKey('DB'));
        self::assertTrue($metadata->hasConfigurationKey('SYS'));
        self::assertTrue($metadata->hasConfigurationKey('EXTENSIONS'));
        self::assertFalse($metadata->hasConfigurationKey('NONEXISTENT'));
        self::assertFalse($metadata->hasConfigurationKey(''));
    }

    public function testGetParseStatistic(): void
    {
        $metadata = new ConfigurationMetadata(
            '/path/to/config.php',
            'config.php',
            'php',
            1024,
            $this->sampleLastModified,
            $this->sampleParsedAt,
            'PhpConfigurationParser',
            $this->sampleParseStatistics,
        );

        self::assertSame(0.025, $metadata->getParseStatistic('parse_duration_seconds'));
        self::assertSame(150, $metadata->getParseStatistic('lines_processed'));
        self::assertSame(45, $metadata->getParseStatistic('tokens_found'));
        self::assertSame(2048, $metadata->getParseStatistic('memory_usage_bytes'));
        self::assertNull($metadata->getParseStatistic('nonexistent'));
        self::assertSame('default', $metadata->getParseStatistic('nonexistent', 'default'));
    }

    public function testGetCustomDataValue(): void
    {
        $metadata = new ConfigurationMetadata(
            '/path/to/config.php',
            'config.php',
            'php',
            1024,
            $this->sampleLastModified,
            $this->sampleParsedAt,
            'PhpConfigurationParser',
            [],
            [],
            null,
            null,
            $this->sampleCustomData,
        );

        self::assertSame(25, $metadata->getCustomDataValue('extension_count'));
        self::assertTrue($metadata->getCustomDataValue('custom_config_detected'));
        self::assertSame(2, $metadata->getCustomDataValue('security_warnings'));
        self::assertNull($metadata->getCustomDataValue('nonexistent'));
        self::assertSame('default', $metadata->getCustomDataValue('nonexistent', 'default'));
    }

    public function testIsLargeFile(): void
    {
        $smallMetadata = new ConfigurationMetadata(
            '/path/to/small.php',
            'small.php',
            'php',
            1024, // 1KB
            $this->sampleLastModified,
            $this->sampleParsedAt,
            'PhpConfigurationParser',
        );

        $largeMetadata = new ConfigurationMetadata(
            '/path/to/large.php',
            'large.php',
            'php',
            2097152, // 2MB
            $this->sampleLastModified,
            $this->sampleParsedAt,
            'PhpConfigurationParser',
        );

        // Test with default threshold (1MB)
        self::assertFalse($smallMetadata->isLargeFile());
        self::assertTrue($largeMetadata->isLargeFile());

        // Test with custom threshold
        self::assertFalse($smallMetadata->isLargeFile(2048)); // 2KB threshold
        self::assertTrue($smallMetadata->isLargeFile(512)); // 512B threshold
        self::assertTrue($largeMetadata->isLargeFile(1048576)); // 1MB threshold
        self::assertFalse($largeMetadata->isLargeFile(5242880)); // 5MB threshold
    }

    public function testIsRecentlyModified(): void
    {
        $oldDate = new \DateTimeImmutable('-10 days');
        $recentDate = new \DateTimeImmutable('-30 minutes'); // Changed from -1 hour to ensure it's clearly within boundaries

        $oldMetadata = new ConfigurationMetadata(
            '/path/to/old.php',
            'old.php',
            'php',
            1024,
            $oldDate,
            $this->sampleParsedAt,
            'PhpConfigurationParser',
        );

        $recentMetadata = new ConfigurationMetadata(
            '/path/to/recent.php',
            'recent.php',
            'php',
            1024,
            $recentDate,
            $this->sampleParsedAt,
            'PhpConfigurationParser',
        );

        // Test with default interval (1 day)
        self::assertFalse($oldMetadata->isRecentlyModified());
        self::assertTrue($recentMetadata->isRecentlyModified());

        // Test with custom interval
        $longInterval = new \DateInterval('P30D'); // 30 days
        $shortInterval = new \DateInterval('PT1H'); // 1 hour

        self::assertTrue($oldMetadata->isRecentlyModified($longInterval));
        self::assertFalse($oldMetadata->isRecentlyModified($shortInterval));
        self::assertTrue($recentMetadata->isRecentlyModified($longInterval));
        self::assertTrue($recentMetadata->isRecentlyModified($shortInterval));
    }

    public function testGetFileAgeInDays(): void
    {
        $tenDaysAgo = new \DateTimeImmutable('-10 days');
        $metadata = new ConfigurationMetadata(
            '/path/to/config.php',
            'config.php',
            'php',
            1024,
            $tenDaysAgo,
            $this->sampleParsedAt,
            'PhpConfigurationParser',
        );

        $age = $metadata->getFileAgeInDays();
        self::assertSame(10, $age);
    }

    public function testGetParseDuration(): void
    {
        $metadata = new ConfigurationMetadata(
            '/path/to/config.php',
            'config.php',
            'php',
            1024,
            $this->sampleLastModified,
            $this->sampleParsedAt,
            'PhpConfigurationParser',
            $this->sampleParseStatistics,
        );

        self::assertSame(0.025, $metadata->getParseDuration());
    }

    public function testGetParseDurationWithMissingData(): void
    {
        $metadata = new ConfigurationMetadata(
            '/path/to/config.php',
            'config.php',
            'php',
            1024,
            $this->sampleLastModified,
            $this->sampleParsedAt,
            'PhpConfigurationParser',
            [], // No statistics
        );

        self::assertNull($metadata->getParseDuration());
    }

    public function testGetRelativePath(): void
    {
        $metadata = new ConfigurationMetadata(
            '/var/www/typo3/config/LocalConfiguration.php',
            'LocalConfiguration.php',
            'php',
            1024,
            $this->sampleLastModified,
            $this->sampleParsedAt,
            'PhpConfigurationParser',
        );

        // Test with matching installation root
        self::assertSame('config/LocalConfiguration.php', $metadata->getRelativePath('/var/www/typo3'));
        self::assertSame('config/LocalConfiguration.php', $metadata->getRelativePath('/var/www/typo3/'));

        // Test with non-matching installation root
        self::assertSame('/var/www/typo3/config/LocalConfiguration.php', $metadata->getRelativePath('/different/root'));

        // Test with partial match - should return full path when not a complete installation root match
        self::assertSame('/var/www/typo3/config/LocalConfiguration.php', $metadata->getRelativePath('/var/www'));
    }

    public function testIsCoreConfiguration(): void
    {
        $coreFiles = [
            'LocalConfiguration.php',
            'AdditionalConfiguration.php',
            'PackageStates.php',
            'Services.yaml',
        ];

        foreach ($coreFiles as $fileName) {
            $metadata = new ConfigurationMetadata(
                "/path/to/{$fileName}",
                $fileName,
                'php',
                1024,
                $this->sampleLastModified,
                $this->sampleParsedAt,
                'PhpConfigurationParser',
            );

            self::assertTrue($metadata->isCoreConfiguration(), "File {$fileName} should be considered core configuration");
        }

        $nonCoreMetadata = new ConfigurationMetadata(
            '/path/to/custom.php',
            'custom.php',
            'php',
            1024,
            $this->sampleLastModified,
            $this->sampleParsedAt,
            'PhpConfigurationParser',
        );

        self::assertFalse($nonCoreMetadata->isCoreConfiguration());
    }

    public function testIsSiteConfiguration(): void
    {
        $siteMetadata = new ConfigurationMetadata(
            '/config/sites/main/config.yaml',
            'config.yaml',
            'yaml',
            1024,
            $this->sampleLastModified,
            $this->sampleParsedAt,
            'YamlConfigurationParser',
        );

        $nonSiteMetadata = new ConfigurationMetadata(
            '/other/path/config.yaml',
            'config.yaml',
            'yaml',
            1024,
            $this->sampleLastModified,
            $this->sampleParsedAt,
            'YamlConfigurationParser',
        );

        $nonYamlSiteMetadata = new ConfigurationMetadata(
            '/config/sites/main/config.php',
            'config.php',
            'php',
            1024,
            $this->sampleLastModified,
            $this->sampleParsedAt,
            'PhpConfigurationParser',
        );

        self::assertTrue($siteMetadata->isSiteConfiguration());
        self::assertFalse($nonSiteMetadata->isSiteConfiguration());
        self::assertFalse($nonYamlSiteMetadata->isSiteConfiguration());
    }

    public function testIsExtensionConfiguration(): void
    {
        $extensionConfigs = [
            ['/var/www/ext/my_ext/ext_localconf.php', 'ext_localconf.php'],
            ['/var/www/ext/my_ext/ext_tables.php', 'ext_tables.php'],
            ['/var/www/typo3conf/ext/news/Configuration/Services.yaml', 'Services.yaml'],
        ];

        foreach ($extensionConfigs as [$filePath, $fileName]) {
            $metadata = new ConfigurationMetadata(
                $filePath,
                $fileName,
                'php',
                1024,
                $this->sampleLastModified,
                $this->sampleParsedAt,
                'PhpConfigurationParser',
            );

            self::assertTrue($metadata->isExtensionConfiguration(), "File {$filePath} should be considered extension configuration");
        }

        $nonExtensionMetadata = new ConfigurationMetadata(
            '/var/www/config/LocalConfiguration.php',
            'LocalConfiguration.php',
            'php',
            1024,
            $this->sampleLastModified,
            $this->sampleParsedAt,
            'PhpConfigurationParser',
        );

        self::assertFalse($nonExtensionMetadata->isExtensionConfiguration());
    }

    public function testGetCategory(): void
    {
        // Core configuration
        $coreMetadata = new ConfigurationMetadata(
            '/var/www/config/LocalConfiguration.php',
            'LocalConfiguration.php',
            'php',
            1024,
            $this->sampleLastModified,
            $this->sampleParsedAt,
            'PhpConfigurationParser',
        );
        self::assertSame('core', $coreMetadata->getCategory());

        // Site configuration
        $siteMetadata = new ConfigurationMetadata(
            '/config/sites/main/config.yaml',
            'config.yaml',
            'yaml',
            1024,
            $this->sampleLastModified,
            $this->sampleParsedAt,
            'YamlConfigurationParser',
        );
        self::assertSame('site', $siteMetadata->getCategory());

        // Extension configuration
        $extensionMetadata = new ConfigurationMetadata(
            '/var/www/ext/my_ext/ext_localconf.php',
            'ext_localconf.php',
            'php',
            1024,
            $this->sampleLastModified,
            $this->sampleParsedAt,
            'PhpConfigurationParser',
        );
        self::assertSame('extension', $extensionMetadata->getCategory());

        // Custom configuration
        $customMetadata = new ConfigurationMetadata(
            '/var/www/custom/config.php',
            'config.php',
            'php',
            1024,
            $this->sampleLastModified,
            $this->sampleParsedAt,
            'PhpConfigurationParser',
        );
        self::assertSame('custom', $customMetadata->getCategory());
    }

    public function testWithCustomData(): void
    {
        $metadata = new ConfigurationMetadata(
            '/path/to/config.php',
            'config.php',
            'php',
            1024,
            $this->sampleLastModified,
            $this->sampleParsedAt,
            'PhpConfigurationParser',
            [],
            [],
            null,
            null,
            $this->sampleCustomData,
        );

        $additionalData = [
            'new_key' => 'new_value',
            'extension_count' => 30, // Override existing
        ];

        $newMetadata = $metadata->withCustomData($additionalData);

        // Original should be unchanged
        self::assertSame(25, $metadata->getCustomDataValue('extension_count'));
        self::assertNull($metadata->getCustomDataValue('new_key'));

        // New metadata should have merged data
        self::assertSame(30, $newMetadata->getCustomDataValue('extension_count')); // overridden
        self::assertSame('new_value', $newMetadata->getCustomDataValue('new_key')); // added
        self::assertTrue($newMetadata->getCustomDataValue('custom_config_detected')); // preserved

        // Other properties should be the same
        self::assertSame('/path/to/config.php', $newMetadata->getFilePath());
        self::assertSame('config.php', $newMetadata->getFileName());
        self::assertSame('php', $newMetadata->getFormat());
    }

    public function testWithConfigurationKeys(): void
    {
        $metadata = new ConfigurationMetadata(
            '/path/to/config.php',
            'config.php',
            'php',
            1024,
            $this->sampleLastModified,
            $this->sampleParsedAt,
            'PhpConfigurationParser',
            [],
            $this->sampleConfigurationKeys,
        );

        $newKeys = ['NEW_KEY', 'ANOTHER_KEY', 'DB']; // Some new, some existing
        $newMetadata = $metadata->withConfigurationKeys($newKeys);

        // Original should be unchanged
        self::assertSame($this->sampleConfigurationKeys, $metadata->getConfigurationKeys());
        self::assertTrue($metadata->hasConfigurationKey('SYS'));
        self::assertFalse($metadata->hasConfigurationKey('NEW_KEY'));

        // New metadata should have new keys
        self::assertSame($newKeys, $newMetadata->getConfigurationKeys());
        self::assertFalse($newMetadata->hasConfigurationKey('SYS')); // not in new keys
        self::assertTrue($newMetadata->hasConfigurationKey('NEW_KEY')); // in new keys
        self::assertTrue($newMetadata->hasConfigurationKey('DB')); // in new keys

        // Other properties should be the same
        self::assertSame('/path/to/config.php', $newMetadata->getFilePath());
        self::assertSame('config.php', $newMetadata->getFileName());
        self::assertSame('php', $newMetadata->getFormat());
    }

    public function testToArrayWithCompleteData(): void
    {
        $metadata = new ConfigurationMetadata(
            '/var/www/config/LocalConfiguration.php',
            'LocalConfiguration.php',
            'php',
            2048,
            $this->sampleLastModified,
            $this->sampleParsedAt,
            'PhpConfigurationParser',
            $this->sampleParseStatistics,
            $this->sampleConfigurationKeys,
            '12.4.0',
            '8.1.0',
            $this->sampleCustomData,
        );

        $array = $metadata->toArray();

        // Test basic properties
        self::assertSame('/var/www/config/LocalConfiguration.php', $array['file_path']);
        self::assertSame('LocalConfiguration.php', $array['file_name']);
        self::assertSame('php', $array['format']);
        self::assertSame(2048, $array['file_size']);
        self::assertSame('2.00 KB', $array['file_size_human']);
        self::assertSame($this->sampleLastModified->format(\DateTimeInterface::ATOM), $array['last_modified']);
        self::assertSame($this->sampleParsedAt->format(\DateTimeInterface::ATOM), $array['parsed_at']);
        self::assertSame('PhpConfigurationParser', $array['parser']);
        self::assertSame($this->sampleParseStatistics, $array['parse_statistics']);
        self::assertSame($this->sampleConfigurationKeys, $array['configuration_keys']);
        self::assertSame('12.4.0', $array['typo3_version']);
        self::assertSame('8.1.0', $array['php_version']);
        self::assertSame($this->sampleCustomData, $array['custom_data']);

        // Test file analysis
        self::assertArrayHasKey('file_analysis', $array);
        $analysis = $array['file_analysis'];
        self::assertFalse($analysis['is_large_file']);
        self::assertIsInt($analysis['file_age_days']);
        self::assertSame('core', $analysis['category']);
        self::assertTrue($analysis['is_core_configuration']);
        self::assertFalse($analysis['is_site_configuration']);
        self::assertFalse($analysis['is_extension_configuration']);
    }

    public function testToArrayWithMinimalData(): void
    {
        $metadata = new ConfigurationMetadata(
            '/path/to/custom.php',
            'custom.php',
            'php',
            512,
            $this->sampleLastModified,
            $this->sampleParsedAt,
            'PhpConfigurationParser',
        );

        $array = $metadata->toArray();

        self::assertSame('/path/to/custom.php', $array['file_path']);
        self::assertSame('custom.php', $array['file_name']);
        self::assertSame('php', $array['format']);
        self::assertSame(512, $array['file_size']);
        self::assertSame('512.00 B', $array['file_size_human']);
        self::assertSame([], $array['parse_statistics']);
        self::assertSame([], $array['configuration_keys']);
        self::assertNull($array['typo3_version']);
        self::assertNull($array['php_version']);
        self::assertSame([], $array['custom_data']);

        // Test file analysis for custom file
        $analysis = $array['file_analysis'];
        self::assertSame('custom', $analysis['category']);
        self::assertFalse($analysis['is_core_configuration']);
        self::assertFalse($analysis['is_site_configuration']);
        self::assertFalse($analysis['is_extension_configuration']);
    }

    /**
     * @dataProvider fileSizeProvider
     */
    public function testFormatFileSize(int $fileSize, string $expectedFormat): void
    {
        $metadata = new ConfigurationMetadata(
            '/path/to/file.php',
            'file.php',
            'php',
            $fileSize,
            $this->sampleLastModified,
            $this->sampleParsedAt,
            'PhpConfigurationParser',
        );

        $array = $metadata->toArray();
        self::assertSame($expectedFormat, $array['file_size_human']);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public function fileSizeProvider(): array
    {
        return [
            'bytes' => [512, '512.00 B'],
            'kilobytes' => [1536, '1.50 KB'], // 1.5 KB
            'megabytes' => [2097152, '2.00 MB'], // 2 MB
            'gigabytes' => [1073741824, '1.00 GB'], // 1 GB
            'zero bytes' => [0, '0.00 B'],
            'one byte' => [1, '1.00 B'],
            'exact kilobyte' => [1024, '1.00 KB'],
            'exact megabyte' => [1048576, '1.00 MB'],
        ];
    }

    public function testImmutabilityOfMetadata(): void
    {
        $originalStatistics = ['key' => 'value'];
        $originalKeys = ['key1', 'key2'];
        $originalCustomData = ['custom' => 'data'];

        $metadata = new ConfigurationMetadata(
            '/path/to/config.php',
            'config.php',
            'php',
            1024,
            $this->sampleLastModified,
            $this->sampleParsedAt,
            'PhpConfigurationParser',
            $originalStatistics,
            $originalKeys,
            '12.4.0',
            '8.1.0',
            $originalCustomData,
        );

        // Modify original arrays
        $originalStatistics['key'] = 'modified';
        $originalKeys[] = 'new_key';
        $originalCustomData['custom'] = 'modified';

        // Verify metadata is not affected
        self::assertSame('value', $metadata->getParseStatistic('key'));
        self::assertSame(['key1', 'key2'], $metadata->getConfigurationKeys());
        self::assertSame('data', $metadata->getCustomDataValue('custom'));
    }

    public function testComplexMetadataScenario(): void
    {
        $complexStatistics = [
            'parse_duration_seconds' => 0.157,
            'lines_processed' => 2847,
            'tokens_found' => 1456,
            'memory_usage_bytes' => 8192,
            'warnings_count' => 3,
            'errors_count' => 0,
            'ast_nodes_created' => 234,
        ];

        $complexKeys = [
            'DB',
            'SYS',
            'EXTENSIONS',
            'FE',
            'BE',
            'GFX',
            'MAIL',
            'LOG',
            'HTTP',
        ];

        $complexCustomData = [
            'extension_count' => 47,
            'third_party_extensions' => 12,
            'deprecated_config_keys' => 5,
            'security_warnings' => 1,
            'performance_flags' => ['caching_enabled' => true, 'debug_disabled' => true],
            'composer_mode' => true,
            'database_connections' => 2,
        ];

        $lastModified = new \DateTimeImmutable('-3 days');
        $parsedAt = new \DateTimeImmutable('now');

        $metadata = new ConfigurationMetadata(
            '/var/www/html/typo3conf/LocalConfiguration.php',
            'LocalConfiguration.php',
            'php',
            15360, // 15KB
            $lastModified,
            $parsedAt,
            'CPSIT\\UpgradeAnalyzer\\Infrastructure\\Parser\\PhpConfigurationParser',
            $complexStatistics,
            $complexKeys,
            '12.4.8',
            '8.2.0',
            $complexCustomData,
        );

        // Test comprehensive functionality
        self::assertTrue($metadata->isCoreConfiguration());
        self::assertSame('core', $metadata->getCategory());
        self::assertFalse($metadata->isLargeFile()); // 15KB < 1MB
        self::assertTrue($metadata->isLargeFile(10240)); // 15KB > 10KB
        self::assertFalse($metadata->isRecentlyModified()); // 3 days > 1 day
        self::assertTrue($metadata->isRecentlyModified(new \DateInterval('P7D'))); // 3 days < 7 days
        self::assertSame(3, $metadata->getFileAgeInDays());

        // Test statistics access
        self::assertSame(0.157, $metadata->getParseDuration());
        self::assertSame(2847, $metadata->getParseStatistic('lines_processed'));
        self::assertSame(0, $metadata->getParseStatistic('errors_count'));

        // Test configuration keys
        self::assertTrue($metadata->hasConfigurationKey('EXTENSIONS'));
        self::assertTrue($metadata->hasConfigurationKey('LOG'));
        self::assertFalse($metadata->hasConfigurationKey('NONEXISTENT'));
        self::assertCount(9, $metadata->getConfigurationKeys());

        // Test custom data
        self::assertSame(47, $metadata->getCustomDataValue('extension_count'));
        self::assertTrue($metadata->getCustomDataValue('composer_mode'));
        self::assertSame(2, $metadata->getCustomDataValue('database_connections'));
        self::assertSame(['caching_enabled' => true, 'debug_disabled' => true], $metadata->getCustomDataValue('performance_flags'));

        // Test relative path
        self::assertSame('typo3conf/LocalConfiguration.php', $metadata->getRelativePath('/var/www/html'));

        // Test immutable operations
        $withAdditionalData = $metadata->withCustomData(['new_metric' => 'new_value']);
        self::assertNull($metadata->getCustomDataValue('new_metric'));
        self::assertSame('new_value', $withAdditionalData->getCustomDataValue('new_metric'));
        self::assertSame(47, $withAdditionalData->getCustomDataValue('extension_count')); // preserved

        $withNewKeys = $metadata->withConfigurationKeys(['NEW_SECTION', 'ANOTHER_SECTION']);
        self::assertTrue($metadata->hasConfigurationKey('DB')); // original unchanged
        self::assertFalse($withNewKeys->hasConfigurationKey('DB')); // new instance changed
        self::assertTrue($withNewKeys->hasConfigurationKey('NEW_SECTION'));

        // Test array conversion
        $array = $metadata->toArray();
        self::assertSame('15.00 KB', $array['file_size_human']);
        self::assertSame('12.4.8', $array['typo3_version']);
        self::assertSame('8.2.0', $array['php_version']);
        self::assertTrue($array['file_analysis']['is_core_configuration']);
        self::assertSame(3, $array['file_analysis']['file_age_days']);
    }
}
