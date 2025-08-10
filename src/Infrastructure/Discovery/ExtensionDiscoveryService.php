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
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService;
use Psr\Log\LoggerInterface;

class ExtensionDiscoveryService implements ExtensionDiscoveryServiceInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ConfigurationService $configService,
        private readonly CacheService $cacheService,
    ) {
    }

    public function discoverExtensions(string $installationPath, ?array $customPaths = null): ExtensionDiscoveryResult
    {
        $this->logger->info('Starting extension discovery', ['path' => $installationPath]);

        try {
            // Check cache if enabled
            if ($this->configService->isResultCacheEnabled()) {
                $cacheKey = $this->cacheService->generateKey('extension_discovery', $installationPath, [
                    'custom_paths' => $customPaths ?? [],
                ]);
                $cachedResult = $this->cacheService->get($cacheKey);

                if (null !== $cachedResult) {
                    $this->logger->debug('Found cached extension discovery result', ['cache_key' => $cacheKey]);

                    return $this->deserializeResult($cachedResult);
                }
            }
            // Validate installation path exists
            if (!is_dir($installationPath)) {
                $this->logger->error('Extension discovery failed: Installation path does not exist', [
                    'path' => $installationPath,
                ]);

                return ExtensionDiscoveryResult::failed(
                    \sprintf('Installation path does not exist: %s', $installationPath),
                    [],
                );
            }

            $extensions = [];
            $successfulMethods = [];
            $discoveryMetadata = [];

            // Determine paths based on custom paths or defaults
            $paths = $this->resolvePaths($installationPath, $customPaths);

            // Try PackageStates.php first (legacy and modern TYPO3)
            $packageStatesData = $this->discoverFromPackageStates($installationPath, $paths);
            $discoveryMetadata[] = [
                'method' => 'PackageStates.php',
                'attempted' => true,
                'successful' => !empty($packageStatesData),
                'extensions_found' => \count($packageStatesData),
                'file_path' => $paths['package_states'],
            ];

            if (!empty($packageStatesData)) {
                $extensions = array_merge($extensions, $packageStatesData);
                $successfulMethods[] = 'PackageStates.php';
                $this->logger->info('Found extensions via PackageStates.php', ['count' => \count($packageStatesData)]);
            }

            // Try composer installed.json for composer mode installations
            $composerData = $this->discoverFromComposerInstalled($installationPath, $paths);
            $discoveryMetadata[] = [
                'method' => 'composer installed.json',
                'attempted' => true,
                'successful' => !empty($composerData),
                'extensions_found' => \count($composerData),
                'file_path' => $paths['composer_installed'],
            ];

            if (!empty($composerData)) {
                // Merge composer extensions, avoid duplicates
                $addedCount = 0;
                foreach ($composerData as $extension) {
                    $exists = false;
                    foreach ($extensions as $existingExtension) {
                        if ($existingExtension->getKey() === $extension->getKey()) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $extensions[] = $extension;
                        ++$addedCount;
                    }
                }

                if ($addedCount > 0) {
                    $successfulMethods[] = 'composer installed.json';
                    $this->logger->info('Found additional extensions via composer installed.json', ['count' => $addedCount]);
                }
            }

            $this->logger->info('Extension discovery completed', ['total_extensions' => \count($extensions)]);

            $result = ExtensionDiscoveryResult::success($extensions, $successfulMethods, $discoveryMetadata);

            // Cache the result if enabled
            if ($this->configService->isResultCacheEnabled()) {
                $cacheKey = $this->cacheService->generateKey('extension_discovery', $installationPath, [
                    'custom_paths' => $customPaths ?? [],
                ]);
                $this->cacheService->set($cacheKey, $this->serializeResult($result));
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Extension discovery failed', ['error' => $e->getMessage()]);

            return ExtensionDiscoveryResult::failed($e->getMessage(), $discoveryMetadata ?? []);
        }
    }

    /**
     * Resolve paths based on custom paths or defaults.
     *
     * @param string     $installationPath Base installation path
     * @param array|null $customPaths      Custom paths from installation metadata
     *
     * @return array<string, string> Resolved paths
     */
    private function resolvePaths(string $installationPath, ?array $customPaths): array
    {
        $vendorDir = $customPaths['vendor-dir'] ?? 'vendor';
        $webDir = $customPaths['web-dir'] ?? 'public';
        $typo3confDir = $customPaths['typo3conf-dir'] ?? $webDir . '/typo3conf';

        return [
            'package_states' => $installationPath . '/' . $typo3confDir . '/PackageStates.php',
            'composer_installed' => $installationPath . '/' . $vendorDir . '/composer/installed.json',
            'vendor_dir' => $installationPath . '/' . $vendorDir,
            'web_dir' => $installationPath . '/' . $webDir,
            'typo3conf_dir' => $installationPath . '/' . $typo3confDir,
        ];
    }

    /**
     * @return Extension[]
     */
    private function discoverFromPackageStates(string $installationPath, array $paths): array
    {
        $packageStatesPath = $paths['package_states'];

        if (!file_exists($packageStatesPath)) {
            $this->logger->debug('PackageStates.php not found', ['path' => $packageStatesPath]);

            return [];
        }

        try {
            // First try to read and parse as plain text to detect obvious syntax errors
            $fileContent = file_get_contents($packageStatesPath);
            if (false === $fileContent) {
                throw new \Exception('Could not read PackageStates.php file');
            }

            // Basic syntax validation - check for obvious PHP syntax issues
            if (!str_starts_with(trim($fileContent), '<?php')) {
                throw new \Exception('PackageStates.php does not start with PHP opening tag');
            }

            // Check for balanced brackets and basic structure
            $openBrackets = substr_count($fileContent, '[');
            $closeBrackets = substr_count($fileContent, ']');
            if ($openBrackets !== $closeBrackets) {
                throw new \Exception('PackageStates.php has unbalanced brackets');
            }

            // Try to include the file
            $packageStatesContent = include $packageStatesPath;

            if (!\is_array($packageStatesContent) || !isset($packageStatesContent['packages'])) {
                $this->logger->warning('Invalid PackageStates.php format');

                return [];
            }

            $extensions = [];

            foreach ($packageStatesContent['packages'] as $packageKey => $packageData) {
                // Skip TYPO3 core packages
                if (str_starts_with($packageKey, 'typo3/cms-')) {
                    continue;
                }

                $extension = $this->createExtensionFromPackageData($packageKey, $packageData, $installationPath, $paths);
                if (null !== $extension) {
                    $extensions[] = $extension;
                }
            }

            return $extensions;
        } catch (\Exception $e) {
            $this->logger->error('Failed to parse PackageStates.php', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return Extension[]
     */
    private function discoverFromComposerInstalled(string $installationPath, array $paths): array
    {
        $installedJsonPath = $paths['composer_installed'];

        if (!file_exists($installedJsonPath)) {
            $this->logger->debug('composer installed.json not found', ['path' => $installedJsonPath]);

            return [];
        }

        try {
            $installedContent = json_decode(file_get_contents($installedJsonPath), true, 512, JSON_THROW_ON_ERROR);

            if (!\is_array($installedContent) || !isset($installedContent['packages'])) {
                $this->logger->warning('Invalid composer installed.json format');

                return [];
            }

            $extensions = [];

            foreach ($installedContent['packages'] as $packageData) {
                // Only process TYPO3 extensions
                if (!isset($packageData['type']) || 'typo3-cms-extension' !== $packageData['type']) {
                    continue;
                }

                // Skip TYPO3 core extensions
                if (isset($packageData['name']) && str_starts_with($packageData['name'], 'typo3/cms-')) {
                    continue;
                }

                $extension = $this->createExtensionFromComposerData($packageData, $installationPath);
                if (null !== $extension) {
                    $extensions[] = $extension;
                }
            }

            return $extensions;
        } catch (\Exception $e) {
            $this->logger->error('Failed to parse composer installed.json', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function createExtensionFromPackageData(string $packageKey, array $packageData, string $installationPath, array $paths): ?Extension
    {
        try {
            $packagePath = $packageData['packagePath'] ?? null;
            if (!$packagePath) {
                return null;
            }

            // For extensions, we need to resolve paths relative to the correct base directory
            // PackageStates paths are relative to the installation root, but for composer installations
            // we need to account for the web directory
            if (str_starts_with($packagePath, 'typo3conf/')) {
                // Use resolved typo3conf directory from paths
                $typo3confDir = $paths['typo3conf_dir'];
                $extensionRelativePath = substr($packagePath, \strlen('typo3conf/'));
                $fullPath = $typo3confDir . '/' . $extensionRelativePath;
            } else {
                // Fallback to original logic for other paths
                $fullPath = $installationPath . '/' . ltrim($packagePath, '/');
            }

            // Try to read ext_emconf.php
            $emconfPath = $fullPath . '/ext_emconf.php';
            $version = '0.0.0';
            $title = $packageKey;
            $emConfiguration = [];

            if (file_exists($emconfPath)) {
                try {
                    $EM_CONF = [];
                    include $emconfPath;

                    if (isset($EM_CONF[$packageKey])) {
                        $emConfig = $EM_CONF[$packageKey];
                        $version = $emConfig['version'] ?? '0.0.0';
                        $title = $emConfig['title'] ?? $packageKey;
                        $emConfiguration = $emConfig;
                    }
                } catch (\Exception $e) {
                    // If ext_emconf.php is corrupted, we can't trust the extension
                    // This indicates a serious issue with the extension
                    throw new \RuntimeException(\sprintf('Failed to parse ext_emconf.php for extension "%s": %s', $packageKey, $e->getMessage()), 0, $e);
                }
            }

            $extension = new Extension(
                $packageKey,
                $title,
                Version::fromString($version),
                $this->determineExtensionType($fullPath),
                null, // No composer name from PackageStates
            );

            $extension->setActive('active' === $packageData['state']);
            $extension->setEmConfiguration($emConfiguration);

            return $extension;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to create extension from package data', [
                'package_key' => $packageKey,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function createExtensionFromComposerData(array $packageData, string $installationPath): ?Extension
    {
        try {
            $packageName = $packageData['name'] ?? null;
            $version = $packageData['version'] ?? '0.0.0';

            if (!$packageName) {
                return null;
            }

            // Extract extension key from composer name or extra config
            $extensionKey = $this->extractExtensionKeyFromComposerName($packageName, $packageData);

            $title = $packageData['description'] ?? $extensionKey;

            $extension = new Extension(
                $extensionKey,
                $title,
                Version::fromString($version),
                'composer',
                $packageName,
            );

            $extension->setActive(true); // Assume composer packages are active

            return $extension;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to create extension from composer data', [
                'package_name' => $packageData['name'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function extractExtensionKeyFromComposerName(string $composerName, array $packageData): string
    {
        // Check for explicit extension key in extra config
        if (isset($packageData['extra']['typo3/cms']['extension-key'])) {
            return $packageData['extra']['typo3/cms']['extension-key'];
        }

        // Extract from composer name (vendor/extension-name -> extension_name)
        $parts = explode('/', $composerName);
        if (2 === \count($parts)) {
            return str_replace('-', '_', $parts[1]);
        }

        return str_replace(['/', '-'], '_', $composerName);
    }

    private function determineExtensionType(string $extensionPath): string
    {
        // Check if it's in typo3/sysext (system extension)
        if (str_contains($extensionPath, '/typo3/sysext/')) {
            return 'system';
        }

        // Check if it's in typo3conf/ext (local extension)
        if (str_contains($extensionPath, '/typo3conf/ext/')) {
            return 'local';
        }

        // Check if it's in vendor (composer extension)
        if (str_contains($extensionPath, '/vendor/')) {
            return 'composer';
        }

        return 'local';
    }


    private function serializeResult(ExtensionDiscoveryResult $result): array
    {
        $data = $result->toArray();
        $data['cached_at'] = time();

        return $data;
    }

    private function deserializeResult(array $data): ExtensionDiscoveryResult
    {
        $this->logger->info('Using cached extension discovery result', [
            'cached_at' => $data['cached_at'] ?? 'unknown',
            'extensions_count' => \count($data['extensions'] ?? []),
        ]);

        return ExtensionDiscoveryResult::fromArray($data);
    }
}
