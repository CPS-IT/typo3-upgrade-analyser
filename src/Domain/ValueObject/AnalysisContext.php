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
class AnalysisContext
{
    public function __construct(
        private readonly Version $currentVersion,
        private readonly Version $targetVersion,
        private readonly array $phpVersions = [],
        private readonly array $configuration = [],
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

    public function getConfigurationValue(string $key, mixed $default = null): mixed
    {
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
        );
    }

    public function withPhpVersions(array $phpVersions): self
    {
        return new self(
            $this->currentVersion,
            $this->targetVersion,
            $phpVersions,
            $this->configuration,
        );
    }
}
