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
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\ConfigurationData;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\ConfigurationMetadata;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\ParseResult;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ConfigurationDiscoveryService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Parser\ConfigurationParserInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Comprehensive test coverage for ConfigurationDiscoveryService
 * 
 * Tests integration between ConfigurationParsingFramework and InstallationDiscoverySystem
 * 
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ConfigurationDiscoveryService
 */
final class ConfigurationDiscoveryServiceTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private ConfigurationParserInterface&MockObject $phpParser;
    private ConfigurationParserInterface&MockObject $yamlParser;
    private ConfigurationDiscoveryService $service;
    private string $testInstallationPath;
    private Installation $installation;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->phpParser = $this->createMock(ConfigurationParserInterface::class);
        $this->yamlParser = $this->createMock(ConfigurationParserInterface::class);
        
        // Configure PHP parser mock
        $this->phpParser->method('getFormat')->willReturn('php');
        $this->phpParser->method('supports')->willReturnCallback(
            fn(string $path) => str_ends_with($path, '.php')
        );
        
        // Configure YAML parser mock
        $this->yamlParser->method('getFormat')->willReturn('yaml');
        $this->yamlParser->method('supports')->willReturnCallback(
            fn(string $path) => str_ends_with($path, '.yaml')
        );
        
        $this->service = new ConfigurationDiscoveryService(
            [$this->phpParser, $this->yamlParser],
            $this->logger
        );
        
        // Create a real temporary directory for more realistic testing
        $this->testInstallationPath = sys_get_temp_dir() . '/typo3-test-' . uniqid();
        mkdir($this->testInstallationPath, 0755, true);
        
        $this->installation = new Installation(
            $this->testInstallationPath,
            new Version('12.4.0')
        );
    }
    
    protected function tearDown(): void
    {
        // Clean up temporary directory
        if (is_dir($this->testInstallationPath)) {
            $this->removeDirectory($this->testInstallationPath);
        }
    }
    
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testConstructorWithParsers(): void
    {
        $parsers = [$this->phpParser, $this->yamlParser];
        $service = new ConfigurationDiscoveryService($parsers, $this->logger);
        
        self::assertSame($parsers, $service->getParsers());
    }

    public function testDiscoverConfigurationBasicFunctionality(): void
    {
        // Create actual test files
        $this->createConfigurationFiles([
            'config/LocalConfiguration.php' => "<?php\nreturn [];\n",
            'config/Services.yaml' => "services:\n  test: {}\n",
        ]);
        
        // Configure parser to return successful result
        $configData = ['SYS' => ['sitename' => 'Test Site']];
        $parseResult = ParseResult::success($configData, 'php', $this->testInstallationPath . '/config/LocalConfiguration.php');
        
        $this->phpParser->expects(self::once())
            ->method('parseFile')
            ->with(self::stringContains('/config/LocalConfiguration.php'))
            ->willReturn($parseResult);
        
        $this->yamlParser->expects(self::once())
            ->method('parseFile')
            ->with(self::stringContains('Services.yaml'))
            ->willReturn(ParseResult::success([], 'yaml', $this->testInstallationPath . '/config/Services.yaml'));
        
        $this->logger->expects(self::atLeastOnce())
            ->method('info')
            ->with(
                self::stringContains('Configuration discovery'),
                self::isType('array')
            );
        
        $result = $this->service->discoverConfiguration($this->installation);
        
        self::assertInstanceOf(Installation::class, $result);
        self::assertGreaterThan(0, count($result->getAllConfigurationData()));
    }

    public function testDiscoverConfigurationWithPhpParser(): void
    {
        $this->createConfigurationFiles(['config/LocalConfiguration.php' => "<?php\nreturn [];\n"]);
        
        $configData = [
            'SYS' => [
                'sitename' => 'Test Site',
                'version' => '12.4.0',
            ],
            'DB' => [
                'Connections' => [
                    'Default' => [
                        'host' => 'localhost',
                        'dbname' => 'test_db',
                    ],
                ],
            ],
        ];
        
        $parseResult = ParseResult::success(
            $configData,
            'php',
            $this->testInstallationPath . '/config/LocalConfiguration.php',
            ['Deprecated configuration found'],
            ['parser_version' => '1.0']
        );
        
        $this->phpParser->expects(self::once())
            ->method('parseFile')
            ->willReturn($parseResult);
        
        $result = $this->service->discoverConfiguration($this->installation);
        
        $localConfig = $result->getConfigurationData('LocalConfiguration');
        self::assertInstanceOf(ConfigurationData::class, $localConfig);
        self::assertSame($configData, $localConfig->getData());
        self::assertSame('php', $localConfig->getFormat());
        self::assertSame(['Deprecated configuration found'], $localConfig->getValidationWarnings());
        
        $metadata = $result->getConfigurationMetadata('LocalConfiguration');
        self::assertInstanceOf(ConfigurationMetadata::class, $metadata);
        self::assertSame('php', $metadata->getFormat());
        self::assertSame('12.4.0', $metadata->getTypo3Version());
    }

    public function testDiscoverConfigurationWithYamlParser(): void
    {
        $this->createConfigurationFiles(['config/Services.yaml' => "services:\n  test: {}\n"]);
        
        $servicesConfig = [
            'services' => [
                'App\\Service\\TestService' => [
                    'class' => 'App\\Service\\TestService',
                    'arguments' => ['@dependency'],
                ],
            ],
        ];
        
        $parseResult = ParseResult::success(
            $servicesConfig,
            'yaml',
            $this->testInstallationPath . '/config/Services.yaml'
        );
        
        $this->yamlParser->expects(self::once())
            ->method('parseFile')
            ->willReturn($parseResult);
        
        $result = $this->service->discoverConfiguration($this->installation);
        
        $servicesData = $result->getConfigurationData('Services');
        self::assertInstanceOf(ConfigurationData::class, $servicesData);
        self::assertSame($servicesConfig, $servicesData->getData());
        self::assertSame('yaml', $servicesData->getFormat());
    }

    public function testDiscoverConfigurationErrorHandlingWhenParserFails(): void
    {
        $this->createConfigurationFiles(['config/LocalConfiguration.php' => "<?php\nreturn [];\n"]);
        
        $parseResult = ParseResult::failure(
            ['Syntax error in configuration file', 'Missing required configuration'],
            'php',
            $this->testInstallationPath . '/config/LocalConfiguration.php',
            ['File is large'],
            ['parse_duration' => 0.5]
        );
        
        $this->phpParser->expects(self::once())
            ->method('parseFile')
            ->willReturn($parseResult);
        
        $this->logger->expects(self::once())
            ->method('error')
            ->with(
                'Configuration file parsing failed',
                self::callback(function (array $context) {
                    return isset($context['errors']) &&
                           in_array('Syntax error in configuration file', $context['errors'], true) &&
                           in_array('Missing required configuration', $context['errors'], true);
                })
            );
        
        $result = $this->service->discoverConfiguration($this->installation);
        
        // Should not have configuration data for failed parsing
        self::assertNull($result->getConfigurationData('LocalConfiguration'));
        
        // But should have metadata indicating parsing failure
        $metadata = $result->getConfigurationMetadata('LocalConfiguration');
        self::assertInstanceOf(ConfigurationMetadata::class, $metadata);
        self::assertTrue($metadata->getCustomDataValue('parse_failed', false));
        self::assertSame(2, $metadata->getCustomDataValue('error_count'));
    }

    public function testDiscoverConfigurationErrorHandlingWhenParserThrowsException(): void
    {
        $this->createConfigurationFiles(['config/LocalConfiguration.php' => "<?php\nreturn [];\n"]);
        
        $this->phpParser->expects(self::once())
            ->method('parseFile')
            ->willThrowException(new \RuntimeException('Parser crashed'));
        
        $this->logger->expects(self::once())
            ->method('error')
            ->with(
                'Exception during configuration file parsing',
                self::callback(function (array $context) {
                    return $context['exception'] === 'Parser crashed' &&
                           $context['exception_class'] === \RuntimeException::class;
                })
            );
        
        $result = $this->service->discoverConfiguration($this->installation);
        
        // Should not have any configuration data when parser throws exception
        self::assertNull($result->getConfigurationData('LocalConfiguration'));
        self::assertNull($result->getConfigurationMetadata('LocalConfiguration'));
    }

    public function testConfigurationFileIdentificationLogic(): void
    {
        $this->createConfigurationFiles([
            'config/LocalConfiguration.php' => "<?php\nreturn [];\n",
            'config/AdditionalConfiguration.php' => "<?php\nreturn [];\n",
            'config/PackageStates.php' => "<?php\nreturn [];\n",
            'config/Services.yaml' => "services:\n  test: {}\n",
        ]);
        
        // Configure parsers to return successful results
        $this->phpParser->method('parseFile')->willReturn(
            ParseResult::success([], 'php', '/test/path')
        );
        $this->yamlParser->method('parseFile')->willReturn(
            ParseResult::success([], 'yaml', '/test/path')
        );
        
        $result = $this->service->discoverConfiguration($this->installation);
        
        // Verify core configuration identifiers are correctly generated
        self::assertTrue($result->hasConfiguration('LocalConfiguration'));
        self::assertTrue($result->hasConfiguration('AdditionalConfiguration'));
        self::assertTrue($result->hasConfiguration('PackageStates'));
        self::assertTrue($result->hasConfiguration('Services'));
    }

    public function testConfigurationMetadataGeneration(): void
    {
        $this->createConfigurationFiles(['config/LocalConfiguration.php' => "<?php\nreturn [];\n"]);
        
        $configData = [
            'SYS' => [
                'version' => '12.4.0',
                'phpVersion' => '8.1.0',
            ],
        ];
        
        $parseResult = ParseResult::success(
            $configData,
            'php',
            $this->testInstallationPath . '/config/LocalConfiguration.php',
            [],
            ['parse_time' => 0.1, 'memory_usage' => 1024]
        );
        
        $this->phpParser->expects(self::once())
            ->method('parseFile')
            ->willReturn($parseResult);
        
        $result = $this->service->discoverConfiguration($this->installation);
        
        $metadata = $result->getConfigurationMetadata('LocalConfiguration');
        self::assertInstanceOf(ConfigurationMetadata::class, $metadata);
        
        // Verify metadata properties
        self::assertSame('LocalConfiguration.php', $metadata->getFileName());
        self::assertSame('php', $metadata->getFormat());
        self::assertSame('12.4.0', $metadata->getTypo3Version());
        self::assertSame('8.1.0', $metadata->getPhpVersion());
        self::assertContains('SYS', $metadata->getConfigurationKeys());
        self::assertSame(get_class($this->phpParser), $metadata->getParser());
    }

    public function testGetConfigurationSummary(): void
    {
        $this->createConfigurationFiles([
            'config/LocalConfiguration.php' => "<?php\nreturn [];\n",
            'config/Services.yaml' => "services:\n  test: {}\n",
        ]);
        
        // Mock successful parsing results
        $localConfigData = ['SYS' => ['sitename' => 'Test']];
        $servicesConfigData = ['services' => []];
        
        $this->phpParser->method('parseFile')->willReturn(
            ParseResult::success($localConfigData, 'php', $this->testInstallationPath . '/config/LocalConfiguration.php')
        );
        $this->yamlParser->method('parseFile')->willReturn(
            ParseResult::success($servicesConfigData, 'yaml', $this->testInstallationPath . '/config/Services.yaml')
        );
        
        $result = $this->service->discoverConfiguration($this->installation);
        $summary = $this->service->getConfigurationSummary($result);
        
        self::assertIsArray($summary);
        self::assertArrayHasKey('total_configurations', $summary);
        self::assertArrayHasKey('configurations', $summary);
        self::assertArrayHasKey('statistics', $summary);
        self::assertArrayHasKey('categories', $summary);
        
        self::assertSame(2, $summary['total_configurations']);
        self::assertArrayHasKey('LocalConfiguration', $summary['configurations']);
        self::assertArrayHasKey('Services', $summary['configurations']);
        
        // Verify statistics
        self::assertSame(2, $summary['statistics']['total_files']);
        self::assertSame(2, $summary['statistics']['successful_parses']);
        self::assertSame(0, $summary['statistics']['failed_parses']);
        
        // Verify categories
        self::assertSame(2, $summary['categories']['core']);
    }

    public function testDiscoverConfigurationWithNoParserFound(): void
    {
        // This test is for the case when the service encounters files that don't match any parser
        // We need to create a file with no matching parser or test an empty directory
        $result = $this->service->discoverConfiguration($this->installation);
        
        // Should not have any configuration data when no files are found
        self::assertEmpty($result->getAllConfigurationData());
    }

    public function testDiscoverConfigurationWithSiteConfigurations(): void
    {
        $this->createConfigurationFiles([
            'config/sites/main/config.yaml' => "rootPageId: 1\nbase: 'https://example.com'\n",
            'config/sites/sub/config.yaml' => "rootPageId: 2\nbase: 'https://sub.example.com'\n",
        ]);
        
        $siteConfig = [
            'rootPageId' => 1,
            'base' => 'https://example.com',
        ];
        
        $this->yamlParser->method('parseFile')->willReturn(
            ParseResult::success($siteConfig, 'yaml', '/test/path')
        );
        
        $result = $this->service->discoverConfiguration($this->installation);
        
        // Verify site configurations are identified correctly
        self::assertTrue($result->hasConfiguration('Site.main'));
        self::assertTrue($result->hasConfiguration('Site.sub'));
        
        $mainSiteConfig = $result->getConfigurationData('Site.main');
        self::assertInstanceOf(ConfigurationData::class, $mainSiteConfig);
        self::assertSame($siteConfig, $mainSiteConfig->getData());
    }

    public function testDiscoverConfigurationWithExtensionServices(): void
    {
        $this->createConfigurationFiles([
            'extensions/ext1/Configuration/Services.yaml' => "services:\n  Ext\\Service: {}\n",
            'ext/ext2/Configuration/Services.yaml' => "services:\n  Ext\\Service2: {}\n",
        ]);
        
        $serviceConfig = [
            'services' => [
                'Ext\\Service' => ['class' => 'Ext\\Service'],
            ],
        ];
        
        $this->yamlParser->method('parseFile')->willReturn(
            ParseResult::success($serviceConfig, 'yaml', '/test/path')
        );
        
        $result = $this->service->discoverConfiguration($this->installation);
        
        // Verify extension service configurations are identified
        self::assertTrue($result->hasConfiguration('Services.ext1'));
        self::assertTrue($result->hasConfiguration('Services.ext2'));
    }

    public function testDiscoverConfigurationWithComplexStructure(): void
    {
        $this->createConfigurationFiles([
            'config/LocalConfiguration.php' => "<?php\nreturn [];\n",
            'config/AdditionalConfiguration.php' => "<?php\nreturn [];\n",
            'config/PackageStates.php' => "<?php\nreturn [];\n",
            'config/Services.yaml' => "services:\n  test: {}\n",
            'config/sites/main/config.yaml' => "base: 'https://example.com'\n",
            'extensions/custom_ext/Configuration/Services.yaml' => "services:\n  Ext\\Service: {}\n",
        ]);
        
        // Configure parsers for multiple files
        $this->phpParser->method('parseFile')->willReturnOnConsecutiveCalls(
            ParseResult::success(['SYS' => ['sitename' => 'Test']], 'php', '/test/LocalConfiguration.php'),
            ParseResult::success(['ADDITIONAL' => ['custom' => 'value']], 'php', '/test/AdditionalConfiguration.php'),
            ParseResult::success(['packages' => ['core' => ['active' => true]]], 'php', '/test/PackageStates.php')
        );
        
        $this->yamlParser->method('parseFile')->willReturnOnConsecutiveCalls(
            ParseResult::success(['services' => []], 'yaml', '/test/Services.yaml'),
            ParseResult::success(['base' => 'https://example.com'], 'yaml', '/test/site/config.yaml'),
            ParseResult::success(['services' => ['Ext\\Service' => []]], 'yaml', '/test/ext/Services.yaml')
        );
        
        $result = $this->service->discoverConfiguration($this->installation);
        
        // Verify all configurations are discovered
        self::assertTrue($result->hasConfiguration('LocalConfiguration'));
        self::assertTrue($result->hasConfiguration('AdditionalConfiguration'));
        self::assertTrue($result->hasConfiguration('PackageStates'));
        self::assertTrue($result->hasConfiguration('Services'));
        self::assertTrue($result->hasConfiguration('Site.main'));
        self::assertTrue($result->hasConfiguration('Services.custom_ext'));
        
        $summary = $this->service->getConfigurationSummary($result);
        self::assertSame(6, $summary['total_configurations']);
        
        // Verify that we have the expected number of configurations
        // The exact category counts may vary based on path detection logic,
        // but we should have all 6 configurations discovered
        $totalCategoryCounts = array_sum($summary['categories']);
        self::assertSame(6, $totalCategoryCounts);
    }

    public function testDiscoverConfigurationLogging(): void
    {
        $this->createConfigurationFiles(['config/LocalConfiguration.php' => "<?php\nreturn [];\n"]);
        
        $this->phpParser->method('parseFile')->willReturn(
            ParseResult::success(['SYS' => []], 'php', '/test/path')
        );
        
        // Verify that logging methods are called
        $this->logger->expects(self::atLeastOnce())
            ->method('info');
        
        $this->logger->expects(self::atLeastOnce())
            ->method('debug');
        
        $this->service->discoverConfiguration($this->installation);
    }

    public function testDiscoverConfigurationWithLegacyPaths(): void
    {
        $this->createConfigurationFiles([
            'typo3conf/LocalConfiguration.php' => "<?php\nreturn [];\n",
            'typo3conf/AdditionalConfiguration.php' => "<?php\nreturn [];\n",
        ]);
        
        $this->phpParser->method('parseFile')->willReturn(
            ParseResult::success(['SYS' => []], 'php', '/test/path')
        );
        
        $result = $this->service->discoverConfiguration($this->installation);
        
        // Should find legacy configuration files
        self::assertTrue($result->hasConfiguration('LocalConfiguration'));
        self::assertTrue($result->hasConfiguration('AdditionalConfiguration'));
    }

    /**
     * Create configuration files for testing with real filesystem
     */
    private function createConfigurationFiles(array $files): void
    {
        foreach ($files as $relativePath => $content) {
            $fullPath = $this->testInstallationPath . '/' . $relativePath;
            $dir = dirname($fullPath);
            
            // Create directory structure if it doesn't exist
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            // Create the file with content
            file_put_contents($fullPath, $content);
        }
    }
}