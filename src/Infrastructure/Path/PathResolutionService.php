<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Path;

use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Cache\PathResolutionCacheInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionRequest;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionResponse;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\InstallationTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Exception\PathResolutionException;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Recovery\ErrorRecoveryManager;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\PathResolutionStrategyRegistry;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Validation\PathResolutionValidator;
use Psr\Log\LoggerInterface;

/**
 * Main service for coordinating path resolution with caching, validation, and error recovery.
 * Orchestrates the complete path resolution pipeline with performance optimization.
 */
final class PathResolutionService implements PathResolutionServiceInterface
{
    public function __construct(
        private readonly PathResolutionStrategyRegistry $strategyRegistry,
        private readonly PathResolutionValidator $validator,
        private readonly PathResolutionCacheInterface $cache,
        private readonly ErrorRecoveryManager $errorRecoveryManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function resolvePath(PathResolutionRequest $request): PathResolutionResponse
    {
        $startTime = microtime(true);

        $this->logger->debug('Starting path resolution', [
            'path_type' => $request->pathType->value,
            'installation_type' => $request->installationType->value,
            'installation_path' => $request->installationPath,
        ]);

        // 1. Early validation
        $validationResult = $this->validator->validate($request);
        if (!$validationResult->isValid()) {
            return $this->createValidationErrorResponse($request, $validationResult, $startTime);
        }

        // 2. Check cache
        if ($cachedResponse = $this->cache->get($request)) {
            $this->logger->debug('Cache hit for path resolution', [
                'cache_key' => $request->getCacheKey(),
                'resolution_time' => microtime(true) - $startTime,
            ]);

            return $cachedResponse;
        }

        try {
            // 3. Execute strategy resolution
            $strategy = $this->strategyRegistry->getStrategy($request);
            $response = $strategy->resolve($request);

            // 4. Cache successful responses and responses with alternatives
            if ($response->isSuccess() || !empty($response->alternativePaths)) {
                $this->cache->put($request, $response);
            }

            $this->logger->debug('Path resolution completed successfully', [
                'strategy_used' => $strategy::class,
                'status' => $response->status->value,
                'resolution_time' => microtime(true) - $startTime,
            ]);

            return $response;
        } catch (PathResolutionException $exception) {
            $this->logger->warning('Path resolution failed, attempting error recovery', [
                'error_code' => $exception->getErrorCode(),
                'error_message' => $exception->getMessage(),
            ]);

            // 5. Attempt error recovery
            return $this->errorRecoveryManager->attemptRecovery($exception, $request, $startTime);
        }
    }

    /**
     * @param PathResolutionRequest[] $requests
     *
     * @return PathResolutionResponse[]
     */
    public function resolveMultiplePaths(array $requests): array
    {
        if (empty($requests)) {
            return [];
        }

        $this->logger->info('Starting batch path resolution', [
            'request_count' => \count($requests),
        ]);

        // Optimize batch processing with request grouping
        return $this->processOptimizedBatch($requests);
    }

    /**
     * Process batch requests with optimization strategies.
     */
    private function processOptimizedBatch(array $requests): array
    {
        $startTime = microtime(true);

        // 1. Group requests by installation path for cache optimization
        $groupedRequests = $this->groupRequestsByInstallation($requests);

        $responses = [];
        $successCount = 0;
        $errorCount = 0;
        $cacheHitCount = 0;

        foreach ($groupedRequests as $installationPath => $group) {
            $this->logger->debug('Processing batch group', [
                'installation_path' => $installationPath,
                'group_size' => \count($group['requests']),
            ]);

            // 2. Pre-warm cache for this installation
            $this->preWarmCache($installationPath, $group['requests']);

            // 3. Process requests in the group
            foreach ($group['requests'] as $originalIndex => $request) {
                try {
                    $response = $this->resolvePath($request);
                    $responses[$originalIndex] = $response;

                    if ($response->isSuccess()) {
                        ++$successCount;
                    } else {
                        ++$errorCount;
                    }

                    if ($response->metadata->wasFromCache) {
                        ++$cacheHitCount;
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Batch resolution failed for request', [
                        'index' => $originalIndex,
                        'error' => $e->getMessage(),
                        'path_type' => $request->pathType->value,
                    ]);
                    ++$errorCount;

                    // Create error response for failed request
                    $responses[$originalIndex] = PathResolutionResponse::error(
                        $request->pathType,
                        $this->createErrorMetadata($request, 'batch_processing_error'),
                        [$e->getMessage()],
                        ['Batch processing failed for this request'],
                        $request->getCacheKey(),
                        0.0,
                    );
                }
            }
        }

        // 4. Restore original order - avoid unnecessary array copy
        ksort($responses);

        $totalTime = microtime(true) - $startTime;
        $cacheHitRatio = \count($requests) > 0 ? $cacheHitCount / \count($requests) : 0;

        $this->logger->info('Batch path resolution completed', [
            'total_requests' => \count($requests),
            'successful_resolutions' => $successCount,
            'failed_resolutions' => $errorCount,
            'cache_hit_count' => $cacheHitCount,
            'cache_hit_ratio' => round($cacheHitRatio * 100, 2) . '%',
            'installation_groups' => \count($groupedRequests),
            'total_time_seconds' => round($totalTime, 3),
            'average_time_per_request' => round($totalTime / \count($requests) * 1000, 2) . 'ms',
        ]);

        return $responses;
    }

