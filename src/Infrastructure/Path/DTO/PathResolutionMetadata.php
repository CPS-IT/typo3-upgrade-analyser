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

use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\InstallationTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;

/**
 * Comprehensive metadata object with detailed resolution information.
 */
final readonly class PathResolutionMetadata
{
    public function __construct(
        public PathTypeEnum $pathType,
        public InstallationTypeEnum $installationType,
        public string $usedStrategy,
        public int $strategyPriority,
        public array $attemptedPaths = [],
        public array $strategyChain = [],
        public float $cacheHitRatio = 0.0,
        public bool $wasFromCache = false,
        public ?string $fallbackReason = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'pathType' => $this->pathType->value,
            'installationType' => $this->installationType->value,
            'usedStrategy' => $this->usedStrategy,
            'strategyPriority' => $this->strategyPriority,
            'attemptedPaths' => $this->attemptedPaths,
            'strategyChain' => $this->strategyChain,
            'cacheHitRatio' => $this->cacheHitRatio,
            'wasFromCache' => $this->wasFromCache,
            'fallbackReason' => $this->fallbackReason,
        ];
    }
}
