<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\ExternalTool;

use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryHealth;
use PHPUnit\Framework\TestCase;

/**
 * Test case for GitRepositoryHealth.
 *
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryHealth
 */
class GitRepositoryHealthTest extends TestCase
{
    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryHealth::__construct
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryHealth::getLastCommitDate
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryHealth::getStarCount
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryHealth::getForkCount
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryHealth::getOpenIssuesCount
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryHealth::getClosedIssuesCount
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryHealth::isArchived
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryHealth::hasReadme
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryHealth::hasLicense
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryHealth::getContributorCount
     */
    public function testConstructorAndGetters(): void
    {
        $lastCommitDate = new \DateTimeImmutable('2024-01-15T10:00:00Z');

        $health = new GitRepositoryHealth(
            lastCommitDate: $lastCommitDate,
            starCount: 25,
            forkCount: 5,
            openIssuesCount: 3,
            closedIssuesCount: 15,
            isArchived: false,
            hasReadme: true,
            hasLicense: true,
            contributorCount: 8,
        );

        $this->assertSame($lastCommitDate, $health->getLastCommitDate());
        $this->assertEquals(25, $health->getStarCount());
        $this->assertEquals(5, $health->getForkCount());
        $this->assertEquals(3, $health->getOpenIssuesCount());
        $this->assertEquals(15, $health->getClosedIssuesCount());
        $this->assertFalse($health->isArchived());
        $this->assertTrue($health->hasReadme());
        $this->assertTrue($health->hasLicense());
        $this->assertEquals(8, $health->getContributorCount());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryHealth::calculateHealthScore
     */
    public function testCalculateHealthScoreHealthyRepository(): void
    {
        $health = new GitRepositoryHealth(
            lastCommitDate: new \DateTimeImmutable('-1 week'), // Recent activity
            starCount: 100,
            forkCount: 20,
            openIssuesCount: 5,
            closedIssuesCount: 50,
            isArchived: false,
            hasReadme: true,
            hasLicense: true,
            contributorCount: 10,
        );

        $score = $health->calculateHealthScore();

        // Should be a high score for a healthy repository
        $this->assertGreaterThan(0.8, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryHealth::calculateHealthScore
     */
    public function testCalculateHealthScoreArchivedRepository(): void
    {
        $health = new GitRepositoryHealth(
            lastCommitDate: new \DateTimeImmutable('-2 years'),
            starCount: 50,
            forkCount: 10,
            openIssuesCount: 20,
            closedIssuesCount: 5,
            isArchived: true,
            hasReadme: true,
            hasLicense: true,
            contributorCount: 3,
        );

        $score = $health->calculateHealthScore();

        // Archived repositories should have very low scores
        $this->assertLessThan(0.4, $score);
        $this->assertGreaterThanOrEqual(0.0, $score);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryHealth::calculateHealthScore
     */
    public function testCalculateHealthScoreInactiveRepository(): void
    {
        $health = new GitRepositoryHealth(
            lastCommitDate: new \DateTimeImmutable('-3 years'), // Very old
            starCount: 2,
            forkCount: 0,
            openIssuesCount: 15,
            closedIssuesCount: 1,
            isArchived: false,
            hasReadme: false,
            hasLicense: false,
            contributorCount: 1,
        );

        $score = $health->calculateHealthScore();

        // Should be a low score for inactive repository
        $this->assertLessThan(0.4, $score);
        $this->assertGreaterThanOrEqual(0.0, $score);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryHealth::calculateHealthScore
     */
    public function testCalculateHealthScoreModerateRepository(): void
    {
        $health = new GitRepositoryHealth(
            lastCommitDate: new \DateTimeImmutable('-3 months'),
            starCount: 15,
            forkCount: 3,
            openIssuesCount: 8,
            closedIssuesCount: 12,
            isArchived: false,
            hasReadme: true,
            hasLicense: false,
            contributorCount: 2,
        );

        $score = $health->calculateHealthScore();

        // Should be a moderate score
        $this->assertGreaterThan(0.3, $score);
        $this->assertLessThan(0.8, $score);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryHealth::calculateHealthScore
     */
    public function testCalculateHealthScoreWithNullLastCommit(): void
    {
        $health = new GitRepositoryHealth(
            lastCommitDate: null,
            starCount: 10,
            forkCount: 2,
            openIssuesCount: 5,
            closedIssuesCount: 10,
            isArchived: false,
            hasReadme: true,
            hasLicense: true,
            contributorCount: 3,
        );

        $score = $health->calculateHealthScore();

        // Should still calculate a score, but penalized for no commit info
        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(1.0, $score);
        $this->assertLessThan(0.6, $score); // Penalized for no activity info
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryHealth::getTotalIssuesCount
     */
    public function testGetTotalIssuesCount(): void
    {
        $health = new GitRepositoryHealth(
            lastCommitDate: new \DateTimeImmutable(),
            starCount: 10,
            forkCount: 2,
            openIssuesCount: 5,
            closedIssuesCount: 15,
            isArchived: false,
            hasReadme: true,
            hasLicense: true,
            contributorCount: 3,
        );

        $this->assertEquals(20, $health->getTotalIssuesCount());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryHealth::getIssueResolutionRate
     */
    public function testGetIssueResolutionRate(): void
    {
        $health = new GitRepositoryHealth(
            lastCommitDate: new \DateTimeImmutable(),
            starCount: 10,
            forkCount: 2,
            openIssuesCount: 5,
            closedIssuesCount: 15,
            isArchived: false,
            hasReadme: true,
            hasLicense: true,
            contributorCount: 3,
        );

        $this->assertEquals(0.75, $health->getIssueResolutionRate()); // 15/20 = 0.75
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryHealth::getIssueResolutionRate
     */
    public function testGetIssueResolutionRateWithNoIssues(): void
    {
        $health = new GitRepositoryHealth(
            lastCommitDate: new \DateTimeImmutable(),
            starCount: 10,
            forkCount: 2,
            openIssuesCount: 0,
            closedIssuesCount: 0,
            isArchived: false,
            hasReadme: true,
            hasLicense: true,
            contributorCount: 3,
        );

        $this->assertEquals(1.0, $health->getIssueResolutionRate()); // Perfect score when no issues
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryHealth::__construct
     */
    public function testReadonlyProperties(): void
    {
        $health = new GitRepositoryHealth(
            lastCommitDate: new \DateTimeImmutable(),
            starCount: 10,
            forkCount: 2,
            openIssuesCount: 5,
            closedIssuesCount: 15,
            isArchived: false,
            hasReadme: true,
            hasLicense: true,
            contributorCount: 3,
        );

        // Properties should be readonly - this test verifies the class structure
        $reflection = new \ReflectionClass($health);

        $starCountProperty = $reflection->getProperty('starCount');
        $this->assertTrue($starCountProperty->isReadOnly());

        $isArchivedProperty = $reflection->getProperty('isArchived');
        $this->assertTrue($isArchivedProperty->isReadOnly());
    }
}
