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
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Exception\InvalidRequestException;

/**
 * Builder for creating validated PathResolutionRequest objects.
 * Implements comprehensive validation at construction time.
 */
final class PathResolutionRequestBuilder
{
    private ?PathTypeEnum $pathType = null;
    private ?string $installationPath = null;
    private ?InstallationTypeEnum $installationType = null;
    private ?PathConfiguration $pathConfiguration = null;
    private ?ExtensionIdentifier $extensionIdentifier = null;
    private array $validationRules = [];
    private array $fallbackStrategies = [];
    private ?CacheOptions $cacheOptions = null;

    public function pathType(PathTypeEnum $pathType): self
    {
        $this->pathType = $pathType;

        return $this;
    }

    public function installationPath(string $path): self
    {
        if (!is_dir($path) && !is_file($path)) {
            throw new InvalidRequestException("Installation path does not exist: {$path}");
        }

        $this->installationPath = realpath($path) ?: $path;

        return $this;
    }

    public function installationType(InstallationTypeEnum $type): self
    {
        $this->installationType = $type;

        return $this;
    }

    public function pathConfiguration(PathConfiguration $config): self
    {
        $this->pathConfiguration = $config;

        return $this;
    }

    public function extensionIdentifier(ExtensionIdentifier $identifier): self
    {
        $this->extensionIdentifier = $identifier;

        return $this;
    }

    public function addValidationRule(string $rule, array $parameters = []): self
    {
        $this->validationRules[$rule] = $parameters;

        return $this;
    }

    public function addFallbackStrategy(string $strategy, int $priority = 100): self
    {
        $this->fallbackStrategies[] = new FallbackStrategy($strategy, $priority);

        return $this;
    }

    public function cacheOptions(CacheOptions $options): self
    {
        $this->cacheOptions = $options;

        return $this;
    }

    public function build(): PathResolutionRequest
    {
        $this->validateRequiredFields();
        $this->validateFieldCompatibility();

        return PathResolutionRequest::create(
            $this->pathType, // @phpstan-ignore-line (validated in validateRequiredFields)
            $this->installationPath, // @phpstan-ignore-line (validated in validateRequiredFields)
            $this->installationType, // @phpstan-ignore-line (validated in validateRequiredFields)
            $this->pathConfiguration ?? PathConfiguration::createDefault(),
            $this->extensionIdentifier,
            $this->validationRules,
            $this->fallbackStrategies,
            $this->cacheOptions ?? new CacheOptions(),
        );
    }

    private function validateRequiredFields(): void
    {
        $missing = [];

        if (null === $this->pathType) {
            $missing[] = 'pathType';
        }
        if (null === $this->installationPath) {
            $missing[] = 'installationPath';
        }
        if (null === $this->installationType) {
            $missing[] = 'installationType';
        }

        if (!empty($missing)) {
            throw new InvalidRequestException('Missing required fields: ' . implode(', ', $missing));
        }
    }

    private function validateFieldCompatibility(): void
    {
        if ($this->pathType && $this->installationType) {
            if (!$this->pathType->isCompatibleWith($this->installationType)) {
                throw new InvalidRequestException("Path type {$this->pathType->value} is not compatible with installation type {$this->installationType->value}");
            }
        }
    }
}
