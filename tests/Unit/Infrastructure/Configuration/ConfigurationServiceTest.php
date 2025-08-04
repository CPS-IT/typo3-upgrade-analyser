<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Configuration;

use CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

final class ConfigurationServiceTest extends TestCase
{
    private LoggerInterface $logger;
    private string $tempConfigFile;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->tempConfigFile = tempnam(sys_get_temp_dir(), 'config_test_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempConfigFile)) {
            unlink($this->tempConfigFile);
        }
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::__construct
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::getTargetVersion
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::isResultCacheEnabled
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::getResultCacheTtl
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::getInstallationPath
     */
    public function testConstructorWithDefaultPath(): void
    {
        $service = new ConfigurationService($this->logger, '/non/existent/config.yaml');

        // Should use default configuration when file doesn't exist
        // Note: When file doesn't exist, it loads default config ('13.4'), not method default
        $this->assertSame('13.4', $service->getTargetVersion());
        $this->assertTrue($service->isResultCacheEnabled());
        $this->assertSame(3600, $service->getResultCacheTtl());
        $this->assertNull($service->getInstallationPath());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::__construct
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::loadConfiguration
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::getInstallationPath
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::getTargetVersion
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::isResultCacheEnabled
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::getResultCacheTtl
     */
    public function testConstructorWithValidConfigFile(): void
    {
        $config = [
            'analysis' => [
                'installationPath' => '/path/to/typo3',
                'targetVersion' => '12.4',
                'resultCache' => [
                    'enabled' => true,
                    'ttl' => 7200,
                ],
            ],
        ];

        file_put_contents($this->tempConfigFile, Yaml::dump($config));

        $service = new ConfigurationService($this->logger, $this->tempConfigFile);

        $this->assertSame('/path/to/typo3', $service->getInstallationPath());
        $this->assertSame('12.4', $service->getTargetVersion());
        $this->assertTrue($service->isResultCacheEnabled());
        $this->assertSame(7200, $service->getResultCacheTtl());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::__construct
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::loadConfiguration
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::getDefaultConfiguration
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::getTargetVersion
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::isResultCacheEnabled
     */
    public function testConstructorWithMissingConfigFile(): void
    {
        $nonExistentFile = '/non/existent/config.yaml';

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Configuration file not found, using defaults', ['path' => $nonExistentFile]);

        $service = new ConfigurationService($this->logger, $nonExistentFile);

        // Should use default configuration
        // Note: When config file is missing, it uses default config ('13.4')
        $this->assertSame('13.4', $service->getTargetVersion());
        $this->assertTrue($service->isResultCacheEnabled());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::__construct
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::loadConfiguration
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::getDefaultConfiguration
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::getTargetVersion
     */
    public function testConstructorWithInvalidYamlSyntax(): void
    {
        file_put_contents($this->tempConfigFile, 'invalid: yaml: syntax: [');

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to load configuration file', $this->callback(function ($context) {
                return isset($context['path']) && isset($context['error']);
            }));

        $service = new ConfigurationService($this->logger, $this->tempConfigFile);

        // Should fall back to default configuration
        $this->assertSame('13.4', $service->getTargetVersion());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::__construct
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::loadConfiguration
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::getTargetVersion
     */
    public function testConstructorWithEmptyConfigFile(): void
    {
        file_put_contents($this->tempConfigFile, '');

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Configuration loaded', ['path' => $this->tempConfigFile]);

        $service = new ConfigurationService($this->logger, $this->tempConfigFile);

        // Should handle empty config and return method defaults (not service defaults)
        $this->assertSame('13.4', $service->getTargetVersion());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::__construct
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::loadConfiguration
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::getTargetVersion
     */
    public function testConstructorWithNonArrayYamlContent(): void
    {
        file_put_contents($this->tempConfigFile, 'just_a_string');

        $service = new ConfigurationService($this->logger, $this->tempConfigFile);

        // Should handle non-array content and return method defaults
        $this->assertSame('13.4', $service->getTargetVersion());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::get
     */
    public function testGetWithSimpleKey(): void
    {
        $config = ['key' => 'value'];
        file_put_contents($this->tempConfigFile, Yaml::dump($config));

        $service = new ConfigurationService($this->logger, $this->tempConfigFile);

        $this->assertSame('value', $service->get('key'));
        $this->assertNull($service->get('nonexistent'));
        $this->assertSame('default', $service->get('nonexistent', 'default'));
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::get
     */
    public function testGetWithNestedKey(): void
    {
        $config = [
            'level1' => [
                'level2' => [
                    'level3' => 'deep_value',
                ],
            ],
        ];
        file_put_contents($this->tempConfigFile, Yaml::dump($config));

        $service = new ConfigurationService($this->logger, $this->tempConfigFile);

        $this->assertSame('deep_value', $service->get('level1.level2.level3'));
        $this->assertNull($service->get('level1.level2.nonexistent'));
        $this->assertSame('default', $service->get('level1.nonexistent.level3', 'default'));
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::get
     */
    public function testGetWithNonArrayIntermediateValue(): void
    {
        $config = [
            'level1' => 'string_value',
        ];
        file_put_contents($this->tempConfigFile, Yaml::dump($config));

        $service = new ConfigurationService($this->logger, $this->tempConfigFile);

        // Should return default when trying to access nested key on non-array value
        $this->assertSame('default', $service->get('level1.level2', 'default'));
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::set
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::get
     */
    public function testSetWithSimpleKey(): void
    {
        $service = new ConfigurationService($this->logger, $this->tempConfigFile);

        $service->set('new_key', 'new_value');

        $this->assertSame('new_value', $service->get('new_key'));
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::set
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::get
     */
    public function testSetWithNestedKey(): void
    {
        $service = new ConfigurationService($this->logger, $this->tempConfigFile);

        $service->set('level1.level2.level3', 'nested_value');

        $this->assertSame('nested_value', $service->get('level1.level2.level3'));
        $this->assertIsArray($service->get('level1'));
        $this->assertIsArray($service->get('level1.level2'));
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::set
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::get
     */
    public function testSetOverwritesExistingValue(): void
    {
        $config = ['existing' => 'old_value'];
        file_put_contents($this->tempConfigFile, Yaml::dump($config));

        $service = new ConfigurationService($this->logger, $this->tempConfigFile);

        $service->set('existing', 'new_value');

        $this->assertSame('new_value', $service->get('existing'));
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::set
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::get
     */
    public function testSetCreatesNestedStructureFromScalar(): void
    {
        $service = new ConfigurationService($this->logger, $this->tempConfigFile);

        $service->set('scalar', 'value');
        $service->set('scalar.nested', 'nested_value');

        // The scalar value should be overwritten with array structure
        $this->assertSame('nested_value', $service->get('scalar.nested'));
        $this->assertIsArray($service->get('scalar'));
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::getAll
     */
    public function testGetAll(): void
    {
        $config = [
            'key1' => 'value1',
            'key2' => ['nested' => 'value2'],
        ];
        file_put_contents($this->tempConfigFile, Yaml::dump($config));

        $service = new ConfigurationService($this->logger, $this->tempConfigFile);

        $this->assertSame($config, $service->getAll());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::getAll
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::set
     */
    public function testGetAllAfterModification(): void
    {
        $service = new ConfigurationService($this->logger, $this->tempConfigFile);

        $service->set('new_key', 'new_value');

        $all = $service->getAll();
        $this->assertArrayHasKey('new_key', $all);
        $this->assertSame('new_value', $all['new_key']);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::reload
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::loadConfiguration
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::get
     */
    public function testReload(): void
    {
        $initialConfig = ['key' => 'initial_value'];
        file_put_contents($this->tempConfigFile, Yaml::dump($initialConfig));

        $service = new ConfigurationService($this->logger, $this->tempConfigFile);
        $this->assertSame('initial_value', $service->get('key'));

        // Modify the file
        $updatedConfig = ['key' => 'updated_value'];
        file_put_contents($this->tempConfigFile, Yaml::dump($updatedConfig));

        $service->reload();
        $this->assertSame('updated_value', $service->get('key'));
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::reload
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::loadConfiguration
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::getDefaultConfiguration
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::getTargetVersion
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::get
     */
    public function testReloadWithDeletedFile(): void
    {
        $config = ['key' => 'value'];
        file_put_contents($this->tempConfigFile, Yaml::dump($config));

        $service = new ConfigurationService($this->logger, $this->tempConfigFile);
        $this->assertSame('value', $service->get('key'));

        // Delete the file
        unlink($this->tempConfigFile);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Configuration file not found, using defaults');

        $service->reload();

        // Should fall back to defaults
        $this->assertSame('13.4', $service->getTargetVersion());
        $this->assertNull($service->get('key'));
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::withConfigPath
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::get
     */
    public function testWithConfigPath(): void
    {
        $config1 = ['key' => 'value1'];
        $config2 = ['key' => 'value2'];

        $tempFile1 = tempnam(sys_get_temp_dir(), 'config1_');
        $tempFile2 = tempnam(sys_get_temp_dir(), 'config2_');

        file_put_contents($tempFile1, Yaml::dump($config1));
        file_put_contents($tempFile2, Yaml::dump($config2));

        try {
            $service1 = new ConfigurationService($this->logger, $tempFile1);
            $service2 = $service1->withConfigPath($tempFile2);

            $this->assertSame('value1', $service1->get('key'));
            $this->assertSame('value2', $service2->get('key'));

            // Services should be different instances
            $this->assertNotSame($service1, $service2);
        } finally {
            unlink($tempFile1);
            unlink($tempFile2);
        }
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::isResultCacheEnabled
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::get
     */
    public function testIsResultCacheEnabled(): void
    {
        $config = [
            'analysis' => [
                'resultCache' => ['enabled' => true],
            ],
        ];
        file_put_contents($this->tempConfigFile, Yaml::dump($config));

        $service = new ConfigurationService($this->logger, $this->tempConfigFile);

        $this->assertTrue($service->isResultCacheEnabled());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::isResultCacheEnabled
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::get
     */
    public function testIsResultCacheEnabledDefault(): void
    {
        $service = new ConfigurationService($this->logger, $this->tempConfigFile);

        $this->assertTrue($service->isResultCacheEnabled());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::getResultCacheTtl
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::get
     */
    public function testGetResultCacheTtl(): void
    {
        $config = [
            'analysis' => [
                'resultCache' => ['ttl' => 1800],
            ],
        ];
        file_put_contents($this->tempConfigFile, Yaml::dump($config));

        $service = new ConfigurationService($this->logger, $this->tempConfigFile);

        $this->assertSame(1800, $service->getResultCacheTtl());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::getResultCacheTtl
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::get
     */
    public function testGetResultCacheTtlDefault(): void
    {
        $service = new ConfigurationService($this->logger, $this->tempConfigFile);

        $this->assertSame(3600, $service->getResultCacheTtl());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::getInstallationPath
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::get
     */
    public function testGetInstallationPath(): void
    {
        $config = [
            'analysis' => [
                'installationPath' => '/custom/path',
            ],
        ];
        file_put_contents($this->tempConfigFile, Yaml::dump($config));

        $service = new ConfigurationService($this->logger, $this->tempConfigFile);

        $this->assertSame('/custom/path', $service->getInstallationPath());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::getInstallationPath
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::get
     */
    public function testGetInstallationPathDefault(): void
    {
        $service = new ConfigurationService($this->logger, $this->tempConfigFile);

        $this->assertNull($service->getInstallationPath());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::getTargetVersion
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::get
     */
    public function testGetTargetVersion(): void
    {
        $config = [
            'analysis' => [
                'targetVersion' => '11.5',
            ],
        ];
        file_put_contents($this->tempConfigFile, Yaml::dump($config));

        $service = new ConfigurationService($this->logger, $this->tempConfigFile);

        $this->assertSame('11.5', $service->getTargetVersion());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::getTargetVersion
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::get
     */
    public function testGetTargetVersionDefault(): void
    {
        $service = new ConfigurationService($this->logger, $this->tempConfigFile);

        // When config file is empty, analysis.targetVersion doesn't exist, so it uses method default
        $this->assertSame('13.4', $service->getTargetVersion());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::get
     */
    public function testGetTargetVersionFallbackToMethodDefault(): void
    {
        $service = new ConfigurationService($this->logger, $this->tempConfigFile);

        // Should use method's default parameter, not the service default
        $this->assertSame('12.4', $service->get('analysis.targetVersion', '12.4'));
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::__construct
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::loadConfiguration
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::getDefaultConfiguration
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::getAll
     */
    public function testDefaultConfigurationStructure(): void
    {
        $service = new ConfigurationService($this->logger, '/non/existent/file.yaml');

        $config = $service->getAll();

        $this->assertArrayHasKey('analysis', $config);
        $this->assertArrayHasKey('installationPath', $config['analysis']);
        $this->assertArrayHasKey('targetVersion', $config['analysis']);
        $this->assertArrayHasKey('resultCache', $config['analysis']);
        $this->assertArrayHasKey('enabled', $config['analysis']['resultCache']);
        $this->assertArrayHasKey('ttl', $config['analysis']['resultCache']);

        $this->assertNull($config['analysis']['installationPath']);
        $this->assertSame('13.4', $config['analysis']['targetVersion']);
        $this->assertTrue($config['analysis']['resultCache']['enabled']);
        $this->assertSame(3600, $config['analysis']['resultCache']['ttl']);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService::get
     */
    public function testComplexNestedConfiguration(): void
    {
        $config = [
            'analysis' => [
                'installationPath' => '/path/to/typo3',
                'targetVersion' => '12.4',
                'resultCache' => [
                    'enabled' => true,
                    'ttl' => 1800,
                ],
                'analyzers' => [
                    'version' => ['enabled' => true],
                    'deprecation' => ['enabled' => false],
                ],
            ],
            'output' => [
                'format' => 'json',
                'file' => 'results.json',
            ],
        ];

        file_put_contents($this->tempConfigFile, Yaml::dump($config));

        $service = new ConfigurationService($this->logger, $this->tempConfigFile);

        // Test deeply nested access
        $this->assertTrue($service->get('analysis.analyzers.version.enabled'));
        $this->assertFalse($service->get('analysis.analyzers.deprecation.enabled'));
        $this->assertSame('json', $service->get('output.format'));
        $this->assertSame('results.json', $service->get('output.file'));

        // Test non-existent nested keys
        $this->assertNull($service->get('analysis.analyzers.nonexistent.enabled'));
        $this->assertSame('default', $service->get('output.nonexistent.key', 'default'));
    }
}
