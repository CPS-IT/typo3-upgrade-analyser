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

    public function calculateHealthScore(): float
    {
        $score = 0.0;
        $maxScore = 0.0;
        
        // Activity score (30% weight)
        $maxScore += 0.3;
        if ($this->lastCommitDate) {
            $daysSinceCommit = $this->getDaysSinceLastCommit();
            if ($daysSinceCommit <= 30) {
                $score += 0.3;
            } elseif ($daysSinceCommit <= 90) {
                $score += 0.2;
            } elseif ($daysSinceCommit <= 365) {
                $score += 0.1;
            }
        }
        
        // Popularity score (20% weight)
        $maxScore += 0.2;
        if ($this->starCount >= 100) {
            $score += 0.2;
        } elseif ($this->starCount >= 50) {
            $score += 0.15;
        } elseif ($this->starCount >= 10) {
            $score += 0.1;
        } elseif ($this->starCount >= 1) {
            $score += 0.05;
        }
        
        // Issue management score (20% weight)
        $maxScore += 0.2;
        if ($this->hasGoodIssueManagement()) {
            $score += 0.2;
        } elseif ($this->getIssueResolutionRate() >= 0.5) {
            $score += 0.1;
        }
        
        // Documentation score (15% weight)
        $maxScore += 0.15;
        if ($this->hasGoodDocumentation()) {
            $score += 0.15;
        } elseif ($this->hasReadme || $this->hasLicense) {
            $score += 0.075;
        }
        
        // Community score (10% weight)
        $maxScore += 0.1;
        if ($this->contributorCount >= 10) {
            $score += 0.1;
        } elseif ($this->contributorCount >= 5) {
            $score += 0.075;
        } elseif ($this->contributorCount >= 2) {
            $score += 0.05;
        } elseif ($this->contributorCount >= 1) {
            $score += 0.025;
        }
        
        // Archive penalty (5% weight)
        $maxScore += 0.05;
        if (!$this->isArchived) {
            $score += 0.05;
        }
        
        return $score;
    }

    public function getTotalIssuesCount(): int
    {
        return $this->openIssuesCount + $this->closedIssuesCount;
    }

    public function getIssueResolutionRate(): float
    {
        $totalIssues = $this->getTotalIssuesCount();
        if ($totalIssues === 0) {
            return 1.0; // Perfect score when no issues
        }
        
        return $this->closedIssuesCount / $totalIssues;
    }
}