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
use Psr\Log\LoggerInterface;

/**
 * Multi-layer caching implementation with memory and persistent storage.
 * Includes intelligent invalidation and performance optimization.
 */
final class MultiLayerPathResolutionCache implements PathResolutionCacheInterface
{
    private array $memoryCache = [];
    private array $cacheStats = [
        'hits' => 0,
        'misses' => 0,
        'invalidations' => 0,
        'memory_usage' => 0,
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $maxMemoryEntries = 1000,
        private readonly int $defaultTtl = 300,
    ) {
    }

    public function get(PathResolutionRequest $request): ?PathResolutionResponse
    {
        $cacheKey = $this->generateCacheKey($request);

        if (!$this->shouldCache($request)) {
            $this->logger->debug('Cache disabled for request', ['cache_key' => $cacheKey]);

            return null;
        }

        // Check memory cache first
        if (isset($this->memoryCache[$cacheKey])) {
            $cacheEntry = $this->memoryCache[$cacheKey];

            if ($this->isCacheEntryValid($cacheEntry)) {
                ++$this->cacheStats['hits'];
                ++$cacheEntry['access_count'];
                $cacheEntry['last_access'] = time();

                $this->logger->debug('Memory cache hit', [
                    'cache_key' => $cacheKey,
                    'age_seconds' => time() - $cacheEntry['timestamp'],
                ]);

                return $cacheEntry['response'];
            }
            // Remove expired entry
            unset($this->memoryCache[$cacheKey]);
            $this->logger->debug('Expired cache entry removed', ['cache_key' => $cacheKey]);
        }

        ++$this->cacheStats['misses'];

        return null;
    }

    public function put(PathResolutionRequest $request, PathResolutionResponse $response): void
    {
        $cacheKey = $this->generateCacheKey($request);

        if (!$this->shouldCache($request)) {
            return;
        }

        try {
            // Store in memory cache
            if ($request->cacheOptions->useMemoryCache) {
                $this->putInMemoryCache($cacheKey, $response);
            }

            $this->logger->debug('Cached path resolution result', [
                'cache_key' => $cacheKey,
                'memory_cached' => $request->cacheOptions->useMemoryCache,
                'status' => $response->status->value,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to cache path resolution result', [
                'cache_key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function invalidate(array $criteria): void
    {
        $invalidatedCount = 0;

        // Invalidate memory cache
        $keysToInvalidate = $this->findMatchingKeys($this->memoryCache, $criteria);
        foreach ($keysToInvalidate as $key) {
            unset($this->memoryCache[$key]);
            ++$invalidatedCount;
        }

        $this->cacheStats['invalidations'] += $invalidatedCount;

        $this->logger->info('Cache invalidation completed', [
            'criteria' => $criteria,
            'invalidated_count' => $invalidatedCount,
        ]);
    }

    public function clear(): void
    {
        $memoryCount = \count($this->memoryCache);

        $this->memoryCache = [];

        $this->logger->info('Cache cleared', [
            'memory_entries_cleared' => $memoryCount,
        ]);
    }

    public function getStats(): PathResolutionCacheStats
    {
        $memoryUsage = $this->calculateMemoryUsage();
        $this->cacheStats['memory_usage'] = $memoryUsage;

        $hits = (int) $this->cacheStats['hits'];
        $misses = (int) $this->cacheStats['misses'];
        $totalRequests = $hits + $misses;
        $hitRatio = $totalRequests > 0 ? ($hits / $totalRequests) : 0.0;

        return new PathResolutionCacheStats(
            $hits,
            $misses,
            (int) $this->cacheStats['invalidations'],
            $memoryUsage,
            \count($this->memoryCache),
            $hitRatio,
        );
    }

    public function shouldCache(PathResolutionRequest $request): bool
    {
        if (!$request->cacheOptions->enabled) {
            return false;
        }

        // Don't cache requests with custom validation rules that might change
        if (!empty($request->validationRules)) {
            return false;
        }

        // Don't cache AUTO_DETECT requests as they might have different results over time
        if ('auto_detect' === $request->installationType->value) {
            return false;
        }

        return true;
    }

    public function isValid(string $cacheKey, PathResolutionResponse $cached): bool
    {
        // Check if cached entry has expired
        if (isset($this->memoryCache[$cacheKey])) {
            $cacheEntry = $this->memoryCache[$cacheKey];

            return $this->isCacheEntryValid($cacheEntry);
        }

        // Check filesystem-based invalidation triggers
        if ($cached->isSuccess() && $cached->resolvedPath) {
            $lastModified = @filemtime($cached->resolvedPath);
            if (false === $lastModified) {
                // File was deleted
                return false;
            }

            // Compare with cache timestamp from metadata
            if ($cached->metadata->wasFromCache && isset($this->memoryCache[$cacheKey])) {
                $cacheTimestamp = $this->memoryCache[$cacheKey]['timestamp'];
                if ($lastModified > $cacheTimestamp) {
                    return false;
                }
            }
        }

        return true;
    }

    private function generateCacheKey(PathResolutionRequest $request): string
    {
        return $request->getCacheKey();
    }

    private function putInMemoryCache(string $cacheKey, PathResolutionResponse $response): void
    {
        // Implement LRU eviction if cache is full
        if (\count($this->memoryCache) >= $this->maxMemoryEntries) {
            $this->evictLeastRecentlyUsed();
        }

        $this->memoryCache[$cacheKey] = [
            'response' => $response,
            'timestamp' => time(),
            'ttl' => $response->metadata->cacheHitRatio > 0 ? $this->defaultTtl * 2 : $this->defaultTtl,
            'access_count' => 1,
            'last_access' => time(),
        ];
    }

    private function evictLeastRecentlyUsed(): void
    {
        if (empty($this->memoryCache)) {
            return;
        }

        $oldestKey = null;
        $oldestTime = time();

        foreach ($this->memoryCache as $key => $entry) {
            if ($entry['last_access'] < $oldestTime) {
                $oldestTime = $entry['last_access'];
                $oldestKey = $key;
            }
        }

        if ($oldestKey) {
            unset($this->memoryCache[$oldestKey]);
            $this->logger->debug('Evicted cache entry', ['cache_key' => $oldestKey]);
        }
    }

    private function findMatchingKeys(array $cache, array $criteria): array
    {
        $matchingKeys = [];

        foreach (array_keys($cache) as $key) {
            if ($this->keyMatchesCriteria($key, $criteria)) {
                $matchingKeys[] = $key;
            }
        }

        return $matchingKeys;
    }

    private function keyMatchesCriteria(string $key, array $criteria): bool
    {
        // Implement pattern matching based on cache key structure
        foreach ($criteria as $criterion => $value) {
            switch ($criterion) {
                case 'path_type':
                    if (!str_contains($key, "pathType:{$value}")) {
                        return false;
                    }
                    break;
                case 'installation_path':
                    if (!str_contains($key, $value)) {
                        return false;
                    }
                    break;
                case 'installation_type':
                    if (!str_contains($key, "installationType:{$value}")) {
                        return false;
                    }
                    break;
            }
        }

        return true;
    }

    private function isCacheEntryValid(array $cacheEntry): bool
    {
        $age = time() - $cacheEntry['timestamp'];

        return $age <= $cacheEntry['ttl'];
    }

    private function calculateMemoryUsage(): int
    {
        return memory_get_usage() - memory_get_usage(true);
    }
}
