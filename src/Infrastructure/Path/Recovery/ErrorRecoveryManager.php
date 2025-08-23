<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Path\Recovery;

use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionMetadata;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionRequest;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionResponse;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Exception\PathResolutionException;
use Psr\Log\LoggerInterface;

/**
 * Manages error recovery for path resolution failures.
 * Implements fallback strategies and alternative resolution attempts.
 */
final class ErrorRecoveryManager
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function attemptRecovery(
        PathResolutionException $exception,
        PathResolutionRequest $request,
        float $startTime,
    ): PathResolutionResponse {
        $this->logger->info('Attempting error recovery', [
            'exception_type' => \get_class($exception),
            'error_code' => $exception->getErrorCode(),
            'retryable' => $exception->getRetryable(),
            'path_type' => $request->pathType->value,
        ]);

        // Check if the error is retryable
        if (!$exception->getRetryable()) {
            return $this->createFailureResponse($exception, $request, $startTime);
        }

        // Execute recovery strategies
        $recoveryStrategies = $exception->getRecoveryStrategies();

        foreach ($recoveryStrategies as $strategy) {
            try {
                $result = $this->executeRecoveryStrategy($strategy, $exception, $request, $startTime);
                if ($result) {
                    $this->logger->info('Error recovery successful', [
                        'strategy' => $strategy['strategy'],
                        'original_error' => $exception->getErrorCode(),
                    ]);

                    return $result;
                }
            } catch (\Exception $recoveryException) {
                $this->logger->warning('Recovery strategy failed', [
                    'strategy' => $strategy['strategy'],
                    'recovery_error' => $recoveryException->getMessage(),
                ]);
            }
        }

        // If no recovery strategies worked, try fallback paths from request
        if (!empty($request->fallbackStrategies)) {
            return $this->tryFallbackStrategies($request, $exception, $startTime);
        }

        $this->logger->error('All error recovery attempts failed', [
            'original_error' => $exception->getErrorCode(),
            'recovery_strategies_tried' => \count($recoveryStrategies),
            'fallback_strategies_tried' => \count($request->fallbackStrategies),
        ]);

        return $this->createFailureResponse($exception, $request, $startTime);
    }

    private function executeRecoveryStrategy(
        array $strategy,
        PathResolutionException $exception,
        PathResolutionRequest $request,
        float $startTime,
    ): ?PathResolutionResponse {
        $strategyName = $strategy['strategy'];
        $parameters = $strategy['parameters'] ?? [];

        return match ($strategyName) {
            'alternative_path_search' => $this->attemptAlternativePathSearch($exception, $request, $startTime, $parameters),
            'configuration_update_suggestion' => $this->suggestConfigurationUpdate($exception, $request, $startTime, $parameters),
            'fallback_to_default_paths' => $this->fallbackToDefaultPaths($exception, $request, $startTime, $parameters),
            'installation_type_detection' => $this->retryWithDetectedInstallationType($exception, $request, $startTime, $parameters),
            'custom_path_search' => $this->searchCustomPaths($exception, $request, $startTime, $parameters),
            default => null,
        };
    }

    private function attemptAlternativePathSearch(
        PathResolutionException $exception,
        PathResolutionRequest $request,
        float $startTime,
        array $parameters,
    ): ?PathResolutionResponse {
        if (!$request->extensionIdentifier) {
            return null;
        }

        $extensionKey = $request->extensionIdentifier->key;
        $alternativePaths = $this->findAlternativePaths($extensionKey, $request->installationPath);

        if (!empty($alternativePaths)) {
            $resolutionTime = microtime(true) - $startTime;
            $metadata = new PathResolutionMetadata(
                $request->pathType,
                $request->installationType,
                'error_recovery_alternative_search',
                0,
                [],
                ['error_recovery'],
                0.0,
                false,
                'alternative_path_search_recovery',
            );

            return PathResolutionResponse::notFound(
                $request->pathType,
                $metadata,
                $alternativePaths,
                ['Alternative paths found through error recovery'],
                $request->getCacheKey(),
                $resolutionTime,
            );
        }

        return null;
    }

    private function suggestConfigurationUpdate(
        PathResolutionException $exception,
        PathResolutionRequest $request,
        float $startTime,
        array $parameters,
    ): ?PathResolutionResponse {
        $suggestions = $this->generateConfigurationSuggestions($request);

        if (!empty($suggestions)) {
            $resolutionTime = microtime(true) - $startTime;
            $metadata = new PathResolutionMetadata(
                $request->pathType,
                $request->installationType,
                'error_recovery_config_suggestions',
                0,
                [],
                ['error_recovery'],
                0.0,
                false,
                'configuration_suggestions_provided',
            );

            return PathResolutionResponse::notFound(
                $request->pathType,
                $metadata,
                [],
                $suggestions,
                $request->getCacheKey(),
                $resolutionTime,
            );
        }

        return null;
    }

    private function fallbackToDefaultPaths(
        PathResolutionException $exception,
        PathResolutionRequest $request,
        float $startTime,
        array $parameters,
    ): ?PathResolutionResponse {
        $defaultStrategies = $request->pathType->getDefaultFallbackStrategies();

        foreach ($defaultStrategies as $strategyClass) {
            // This would require a strategy factory to create instances
            // For now, we'll return suggested paths based on path type
            $defaultPaths = $this->getDefaultPathsForType($request);

            if (!empty($defaultPaths)) {
                $resolutionTime = microtime(true) - $startTime;
                $metadata = new PathResolutionMetadata(
                    $request->pathType,
                    $request->installationType,
                    'error_recovery_default_fallback',
                    0,
                    [],
                    ['error_recovery'],
                    0.0,
                    false,
                    'default_paths_fallback',
                );

                return PathResolutionResponse::notFound(
                    $request->pathType,
                    $metadata,
                    $defaultPaths,
                    ['Using default fallback paths'],
                    $request->getCacheKey(),
                    $resolutionTime,
                );
            }
        }

        return null;
    }

    private function retryWithDetectedInstallationType(
        PathResolutionException $exception,
        PathResolutionRequest $request,
        float $startTime,
        array $parameters,
    ): PathResolutionResponse {
        // This would require re-running the resolution with auto-detection
        // For now, we'll suggest this as a recovery option
        $warnings = ['Consider using AUTO_DETECT installation type for automatic detection'];

        $resolutionTime = microtime(true) - $startTime;
        $metadata = new PathResolutionMetadata(
            $request->pathType,
            $request->installationType,
            'error_recovery_auto_detect_suggestion',
            0,
            [],
            ['error_recovery'],
            0.0,
            false,
            'auto_detect_installation_type_suggested',
        );

        return PathResolutionResponse::notFound(
            $request->pathType,
            $metadata,
            [],
            $warnings,
            $request->getCacheKey(),
            $resolutionTime,
        );
    }

    private function searchCustomPaths(
        PathResolutionException $exception,
        PathResolutionRequest $request,
        float $startTime,
        array $parameters,
    ): ?PathResolutionResponse {
        $customPaths = $this->searchForCustomInstallationPaths($request->installationPath);

        if (!empty($customPaths)) {
            $resolutionTime = microtime(true) - $startTime;
            $metadata = new PathResolutionMetadata(
                $request->pathType,
                $request->installationType,
                'error_recovery_custom_search',
                0,
                $customPaths,
                ['error_recovery'],
                0.0,
                false,
                'custom_path_search_recovery',
            );

            return PathResolutionResponse::notFound(
                $request->pathType,
                $metadata,
                $customPaths,
                ['Custom installation paths found'],
                $request->getCacheKey(),
                $resolutionTime,
            );
        }

        return null;
    }

    private function tryFallbackStrategies(
        PathResolutionRequest $request,
        PathResolutionException $exception,
        float $startTime,
    ): PathResolutionResponse {
        // Sort fallback strategies by priority
        $sortedStrategies = $request->fallbackStrategies;
        usort($sortedStrategies, fn ($a, $b): int => $b->priority <=> $a->priority);

        $warnings = ['Fallback strategies attempted but not implemented'];
        $resolutionTime = microtime(true) - $startTime;
        $metadata = new PathResolutionMetadata(
            $request->pathType,
            $request->installationType,
            'error_recovery_fallback_failed',
            0,
            [],
            ['error_recovery'],
            0.0,
            false,
            'fallback_strategies_not_implemented',
        );

        return PathResolutionResponse::error(
            $request->pathType,
            $metadata,
            ['Fallback strategies not yet implemented'],
            $warnings,
            $request->getCacheKey(),
            $resolutionTime,
        );
    }

    private function createFailureResponse(
        PathResolutionException $exception,
        PathResolutionRequest $request,
        float $startTime,
    ): PathResolutionResponse {
        $resolutionTime = microtime(true) - $startTime;

        $metadata = new PathResolutionMetadata(
            $request->pathType,
            $request->installationType,
            'error_recovery_failed',
            0,
            [],
            ['error_recovery'],
            0.0,
            false,
            'all_recovery_attempts_failed',
        );

        $errors = [$exception->getMessage()];
        if ($exception->getContext()) {
            $errors[] = 'Context: ' . json_encode($exception->getContext());
        }

        return PathResolutionResponse::error(
            $request->pathType,
            $metadata,
            $errors,
            ['All error recovery attempts failed'],
            $request->getCacheKey(),
            $resolutionTime,
        );
    }

    private function findAlternativePaths(string $extensionKey, string $installationPath): array
    {
        $alternatives = [];

        // Enhanced search locations including Docker, custom installations
        $searchLocations = $this->getExtensionSearchLocations($installationPath);

        foreach ($searchLocations as $location) {
            $path = $location . '/' . $extensionKey;
            if (is_dir($path)) {
                $alternatives[] = $path;

                $this->logger->debug('Alternative extension path found', [
                    'extension' => $extensionKey,
                    'path' => $path,
                    'search_location' => $location,
                ]);
            }
        }

        // Remove duplicates and sort by path length (shorter paths first)
        $alternatives = array_unique($alternatives);
        usort($alternatives, fn ($a, $b): int => \strlen($a) <=> \strlen($b));

        return $alternatives;
    }

    /**
     * Get comprehensive list of extension search locations.
     *
     * @param string $installationPath Base installation path
     *
     * @return array<string> Extension search directories
     */
    private function getExtensionSearchLocations(string $installationPath): array
    {
        $locations = [];

        // Standard Composer installations
        $standardPaths = [
            '/public/typo3conf/ext',
            '/web/typo3conf/ext', // Alternative web dir
            '/typo3conf/ext', // Legacy installations
        ];

        // Docker and custom installations
        $dockerPaths = [
            '/app/public/typo3conf/ext',
            '/var/www/html/typo3conf/ext',
            '/var/www/html/public/typo3conf/ext',
            '/htdocs/typo3conf/ext',
            '/htdocs/public/typo3conf/ext',
            '/public_html/typo3conf/ext',
        ];

        // Check standard paths first
        foreach ($standardPaths as $path) {
            $fullPath = $installationPath . $path;
            if (is_dir($fullPath)) {
                $locations[] = $fullPath;
            }
        }

        // Check Docker and custom paths
        foreach ($dockerPaths as $path) {
            $fullPath = $installationPath . $path;
            if (is_dir($fullPath)) {
                $locations[] = $fullPath;
            }
        }

        // Check composer.json for custom web-dir configuration
        $customWebDir = $this->detectCustomWebDir($installationPath);
        if ($customWebDir) {
            $customExtPath = $installationPath . '/' . $customWebDir . '/typo3conf/ext';
            if (is_dir($customExtPath)) {
                $locations[] = $customExtPath;
            }
        }

        return array_unique($locations);
    }

    /**
     * Detect custom web directory from composer.json.
     *
     * @param string $installationPath Installation path
     *
     * @return string|null Custom web directory or null
     */
    private function detectCustomWebDir(string $installationPath): ?string
    {
        $composerJsonPath = $installationPath . '/composer.json';

        if (!file_exists($composerJsonPath)) {
            return null;
        }

        try {
            $json = file_get_contents($composerJsonPath);
            if (false === $json) {
                return null;
            }

            $composerData = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            return $composerData['extra']['typo3/cms']['web-dir'] ?? null;
        } catch (\JsonException $e) {
            $this->logger->debug('Failed to parse composer.json for web-dir detection', [
                'path' => $composerJsonPath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function generateConfigurationSuggestions(PathResolutionRequest $request): array
    {
        $suggestions = [];

        // Suggest custom path configurations
        if (empty($request->pathConfiguration->customPaths)) {
            $suggestions[] = 'Consider adding custom path mappings to pathConfiguration.customPaths';
        }

        // Suggest search directories
        if (empty($request->pathConfiguration->searchDirectories)) {
            $suggestions[] = 'Consider adding search directories to pathConfiguration.searchDirectories';
        }

        // Installation type specific suggestions
        if ('custom' === $request->installationType->value) {
            $suggestions[] = 'For custom installations, ensure proper directory structure is configured';
        }

        return $suggestions;
    }

    private function getDefaultPathsForType(PathResolutionRequest $request): array
    {
        if (!$request->extensionIdentifier) {
            return $this->getDefaultDirectoryPaths($request->installationPath);
        }

        $extensionKey = $request->extensionIdentifier->key;

        return $this->findAlternativePaths($extensionKey, $request->installationPath);
    }

    /**
     * Get default directory paths for common TYPO3 directories.
     *
     * @param string $installationPath Installation path
     *
     * @return array<string> Default directory paths
     */
    private function getDefaultDirectoryPaths(string $installationPath): array
    {
        $paths = [];

        // Standard directory patterns
        $directoryPatterns = [
            // Web directories
            '/public',
            '/web',
            '/htdocs',
            '/public_html',
            '/app/public',
            '/var/www/html',
            // TYPO3 conf directories
            '/public/typo3conf',
            '/web/typo3conf',
            '/typo3conf',
            '/htdocs/typo3conf',
            '/app/public/typo3conf',
            // Vendor directories
            '/vendor',
            '/app/vendor',
        ];

        foreach ($directoryPatterns as $pattern) {
            $path = $installationPath . $pattern;
            if (is_dir($path)) {
                $paths[] = $path;
            }
        }

        // Add custom web dir based paths
        $customWebDir = $this->detectCustomWebDir($installationPath);
        if ($customWebDir) {
            $customPaths = [
                $installationPath . '/' . $customWebDir,
                $installationPath . '/' . $customWebDir . '/typo3conf',
            ];

            foreach ($customPaths as $customPath) {
                if (is_dir($customPath)) {
                    $paths[] = $customPath;
                }
            }
        }

        return array_unique($paths);
    }

    private function searchForCustomInstallationPaths(string $installationPath): array
    {
        $customPaths = [];

        // Enhanced search for custom installation patterns
        $searchPatterns = [
            // Docker container patterns
            '/app',
            '/var/www/html',
            '/usr/share/nginx/html',
            // Traditional web server patterns
            '/htdocs',
            '/public_html',
            '/www',
            // Development patterns
            '/web',
            '/public',
            '/src/public',
        ];

        foreach ($searchPatterns as $pattern) {
            $searchPath = $installationPath . $pattern;

            // Check if this path contains TYPO3 indicators
            if ($this->isTypo3Directory($searchPath)) {
                $customPaths[] = $searchPath;

                // Add related paths
                $relatedPaths = [
                    $searchPath . '/typo3conf',
                    $searchPath . '/typo3conf/ext',
                ];

                foreach ($relatedPaths as $relatedPath) {
                    if (is_dir($relatedPath)) {
                        $customPaths[] = $relatedPath;
                    }
                }
            }
        }

        // Check composer.json for custom paths
        $composerPaths = $this->getComposerBasedPaths($installationPath);
        $customPaths = array_merge($customPaths, $composerPaths);

        // Filter out non-existent paths and remove duplicates
        $customPaths = array_filter($customPaths, 'is_dir');
        $customPaths = array_unique($customPaths);

        $this->logger->debug('Custom installation paths discovered', [
            'installation_path' => $installationPath,
            'paths_found' => \count($customPaths),
            'paths' => $customPaths,
        ]);

        return array_values($customPaths);
    }

    /**
     * Check if a directory contains TYPO3 indicators.
     *
     * @param string $path Directory path to check
     *
     * @return bool True if TYPO3 indicators found
     */
    private function isTypo3Directory(string $path): bool
    {
        if (!is_dir($path)) {
            return false;
        }

        $typo3Indicators = [
            '/typo3conf',
            '/typo3',
            '/index.php', // TYPO3 main entry point
        ];

        $foundIndicators = 0;
        foreach ($typo3Indicators as $indicator) {
            if (file_exists($path . $indicator)) {
                ++$foundIndicators;
            }
        }

        // Require at least 2 indicators for confidence
        return $foundIndicators >= 2;
    }

    /**
     * Get custom paths from composer.json configuration.
     *
     * @param string $installationPath Installation path
     *
     * @return array<string> Custom paths from composer config
     */
    private function getComposerBasedPaths(string $installationPath): array
    {
        $paths = [];
        $composerJsonPath = $installationPath . '/composer.json';

        if (!file_exists($composerJsonPath)) {
            return $paths;
        }

        try {
            $json = file_get_contents($composerJsonPath);
            if (false === $json) {
                return $paths;
            }

            $composerData = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            // Check vendor-dir configuration
            if (isset($composerData['config']['vendor-dir'])) {
                $vendorDir = $installationPath . '/' . $composerData['config']['vendor-dir'];
                if (is_dir($vendorDir)) {
                    $paths[] = $vendorDir;
                }
            }

            // Check TYPO3 web-dir configuration
            if (isset($composerData['extra']['typo3/cms']['web-dir'])) {
                $webDir = $installationPath . '/' . $composerData['extra']['typo3/cms']['web-dir'];
                if (is_dir($webDir)) {
                    $paths[] = $webDir;

                    // Add related TYPO3 paths
                    $typo3Paths = [
                        $webDir . '/typo3conf',
                        $webDir . '/typo3conf/ext',
                        $webDir . '/typo3',
                    ];

                    foreach ($typo3Paths as $typo3Path) {
                        if (is_dir($typo3Path)) {
                            $paths[] = $typo3Path;
                        }
                    }
                }
            }
        } catch (\JsonException $e) {
            $this->logger->debug('Failed to parse composer.json for custom paths', [
                'path' => $composerJsonPath,
                'error' => $e->getMessage(),
            ]);
        }

        return $paths;
    }
}
