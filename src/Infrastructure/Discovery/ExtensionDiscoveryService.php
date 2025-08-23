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

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathConfiguration;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionRequest;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\InstallationTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\PathResolutionServiceInterface;
use Psr\Log\LoggerInterface;

class ExtensionDiscoveryService implements ExtensionDiscoveryServiceInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ConfigurationService $configService,
        private readonly CacheService $cacheService,
        private readonly PathResolutionServiceInterface $pathResolutionService,
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
     * Resolve paths using PathResolutionService with sophisticated TYPO3 installation detection.
     * Falls back to hardcoded logic if PathResolutionService fails to ensure backward compatibility.
     *
     * @param string     $installationPath Base installation path
     * @param array|null $customPaths      Custom paths from installation metadata
     *
     * @return array<string, string> Resolved paths
     */
    private function resolvePaths(string $installationPath, ?array $customPaths): array
    {
        try {
            // Create path configuration from custom paths
            $pathConfiguration = PathConfiguration::fromArray([
                'customPaths' => $customPaths ?? [],
                'validateExists' => false, // Don't require existence for discovery
                'followSymlinks' => true,
            ]);

            // Determine installation type based on filesystem structure
            $installationType = $this->determineInstallationType($installationPath, $customPaths);

            // Use PathResolutionService to resolve all required paths
            $resolvedPaths = [];
            $pathTypes = [
                'package_states' => PathTypeEnum::PACKAGE_STATES,
                'composer_installed' => PathTypeEnum::COMPOSER_INSTALLED,
                'vendor_dir' => PathTypeEnum::VENDOR_DIR,
                'web_dir' => PathTypeEnum::WEB_DIR,
                'typo3conf_dir' => PathTypeEnum::TYPO3CONF_DIR,
            ];

            $allSuccessful = true;
            $pathResolutionErrors = [];

            foreach ($pathTypes as $key => $pathType) {
                $request = PathResolutionRequest::create(
                    $pathType,
                    $installationPath,
                    $installationType,
                    $pathConfiguration,
                );

                $response = $this->pathResolutionService->resolvePath($request);

                if ($response->isSuccess() && $response->resolvedPath) {
                    $resolvedPaths[$key] = $response->resolvedPath;

                    $this->logger->debug('Successfully resolved path using PathResolutionService', [
                        'path_type' => $pathType->value,
                        'resolved_path' => $response->resolvedPath,
                        'installation_type' => $installationType->value,
                        'strategy_used' => $response->metadata->usedStrategy,
                    ]);
                } else {
                    $allSuccessful = false;
                    $pathResolutionErrors[$key] = [
                        'path_type' => $pathType->value,
                        'errors' => $response->errors,
                        'status' => $response->status->value,
                    ];

                    $this->logger->warning('PathResolutionService failed to resolve path', [
                        'path_type' => $pathType->value,
                        'installation_path' => $installationPath,
                        'installation_type' => $installationType->value,
                        'errors' => $response->errors,
                    ]);
                }
            }

            // If all paths were resolved successfully, return them
            if ($allSuccessful) {
                $this->logger->info('All paths successfully resolved using PathResolutionService', [
                    'installation_path' => $installationPath,
                    'installation_type' => $installationType->value,
                    'paths_resolved' => array_keys($resolvedPaths),
                ]);

                return $resolvedPaths;
            }

            // If some paths failed, log details and fall back
            $this->logger->warning('Some path resolutions failed, using fallback method', [
                'installation_path' => $installationPath,
                'successful_paths' => array_keys($resolvedPaths),
                'failed_paths' => array_keys($pathResolutionErrors),
                'path_resolution_errors' => $pathResolutionErrors,
            ]);

            // Use successful resolutions where possible, fallback for failed ones
            return $this->getFallbackPaths($installationPath, $customPaths, $resolvedPaths);
        } catch (\Throwable $e) {
            $this->logger->error('Error using PathResolutionService for discovery, falling back to legacy method', [
                'installation_path' => $installationPath,
                'error' => $e->getMessage(),
            ]);

            // Complete fallback to legacy method
            return $this->getFallbackPaths($installationPath, $customPaths);
        }
    }

    /**
     * Determine installation type based on filesystem structure and custom paths.
     */
    private function determineInstallationType(string $installationPath, ?array $customPaths): InstallationTypeEnum
    {
        // Check if it's a Composer installation
        if (file_exists($installationPath . '/composer.json')) {
            // Check for custom web directory
            $webDir = $customPaths['web-dir'] ?? null;
            if ($webDir && 'public' !== $webDir) {
                return InstallationTypeEnum::COMPOSER_CUSTOM;
            }

            // Check if public directory exists (standard Composer layout)
            if (is_dir($installationPath . '/public')) {
                return InstallationTypeEnum::COMPOSER_STANDARD;
            }

            return InstallationTypeEnum::COMPOSER_CUSTOM;
        }

        // Check for legacy source installation
        if (is_dir($installationPath . '/typo3_src') || is_dir($installationPath . '/typo3/sysext')) {
            return InstallationTypeEnum::LEGACY_SOURCE;
        }

        // Check for Docker container patterns
        if (is_dir($installationPath . '/app') || str_contains($installationPath, '/app/')) {
            return InstallationTypeEnum::DOCKER_CONTAINER;
        }

        // Default to auto-detect if unsure
        return InstallationTypeEnum::AUTO_DETECT;
    }

    /**
     * Legacy fallback method for path resolution.
     * Used when PathResolutionService fails or partially fails.
     *
     * @param array<string, string> $successfulPaths Already resolved paths from PathResolutionService
     */
    private function getFallbackPaths(string $installationPath, ?array $customPaths, array $successfulPaths = []): array
    {
        $vendorDir = $customPaths['vendor-dir'] ?? 'vendor';
        $webDir = $customPaths['web-dir'] ?? 'public';
        $typo3confDir = $customPaths['typo3conf-dir'] ?? $webDir . '/typo3conf';

        $fallbackPaths = [
            'package_states' => $installationPath . '/' . $typo3confDir . '/PackageStates.php',
            'composer_installed' => $installationPath . '/' . $vendorDir . '/composer/installed.json',
            'vendor_dir' => $installationPath . '/' . $vendorDir,
            'web_dir' => $installationPath . '/' . $webDir,
            'typo3conf_dir' => $installationPath . '/' . $typo3confDir,
        ];

        // Use successful PathResolutionService results where available
        return array_merge($fallbackPaths, $successfulPaths);
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
            $json = file_get_contents($installedJsonPath);
            if (false === $json) {
                throw new \RuntimeException('Could not read composer installed.json file');
            }
            $installedContent = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

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

                    // @phpstan-ignore isset.offset
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
