<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy;

use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionMetadata;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionRequest;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionResponse;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\InstallationTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\StrategyPriorityEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Exception\PathNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Strategy for resolving PackageStates.php file path based on typo3conf directory configuration.
 * Resolves {typo3conf-dir}/PackageStates.php path by first determining the typo3conf directory.
 */
final class PackageStatesPathResolutionStrategy implements PathResolutionStrategyInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function resolve(PathResolutionRequest $request): PathResolutionResponse
    {
        $startTime = microtime(true);
        $attemptedPaths = [];
        $warnings = [];
        $errors = [];

        // Validation: PackageStates.php must be requested as a file, not a directory
        if (!is_dir($request->installationPath)) {
            $errors[] = 'Installation path must be a directory for path type: package_states';
        }

        $this->logger->debug('Resolving PackageStates.php path', [
            'installation_path' => $request->installationPath,
            'installation_type' => $request->installationType->value,
        ]);

        $packageStatesPath = null;
        if (empty($errors)) {
            $packageStatesPath = $this->resolvePackageStatesPath($request->installationPath, $attemptedPaths);
        }

        $resolutionTime = microtime(true) - $startTime;
        $metadata = new PathResolutionMetadata(
            $request->pathType,
            $request->installationType,
            $this->getIdentifier(),
            $this->getPriority($request->pathType, $request->installationType)->value,
            $attemptedPaths,
            [$this->getIdentifier()],
            0.0,
            false,
            null,
        );

        if (!empty($errors)) {
            $this->logger->warning('PackageStates.php resolution failed due to validation errors', [
                'errors' => $errors,
                'installation_type' => $request->installationType->value,
            ]);

            return PathResolutionResponse::error(
                $request->pathType,
                $metadata,
                $errors,
                $warnings,
                $request->getCacheKey(),
                $resolutionTime,
            );
        }

        if ($packageStatesPath && file_exists($packageStatesPath)) {
            $this->logger->info('PackageStates.php resolved successfully', [
                'resolved_path' => $packageStatesPath,
                'resolution_time' => $resolutionTime,
            ]);

            return PathResolutionResponse::success(
                $request->pathType,
                $packageStatesPath,
                $metadata,
                [],
                $warnings,
                $request->getCacheKey(),
                $resolutionTime,
            );
        }

        $this->logger->warning('PackageStates.php not found', [
            'attempted_paths' => $attemptedPaths,
            'installation_type' => $request->installationType->value,
        ]);

        $exception = new PathNotFoundException('PackageStates.php not found');
        $exception->setAttemptedPaths($attemptedPaths);

        return PathResolutionResponse::notFound(
            $request->pathType,
            $metadata,
            [],
            $warnings,
            $request->getCacheKey(),
            $resolutionTime,
        );
    }

    public function getSupportedPathTypes(): array
    {
        return [PathTypeEnum::PACKAGE_STATES];
    }

    public function getSupportedInstallationTypes(): array
    {
        return [
            InstallationTypeEnum::COMPOSER_STANDARD,
            InstallationTypeEnum::COMPOSER_CUSTOM,
            InstallationTypeEnum::LEGACY_SOURCE,
            InstallationTypeEnum::AUTO_DETECT,
        ];
    }

    public function getPriority(PathTypeEnum $pathType, InstallationTypeEnum $installationType): StrategyPriorityEnum
    {
        if (PathTypeEnum::PACKAGE_STATES !== $pathType) {
            return StrategyPriorityEnum::LOWEST;
        }

        return match ($installationType) {
            InstallationTypeEnum::COMPOSER_STANDARD => StrategyPriorityEnum::HIGH,
            InstallationTypeEnum::COMPOSER_CUSTOM => StrategyPriorityEnum::HIGHEST,
            InstallationTypeEnum::LEGACY_SOURCE => StrategyPriorityEnum::HIGH,
            InstallationTypeEnum::AUTO_DETECT => StrategyPriorityEnum::NORMAL,
            default => StrategyPriorityEnum::LOWEST,
        };
    }

    public function canHandle(PathResolutionRequest $request): bool
    {
        return PathTypeEnum::PACKAGE_STATES === $request->pathType
            && \in_array($request->installationType, $this->getSupportedInstallationTypes(), true);
    }

    public function getIdentifier(): string
    {
        return 'package_states_path_resolution_strategy';
    }

    public function getRequiredConfiguration(): array
    {
        return [];
    }

    public function validateEnvironment(): array
    {
        $errors = [];

        if (!\function_exists('file_exists')) {
            $errors[] = 'file_exists() function is not available';
        }

        if (!\function_exists('is_dir')) {
            $errors[] = 'is_dir() function is not available';
        }

        return $errors;
    }

    private function resolvePackageStatesPath(string $installationPath, array &$attemptedPaths): ?string
    {
        // First, determine the typo3conf directory from composer.json or use defaults
        $typo3confDir = $this->getTypo3ConfDirectory($installationPath, $attemptedPaths);

        if ($typo3confDir && is_dir($typo3confDir)) {
            // Try {typo3conf-dir}/PackageStates.php
            $packageStatesPath = $typo3confDir . '/PackageStates.php';
            $attemptedPaths[] = $packageStatesPath;

            if (file_exists($packageStatesPath)) {
                $this->logger->debug('Found PackageStates.php in typo3conf directory', [
                    'typo3conf_dir' => $typo3confDir,
                    'package_states_path' => $packageStatesPath,
                ]);

                return $packageStatesPath;
            }
        }

        // Fallback: try common PackageStates.php locations
        $fallbackPaths = [
            $installationPath . '/typo3conf/PackageStates.php', // Legacy installation
        ];

        foreach ($fallbackPaths as $fallbackPath) {
            $attemptedPaths[] = $fallbackPath;
            if (file_exists($fallbackPath)) {
                $this->logger->debug('Found PackageStates.php in fallback location', [
                    'package_states_path' => $fallbackPath,
                ]);

                return $fallbackPath;
            }
        }

        return null;
    }

    private function getTypo3ConfDirectory(string $installationPath, array &$attemptedPaths): ?string
    {
        // First, determine the web directory from composer.json or use defaults
        $webDirName = $this->getWebDirectoryName($installationPath, $attemptedPaths);

        if ($webDirName) {
            // Try {web-dir}/typo3conf
            $typo3confPath = $installationPath . '/' . $webDirName . '/typo3conf';
            $attemptedPaths[] = $typo3confPath;

            if (is_dir($typo3confPath)) {
                $this->logger->debug('Found typo3conf directory in web directory for PackageStates resolution', [
                    'web_dir_name' => $webDirName,
                    'typo3conf_path' => $typo3confPath,
                ]);

                return $typo3confPath;
            }
        }

        // Fallback: try legacy typo3conf location
        $legacyTypo3confPath = $installationPath . '/typo3conf';
        $attemptedPaths[] = $legacyTypo3confPath;

        if (is_dir($legacyTypo3confPath)) {
            $this->logger->debug('Found typo3conf directory in legacy location for PackageStates resolution', [
                'typo3conf_path' => $legacyTypo3confPath,
            ]);

            return $legacyTypo3confPath;
        }

        return null;
    }

    private function getWebDirectoryName(string $installationPath, array &$attemptedPaths): string
    {
        // Read composer.json to get web-dir configuration
        $composerJsonPath = $installationPath . '/composer.json';
        $attemptedPaths[] = $composerJsonPath;

        if (!file_exists($composerJsonPath)) {
            $this->logger->debug('composer.json not found, using default web directories for PackageStates', [
                'composer_json_path' => $composerJsonPath,
            ]);

            return 'public';
        }

        try {
            $composerContent = file_get_contents($composerJsonPath);
            if (false === $composerContent) {
                $this->logger->warning('Failed to read composer.json for PackageStates', [
                    'composer_json_path' => $composerJsonPath,
                ]);

                return 'public';
            }

            $composerData = json_decode($composerContent, true);
            if (!\is_array($composerData)) {
                $this->logger->warning('Invalid JSON in composer.json for PackageStates', [
                    'composer_json_path' => $composerJsonPath,
                    'json_error' => json_last_error_msg(),
                ]);

                return 'public';
            }

            // Get web-dir from extra.typo3/cms section, default to 'public'
            $webDirName = $composerData['extra']['typo3/cms']['web-dir'] ?? 'public';

            $this->logger->debug('Found web directory configuration for PackageStates resolution', [
                'web_dir_name' => $webDirName,
            ]);

            return $webDirName;
        } catch (\Throwable $e) {
            $this->logger->warning('Error parsing composer.json for web directory (PackageStates)', [
                'composer_json_path' => $composerJsonPath,
                'error' => $e->getMessage(),
            ]);

            return 'public';
        }
    }
}
