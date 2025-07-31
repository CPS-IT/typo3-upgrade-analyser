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
 * Health metrics for a Git repository
 */
class GitRepositoryHealth
{
    public function __construct(
        private readonly ?\DateTimeImmutable $lastCommitDate = null,
        private readonly int $starCount = 0,
        private readonly int $forkCount = 0,
        private readonly int $openIssuesCount = 0,
        private readonly int $closedIssuesCount = 0,
        private readonly bool $isArchived = false,
        private readonly bool $hasReadme = false,
        private readonly bool $hasLicense = false,
        private readonly int $contributorCount = 0
    ) {
    }

    public function getLastCommitDate(): ?\DateTimeImmutable
    {
        return $this->lastCommitDate;
    }

    public function getStarCount(): int
    {
        return $this->starCount;
    }

    public function getForkCount(): int
    {
        return $this->forkCount;
    }

    public function getOpenIssuesCount(): int
    {
        return $this->openIssuesCount;
    }

    public function getClosedIssuesCount(): int
    {
        return $this->closedIssuesCount;
    }

    public function isArchived(): bool
    {
        return $this->isArchived;
    }

    public function hasReadme(): bool
    {
        return $this->hasReadme;
    }

    public function hasLicense(): bool
    {
        return $this->hasLicense;
    }

    public function getContributorCount(): int
    {
        return $this->contributorCount;
    }

    public function getDaysSinceLastCommit(): ?int
    {
        if (!$this->lastCommitDate) {
            return null;
        }
        
        return (new \DateTimeImmutable())->diff($this->lastCommitDate)->days;
    }

    public function isActivelyMaintained(): bool
    {
        $daysSinceLastCommit = $this->getDaysSinceLastCommit();
        return $daysSinceLastCommit !== null && $daysSinceLastCommit <= 90;
    }

    public function hasGoodIssueManagement(): bool
    {
        $totalIssues = $this->openIssuesCount + $this->closedIssuesCount;
        if ($totalIssues === 0) {
            return true; // No issues is good
        }
        
        return ($this->closedIssuesCount / $totalIssues) >= 0.7;
    }

    public function isPopular(): bool
    {
        return $this->starCount >= 50;
    }

    public function hasGoodDocumentation(): bool
    {
        return $this->hasReadme && $this->hasLicense;
    }
}