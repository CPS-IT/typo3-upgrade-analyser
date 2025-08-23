<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Discovery;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Installation;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\ConfigurationData;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\ConfigurationMetadata;
use CPSIT\UpgradeAnalyzer\Infrastructure\Parser\ConfigurationParserInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathConfiguration;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionRequest;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\InstallationTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\PathResolutionServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;

/**
 * Service for discovering and parsing configuration files in TYPO3 installations.
 *
 * Integrates configuration parsing capabilities with the installation discovery system
 * to automatically detect, parse, and validate TYPO3 configuration files.
 */
class ConfigurationDiscoveryService
{
    /**
     * @param iterable<ConfigurationParserInterface> $parsers               Available configuration parsers
     * @param PathResolutionServiceInterface         $pathResolutionService Path resolution service
     * @param LoggerInterface                        $logger                Logger instance
     */
    public function __construct(
        private readonly iterable $parsers,
        private readonly PathResolutionServiceInterface $pathResolutionService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Discover and parse configuration files in installation.
     *
     * @param Installation $installation TYPO3 installation
     *
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
            'configurations_found' => \count($installation->getAllConfigurationData()),
            'has_errors' => $installation->hasConfigurationErrors(),
        ]);

        return $installation;
    }

    /**
     * Find configuration files in installation directory using PathResolutionService.
     *
     * @param string $installationPath Installation root path
     *
     * @return array<\SplFileInfo> Configuration files found
     */
    private function findConfigurationFiles(string $installationPath): array
    {
        $files = [];
        $installationType = $this->determineInstallationType($installationPath);
        $pathConfiguration = PathConfiguration::createDefault();

        try {
            // Find core configuration files using PathResolutionService
            $coreConfigFiles = $this->findCoreConfigurationFiles($installationPath, $installationType, $pathConfiguration);
            $files = array_merge($files, $coreConfigFiles);

            // Find Services.yaml files
            $servicesFiles = $this->findServicesFiles($installationPath, $installationType, $pathConfiguration);
            $files = array_merge($files, $servicesFiles);

            // Find site configuration files
            $siteConfigFiles = $this->findSiteConfigurationFiles($installationPath, $installationType, $pathConfiguration);
            $files = array_merge($files, $siteConfigFiles);

            $this->logger->debug('Configuration file discovery completed', [
                'installation_path' => $installationPath,
                'installation_type' => $installationType->value,
                'files_found' => \count($files),
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('Error finding configuration files', [
                'installation_path' => $installationPath,
                'error' => $e->getMessage(),
            ]);
        }

        return $files;
    }

    /**
     * Find core TYPO3 configuration files.
     *
     * @param string               $installationPath  Installation path
     * @param InstallationTypeEnum $installationType  Installation type
     * @param PathConfiguration    $pathConfiguration Path configuration
     *
     * @return array<\SplFileInfo> Core configuration files
     */
    private function findCoreConfigurationFiles(
        string $installationPath,
        InstallationTypeEnum $installationType,
        PathConfiguration $pathConfiguration,
    ): array {
        $files = [];
        $coreConfigs = [
            'LocalConfiguration.php' => PathTypeEnum::LOCAL_CONFIGURATION,
            'AdditionalConfiguration.php' => PathTypeEnum::LOCAL_CONFIGURATION, // Same type for both
            'PackageStates.php' => PathTypeEnum::PACKAGE_STATES,
        ];

        foreach ($coreConfigs as $configFile => $pathType) {
            $request = PathResolutionRequest::builder()
                ->pathType($pathType)
                ->installationPath($installationPath)
                ->installationType($installationType)
                ->pathConfiguration($pathConfiguration)
                ->build();

            $response = $this->pathResolutionService->resolvePath($request);

            // Try both modern and legacy paths
            $searchPaths = [];
            if ($response->isSuccess() && $response->resolvedPath) {
                // Use resolved configuration directory
                $configDir = \dirname($response->resolvedPath);
                $searchPaths[] = $configDir . '/' . $configFile;
            }

            // Add alternative paths from response
            foreach ($response->alternativePaths as $altPath) {
                $searchPaths[] = \dirname($altPath) . '/' . $configFile;
            }

            // Add standard fallback paths
            $searchPaths = array_merge($searchPaths, [
                $installationPath . '/config/' . $configFile, // TYPO3 v12+
                $installationPath . '/typo3conf/' . $configFile, // TYPO3 v11 and earlier
            ]);

            // Check all paths and add existing files
            foreach (array_unique($searchPaths) as $configPath) {
                if (file_exists($configPath)) {
                    $files[] = new \SplFileInfo($configPath);

                    $this->logger->debug('Core configuration file found', [
                        'file' => $configFile,
                        'path' => $configPath,
                        'type' => $pathType->value,
                    ]);
                    break; // Only add the first found instance
                }
            }
        }

        return $files;
    }

    /**
     * Find Services.yaml files using PathResolutionService.
     *
     * @param string               $installationPath  Installation path
     * @param InstallationTypeEnum $installationType  Installation type
     * @param PathConfiguration    $pathConfiguration Path configuration
     *
     * @return array<\SplFileInfo> Services.yaml files
     */
    private function findServicesFiles(
        string $installationPath,
        InstallationTypeEnum $installationType,
        PathConfiguration $pathConfiguration,
    ): array {
        $files = [];
        $finder = new Finder();

        // Get base directories to search from PathResolutionService
        $searchDirectories = $this->getSearchDirectories($installationPath, $installationType, $pathConfiguration);

        foreach ($searchDirectories as $searchDir) {
            if (!is_dir($searchDir)) {
                continue;
            }

            try {
                $servicesFiles = $finder->create()
                    ->files()
                    ->name('Services.yaml')
                    ->in($searchDir)
                    ->depth('< 4'); // Limit depth to avoid deep traversal

                foreach ($servicesFiles as $file) {
                    $files[] = $file;

                    $this->logger->debug('Services.yaml file found', [
                        'path' => $file->getRealPath(),
                        'search_dir' => $searchDir,
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->debug('Error searching for Services.yaml in directory', [
                    'directory' => $searchDir,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $files;
    }

    /**
     * Find site configuration files.
     *
     * @param string               $installationPath  Installation path
     * @param InstallationTypeEnum $installationType  Installation type
     * @param PathConfiguration    $pathConfiguration Path configuration
     *
     * @return array<\SplFileInfo> Site configuration files
     */
    private function findSiteConfigurationFiles(
        string $installationPath,
        InstallationTypeEnum $installationType,
        PathConfiguration $pathConfiguration,
    ): array {
        $files = [];
        $finder = new Finder();

        // Try to find config directory using PathResolutionService
        $request = PathResolutionRequest::builder()
            ->pathType(PathTypeEnum::LOCAL_CONFIGURATION)
            ->installationPath($installationPath)
            ->installationType($installationType)
            ->pathConfiguration($pathConfiguration)
            ->build();

        $response = $this->pathResolutionService->resolvePath($request);

        $siteConfigDirs = [];
        if ($response->isSuccess() && $response->resolvedPath) {
            // Use the directory containing the resolved configuration file
            $configBaseDir = \dirname($response->resolvedPath);
            $siteConfigDirs[] = $configBaseDir . '/sites';
        }

        // Add alternative paths
        foreach ($response->alternativePaths as $altPath) {
            $siteConfigDirs[] = \dirname($altPath) . '/sites';
        }

        // Add standard fallback paths
        $siteConfigDirs = array_merge($siteConfigDirs, [
            $installationPath . '/config/sites', // TYPO3 v12+
            $installationPath . '/typo3conf/sites', // Fallback
        ]);

        // Search in all potential site config directories
        foreach (array_unique($siteConfigDirs) as $siteConfigDir) {
            if (!is_dir($siteConfigDir)) {
                continue;
            }

            try {
                $siteConfigs = $finder->create()
                    ->files()
                    ->name('config.yaml')
                    ->in($siteConfigDir)
                    ->depth('== 1'); // Only direct subdirectories

                foreach ($siteConfigs as $file) {
                    $files[] = $file;

                    $this->logger->debug('Site configuration file found', [
                        'path' => $file->getRealPath(),
                        'site_dir' => $siteConfigDir,
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->debug('Error searching for site configs in directory', [
                    'directory' => $siteConfigDir,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $files;
    }

    /**
     * Get search directories for Services.yaml files.
     *
     * @param string               $installationPath  Installation path
     * @param InstallationTypeEnum $installationType  Installation type
     * @param PathConfiguration    $pathConfiguration Path configuration
     *
     * @return array<string> Search directories
     */
    private function getSearchDirectories(
        string $installationPath,
        InstallationTypeEnum $installationType,
        PathConfiguration $pathConfiguration,
    ): array {
        $directories = [$installationPath]; // Always include root

        // Get additional directories from PathResolutionService
        $pathTypes = [
            PathTypeEnum::WEB_DIR,
            PathTypeEnum::TYPO3CONF_DIR,
            PathTypeEnum::VENDOR_DIR,
        ];

        foreach ($pathTypes as $pathType) {
            $request = PathResolutionRequest::builder()
                ->pathType($pathType)
                ->installationPath($installationPath)
                ->installationType($installationType)
                ->pathConfiguration($pathConfiguration)
                ->build();

            $response = $this->pathResolutionService->resolvePath($request);

            if ($response->isSuccess() && $response->resolvedPath && is_dir($response->resolvedPath)) {
                $directories[] = $response->resolvedPath;
            }

            // Add alternative paths
            foreach ($response->alternativePaths as $altPath) {
                if (is_dir($altPath)) {
                    $directories[] = $altPath;
                }
            }
        }

        return array_unique($directories);
    }

    /**
     * Determine installation type for path resolution.
     *
     * @param string $installationPath Installation path
     *
     * @return InstallationTypeEnum Installation type
     */
    private function determineInstallationType(string $installationPath): InstallationTypeEnum
    {
        // Check for composer.json to determine if it's a Composer installation
        if (file_exists($installationPath . '/composer.json')) {
            try {
                $json = file_get_contents($installationPath . '/composer.json');
                if (false !== $json) {
                    $composerData = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

                    // Check for custom TYPO3 configuration
                    if (isset($composerData['extra']['typo3/cms'])) {
                        return InstallationTypeEnum::COMPOSER_CUSTOM;
                    }

                    // Check for custom vendor directory
                    if (isset($composerData['config']['vendor-dir']) && 'vendor' !== $composerData['config']['vendor-dir']) {
                        return InstallationTypeEnum::COMPOSER_CUSTOM;
                    }

                    return InstallationTypeEnum::COMPOSER_STANDARD;
                }
            } catch (\JsonException) {
                // Fall through to default
            }
        }

        // Check for Docker indicators
        if (file_exists($installationPath . '/docker-compose.yml') || file_exists($installationPath . '/Dockerfile')) {
            return InstallationTypeEnum::DOCKER_CONTAINER;
        }

        // Default to legacy source installation
        return InstallationTypeEnum::LEGACY_SOURCE;
    }

    /**
     * Parse individual configuration file.
     *
     * @param Installation $installation TYPO3 installation
     * @param \SplFileInfo $file         Configuration file
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
        if (null === $parser) {
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
                    $parseResult->getWarnings(),
                );

                $configMetadata = new ConfigurationMetadata(
                    $filePath,
                    $fileName,
                    $parseResult->getFormat(),
                    $file->getSize(),
                    new \DateTimeImmutable('@' . $file->getMTime()),
                    new \DateTimeImmutable(),
                    \get_class($parser),
                    $parseResult->getMetadata(),
                    array_keys($parseResult->getData()),
                    $this->extractTypo3Version($parseResult->getData()),
                    $this->extractPhpVersion($parseResult->getData()),
                );

                $identifier = $this->generateConfigurationIdentifier($file);
                $installation->addConfigurationData($identifier, $configData);
                $installation->addConfigurationMetadata($identifier, $configMetadata);

                $this->logger->info('Configuration file parsed successfully', [
                    'identifier' => $identifier,
                    'file_path' => $filePath,
                    'keys_found' => \count($parseResult->getData()),
                    'warnings_count' => \count($parseResult->getWarnings()),
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
                    \get_class($parser),
                    ['parse_errors' => $parseResult->getErrors()],
                    [],
                    null,
                    null,
                    ['parse_failed' => true, 'error_count' => \count($parseResult->getErrors())],
                );

                $identifier = $this->generateConfigurationIdentifier($file);
                $installation->addConfigurationMetadata($identifier, $configMetadata);
            }
        } catch (\Exception $e) {
            $this->logger->error('Exception during configuration file parsing', [
                'file_path' => $filePath,
                'exception' => $e->getMessage(),
                'exception_class' => \get_class($e),
            ]);
        }
    }

    /**
     * Find appropriate parser for configuration file.
     *
     * @param \SplFileInfo $file Configuration file
     *
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
     * Generate configuration identifier for file.
     *
     * @param \SplFileInfo $file Configuration file
     *
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
        if ('Services.yaml' === $fileName) {
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
        if ('config.yaml' === $fileName && str_contains($filePath, '/config/sites/')) {
            if (preg_match('#/config/sites/([^/]+)/config\.yaml$#', $filePath, $matches)) {
                return 'Site.' . $matches[1];
            }

            return 'Site.unknown';
        }

        // Fallback: use filename without extension
        return pathinfo($fileName, PATHINFO_FILENAME);
    }

    /**
     * Extract TYPO3 version from configuration data.
     *
     * @param array<string, mixed> $data Configuration data
     *
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
     * Extract PHP version requirement from configuration data.
     *
     * @param array<string, mixed> $data Configuration data
     *
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
     * Get available configuration parsers.
     *
     * @return array<ConfigurationParserInterface> Available parsers
     */
    public function getParsers(): array
    {
        return iterator_to_array($this->parsers);
    }

    /**
     * Get configuration files summary for installation.
     *
     * @param Installation $installation TYPO3 installation
     *
     * @return array<string, mixed> Configuration summary
     */
    public function getConfigurationSummary(Installation $installation): array
    {
        $allConfigs = $installation->getAllConfigurationData();
        $allMetadata = $installation->getAllConfigurationMetadata();

        $summary = [
            'total_configurations' => \count($allConfigs),
            'configurations' => [],
            'statistics' => [
                'total_files' => \count($allMetadata),
                'successful_parses' => \count($allConfigs),
                'failed_parses' => \count($allMetadata) - \count($allConfigs),
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
                'error_count' => \count($configData->getValidationErrors()),
                'warning_count' => \count($configData->getValidationWarnings()),
                'file_size' => $metadata?->getFileSize() ?? 0,
                'category' => $metadata?->getCategory() ?? 'unknown',
            ];

            $summary['statistics']['total_errors'] += \count($configData->getValidationErrors());
            $summary['statistics']['total_warnings'] += \count($configData->getValidationWarnings());

            if ($metadata) {
                $summary['statistics']['total_file_size'] += $metadata->getFileSize();
                $category = $metadata->getCategory();
                if (isset($summary['categories'][$category])) {
                    ++$summary['categories'][$category];
                }
            }
        }

        return $summary;
    }
}
