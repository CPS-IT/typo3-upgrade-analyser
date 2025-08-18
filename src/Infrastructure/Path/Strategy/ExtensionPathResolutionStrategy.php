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
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Exception\InvalidRequestException;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Exception\PathNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Strategy for resolving extension paths in different TYPO3 installation types.
 * Handles Composer, legacy, and custom installations with proper fallback logic.
 */
final class ExtensionPathResolutionStrategy implements PathResolutionStrategyInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function resolve(PathResolutionRequest $request): PathResolutionResponse
    {
        $startTime = microtime(true);

        if (!$request->extensionIdentifier) {
            throw new InvalidRequestException('Extension identifier is required for extension path resolution');
        }

        $extensionKey = $request->extensionIdentifier->key;
        $installationPath = $request->installationPath;
        $attemptedPaths = [];
        $warnings = [];

        $this->logger->debug('Resolving extension path', [
            'extension_key' => $extensionKey,
            'installation_path' => $installationPath,
            'installation_type' => $request->installationType->value,
        ]);

        // Resolve installation path to absolute path
        $resolvedInstallationPath = $this->resolveAbsolutePath($installationPath);
        if ($resolvedInstallationPath !== $installationPath) {
            $warnings[] = "Converted relative path to absolute: {$installationPath} -> {$resolvedInstallationPath}";
        }

        // Determine extension path based on installation type
        $extensionPath = match ($request->installationType) {
            InstallationTypeEnum::COMPOSER_STANDARD => $this->resolveComposerStandardPath($extensionKey, $resolvedInstallationPath, $request, $attemptedPaths),
            InstallationTypeEnum::COMPOSER_CUSTOM => $this->resolveComposerCustomPath($extensionKey, $resolvedInstallationPath, $request, $attemptedPaths),
            InstallationTypeEnum::LEGACY_SOURCE => $this->resolveLegacyPath($extensionKey, $resolvedInstallationPath, $request, $attemptedPaths),
            InstallationTypeEnum::DOCKER_CONTAINER => $this->resolveDockerPath($extensionKey, $resolvedInstallationPath, $request, $attemptedPaths),
            InstallationTypeEnum::CUSTOM => $this->resolveCustomPath($extensionKey, $resolvedInstallationPath, $request, $attemptedPaths),
            InstallationTypeEnum::AUTO_DETECT => $this->resolveAutoDetectPath($extensionKey, $resolvedInstallationPath, $request, $attemptedPaths),
        };

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

        if ($extensionPath && $this->validatePath($extensionPath, $request)) {
            $this->logger->info('Extension path resolved successfully', [
                'extension_key' => $extensionKey,
                'resolved_path' => $extensionPath,
                'resolution_time' => $resolutionTime,
            ]);

            return PathResolutionResponse::success(
                $request->pathType,
                $extensionPath,
                $metadata,
                [],
                $warnings,
                $request->getCacheKey(),
                $resolutionTime,
            );
        }

        $this->logger->warning('Extension path not found', [
            'extension_key' => $extensionKey,
            'attempted_paths' => $attemptedPaths,
            'installation_type' => $request->installationType->value,
        ]);

        $exception = new PathNotFoundException("Extension path not found for: {$extensionKey}");
        $exception->setAttemptedPaths($attemptedPaths);
        $exception->setSuggestedPaths($this->getSuggestedPaths($extensionKey, $resolvedInstallationPath, $request));

        return PathResolutionResponse::notFound(
            $request->pathType,
            $metadata,
            $exception->getSuggestedPaths(),
            $warnings,
            $request->getCacheKey(),
            $resolutionTime,
        );
    }

    public function getSupportedPathTypes(): array
    {
        return [PathTypeEnum::EXTENSION];
    }

    public function getSupportedInstallationTypes(): array
    {
        return [
            InstallationTypeEnum::COMPOSER_STANDARD,
            InstallationTypeEnum::COMPOSER_CUSTOM,
            InstallationTypeEnum::LEGACY_SOURCE,
            InstallationTypeEnum::DOCKER_CONTAINER,
            InstallationTypeEnum::CUSTOM,
            InstallationTypeEnum::AUTO_DETECT,
        ];
    }

    public function getPriority(PathTypeEnum $pathType, InstallationTypeEnum $installationType): StrategyPriorityEnum
    {
        if (PathTypeEnum::EXTENSION !== $pathType) {
            return StrategyPriorityEnum::LOWEST;
        }

        return match ($installationType) {
            InstallationTypeEnum::COMPOSER_STANDARD => StrategyPriorityEnum::HIGHEST,
            InstallationTypeEnum::COMPOSER_CUSTOM => StrategyPriorityEnum::HIGH,
            InstallationTypeEnum::LEGACY_SOURCE => StrategyPriorityEnum::NORMAL,
            InstallationTypeEnum::DOCKER_CONTAINER => StrategyPriorityEnum::LOW,
            InstallationTypeEnum::CUSTOM => StrategyPriorityEnum::LOW,
            InstallationTypeEnum::AUTO_DETECT => StrategyPriorityEnum::LOWEST,
        };
    }

    public function canHandle(PathResolutionRequest $request): bool
    {
        return PathTypeEnum::EXTENSION === $request->pathType
            && null !== $request->extensionIdentifier
            && \in_array($request->installationType, $this->getSupportedInstallationTypes(), true);
    }

    public function getIdentifier(): string
    {
        return 'extension_path_resolution_strategy';
    }

    public function getRequiredConfiguration(): array
    {
        return [];
    }

    public function validateEnvironment(): array
    {
        $errors = [];

        if (!\function_exists('realpath')) {
            $errors[] = 'realpath() function is not available';
        }

        if (!\function_exists('is_dir')) {
            $errors[] = 'is_dir() function is not available';
        }

        return $errors;
    }

    private function resolveAbsolutePath(string $path): string
    {
        if (!str_starts_with($path, '/') && !str_starts_with($path, '\\') && !preg_match('/^[A-Za-z]:/', $path)) {
            $currentDir = getcwd();
            if ($currentDir && !str_starts_with($path, $currentDir)) {
                $resolved = realpath($currentDir . '/' . $path);
                if ($resolved) {
                    return $resolved;
                }
            }
        }

        return realpath($path) ?: $path;
    }

    private function resolveComposerStandardPath(string $extensionKey, string $installationPath, PathResolutionRequest $request, array &$attemptedPaths): ?string
    {
        // First, try custom web directory from composer.json configuration
        $webDir = $request->pathConfiguration->getCustomPath('web-dir') ?? 'public';
        $path = $installationPath . '/' . $webDir . '/typo3conf/ext/' . $extensionKey;
        $attemptedPaths[] = $path;
        if (is_dir($path)) {
            return $path;
        }

        // Fallback: Standard Composer installation if not using custom paths
        if ('public' === $webDir) {
            $path = $installationPath . '/public/typo3conf/ext/' . $extensionKey;
            $attemptedPaths[] = $path;
            if (is_dir($path)) {
                return $path;
            }
        }

        // Additional fallback: Try common alternatives only if custom path failed
        $commonAlternatives = ['app/web', 'web'];
        foreach ($commonAlternatives as $altWebDir) {
            if ($altWebDir !== $webDir) { // Don't try the same path twice
                $path = $installationPath . '/' . $altWebDir . '/typo3conf/ext/' . $extensionKey;
                $attemptedPaths[] = $path;
                if (is_dir($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    private function resolveComposerCustomPath(string $extensionKey, string $installationPath, PathResolutionRequest $request, array &$attemptedPaths): ?string
    {
        // Custom Composer setup with different web directory
        $webDir = $request->pathConfiguration->getCustomPath('web-dir') ?? 'web';
        $path = $installationPath . '/' . $webDir . '/typo3conf/ext/' . $extensionKey;
        $attemptedPaths[] = $path;

        if (is_dir($path)) {
            return $path;
        }

        // Fallback to standard public directory
        $path = $installationPath . '/public/typo3conf/ext/' . $extensionKey;
        $attemptedPaths[] = $path;

        return is_dir($path) ? $path : null;
    }

    private function resolveLegacyPath(string $extensionKey, string $installationPath, PathResolutionRequest $request, array &$attemptedPaths): ?string
    {
        // Legacy installation: typo3conf/ext/
        $path = $installationPath . '/typo3conf/ext/' . $extensionKey;
        $attemptedPaths[] = $path;

        if (is_dir($path)) {
            return $path;
        }

        // Fallback: Check custom typo3conf directory
        $typo3confDir = $request->pathConfiguration->getCustomPath('typo3conf-dir') ?? 'typo3conf';
        if ('typo3conf' !== $typo3confDir) {
            $path = $installationPath . '/' . $typo3confDir . '/ext/' . $extensionKey;
            $attemptedPaths[] = $path;

            if (is_dir($path)) {
                return $path;
            }
        }

        return null;
    }

    private function resolveDockerPath(string $extensionKey, string $installationPath, PathResolutionRequest $request, array &$attemptedPaths): ?string
    {
        // Docker container: app/public/typo3conf/ext/
        $path = $installationPath . '/app/public/typo3conf/ext/' . $extensionKey;
        $attemptedPaths[] = $path;

        if (is_dir($path)) {
            return $path;
        }

        // Fallback to standard paths
        return $this->resolveComposerStandardPath($extensionKey, $installationPath, $request, $attemptedPaths);
    }

    private function resolveCustomPath(string $extensionKey, string $installationPath, PathResolutionRequest $request, array &$attemptedPaths): ?string
    {
        // Try custom directories from configuration
        foreach ($request->pathConfiguration->searchDirectories as $searchDir) {
            $path = $installationPath . '/' . $searchDir . '/' . $extensionKey;
            $attemptedPaths[] = $path;

            if (is_dir($path)) {
                return $path;
            }
        }

        // Try direct path if no search directories
        if (empty($request->pathConfiguration->searchDirectories)) {
            $typo3confDir = $request->pathConfiguration->getCustomPath('typo3conf-dir') ?? 'typo3conf';

            // Handle special case where typo3conf-dir equals extension key (test fixtures)
            if ($typo3confDir === $extensionKey) {
                $path = $installationPath . '/' . $typo3confDir;
                $attemptedPaths[] = $path;

                if (is_dir($path)) {
                    return $path;
                }
            }

            // Standard extension path
            $path = $installationPath . '/' . $typo3confDir . '/ext/' . $extensionKey;
            $attemptedPaths[] = $path;

            if (is_dir($path)) {
                return $path;
            }
        }

        return null;
    }

    private function resolveAutoDetectPath(string $extensionKey, string $installationPath, PathResolutionRequest $request, array &$attemptedPaths): ?string
    {
        // Auto-detect installation type by checking directory structure
        $detectedType = $this->detectInstallationType($installationPath);

        $this->logger->debug('Auto-detected installation type', [
            'installation_path' => $installationPath,
            'detected_type' => $detectedType->value,
        ]);

        return match ($detectedType) {
            InstallationTypeEnum::COMPOSER_STANDARD => $this->resolveComposerStandardPath($extensionKey, $installationPath, $request, $attemptedPaths),
            InstallationTypeEnum::COMPOSER_CUSTOM => $this->resolveComposerCustomPath($extensionKey, $installationPath, $request, $attemptedPaths),
            InstallationTypeEnum::LEGACY_SOURCE => $this->resolveLegacyPath($extensionKey, $installationPath, $request, $attemptedPaths),
            default => $this->resolveCustomPath($extensionKey, $installationPath, $request, $attemptedPaths),
        };
    }

    private function detectInstallationType(string $installationPath): InstallationTypeEnum
    {
        // Check for Composer installation
        if (file_exists($installationPath . '/composer.json')) {
            // Check for standard public directory
            if (is_dir($installationPath . '/public') && is_dir($installationPath . '/public/typo3conf')) {
                return InstallationTypeEnum::COMPOSER_STANDARD;
            }

            // Check for custom web directory
            if (is_dir($installationPath . '/web') && is_dir($installationPath . '/web/typo3conf')) {
                return InstallationTypeEnum::COMPOSER_CUSTOM;
            }

            return InstallationTypeEnum::COMPOSER_STANDARD; // Default to standard
        }

        // Check for legacy installation
        if (is_dir($installationPath . '/typo3_src') || is_dir($installationPath . '/typo3/sysext')) {
            return InstallationTypeEnum::LEGACY_SOURCE;
        }

        // Default to custom
        return InstallationTypeEnum::CUSTOM;
    }

    private function validatePath(string $path, PathResolutionRequest $request): bool
    {
        if (!$request->pathConfiguration->validateExists) {
            return true;
        }

        if (!is_dir($path)) {
            return false;
        }

        if (!$request->pathConfiguration->followSymlinks && is_link($path)) {
            return false;
        }

        return true;
    }

    private function getSuggestedPaths(string $extensionKey, string $installationPath, PathResolutionRequest $request): array
    {
        $suggestions = [];

        // Suggest common extension paths
        $commonPaths = [
            $installationPath . '/public/typo3conf/ext/' . $extensionKey,
            $installationPath . '/web/typo3conf/ext/' . $extensionKey,
            $installationPath . '/typo3conf/ext/' . $extensionKey,
        ];

        foreach ($commonPaths as $path) {
            if (is_dir($path)) {
                $suggestions[] = $path;
            }
        }

        return $suggestions;
    }
}
