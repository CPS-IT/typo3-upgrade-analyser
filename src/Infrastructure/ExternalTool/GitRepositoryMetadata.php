<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool;

/**
 * Metadata about a Git repository.
 */
class GitRepositoryMetadata
{
    public function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly bool $isArchived,
        private readonly bool $isFork,
        private readonly int $starCount,
        private readonly int $forkCount,
        private readonly \DateTimeImmutable $lastUpdated,
        private readonly string $defaultBranch = 'main',
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function isArchived(): bool
    {
        return $this->isArchived;
    }

    public function isFork(): bool
    {
        return $this->isFork;
    }

    public function getStarCount(): int
    {
        return $this->starCount;
    }

    public function getForkCount(): int
    {
        return $this->forkCount;
    }

    public function getLastUpdated(): \DateTimeImmutable
    {
        return $this->lastUpdated;
    }

    public function getDefaultBranch(): string
    {
        return $this->defaultBranch;
    }

    public function getDaysSinceLastUpdate(): int
    {
        $daysDiff = (new \DateTimeImmutable())->diff($this->lastUpdated)->days;

        return false !== $daysDiff ? $daysDiff : 0;
    }

    public function isRecentlyUpdated(): bool
    {
        return $this->getDaysSinceLastUpdate() <= 90;
    }

    public function isPopular(): bool
    {
        return $this->starCount >= 50;
    }
}
