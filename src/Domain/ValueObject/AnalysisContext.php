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
 * Context information for analysis operations.
 */
readonly class AnalysisContext
{
    public function __construct(
        private Version $currentVersion,
        private Version $targetVersion,
        private array $phpVersions = [],
        private array $configuration = [],
        private ?string $installationPath = null,
    ) {
    }

    public function getCurrentVersion(): Version
    {
        return $this->currentVersion;
    }

    public function getTargetVersion(): Version
    {
        return $this->targetVersion;
    }

    public function getPhpVersions(): array
    {
        return $this->phpVersions;
    }

    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    public function getInstallationPath(): ?string
    {
        return $this->installationPath;
    }

    public function getConfigurationValue(string $key, mixed $default = null): mixed
    {
        if (str_contains($key, '.')) {
            $parts = explode('.', $key);
            $value = $this->configuration;
            foreach ($parts as $part) {
                if (!\is_array($value) || !\array_key_exists($part, $value)) {
                    return $default;
                }
                $value = $value[$part];
            }

            return $value;
        }

        return $this->configuration[$key] ?? $default;
    }

    public function hasConfiguration(string $key): bool
    {
        return \array_key_exists($key, $this->configuration);
    }

    public function withConfiguration(array $configuration): self
    {
        return new self(
            $this->currentVersion,
            $this->targetVersion,
            $this->phpVersions,
            array_merge($this->configuration, $configuration),
            $this->installationPath,
        );
    }

    public function withPhpVersions(array $phpVersions): self
    {
        return new self(
            $this->currentVersion,
            $this->targetVersion,
            $phpVersions,
            $this->configuration,
            $this->installationPath,
        );
    }
}
