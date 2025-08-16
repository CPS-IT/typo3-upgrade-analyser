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

use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionRequest;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionResponse;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\InstallationTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;

/**
 * Main service interface for path resolution operations.
 */
interface PathResolutionServiceInterface
{
    /**
     * Resolve a single path based on the provided request.
     */
    public function resolvePath(PathResolutionRequest $request): PathResolutionResponse;

    /**
     * Resolve multiple paths in batch for optimization.
     *
     * @param PathResolutionRequest[] $requests
     *
     * @return PathResolutionResponse[]
     */
    public function resolveMultiplePaths(array $requests): array;

    /**
     * Check if a path type is supported by any registered strategy.
     */
    public function supportsPathType(PathTypeEnum $pathType): bool;

    /**
     * Get available path types for a given installation type.
     *
     * @return PathTypeEnum[]
     */
    public function getAvailablePathTypes(InstallationTypeEnum $installationType): array;

    /**
     * Get service capabilities and configuration.
     */
    public function getResolutionCapabilities(): array;
}
