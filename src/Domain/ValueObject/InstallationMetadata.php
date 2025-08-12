<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Domain\ValueObject;

/**
 * Contains metadata about a discovered TYPO3 installation.
 *
 * This value object stores additional information gathered during the discovery process
 * that might be useful for analysis and validation.
 */
final class InstallationMetadata
{
    /**
     * @param array<string, string> $phpVersions     Detected PHP versions and requirements
     * @param array<string, mixed>  $databaseConfig  Database configuration metadata
     * @param array<string>         $enabledFeatures Enabled TYPO3 features and extensions
     * @param \DateTimeImmutable    $lastModified    Last modification timestamp of the installation
     * @param array<string, string> $customPaths     Custom paths configured in the installation
     * @param array<string, mixed>  $discoveryData   Additional data collected during discovery
     */
    public function __construct(
        private readonly array $phpVersions,
        private readonly array $databaseConfig,
        private readonly array $enabledFeatures,
        private readonly \DateTimeImmutable $lastModified,
        private readonly array $customPaths,
        private readonly array $discoveryData = [],
    ) {
    }

    public function getPhpVersions(): array
    {
        return $this->phpVersions;
    }

    public function getRequiredPhpVersion(): ?string
    {
        return $this->phpVersions['required'] ?? null;
    }

    public function getCurrentPhpVersion(): ?string
    {
        return $this->phpVersions['current'] ?? null;
    }

    public function getDatabaseConfig(): array
    {
        return $this->databaseConfig;
    }

    public function getDatabaseDriver(): ?string
    {
        return $this->databaseConfig['driver'] ?? null;
    }

    public function getEnabledFeatures(): array
    {
        return $this->enabledFeatures;
    }

    public function hasFeature(string $feature): bool
    {
        return \in_array($feature, $this->enabledFeatures, true);
    }

    public function getLastModified(): \DateTimeImmutable
    {
        return $this->lastModified;
    }

    public function getCustomPaths(): array
    {
        return $this->customPaths;
    }

    public function getCustomPath(string $key): ?string
    {
        return $this->customPaths[$key] ?? null;
    }

    public function getDiscoveryData(): array
    {
        return $this->discoveryData;
    }

    public function getDiscoveryValue(string $key): mixed
    {
        return $this->discoveryData[$key] ?? null;
    }

    public function withDiscoveryData(array $additionalData): self
    {
        return new self(
            $this->phpVersions,
            $this->databaseConfig,
            $this->enabledFeatures,
            $this->lastModified,
            $this->customPaths,
            array_merge($this->discoveryData, $additionalData),
        );
    }

    public function toArray(): array
    {
        return [
            'php_versions' => $this->phpVersions,
            'database_config' => $this->databaseConfig,
            'enabled_features' => $this->enabledFeatures,
            'last_modified' => $this->lastModified->format(\DateTimeInterface::ATOM),
            'custom_paths' => $this->customPaths,
            'discovery_data' => $this->discoveryData,
        ];
    }
}