    /**
     * Group requests by installation path to optimize cache usage and reduce redundant operations.
     */
    private function groupRequestsByInstallation(array $requests): array
    {
        $groups = [];

        foreach ($requests as $index => $request) {
            $installationPath = $request->installationPath;

            if (!isset($groups[$installationPath])) {
                $groups[$installationPath] = [
                    'requests' => [],
                    'path_types' => [],
                    'installation_type' => $request->installationType,
                ];
            }

            $groups[$installationPath]['requests'][$index] = $request;
            $groups[$installationPath]['path_types'][] = $request->pathType;
        }

        return $groups;
    }

    /**
     * Pre-warm cache for common path resolution patterns in an installation.
     */
    private function preWarmCache(string $installationPath, array $requests): void
    {
        // Check if installation paths commonly needed are already cached
        $commonPaths = [
            'typo3conf/ext',
            'public/typo3conf/ext',
            'web/typo3conf/ext',
            'typo3conf',
            'fileadmin',
        ];

        $this->logger->debug('Pre-warming cache for installation', [
            'installation_path' => $installationPath,
            'request_count' => \count($requests),
        ]);

        // This optimization can be expanded based on usage patterns
        // For now, we let the existing cache handle optimization naturally
    }

    public function supportsPathType(PathTypeEnum $pathType): bool
    {
        return $this->strategyRegistry->hasStrategyFor($pathType);
    }

    /**
     * @return PathTypeEnum[]
     */
    public function getAvailablePathTypes(InstallationTypeEnum $installationType): array
    {
        return $this->strategyRegistry->getSupportedPathTypes();
    }

    public function getResolutionCapabilities(): array
    {
        $capabilities = $this->strategyRegistry->getCapabilities();
        $cacheStats = $this->cache->getStats();

        return [
            'phase' => 2,
            'status' => 'fully_implemented',
            'supported_path_types' => $capabilities['supported_path_types'],
            'strategy_count' => $capabilities['strategy_count'],
            'cache_statistics' => $cacheStats->toArray(),
            'validation_enabled' => true,
            'error_recovery_enabled' => true,
            'batch_processing_enabled' => true,
            'description' => 'Complete path resolution service with strategy coordination, caching, validation, and error recovery',
        ];
    }

    /**
     * Create validation error response with detailed information.
     */
    private function createValidationErrorResponse(
        PathResolutionRequest $request,
        Validation\ValidationResult $validationResult,
        float $startTime,
    ): PathResolutionResponse {
        $resolutionTime = microtime(true) - $startTime;

        $metadata = $this->createErrorMetadata($request, 'validation_failed');

        return PathResolutionResponse::error(
            $request->pathType,
            $metadata,
            $validationResult->getErrors(),
            $validationResult->getWarnings(),
            $request->getCacheKey(),
            $resolutionTime,
        );
    }

    /**
     * Create error metadata for failed resolutions.
     */
    private function createErrorMetadata(PathResolutionRequest $request, string $errorContext): DTO\PathResolutionMetadata
    {
        return new DTO\PathResolutionMetadata(
            $request->pathType,
            $request->installationType,
            $errorContext,
            0,
            [],
            ['error'],
            0.0,
            false,
            $errorContext,
        );
    }
}
