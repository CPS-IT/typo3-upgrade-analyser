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
 * Immutable data transfer object for path resolution requests.
 * Replaces mixed types with strongly typed, validated objects.
 */
final readonly class PathResolutionRequest
{
    private function __construct(
        public PathTypeEnum $pathType,
        public string $installationPath,
        public InstallationTypeEnum $installationType,
        public PathConfiguration $pathConfiguration,
        public ?ExtensionIdentifier $extensionIdentifier = null,
        public array $validationRules = [],
        public array $fallbackStrategies = [],
        public CacheOptions $cacheOptions = new CacheOptions(),
    ) {
    }

    public static function builder(): PathResolutionRequestBuilder
    {
        return new PathResolutionRequestBuilder();
    }

    public function getCacheKey(): string
    {
        return \sprintf(
            'path_resolution:%s:%s:%s:%s',
            $this->pathType->value,
            $this->installationType->value,
            hash('sha256', $this->installationPath),
            hash('sha256', serialize($this->pathConfiguration->toArray())),
        );
    }

    public function withExtensionIdentifier(ExtensionIdentifier $identifier): self
    {
        return new self(
            $this->pathType,
            $this->installationPath,
            $this->installationType,
            $this->pathConfiguration,
            $identifier,
            $this->validationRules,
            $this->fallbackStrategies,
            $this->cacheOptions,
        );
    }

    /**
     * Internal constructor used by builder.
     */
    public static function create(
        PathTypeEnum $pathType,
        string $installationPath,
        InstallationTypeEnum $installationType,
        PathConfiguration $pathConfiguration,
        ?ExtensionIdentifier $extensionIdentifier = null,
        array $validationRules = [],
        array $fallbackStrategies = [],
        CacheOptions $cacheOptions = new CacheOptions(),
    ): self {
        return new self(
            $pathType,
            $installationPath,
            $installationType,
            $pathConfiguration,
            $extensionIdentifier,
            $validationRules,
            $fallbackStrategies,
            $cacheOptions,
        );
    }
}
