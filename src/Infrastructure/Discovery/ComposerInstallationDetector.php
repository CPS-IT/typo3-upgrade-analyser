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
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\InstallationMetadata;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\InstallationMode;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathConfiguration;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionRequest;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\InstallationTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\PathResolutionServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Detection strategy for Composer-based TYPO3 installations.
 *
 * This strategy detects TYPO3 installations that were set up using Composer.
 * It identifies the installation by looking for key Composer indicators and
 * TYPO3-specific directory structures.
 */
final class ComposerInstallationDetector implements DetectionStrategyInterface
{
    private const REQUIRED_COMPOSER_FILES = ['composer.json'];
    private const TYPO3_CORE_PACKAGES = [
        'typo3/cms-core',
        'typo3/cms',
        'typo3/minimal',
    ];

    public function __construct(
        private readonly VersionExtractor $versionExtractor,
        private readonly PathResolutionServiceInterface $pathResolutionService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function detect(string $path): ?Installation
    {
        $this->logger->debug('Starting Composer installation detection', ['path' => $path]);

        if (!$this->supports($path)) {
            $this->logger->debug('Path not supported by Composer detector', ['path' => $path]);

            return null;
        }

        try {
            // Extract TYPO3 version
            $versionResult = $this->versionExtractor->extractVersion($path);
            if (!$versionResult->isSuccessful()) {
                $this->logger->debug('Version extraction failed', [
                    'path' => $path,
                    'error' => $versionResult->getErrorMessage(),
                ]);

                return null;
            }

            $version = $versionResult->getVersion();
            if (null === $version) {
                $this->logger->debug('No version extracted', ['path' => $path]);

                return null;
            }

            // Create installation metadata
            $metadata = $this->createInstallationMetadata($path, $version);

            // Create installation entity
            $installation = new Installation($path, $version);
            $installation->setMode(InstallationMode::COMPOSER);
            $installation->setMetadata($metadata);

            // Note: Extensions are discovered separately by ExtensionDiscoveryService
            // to maintain proper separation of concerns

            $this->logger->info('Composer TYPO3 installation detected', [
                'path' => $path,
                'version' => $version->toString(),
            ]);

            return $installation;
        } catch (\Throwable $e) {
            $this->logger->error('Error during Composer installation detection', [
                'path' => $path,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return null;
        }
    }

    public function supports(string $path): bool
    {
        if (!is_dir($path)) {
            return false;
        }

        // Check for required Composer files
        foreach (self::REQUIRED_COMPOSER_FILES as $file) {
            if (!file_exists($path . '/' . $file)) {
                return false;
            }
        }

        // Check if composer.json contains TYPO3 packages first
        if (!$this->hasTypo3Packages($path)) {
            return false;
        }

        // Detect web directory for TYPO3 indicators check
        $webDir = $this->getWebDirectoryForSupportsCheck($path);

        // Check for TYPO3 indicators using custom paths (both v11 and v12+ locations)
        $typo3Indicators = [
            'typo3conf', // TYPO3 v11 and earlier
            $webDir . '/typo3conf', // TYPO3 v12+
            $webDir . '/typo3',
            'config/system',
            'var/log',
        ];

        $foundIndicators = 0;
        foreach ($typo3Indicators as $indicator) {
            if (file_exists($path . '/' . $indicator)) {
                ++$foundIndicators;
            }
        }

        // Require at least 2 TYPO3 indicators for confidence
        return $foundIndicators >= 2;
    }

    public function getPriority(): int
    {
        return 100; // High priority for Composer installations
    }

    public function getRequiredIndicators(): array
    {
        // Only require composer.json - TYPO3 paths can be customized
        return self::REQUIRED_COMPOSER_FILES;
    }

    public function getName(): string
    {
        return 'Composer Installation Detector';
    }

    public function getDescription(): string
    {
        return 'Detects TYPO3 installations set up using Composer package manager';
    }

    /**
     * Check if composer.json contains TYPO3 packages.
     *
     * @param string $path Installation path
     *
     * @return bool True if TYPO3 packages are found
     */
    private function hasTypo3Packages(string $path): bool
    {
        $composerJsonPath = $path . '/composer.json';

        if (!file_exists($composerJsonPath)) {
            return false;
        }

        try {
            $json = file_get_contents($composerJsonPath);
            if (false === $json) {
                return false;
            }

            $composerData = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            if (!\is_array($composerData)) {
                return false;
            }

            $requirements = array_merge(
                $composerData['require'] ?? [],
                $composerData['require-dev'] ?? [],
            );

            foreach (self::TYPO3_CORE_PACKAGES as $package) {
                if (isset($requirements[$package])) {
                    return true;
                }
            }

            return false;
        } catch (\JsonException $e) {
            $this->logger->warning('Failed to parse composer.json for TYPO3 package check', [
                'path' => $composerJsonPath,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Create installation metadata from discovered information.
     *
     * @param string $path Installation path
     *
     * @return InstallationMetadata Metadata object
     */
    private function createInstallationMetadata(string $path, Version $version): InstallationMetadata
    {
        $phpVersions = $this->detectPhpVersions($path);
        $databaseConfig = $this->detectDatabaseConfig($path);
        $enabledFeatures = $this->detectEnabledFeatures($path);
        $customPaths = $this->detectCustomPaths($path, $version);
        $lastModified = $this->getLastModifiedTime($path);

        return new InstallationMetadata(
            $phpVersions,
            $databaseConfig,
            $enabledFeatures,
            $lastModified,
            $customPaths,
            [
                'detection_strategy' => $this->getName(),
                'composer_mode' => true,
            ],
        );
    }

    /**
     * Detect PHP version requirements from composer.json.
     *
     * @param string $path Installation path
     *
     * @return array<string> PHP versions
     */
    private function detectPhpVersions(string $path): array
    {
        $composerJsonPath = $path . '/composer.json';

        if (!file_exists($composerJsonPath)) {
            return [];
        }

        try {
            $json = file_get_contents($composerJsonPath);
            if (false === $json) {
                return [];
            }

            $composerData = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            if (isset($composerData['require']['php'])) {
                return [$composerData['require']['php']];
            }

            return [];
        } catch (\JsonException) {
            return [];
        }
    }

    /**
     * Detect database configuration from TYPO3 configuration.
     *
     * @param string $path Installation path
     *
     * @return array<string, mixed> Database configuration
     */
    private function detectDatabaseConfig(string $path): array
    {
        $localConfigPath = $path . '/config/system/settings.php';
        $additionalConfigPath = $path . '/config/system/additional.php';

        // For now, return minimal database info
        // Full configuration parsing will be implemented in Phase 3
        $config = [];

        if (file_exists($localConfigPath)) {
            $config['has_local_configuration'] = true;
        }

        if (file_exists($additionalConfigPath)) {
            $config['has_additional_configuration'] = true;
        }

        return $config;
    }

    /**
     * Detect enabled TYPO3 features.
     *
     * @param string $path Installation path
     *
     * @return array<string> Enabled features
     */
    private function detectEnabledFeatures(string $path): array
    {
        $features = [];

        // Check for common TYPO3 features based on directory structure
        if (is_dir($path . '/public/typo3conf/ext')) {
            $features[] = 'extensions';
        }

        if (is_dir($path . '/config/sites')) {
            $features[] = 'site_configuration';
        }

        if (file_exists($path . '/config/system/settings.php')) {
            $features[] = 'system_configuration';
        }

        return $features;
    }

    /**
     * Detect custom paths in TYPO3 installation using PathResolutionService.
     *
     * @param string  $path    Installation path
     * @param Version $version TYPO3 version for version-specific path detection
     *
     * @return array<string, string> Custom paths
     */
    private function detectCustomPaths(string $path, Version $version): array
    {
        $installationType = $this->determineInstallationType($path, $version);
        $pathConfiguration = PathConfiguration::createDefault();

        $pathTypes = [
            'vendor-dir' => PathTypeEnum::VENDOR_DIR,
            'web-dir' => PathTypeEnum::WEB_DIR,
            'typo3conf-dir' => PathTypeEnum::TYPO3CONF_DIR,
        ];

        $resolvedPaths = [
            'vendor-dir' => 'vendor',
            'web-dir' => 'public',
            'typo3conf-dir' => 'public/typo3conf',
            'var' => 'var',
            'config' => 'config',
        ];

        foreach ($pathTypes as $pathKey => $pathType) {
            $request = PathResolutionRequest::builder()
                ->pathType($pathType)
                ->installationPath($path)
                ->installationType($installationType)
                ->pathConfiguration($pathConfiguration)
                ->build();

            $response = $this->pathResolutionService->resolvePath($request);

            if ($response->isSuccess() && $response->resolvedPath) {
                // Convert absolute path to relative path for consistency
                $relativePath = str_replace($path . '/', '', $response->resolvedPath);
                $resolvedPaths[$pathKey] = $relativePath;

                $this->logger->debug('Path resolved via PathResolutionService', [
                    'path_type' => $pathType->value,
                    'resolved_path' => $relativePath,
                    'strategy' => $response->metadata->usedStrategy,
                ]);
            } else {
                $this->logger->debug('Path resolution failed, using default', [
                    'path_type' => $pathType->value,
                    'default_path' => $resolvedPaths[$pathKey],
                    'errors' => $response->errors,
                ]);
            }
        }

        $this->logger->debug('Custom paths detected using PathResolutionService', [
            'paths' => $resolvedPaths,
            'typo3_version' => $version->toString(),
            'installation_type' => $installationType->value,
        ]);

        return $resolvedPaths;
    }

    /**
     * Get web directory for supports() method check (simplified version).
     *
     * @param string $path Installation path
     *
     * @return string Web directory path
     */
    private function getWebDirectoryForSupportsCheck(string $path): string
    {
        $composerJsonPath = $path . '/composer.json';

        if (!file_exists($composerJsonPath)) {
            return 'public';
        }

        try {
            $json = file_get_contents($composerJsonPath);
            if (false === $json) {
                return 'public';
            }

            $composerData = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            if (!\is_array($composerData)) {
                return 'public';
            }

            // Read TYPO3-specific web-dir configuration
            if (isset($composerData['extra']['typo3/cms']['web-dir'])) {
                return $composerData['extra']['typo3/cms']['web-dir'];
            }

            return 'public';
        } catch (\JsonException) {
            return 'public';
        }
    }

    /**
     * Determine installation type based on composer.json and directory structure.
     *
     * @param string  $path    Installation path
     * @param Version $version TYPO3 version
     *
     * @return InstallationTypeEnum Installation type
     */
    private function determineInstallationType(string $path, Version $version): InstallationTypeEnum
    {
        $composerJsonPath = $path . '/composer.json';

        if (!file_exists($composerJsonPath)) {
            return InstallationTypeEnum::LEGACY_SOURCE;
        }

        try {
            $json = file_get_contents($composerJsonPath);
            if (false === $json) {
                return InstallationTypeEnum::COMPOSER_STANDARD;
            }

            $composerData = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            if (!\is_array($composerData)) {
                return InstallationTypeEnum::COMPOSER_STANDARD;
            }

            // Check for custom TYPO3 configuration
            if (isset($composerData['extra']['typo3/cms'])) {
                $typo3Config = $composerData['extra']['typo3/cms'];

                // If custom web-dir is configured, it's a custom installation
                if (isset($typo3Config['web-dir']) && 'public' !== $typo3Config['web-dir']) {
                    return InstallationTypeEnum::COMPOSER_CUSTOM;
                }
            }

            // Check for custom vendor directory
            if (isset($composerData['config']['vendor-dir']) && 'vendor' !== $composerData['config']['vendor-dir']) {
                return InstallationTypeEnum::COMPOSER_CUSTOM;
            }

            // Check for Docker indicators
            if (file_exists($path . '/docker-compose.yml') || file_exists($path . '/Dockerfile')) {
                return InstallationTypeEnum::DOCKER_CONTAINER;
            }

            return InstallationTypeEnum::COMPOSER_STANDARD;
        } catch (\JsonException) {
            return InstallationTypeEnum::COMPOSER_STANDARD;
        }
    }

    /**
     * Get last modification time of the installation.
     *
     * @param string $path Installation path
     *
     * @return \DateTimeImmutable Last modified time
     */
    private function getLastModifiedTime(string $path): \DateTimeImmutable
    {
        $composerLockPath = $path . '/composer.lock';

        if (file_exists($composerLockPath)) {
            $timestamp = filemtime($composerLockPath);
            if (false !== $timestamp) {
                return new \DateTimeImmutable('@' . $timestamp);
            }
        }

        // Fall back to current time
        return new \DateTimeImmutable();
    }
}
