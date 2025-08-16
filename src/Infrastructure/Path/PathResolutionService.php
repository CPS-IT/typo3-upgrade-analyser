<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 project - inspiring people to share!
 * (c) 2025 Dirk Wenzel, d.wenzel@cps-it.de, https://d-wenzel.com
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Path;

use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionMetadata;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionRequest;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionResponse;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\InstallationTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\PathResolutionStrategyInterface;
use Psr\Log\LoggerInterface;

/**
 * Main service implementation for path resolution operations.
 *
 * This is a minimal placeholder implementation for Phase 1. The complete
 * implementation with strategy resolution will be added in Phase 2.
 */
final class PathResolutionService implements PathResolutionServiceInterface
{
    /**
     * @param iterable<PathResolutionStrategyInterface> $strategies
     */
    public function __construct(
        private readonly iterable $strategies,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function resolvePath(PathResolutionRequest $request): PathResolutionResponse
    {
        // Phase 1: Minimal placeholder implementation
        // Complete strategy-based resolution will be implemented in Phase 2

        $this->logger->warning('PathResolutionService: Using placeholder implementation - full strategy resolution not yet implemented');

        $metadata = new PathResolutionMetadata(
            $request->pathType,
            $request->installationType,
            'placeholder_strategy',
            50,
            [],
            ['placeholder_strategy'],
        );

        return PathResolutionResponse::notFound(
            $request->pathType,
            $metadata,
            [],
            ['Path resolution service is not fully implemented yet (Phase 1 placeholder)'],
        );
    }

    /**
     * @param PathResolutionRequest[] $requests
     *
     * @return PathResolutionResponse[]
     */
    public function resolveMultiplePaths(array $requests): array
    {
        $responses = [];
        foreach ($requests as $request) {
            $responses[] = $this->resolvePath($request);
        }

        return $responses;
    }

    public function supportsPathType(PathTypeEnum $pathType): bool
    {
        // Phase 1: Always return false as no strategies are implemented yet
        return false;
    }

    /**
     * @return PathTypeEnum[]
     */
    public function getAvailablePathTypes(InstallationTypeEnum $installationType): array
    {
        // Phase 1: Return empty array as no strategies are implemented yet
        return [];
    }

    public function getResolutionCapabilities(): array
    {
        $strategyCount = is_countable($this->strategies) ? \count($this->strategies) : 0;

        return [
            'phase' => 1,
            'status' => 'placeholder_implementation',
            'supported_path_types' => [],
            'available_strategies' => $strategyCount,
            'description' => 'Phase 1 placeholder - full implementation pending',
        ];
    }
}
