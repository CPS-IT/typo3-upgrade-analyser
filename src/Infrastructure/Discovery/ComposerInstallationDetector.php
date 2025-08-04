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
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\InstallationMetadata;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\InstallationMode;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
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
    private const TYPO3_INDICATORS = [
        'public/typo3conf',
        'public/typo3',
        'config/system',
        'var/log',
    ];

    private const TYPO3_CORE_PACKAGES = [
        'typo3/cms-core',
        'typo3/cms',
        'typo3/minimal',
    ];

    public function __construct(
        private readonly VersionExtractor $versionExtractor,
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
            $metadata = $this->createInstallationMetadata($path);

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

        // Detect custom paths from composer.json
        $customPaths = $this->detectCustomPaths($path);
        $webDir = $customPaths['web-dir'];

        // Check for TYPO3 indicators using custom paths
        $typo3Indicators = [
            $webDir . '/typo3conf',
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
            $composerData = json_decode(file_get_contents($composerJsonPath), true, 512, JSON_THROW_ON_ERROR);

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
    private function createInstallationMetadata(string $path): InstallationMetadata
    {
        $phpVersions = $this->detectPhpVersions($path);
        $databaseConfig = $this->detectDatabaseConfig($path);
        $enabledFeatures = $this->detectEnabledFeatures($path);
        $customPaths = $this->detectCustomPaths($path);
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
            $composerData = json_decode(file_get_contents($composerJsonPath), true, 512, JSON_THROW_ON_ERROR);

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
     * Detect custom paths in TYPO3 installation.
     *
     * @param string $path Installation path
     *
     * @return array<string, string> Custom paths
     */
    private function detectCustomPaths(string $path): array
    {
        $paths = [
            'vendor-dir' => 'vendor',
            'web-dir' => 'public',
            'typo3conf-dir' => 'public/typo3conf',
            'var' => 'var',
            'config' => 'config',
        ];

        $composerJsonPath = $path . '/composer.json';

        if (!file_exists($composerJsonPath)) {
            $this->logger->debug('composer.json not found, using default paths');

            return $paths;
        }

        try {
            $composerData = json_decode(file_get_contents($composerJsonPath), true, 512, JSON_THROW_ON_ERROR);

            if (!\is_array($composerData)) {
                return $paths;
            }

            // Read composer config section
            if (isset($composerData['config'])) {
                $composerConfig = $composerData['config'];

                if (isset($composerConfig['vendor-dir'])) {
                    $paths['vendor-dir'] = $composerConfig['vendor-dir'];
                }
            }

            // Read TYPO3-specific configuration from extra section
            if (isset($composerData['extra']['typo3/cms'])) {
                $typo3Config = $composerData['extra']['typo3/cms'];

                if (isset($typo3Config['web-dir'])) {
                    $paths['web-dir'] = $typo3Config['web-dir'];
                }
            }

            // Update typo3conf-dir based on web-dir
            $paths['typo3conf-dir'] = $paths['web-dir'] . '/typo3conf';

            $this->logger->debug('Custom paths detected from composer.json', [
                'paths' => $paths,
            ]);

            return $paths;
        } catch (\JsonException $e) {
            $this->logger->warning('Failed to parse composer.json for custom paths', [
                'path' => $composerJsonPath,
                'error' => $e->getMessage(),
            ]);

            return $paths;
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
