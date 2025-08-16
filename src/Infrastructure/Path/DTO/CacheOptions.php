<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO;

/**
 * Cache options object for fine-grained cache control.
 */
final readonly class CacheOptions
{
    public function __construct(
        public bool $enabled = true,
        public int $ttlSeconds = 300,
        public bool $useMemoryCache = true,
        public bool $usePersistentCache = false,
        public array $invalidationTriggers = [],
    ) {
    }
}
