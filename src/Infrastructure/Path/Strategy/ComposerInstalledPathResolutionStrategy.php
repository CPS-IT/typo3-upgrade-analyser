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
 * Strategy for resolving composer installed.json file path based on vendor directory configuration.
 * Resolves {vendor-dir}/composer/installed.json path by first determining the vendor directory.
 */
final class ComposerInstalledPathResolutionStrategy implements PathResolutionStrategyInterface
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

        $this->logger->debug('Resolving composer installed.json path', [
            'installation_path' => $request->installationPath,
            'installation_type' => $request->installationType->value,
        ]);

        $installedJsonPath = $this->resolveComposerInstalledPath($request->installationPath, $attemptedPaths);

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

        if ($installedJsonPath && file_exists($installedJsonPath)) {
            $this->logger->info('Composer installed.json resolved successfully', [
                'resolved_path' => $installedJsonPath,
                'resolution_time' => $resolutionTime,
            ]);

            return PathResolutionResponse::success(
                $request->pathType,
                $installedJsonPath,
                $metadata,
                [],
                $warnings,
                $request->getCacheKey(),
                $resolutionTime,
            );
        }

        $this->logger->warning('Composer installed.json not found', [
            'attempted_paths' => $attemptedPaths,
            'installation_type' => $request->installationType->value,
        ]);

        $exception = new PathNotFoundException('Composer installed.json not found');
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
        return [PathTypeEnum::COMPOSER_INSTALLED];
    }

    public function getSupportedInstallationTypes(): array
    {
        return [
            InstallationTypeEnum::COMPOSER_STANDARD,
            InstallationTypeEnum::COMPOSER_CUSTOM,
            InstallationTypeEnum::AUTO_DETECT,
        ];
    }

    public function getPriority(PathTypeEnum $pathType, InstallationTypeEnum $installationType): StrategyPriorityEnum
    {
        if (PathTypeEnum::COMPOSER_INSTALLED !== $pathType) {
            return StrategyPriorityEnum::LOWEST;
        }

        return match ($installationType) {
            InstallationTypeEnum::COMPOSER_STANDARD => StrategyPriorityEnum::HIGH,
            InstallationTypeEnum::COMPOSER_CUSTOM => StrategyPriorityEnum::HIGHEST,
            InstallationTypeEnum::AUTO_DETECT => StrategyPriorityEnum::NORMAL,
            default => StrategyPriorityEnum::LOWEST,
        };
    }

    public function canHandle(PathResolutionRequest $request): bool
    {
        return PathTypeEnum::COMPOSER_INSTALLED === $request->pathType
            && \in_array($request->installationType, $this->getSupportedInstallationTypes(), true);
    }

    public function getIdentifier(): string
    {
        return 'composer_installed_path_resolution_strategy';
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

    private function resolveComposerInstalledPath(string $installationPath, array &$attemptedPaths): ?string
    {
        // First, determine the vendor directory from composer.json or use defaults
        $vendorDirName = $this->getVendorDirectoryName($installationPath, $attemptedPaths);

        if ($vendorDirName) {
            // Try {vendor-dir}/composer/installed.json
            $installedJsonPath = $installationPath . '/' . $vendorDirName . '/composer/installed.json';
            $attemptedPaths[] = $installedJsonPath;

            if (file_exists($installedJsonPath)) {
                $this->logger->debug('Found composer installed.json in vendor directory', [
                    'vendor_dir_name' => $vendorDirName,
                    'installed_json_path' => $installedJsonPath,
                ]);

                return $installedJsonPath;
            }
        }

        // Fallback: try default vendor location
        $fallbackPath = $installationPath . '/vendor/composer/installed.json';
        $attemptedPaths[] = $fallbackPath;

        if (file_exists($fallbackPath)) {
            $this->logger->debug('Found composer installed.json in default vendor location', [
                'installed_json_path' => $fallbackPath,
            ]);

            return $fallbackPath;
        }

        return null;
    }

    private function getVendorDirectoryName(string $installationPath, array &$attemptedPaths): string
    {
        // Read composer.json to get vendor-dir configuration
        $composerJsonPath = $installationPath . '/composer.json';
        $attemptedPaths[] = $composerJsonPath;

        if (!file_exists($composerJsonPath)) {
            $this->logger->debug('composer.json not found, using default vendor directory', [
                'composer_json_path' => $composerJsonPath,
            ]);

            return 'vendor';
        }

        try {
            $composerContent = file_get_contents($composerJsonPath);
            if (false === $composerContent) {
                $this->logger->warning('Failed to read composer.json', [
                    'composer_json_path' => $composerJsonPath,
                ]);

                return 'vendor';
            }

            $composerData = json_decode($composerContent, true);
            if (!\is_array($composerData)) {
                $this->logger->warning('Invalid JSON in composer.json', [
                    'composer_json_path' => $composerJsonPath,
                    'json_error' => json_last_error_msg(),
                ]);

                return 'vendor';
            }

            // Get vendor-dir from config section, default to 'vendor'
            $vendorDirName = $composerData['config']['vendor-dir'] ?? 'vendor';

            $this->logger->debug('Found vendor directory configuration for installed.json resolution', [
                'vendor_dir_name' => $vendorDirName,
            ]);

            return $vendorDirName;
        } catch (\Throwable $e) {
            $this->logger->warning('Error parsing composer.json for vendor directory', [
                'composer_json_path' => $composerJsonPath,
                'error' => $e->getMessage(),
            ]);

            return 'vendor';
        }
    }
}
