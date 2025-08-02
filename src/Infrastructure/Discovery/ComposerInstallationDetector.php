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

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Installation;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\InstallationMode;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\InstallationMetadata;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use Psr\Log\LoggerInterface;

/**
 * Detection strategy for Composer-based TYPO3 installations
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
        'var/log'
    ];

    private const TYPO3_CORE_PACKAGES = [
        'typo3/cms-core',
        'typo3/cms',
        'typo3/minimal'
    ];

    public function __construct(
        private readonly VersionExtractor $versionExtractor,
        private readonly LoggerInterface $logger
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
                    'error' => $versionResult->getErrorMessage()
                ]);
                return null;
            }

            $version = $versionResult->getVersion();
            if ($version === null) {
                $this->logger->debug('No version extracted', ['path' => $path]);
                return null;
            }

            // Create installation metadata
            $metadata = $this->createInstallationMetadata($path);

            // Create installation entity
            $installation = new Installation($path, $version);
            $installation->setMode(InstallationMode::COMPOSER);
            $installation->setMetadata($metadata);

            // Discover and add extensions (basic discovery for now)
            $extensions = $this->discoverExtensions($path);
            foreach ($extensions as $extension) {
                $installation->addExtension($extension);
            }

            $this->logger->info('Composer TYPO3 installation detected', [
                'path' => $path,
                'version' => $version->toString(),
                'extensions_count' => count($extensions)
            ]);

            return $installation;

        } catch (\Throwable $e) {
            $this->logger->error('Error during Composer installation detection', [
                'path' => $path,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
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

        // Check for TYPO3 indicators
        $foundIndicators = 0;
        foreach (self::TYPO3_INDICATORS as $indicator) {
            if (file_exists($path . '/' . $indicator)) {
                $foundIndicators++;
            }
        }

        // Require at least 2 TYPO3 indicators for confidence
        if ($foundIndicators < 2) {
            return false;
        }

        // Check if composer.json contains TYPO3 packages
        return $this->hasTypo3Packages($path);
    }

    public function getPriority(): int
    {
        return 100; // High priority for Composer installations
    }

    public function getRequiredIndicators(): array
    {
        return array_merge(self::REQUIRED_COMPOSER_FILES, self::TYPO3_INDICATORS);
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
     * Check if composer.json contains TYPO3 packages
     * 
     * @param string $path Installation path
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
            
            if (!is_array($composerData)) {
                return false;
            }

            $requirements = array_merge(
                $composerData['require'] ?? [],
                $composerData['require-dev'] ?? []
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
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Create installation metadata from discovered information
     * 
     * @param string $path Installation path
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
                'composer_mode' => true
            ]
        );
    }

    /**
     * Detect PHP version requirements from composer.json
     * 
     * @param string $path Installation path
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
     * Detect database configuration from TYPO3 configuration
     * 
     * @param string $path Installation path
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
     * Detect enabled TYPO3 features
     * 
     * @param string $path Installation path
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
     * Detect custom paths in TYPO3 installation
     * 
     * @param string $path Installation path
     * @return array<string, string> Custom paths
     */
    private function detectCustomPaths(string $path): array
    {
        $paths = [];

        // Standard Composer TYPO3 paths
        $paths['public'] = 'public';
        $paths['var'] = 'var';
        $paths['config'] = 'config';

        // Check for custom public directory name
        $possiblePublicDirs = ['public', 'web', 'htdocs', 'www'];
        foreach ($possiblePublicDirs as $dir) {
            if (is_dir($path . '/' . $dir) && file_exists($path . '/' . $dir . '/index.php')) {
                $paths['public'] = $dir;
                break;
            }
        }

        return $paths;
    }

    /**
     * Get last modification time of the installation
     * 
     * @param string $path Installation path
     * @return \DateTimeImmutable Last modified time
     */
    private function getLastModifiedTime(string $path): \DateTimeImmutable
    {
        $composerLockPath = $path . '/composer.lock';
        
        if (file_exists($composerLockPath)) {
            $timestamp = filemtime($composerLockPath);
            if ($timestamp !== false) {
                return new \DateTimeImmutable('@' . $timestamp);
            }
        }

        // Fall back to current time
        return new \DateTimeImmutable();
    }

    /**
     * Basic extension discovery for Composer installations
     * 
     * This is a simplified implementation. Full extension discovery
     * will be implemented in Phase 3.
     * 
     * @param string $path Installation path
     * @return array<Extension> Discovered extensions
     */
    private function discoverExtensions(string $path): array
    {
        $extensions = [];

        // Check composer.lock for installed packages
        $composerLockPath = $path . '/composer.lock';
        if (!file_exists($composerLockPath)) {
            return $extensions;
        }

        try {
            $lockData = json_decode(file_get_contents($composerLockPath), true, 512, JSON_THROW_ON_ERROR);
            
            if (!isset($lockData['packages']) || !is_array($lockData['packages'])) {
                return $extensions;
            }

            foreach ($lockData['packages'] as $package) {
                if (!is_array($package) || !isset($package['name'], $package['version'])) {
                    continue;
                }

                // Skip non-TYPO3 packages for now
                if (!str_starts_with($package['name'], 'typo3/') && 
                    !isset($package['type']) || 
                    $package['type'] !== 'typo3-cms-extension') {
                    continue;
                }

                // Create basic extension entity
                $extensionKey = $this->extractExtensionKey($package['name']);
                if ($extensionKey !== null) {
                    $version = Version::fromString($this->normalizeVersion($package['version']));
                    $extension = new Extension($extensionKey, $version);
                    $extension->setType('composer');
                    $extensions[] = $extension;
                }
            }

        } catch (\JsonException $e) {
            $this->logger->warning('Failed to parse composer.lock for extension discovery', [
                'path' => $composerLockPath,
                'error' => $e->getMessage()
            ]);
        }

        return $extensions;
    }

    /**
     * Extract extension key from package name
     * 
     * @param string $packageName Composer package name
     * @return string|null Extension key
     */
    private function extractExtensionKey(string $packageName): ?string
    {
        // Handle TYPO3 core packages
        if (in_array($packageName, self::TYPO3_CORE_PACKAGES, true)) {
            return null; // Core packages are not extensions
        }

        // Extract extension key from package name
        if (str_starts_with($packageName, 'typo3/cms-')) {
            return substr($packageName, 10); // Remove 'typo3/cms-' prefix
        }

        // For third-party extensions, use the part after the slash
        $parts = explode('/', $packageName);
        if (count($parts) === 2) {
            return $parts[1];
        }

        return null;
    }

    /**
     * Normalize version string from composer.lock
     * 
     * @param string $version Raw version
     * @return string Normalized version
     */
    private function normalizeVersion(string $version): string
    {
        // Remove 'v' prefix
        $version = ltrim($version, 'v');
        
        // Handle dev versions
        if (str_starts_with($version, 'dev-')) {
            return '0.0.0'; // Fallback for dev versions
        }

        return $version;
    }
}