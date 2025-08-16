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

use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\ResolutionStatusEnum;

/**
 * Immutable response object with strongly typed results and metadata.
 * Replaces mixed return types with comprehensive response data.
 */
final readonly class PathResolutionResponse
{
    private function __construct(
        public ResolutionStatusEnum $status,
        public PathTypeEnum $pathType,
        public ?string $resolvedPath,
        public PathResolutionMetadata $metadata,
        public array $alternativePaths = [],
        public array $warnings = [],
        public array $errors = [],
        public ?string $cacheKey = null,
        public ?float $resolutionTime = null,
    ) {
    }

    public static function success(
        PathTypeEnum $pathType,
        string $resolvedPath,
        PathResolutionMetadata $metadata,
        array $alternativePaths = [],
        array $warnings = [],
        ?string $cacheKey = null,
        ?float $resolutionTime = null,
    ): self {
        return new self(
            ResolutionStatusEnum::SUCCESS,
            $pathType,
            $resolvedPath,
            $metadata,
            $alternativePaths,
            $warnings,
            [],
            $cacheKey,
            $resolutionTime,
        );
    }

    public static function notFound(
        PathTypeEnum $pathType,
        PathResolutionMetadata $metadata,
        array $alternativePaths = [],
        array $warnings = [],
        ?string $cacheKey = null,
        ?float $resolutionTime = null,
    ): self {
        return new self(
            ResolutionStatusEnum::NOT_FOUND,
            $pathType,
            null,
            $metadata,
            $alternativePaths,
            $warnings,
            [],
            $cacheKey,
            $resolutionTime,
        );
    }

    public static function error(
        PathTypeEnum $pathType,
        PathResolutionMetadata $metadata,
        array $errors,
        array $warnings = [],
        ?string $cacheKey = null,
        ?float $resolutionTime = null,
    ): self {
        return new self(
            ResolutionStatusEnum::ERROR,
            $pathType,
            null,
            $metadata,
            [],
            $warnings,
            $errors,
            $cacheKey,
            $resolutionTime,
        );
    }

    public function isSuccess(): bool
    {
        return ResolutionStatusEnum::SUCCESS === $this->status;
    }

    public function isNotFound(): bool
    {
        return ResolutionStatusEnum::NOT_FOUND === $this->status;
    }

    public function isError(): bool
    {
        return ResolutionStatusEnum::ERROR === $this->status;
    }

    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function getBestAlternative(): ?string
    {
        return $this->alternativePaths[0] ?? null;
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'pathType' => $this->pathType->value,
            'resolvedPath' => $this->resolvedPath,
            'metadata' => $this->metadata->toArray(),
            'alternativePaths' => $this->alternativePaths,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'cacheKey' => $this->cacheKey,
            'resolutionTime' => $this->resolutionTime,
        ];
    }
}
