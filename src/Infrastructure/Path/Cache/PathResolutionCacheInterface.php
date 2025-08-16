<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Path\Cache;

use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionRequest;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionResponse;

/**
 * Interface for path resolution caching with comprehensive cache management.
 */
interface PathResolutionCacheInterface
{
    /**
     * Get cached resolution result if available and valid.
     */
    public function get(PathResolutionRequest $request): ?PathResolutionResponse;

    /**
     * Store resolution result in cache.
     */
    public function put(PathResolutionRequest $request, PathResolutionResponse $response): void;

    /**
     * Invalidate cache entries matching criteria.
     */
    public function invalidate(array $criteria): void;

    /**
     * Clear all cache entries.
     */
    public function clear(): void;

    /**
     * Get cache statistics and performance metrics.
     */
    public function getStats(): PathResolutionCacheStats;

    /**
     * Check if request should be cached based on configuration.
     */
    public function shouldCache(PathResolutionRequest $request): bool;

    /**
     * Check if cached result is still valid.
     */
    public function isValid(string $cacheKey, PathResolutionResponse $cached): bool;
}
