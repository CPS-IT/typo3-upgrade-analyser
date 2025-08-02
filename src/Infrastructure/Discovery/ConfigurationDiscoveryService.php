<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Discovery;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Installation;
use CPSIT\UpgradeAnalyzer\Infrastructure\Parser\ConfigurationParserInterface;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\ConfigurationData;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\ConfigurationMetadata;
use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;

/**
 * Service for discovering and parsing configuration files in TYPO3 installations
 * 
 * Integrates configuration parsing capabilities with the installation discovery system
 * to automatically detect, parse, and validate TYPO3 configuration files.
 */
class ConfigurationDiscoveryService
{
    /**
     * @param array<ConfigurationParserInterface> $parsers Available configuration parsers
     * @param LoggerInterface $logger Logger instance
     */
    public function __construct(
        private readonly array $parsers,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Discover and parse configuration files in installation
     * 
     * @param Installation $installation TYPO3 installation
     * @return Installation Enhanced installation with configuration data
     */
    public function discoverConfiguration(Installation $installation): Installation
    {
        $this->logger->info('Starting configuration discovery', [
            'installation_path' => $installation->getPath(),
            'installation_version' => $installation->getVersion()->toString(),
        ]);

        $configurationFiles = $this->findConfigurationFiles($installation->getPath());
        
        foreach ($configurationFiles as $file) {
            $this->parseConfigurationFile($installation, $file);
        }

        $this->logger->info('Configuration discovery completed', [
            'installation_path' => $installation->getPath(),
            'configurations_found' => count($installation->getAllConfigurationData()),
            'has_errors' => $installation->hasConfigurationErrors(),
        ]);

        return $installation;
    }

    /**
     * Find configuration files in installation directory
     * 
     * @param string $installationPath Installation root path
     * @return array<\SplFileInfo> Configuration files found
     */
    private function findConfigurationFiles(string $installationPath): array
    {
        $finder = new Finder();
        $files = [];

        try {
            // Core configuration files
            $coreConfigs = [
                'LocalConfiguration.php',
                'AdditionalConfiguration.php', 
                'PackageStates.php',
            ];

            foreach ($coreConfigs as $configFile) {
                $configPath = $installationPath . '/config/' . $configFile;
                if (file_exists($configPath)) {
                    $files[] = new \SplFileInfo($configPath);
                }

                // Also check legacy locations
                $legacyPath = $installationPath . '/typo3conf/' . $configFile;
                if (file_exists($legacyPath)) {
                    $files[] = new \SplFileInfo($legacyPath);
                }
            }

            // Services.yaml files
            $servicesFiles = $finder->create()
                ->files()
                ->name('Services.yaml')
                ->in($installationPath)
                ->depth('< 4'); // Limit depth to avoid deep traversal

            foreach ($servicesFiles as $file) {
                $files[] = $file;
            }

            // Site configurations
            $siteConfigDir = $installationPath . '/config/sites';
            if (is_dir($siteConfigDir)) {
                $siteConfigs = $finder->create()
                    ->files()
                    ->name('config.yaml')
                    ->in($siteConfigDir)
                    ->depth('== 1'); // Only direct subdirectories

                foreach ($siteConfigs as $file) {
                    $files[] = $file;
                }
            }

        } catch (\Exception $e) {
            $this->logger->warning('Error finding configuration files', [
                'installation_path' => $installationPath,
                'error' => $e->getMessage(),
            ]);
        }

        return $files;
    }

    /**
     * Parse individual configuration file
     * 
     * @param Installation $installation TYPO3 installation
     * @param \SplFileInfo $file Configuration file
     */
    private function parseConfigurationFile(Installation $installation, \SplFileInfo $file): void
    {
        $filePath = $file->getRealPath();
        $fileName = $file->getFilename();
        
        $this->logger->debug('Parsing configuration file', [
            'file_path' => $filePath,
            'file_name' => $fileName,
            'file_size' => $file->getSize(),
        ]);

        $parser = $this->findParserForFile($file);
        if ($parser === null) {
            $this->logger->warning('No parser found for configuration file', [
                'file_path' => $filePath,
                'file_name' => $fileName,
            ]);
            return;
        }

        try {
            $parseResult = $parser->parseFile($filePath);
            
            if ($parseResult->isSuccessful()) {
                $configData = new ConfigurationData(
                    $parseResult->getData(),
                    $parseResult->getFormat(),
                    $filePath,
                    [],
                    $parseResult->getWarnings()
                );

                $configMetadata = new ConfigurationMetadata(
                    $filePath,
                    $fileName,
                    $parseResult->getFormat(),
                    $file->getSize(),
                    new \DateTimeImmutable('@' . $file->getMTime()),
                    new \DateTimeImmutable(),
                    get_class($parser),
                    $parseResult->getMetadata(),
                    array_keys($parseResult->getData()),
                    $this->extractTypo3Version($parseResult->getData()),
                    $this->extractPhpVersion($parseResult->getData())
                );

                $identifier = $this->generateConfigurationIdentifier($file);
                $installation->addConfigurationData($identifier, $configData);
                $installation->addConfigurationMetadata($identifier, $configMetadata);

                $this->logger->info('Configuration file parsed successfully', [
                    'identifier' => $identifier,
                    'file_path' => $filePath,
                    'keys_found' => count($parseResult->getData()),
                    'warnings_count' => count($parseResult->getWarnings()),
                ]);

            } else {
                $this->logger->error('Configuration file parsing failed', [
                    'file_path' => $filePath,
                    'errors' => $parseResult->getErrors(),
                    'warnings' => $parseResult->getWarnings(),
                ]);

                // Still create metadata for failed parsing attempts
                $configMetadata = new ConfigurationMetadata(
                    $filePath,
                    $fileName,
                    $parser->getFormat(),
                    $file->getSize(),
                    new \DateTimeImmutable('@' . $file->getMTime()),
                    new \DateTimeImmutable(),
                    get_class($parser),
                    ['parse_errors' => $parseResult->getErrors()],
                    [],
                    null,
                    null,
                    ['parse_failed' => true, 'error_count' => count($parseResult->getErrors())]
                );

                $identifier = $this->generateConfigurationIdentifier($file);
                $installation->addConfigurationMetadata($identifier, $configMetadata);
            }

        } catch (\Exception $e) {
            $this->logger->error('Exception during configuration file parsing', [
                'file_path' => $filePath,
                'exception' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);
        }
    }

    /**
     * Find appropriate parser for configuration file
     * 
     * @param \SplFileInfo $file Configuration file
     * @return ConfigurationParserInterface|null Parser or null if none found
     */
    private function findParserForFile(\SplFileInfo $file): ?ConfigurationParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($file->getRealPath())) {
                return $parser;
            }
        }

