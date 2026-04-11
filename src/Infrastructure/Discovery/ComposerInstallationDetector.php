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
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Installation\InstallationMetadata;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Installation\InstallationMode;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\DTO\VersionProfile;
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
final readonly class ComposerInstallationDetector implements DetectionStrategyInterface
{
    private const array REQUIRED_COMPOSER_FILES = ['composer.json'];
    private const array TYPO3_CORE_PACKAGES = [
        'typo3/cms-core',
        'typo3/cms',
        'typo3/minimal',
    ];

    public function __construct(
        private readonly VersionExtractor $versionExtractor,
        private readonly PathResolutionServiceInterface $pathResolutionService,
        private readonly LoggerInterface $logger,
        private readonly VersionProfileRegistry $versionProfileRegistry,
    ) {
    }

    public function detect(string $path): ?Installation
    {
        $this->logger->debug('Starting Composer installation detection', ['path' => $path]);

        $composerData = $this->parseComposerJson($path);

        if (!$this->supportsInternal($path, $composerData)) {
            $this->logger->debug('Path not supported by Composer detector', ['path' => $path]);

            return null;
        }

        // supportsInternal() returning true guarantees composerData is non-null:
        // hasTypo3Packages() is only reached when composerData is not null.
        if (null === $composerData) {
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
            $metadata = $this->createInstallationMetadata($path, $version, $composerData);

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
        } catch (\RuntimeException $e) {
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
        $composerData = $this->parseComposerJson($path);

        return $this->supportsInternal($path, $composerData);
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
     * Read and decode composer.json once; returns null on missing, unreadable, or invalid file.
     *
     * @return array<mixed>|null
     */
    private function parseComposerJson(string $path): ?array
    {
        $composerJsonPath = $path . '/composer.json';

        if (!file_exists($composerJsonPath)) {
            return null;
        }

        $json = file_get_contents($composerJsonPath);
        if (false === $json) {
            $this->logger->warning('Failed to read composer.json', ['path' => $composerJsonPath]);

            return null;
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            return \is_array($data) ? $data : null;
        } catch (\JsonException $e) {
            $this->logger->warning('Failed to parse composer.json', [
                'path' => $composerJsonPath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Internal supports check using pre-parsed composer data.
     *
     * @param array<mixed>|null $composerData
     */
    private function supportsInternal(string $path, ?array $composerData): bool
    {
        if (!is_dir($path)) {
            return false;
        }

        // null composerData means composer.json is missing, unreadable, or invalid
        if (null === $composerData) {
            return false;
        }

        return $this->hasTypo3Packages($composerData);
    }

    /**
     * Check if the pre-parsed composer.json contains TYPO3 packages.
     *
     * @param array<mixed> $composerData
     */
    private function hasTypo3Packages(array $composerData): bool
    {
        $require = \is_array($composerData['require'] ?? null) ? $composerData['require'] : [];
        $requireDev = \is_array($composerData['require-dev'] ?? null) ? $composerData['require-dev'] : [];
        $requirements = array_merge($require, $requireDev);

        foreach (self::TYPO3_CORE_PACKAGES as $package) {
            if (isset($requirements[$package])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create installation metadata from discovered information.
     *
     * @param string       $path         Installation path
     * @param array<mixed> $composerData Pre-parsed composer.json data
     *
     * @return InstallationMetadata Metadata object
     */
    private function createInstallationMetadata(string $path, Version $version, array $composerData): InstallationMetadata
    {
        $phpVersions = $this->detectPhpVersions($composerData);
        $webDir = $this->getWebDirectoryForSupportsCheck($composerData);
        $databaseConfig = $this->detectDatabaseConfig($path, $version);
        $enabledFeatures = $this->detectEnabledFeatures($path, $webDir, $version);
        $customPaths = $this->detectCustomPaths($path, $version, $composerData, $webDir);
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
     * Detect PHP version requirements from pre-parsed composer.json data.
     *
     * @param array<mixed> $composerData
     *
     * @return array<string> PHP versions
     */
    private function detectPhpVersions(array $composerData): array
    {
        if (isset($composerData['require']['php']) && \is_string($composerData['require']['php'])) {
            return [$composerData['require']['php']];
        }

        return [];
    }

    /**
     * Validate and return the web-dir from composer.json extra config.
     *
     * Rejects absolute paths, path traversal sequences, and empty strings.
     * Falls back to 'public' on any invalid value.
     *
     * @param array<mixed> $composerData Pre-parsed composer.json data
     */
    private function getWebDirectoryForSupportsCheck(array $composerData): string
    {
        $webDir = $composerData['extra']['typo3/cms']['web-dir'] ?? null;

        if (!\is_string($webDir) || '' === $webDir) {
            return 'public';
        }

        if (str_contains($webDir, "\x00")) {
            $this->logger->warning('Invalid web-dir: null byte not allowed, falling back to public', ['web-dir' => $webDir]);

            return 'public';
        }

        if ('/' === $webDir[0] || '\\' === $webDir[0]) {
            $this->logger->warning('Invalid web-dir: absolute path not allowed, falling back to public', ['web-dir' => $webDir]);

            return 'public';
        }

        if (1 === preg_match('/^[a-zA-Z]:[\/\\\\]/', $webDir)) {
            $this->logger->warning('Invalid web-dir: Windows drive-letter path not allowed, falling back to public', ['web-dir' => $webDir]);

            return 'public';
        }

        if (str_contains($webDir, '..')) {
            $this->logger->warning('Invalid web-dir: path traversal not allowed, falling back to public', ['web-dir' => $webDir]);

            return 'public';
        }

        return rtrim($webDir, '/\\');
    }

    /**
     * Detect database configuration from TYPO3 configuration.
     *
     * Uses version-aware paths: config/system/settings.php for v12+,
     * typo3conf/LocalConfiguration.php for v11.
     *
     * @param string  $path    Installation path
     * @param Version $version Detected TYPO3 version
     *
     * @return array<string, mixed> Database configuration
     */
    private function detectDatabaseConfig(string $path, Version $version): array
    {
        // Full configuration parsing will be implemented in Phase 3
        $config = [];

        if ($version->getMajor() >= 12) {
            $localConfigPath = $path . '/config/system/settings.php';
            $additionalConfigPath = $path . '/config/system/additional.php';
        } else {
            $localConfigPath = $path . '/typo3conf/LocalConfiguration.php';
            $additionalConfigPath = $path . '/typo3conf/AdditionalConfiguration.php';
        }

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
     * Uses the resolved web-dir and version-aware paths: {webDir}/typo3conf/ext
     * for v12+, typo3conf/ext at the installation root for v11.
     *
     * @param string  $path    Installation path
     * @param string  $webDir  Validated web directory (e.g. 'public')
     * @param Version $version Detected TYPO3 version
     *
     * @return array<string> Enabled features
     */
    private function detectEnabledFeatures(string $path, string $webDir, Version $version): array
    {
        $features = [];

        $extDir = $version->getMajor() >= 12
            ? $path . '/' . $webDir . '/typo3conf/ext'
            : $path . '/typo3conf/ext';

        if (is_dir($extDir)) {
            $features[] = 'extensions';
        }

        if (is_dir($path . '/config/sites')) {
            $features[] = 'site_configuration';
        }

        $settingsFile = $version->getMajor() >= 12
            ? $path . '/config/system/settings.php'
            : $path . '/typo3conf/LocalConfiguration.php';

        if (file_exists($settingsFile)) {
            $features[] = 'system_configuration';
        }

        return $features;
    }

    /**
     * Detect custom paths in TYPO3 installation using PathResolutionService.
     *
     * @param string       $path         Installation path
     * @param Version      $version      TYPO3 version for version-specific path detection
     * @param array<mixed> $composerData Pre-parsed composer.json data
     *
     * @return array<string, string> Custom paths
     */
    private function detectCustomPaths(string $path, Version $version, array $composerData, string $webDir): array
    {
        $installationType = $this->determineInstallationType($composerData, $webDir);
        $pathConfiguration = PathConfiguration::createDefault();

        $pathTypes = [
            'vendor-dir' => PathTypeEnum::VENDOR_DIR,
            'web-dir' => PathTypeEnum::WEB_DIR,
            'typo3conf-dir' => PathTypeEnum::TYPO3CONF_DIR,
        ];

        $defaultProfile = $this->getDefaultProfile($version);
        $resolvedPaths = [
            'vendor-dir' => $defaultProfile->defaultVendorDir,
            'web-dir' => $defaultProfile->defaultWebDir,
            'typo3conf-dir' => $defaultProfile->defaultWebDir . '/typo3conf',
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
     * Determine installation type based on pre-parsed composer.json data and directory structure.
     *
     * @param array<mixed> $composerData Pre-parsed composer.json data
     *
     * @return InstallationTypeEnum Installation type
     */
    private function determineInstallationType(array $composerData, string $validatedWebDir): InstallationTypeEnum
    {
        // If the validated web-dir differs from the default 'public', it's a custom installation
        if ('public' !== $validatedWebDir) {
            return InstallationTypeEnum::COMPOSER_CUSTOM;
        }

        // Check for custom vendor directory
        if (
            isset($composerData['config']['vendor-dir'])
            && \is_string($composerData['config']['vendor-dir'])
            && 'vendor' !== $composerData['config']['vendor-dir']
        ) {
            return InstallationTypeEnum::COMPOSER_CUSTOM;
        }

        return InstallationTypeEnum::COMPOSER_STANDARD;
    }

    private function getDefaultProfile(Version $version): VersionProfile
    {
        $majorVersion = $version->getMajor();
        $supportedVersions = $this->versionProfileRegistry->getSupportedVersions();

        if ([] === $supportedVersions) {
            throw new \LogicException('VersionProfileRegistry has no supported versions configured.');
        }

        if (\in_array($majorVersion, $supportedVersions, true)) {
            return $this->versionProfileRegistry->getProfile($majorVersion);
        }

        $this->logger->warning('Detected TYPO3 major version not in supported profile list, falling back to default profile', [
            'detected_version' => $majorVersion,
            'supported_versions' => $supportedVersions,
        ]);

        return $this->versionProfileRegistry->getProfile($supportedVersions[0]);
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
