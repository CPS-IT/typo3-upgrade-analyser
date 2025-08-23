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

/**
 * Cache statistics object for monitoring and optimization.
 */
final readonly class PathResolutionCacheStats
{
    public function __construct(
        public int $hits,
        public int $misses,
        public int $invalidations,
        public int $memoryUsage,
        public int $memoryEntries,
        public float $hitRatio,
    ) {
    }

    public function toArray(): array
    {
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'invalidations' => $this->invalidations,
            'memory_usage' => $this->memoryUsage,
            'memory_entries' => $this->memoryEntries,
            'hit_ratio' => $this->hitRatio,
        ];
    }
}
