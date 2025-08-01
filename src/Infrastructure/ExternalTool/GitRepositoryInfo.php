<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool;

/**
 * Contains information about a Git repository analysis
 */
class GitRepositoryInfo
{
    /**
     * @param array<GitTag> $allTags
     * @param array<GitTag> $compatibleVersions
     */
    public function __construct(
        private readonly string $repositoryUrl,
        private readonly GitRepositoryMetadata $metadata,
        private readonly array $allTags,
        private readonly array $compatibleVersions,
        private readonly float $healthScore,
        private readonly ?array $composerJson = null,
        private readonly ?GitRepositoryHealth $health = null
    ) {
    }

    public function getRepositoryUrl(): string
    {
        return $this->repositoryUrl;
    }

    public function getMetadata(): GitRepositoryMetadata
    {
        return $this->metadata;
    }

    /**
     * @return array<GitTag>
     */
    public function getAllTags(): array
    {
        return $this->allTags;
    }

    /**
     * Alias for getAllTags() for backward compatibility
     * @return array<GitTag>
     */
    public function getAvailableVersions(): array
    {
        return $this->getAllTags();
    }

    /**
     * @return array<GitTag>
     */
    public function getCompatibleVersions(): array
    {
        return $this->compatibleVersions;
    }

    public function hasCompatibleVersion(): bool
    {
        return !empty($this->compatibleVersions);
    }

    public function getLatestCompatibleVersion(): ?GitTag
    {
        return $this->compatibleVersions[0] ?? null;
    }

    public function getHealthScore(): float
    {
        return $this->healthScore;
    }

    public function getComposerJson(): ?array
    {
        return $this->composerJson;
    }

    public function hasComposerJson(): bool
    {
        return $this->composerJson !== null;
    }

    public function getLatestTag(): ?GitTag
    {
        return $this->allTags[0] ?? null;
    }

    public function isHealthy(): bool
    {
        return $this->healthScore > 0.6;
    }

    public function isWellMaintained(): bool
    {
        return $this->healthScore > 0.8;
    }

    public function getHealth(): ?GitRepositoryHealth
    {
        return $this->health;
    }
}