        return null;
    }

    /**
     * Generate configuration identifier for file
     * 
     * @param \SplFileInfo $file Configuration file
     * @return string Configuration identifier
     */
    private function generateConfigurationIdentifier(\SplFileInfo $file): string
    {
        $fileName = $file->getFilename();
        $filePath = $file->getRealPath();

        // Core configuration files get simple identifiers
        $coreFiles = [
            'LocalConfiguration.php' => 'LocalConfiguration',
            'AdditionalConfiguration.php' => 'AdditionalConfiguration',
            'PackageStates.php' => 'PackageStates',
        ];

        if (isset($coreFiles[$fileName])) {
            return $coreFiles[$fileName];
        }

        // Services.yaml files
        if ($fileName === 'Services.yaml') {
            // Determine context from path
            if (str_contains($filePath, '/config/')) {
                return 'Services';
            }
            
            // Extract extension key from path
            if (preg_match('#/(?:ext|extensions)/([^/]+)/#', $filePath, $matches)) {
                return 'Services.' . $matches[1];
            }
            
            return 'Services.unknown';
        }

        // Site configuration files
        if ($fileName === 'config.yaml' && str_contains($filePath, '/config/sites/')) {
            if (preg_match('#/config/sites/([^/]+)/config\.yaml$#', $filePath, $matches)) {
                return 'Site.' . $matches[1];
            }
            return 'Site.unknown';
        }

        // Fallback: use filename without extension
        return pathinfo($fileName, PATHINFO_FILENAME);
    }

    /**
     * Extract TYPO3 version from configuration data
     * 
     * @param array<string, mixed> $data Configuration data
     * @return string|null TYPO3 version or null if not detectable
     */
    private function extractTypo3Version(array $data): ?string
    {
        // Check SYS configuration for version info
        if (isset($data['SYS']['version'])) {
            return (string) $data['SYS']['version'];
        }

        // Check for version in extensions configuration
        if (isset($data['EXTENSIONS']['backend']['version'])) {
            return (string) $data['EXTENSIONS']['backend']['version'];
        }

        return null;
    }

    /**
     * Extract PHP version requirement from configuration data
     * 
     * @param array<string, mixed> $data Configuration data
     * @return string|null PHP version requirement or null if not specified
     */
    private function extractPhpVersion(array $data): ?string
    {
        // Check for PHP version requirements in system configuration
        if (isset($data['SYS']['phpVersion'])) {
            return (string) $data['SYS']['phpVersion'];
        }

        return null;
    }

    /**
     * Get available configuration parsers
     * 
     * @return array<ConfigurationParserInterface> Available parsers
     */
    public function getParsers(): array
    {
        return $this->parsers;
    }

    /**
     * Get configuration files summary for installation
     * 
     * @param Installation $installation TYPO3 installation
     * @return array<string, mixed> Configuration summary
     */
    public function getConfigurationSummary(Installation $installation): array
    {
        $allConfigs = $installation->getAllConfigurationData();
        $allMetadata = $installation->getAllConfigurationMetadata();

        $summary = [
            'total_configurations' => count($allConfigs),
            'configurations' => [],
            'statistics' => [
                'total_files' => count($allMetadata),
                'successful_parses' => count($allConfigs),
                'failed_parses' => count($allMetadata) - count($allConfigs),
                'total_errors' => 0,
                'total_warnings' => 0,
                'total_file_size' => 0,
            ],
            'categories' => [
                'core' => 0,
                'site' => 0,
                'extension' => 0,
                'custom' => 0,
            ],
        ];

        foreach ($allConfigs as $identifier => $configData) {
            $metadata = $allMetadata[$identifier] ?? null;
            
            $summary['configurations'][$identifier] = [
                'format' => $configData->getFormat(),
                'source' => $configData->getSource(),
                'key_count' => $configData->count(),
                'is_valid' => $configData->isValid(),
                'error_count' => count($configData->getValidationErrors()),
                'warning_count' => count($configData->getValidationWarnings()),
                'file_size' => $metadata?->getFileSize() ?? 0,
                'category' => $metadata?->getCategory() ?? 'unknown',
            ];

            $summary['statistics']['total_errors'] += count($configData->getValidationErrors());
            $summary['statistics']['total_warnings'] += count($configData->getValidationWarnings());
            
            if ($metadata) {
                $summary['statistics']['total_file_size'] += $metadata->getFileSize();
                $category = $metadata->getCategory();
                if (isset($summary['categories'][$category])) {
                    $summary['categories'][$category]++;
                }
            }
        }

        return $summary;
    }
}