<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy;

use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionRequest;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionResponse;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\InstallationTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\StrategyPriorityEnum;

/**
 * Enhanced strategy interface with priority system and capabilities.
 */
interface PathResolutionStrategyInterface
{
    /**
     * Resolve path according to this strategy.
     */
    public function resolve(PathResolutionRequest $request): PathResolutionResponse;

    /**
     * Get supported path types for this strategy.
     *
     * @return PathTypeEnum[]
     */
    public function getSupportedPathTypes(): array;

    /**
     * Get supported installation types for this strategy.
     *
     * @return InstallationTypeEnum[]
     */
    public function getSupportedInstallationTypes(): array;

    /**
     * Get strategy priority for given path and installation type.
     */
    public function getPriority(
        PathTypeEnum $pathType,
        InstallationTypeEnum $installationType,
    ): StrategyPriorityEnum;

    /**
     * Check if strategy can handle the specific request.
     */
    public function canHandle(PathResolutionRequest $request): bool;

    /**
     * Get strategy identifier for logging and debugging.
     */
    public function getIdentifier(): string;

    /**
     * Get strategy configuration requirements.
     */
    public function getRequiredConfiguration(): array;

    /**
     * Validate that strategy can operate with current system state.
     */
    public function validateEnvironment(): array;
}
