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
 * Strategy for resolving vendor directory paths by reading composer.json configuration.
 * Handles both default 'vendor' and custom vendor-dir configurations.
 */
final class VendorDirPathResolutionStrategy implements PathResolutionStrategyInterface
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

        $this->logger->debug('Resolving vendor directory path', [
            'installation_path' => $request->installationPath,
            'installation_type' => $request->installationType->value,
        ]);

        $vendorDir = $this->resolveVendorDirectory($request->installationPath, $attemptedPaths);

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

        if ($vendorDir && is_dir($vendorDir)) {
            $this->logger->info('Vendor directory resolved successfully', [
                'resolved_path' => $vendorDir,
                'resolution_time' => $resolutionTime,
            ]);

            return PathResolutionResponse::success(
                $request->pathType,
                $vendorDir,
                $metadata,
                [],
                $warnings,
                $request->getCacheKey(),
                $resolutionTime,
            );
        }

        $this->logger->warning('Vendor directory not found', [
            'attempted_paths' => $attemptedPaths,
            'installation_type' => $request->installationType->value,
        ]);

        $exception = new PathNotFoundException('Vendor directory not found');
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
        return [PathTypeEnum::VENDOR_DIR];
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
        if (PathTypeEnum::VENDOR_DIR !== $pathType) {
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
        return PathTypeEnum::VENDOR_DIR === $request->pathType
            && \in_array($request->installationType, $this->getSupportedInstallationTypes(), true);
    }

    public function getIdentifier(): string
    {
        return 'vendor_dir_path_resolution_strategy';
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

    private function resolveVendorDirectory(string $installationPath, array &$attemptedPaths): ?string
    {
        // Read composer.json to get vendor-dir configuration
        $composerJsonPath = $installationPath . '/composer.json';
        $attemptedPaths[] = $composerJsonPath;

        if (!file_exists($composerJsonPath)) {
            $this->logger->debug('composer.json not found', [
                'composer_json_path' => $composerJsonPath,
            ]);

            return null;
        }

        try {
            $composerContent = file_get_contents($composerJsonPath);
            if (false === $composerContent) {
                $this->logger->warning('Failed to read composer.json', [
                    'composer_json_path' => $composerJsonPath,
                ]);

                return null;
            }

            $composerData = json_decode($composerContent, true);
            if (!\is_array($composerData)) {
                $this->logger->warning('Invalid JSON in composer.json', [
                    'composer_json_path' => $composerJsonPath,
                    'json_error' => json_last_error_msg(),
                ]);

                return null;
            }

            // Get vendor-dir from config section, default to 'vendor'
            $vendorDirName = $composerData['config']['vendor-dir'] ?? 'vendor';
            $vendorPath = $installationPath . '/' . $vendorDirName;
            $attemptedPaths[] = $vendorPath;

            $this->logger->debug('Found vendor directory configuration', [
                'vendor_dir_name' => $vendorDirName,
                'vendor_path' => $vendorPath,
                'exists' => is_dir($vendorPath),
            ]);

            return $vendorPath;
        } catch (\Throwable $e) {
            $this->logger->warning('Error parsing composer.json', [
                'composer_json_path' => $composerJsonPath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
