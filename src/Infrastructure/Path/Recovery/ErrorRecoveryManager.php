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

        // Search common extension locations
        $commonLocations = [
            '/public/typo3conf/ext/',
            '/web/typo3conf/ext/',
            '/typo3conf/ext/',
            '/app/public/typo3conf/ext/',
            '/htdocs/typo3conf/ext/',
        ];

        foreach ($commonLocations as $location) {
            $path = $installationPath . $location . $extensionKey;
            if (is_dir($path)) {
                $alternatives[] = $path;
            }
        }

        return $alternatives;
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
            return [];
        }

        $extensionKey = $request->extensionIdentifier->key;
        $installationPath = $request->installationPath;

        return [
            $installationPath . '/public/typo3conf/ext/' . $extensionKey,
            $installationPath . '/typo3conf/ext/' . $extensionKey,
            $installationPath . '/web/typo3conf/ext/' . $extensionKey,
        ];
    }

    private function searchForCustomInstallationPaths(string $installationPath): array
    {
        $customPaths = [];

        // Search for TYPO3 directories at different levels
        $searchPaths = [
            $installationPath,
            $installationPath . '/app',
            $installationPath . '/htdocs',
            $installationPath . '/public_html',
        ];

        foreach ($searchPaths as $searchPath) {
            if (is_dir($searchPath . '/typo3conf')) {
                $customPaths[] = $searchPath . '/typo3conf/ext';
            }
        }

        return $customPaths;
    }
}
