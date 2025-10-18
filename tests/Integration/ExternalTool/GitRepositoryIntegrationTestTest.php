<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Integration\ExternalTool;

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitProviderFactory;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryAnalyzer;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser;
use CPSIT\UpgradeAnalyzer\Infrastructure\Repository\RepositoryUrlHandler;
use CPSIT\UpgradeAnalyzer\Infrastructure\Version\ComposerConstraintChecker;
use CPSIT\UpgradeAnalyzer\Tests\Integration\AbstractIntegrationTestCase;

/**
 * Integration tests for Git repository analysis with real API calls.
 *
 * @group integration
 * @group github-api
 * @group real-world
 */
class GitRepositoryIntegrationTestTest extends AbstractIntegrationTestCase
{
    private GitHubClient $gitHubClient;
    private GitProviderFactory $providerFactory;
    private GitRepositoryAnalyzer $repositoryAnalyzer;
    private array $testExtensions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresRealApiCalls();

        // Skip if we've hit rate limits recently
        if ($this->isRateLimited()) {
            $this->markTestSkipped('GitHub API rate limit reached - tests will be skipped');
        }

        // Load test extension data
        $this->testExtensions = $this->loadTestData('known_extensions.json');

        // Create GitHub client with optional authentication
        $this->gitHubClient = new GitHubClient(
            $this->createHttpClientService(),
            $this->createLogger(),
            new RepositoryUrlHandler(),
            $this->getGitHubToken(),
        );

        // Create provider factory
        $this->providerFactory = new GitProviderFactory([$this->gitHubClient], $this->createLogger());

        // Create repository analyzer
        $this->repositoryAnalyzer = new GitRepositoryAnalyzer(
            $this->providerFactory,
            new GitVersionParser(new ComposerConstraintChecker()),
            $this->createLogger(),
        );
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient::supports
     */
    public function testGitHubClientSupportsGitHubUrls(): void
    {
        $this->assertTrue($this->gitHubClient->supports('https://github.com/georgringer/news'));
        $this->assertTrue($this->gitHubClient->supports('https://github.com/FriendsOfTYPO3/extension_builder'));
        $this->assertFalse($this->gitHubClient->supports('https://gitlab.com/example/project'));
        $this->assertFalse($this->gitHubClient->supports('https://bitbucket.org/example/project'));
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient::isAvailable
     */
    public function testGitHubClientIsAvailable(): void
    {
        $this->assertTrue($this->gitHubClient->isAvailable());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient::getRepositoryInfo
     */
    public function testGetRepositoryInfoForActiveRepository(): void
    {
        $startTime = microtime(true);

        $repositoryUrl = $this->testExtensions['extensions']['georgringer/news']['github_url'];

        try {
            $metadata = $this->gitHubClient->getRepositoryInfo($repositoryUrl);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'rate limit')) {
                $this->markRateLimited();
                $this->markTestSkipped('GitHub API rate limit reached: ' . $e->getMessage());
            }
            throw $e;
        }

        $responseTime = microtime(true) - $startTime;

        // Assert basic metadata structure
        $this->assertGitRepositoryMetadataValid($metadata);

        // Assert specific expectations for georgringer/news
        $this->assertEquals('news', $metadata->getName());
        $this->assertStringContainsString('news', strtolower($metadata->getDescription()));
        $this->assertFalse($metadata->isArchived()); // Should be active
        $this->assertGreaterThan(0, $metadata->getStarCount()); // Popular extension

        // Performance assertion
        $this->assertResponseTimeAcceptable($responseTime, 5.0);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient::getRepositoryInfo
     */
    public function testGetRepositoryInfoForArchivedRepository(): void
    {
        $repositoryUrl = $this->testExtensions['extensions']['typo3-extensions/gridelements']['github_url'];

        try {
            $metadata = $this->gitHubClient->getRepositoryInfo($repositoryUrl);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'rate limit')) {
                $this->markRateLimited();
                $this->markTestSkipped('GitHub API rate limit reached: ' . $e->getMessage());
            }
            throw $e;
        }

        // Assert archived status
        $this->assertTrue($metadata->isArchived());
        $this->assertEquals('gridelements', $metadata->getName());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient::getTags
     */
    public function testGetTagsForActiveRepository(): void
    {
        $startTime = microtime(true);

        $repositoryUrl = $this->testExtensions['extensions']['georgringer/news']['github_url'];

        try {
            $tags = $this->gitHubClient->getTags($repositoryUrl);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'rate limit')) {
                $this->markRateLimited();
                $this->markTestSkipped('GitHub API rate limit reached: ' . $e->getMessage());
            }
            throw $e;
        }

        $responseTime = microtime(true) - $startTime;

        // Assert tags structure
        $this->assertGitTagsValid($tags);
        $this->assertGreaterThan(10, \count($tags)); // Should have many releases

        // Assert semantic versioning pattern exists
        $semanticVersionFound = false;
        foreach ($tags as $tag) {
            if (preg_match('/^\d+\.\d+\.\d+$/', $tag->getName())) {
                $semanticVersionFound = true;
                break;
            }
        }
        $this->assertTrue($semanticVersionFound, 'No semantic version tags found');

        // Performance assertion
        $this->assertResponseTimeAcceptable($responseTime, 5.0);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient::getBranches
     */
    public function testGetBranchesForRepository(): void
    {
        $repositoryUrl = $this->testExtensions['extensions']['georgringer/news']['github_url'];

        $branches = $this->cacheApiResponse(
            'github_branches_' . md5($repositoryUrl),
            fn (): array => $this->gitHubClient->getBranches($repositoryUrl),
        );

        $this->assertIsArray($branches);
        $this->assertGreaterThan(0, \count($branches));
        $this->assertContainsEquals('main', $branches, 'Default branch "main" should exist');
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient::getComposerJson
     */
    public function testGetComposerJsonForRepository(): void
    {
        $repositoryUrl = $this->testExtensions['extensions']['georgringer/news']['github_url'];

        $composerJson = $this->cacheApiResponse(
            'github_composer_' . md5($repositoryUrl),
            fn (): ?array => $this->gitHubClient->getComposerJson($repositoryUrl),
        );

        $this->assertIsArray($composerJson);
        $this->assertArrayHasKey('name', $composerJson);
        $this->assertEquals('georgringer/news', $composerJson['name']);

        // Assert TYPO3 specific requirements
        if (isset($composerJson['require'])) {
            $hasTypo3Requirement = false;
            foreach ($composerJson['require'] as $package => $version) {
                if (str_contains($package, 'typo3')) {
                    $hasTypo3Requirement = true;
                    break;
                }
            }
            $this->assertTrue($hasTypo3Requirement, 'Composer.json should have TYPO3 requirement');
        }
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient::getRepositoryHealth
     */
    public function testGetRepositoryHealthForActiveRepository(): void
    {
        $repositoryUrl = $this->testExtensions['extensions']['georgringer/news']['github_url'];

        try {
            $health = $this->gitHubClient->getRepositoryHealth($repositoryUrl);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'rate limit')) {
                $this->markRateLimited();
                $this->markTestSkipped('GitHub API rate limit reached: ' . $e->getMessage());
            }
            throw $e;
        }

        $this->assertGitRepositoryHealthValid($health);

        // Active repository specific assertions
        $this->assertFalse($health->isArchived());
        $this->assertTrue($health->hasReadme());
        $this->assertTrue($health->hasLicense());
        $this->assertGreaterThan(0, $health->getStarCount());

        // Recent activity assertion (last commit within 2 years)
        $twoYearsAgo = new \DateTimeImmutable('-2 years');
        $this->assertGreaterThan(
            $twoYearsAgo,
            $health->getLastCommitDate(),
            'Repository should have recent activity',
        );
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient::getRepositoryHealth
     */
    public function testGetRepositoryHealthForArchivedRepository(): void
    {
        $repositoryUrl = $this->testExtensions['extensions']['typo3-extensions/gridelements']['github_url'];

        try {
            $health = $this->gitHubClient->getRepositoryHealth($repositoryUrl);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'rate limit')) {
                $this->markRateLimited();
                $this->markTestSkipped('GitHub API rate limit reached: ' . $e->getMessage());
            }
            throw $e;
        }

        $this->assertGitRepositoryHealthValid($health);
        $this->assertTrue($health->isArchived());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryAnalyzer::analyzeExtension
     */
    public function testRepositoryAnalyzerWithActiveExtension(): void
    {
        $extension = $this->createTestExtension(
            'news',
            'georgringer/news',
        );

        // Set the repository URL from test data
        $extension->setRepositoryUrl($this->testExtensions['extensions']['georgringer/news']['github_url']);

        $typo3Version = new Version('12.4.0');

        try {
            $result = $this->repositoryAnalyzer->analyzeExtension($extension, $typo3Version);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'rate limit')) {
                $this->markRateLimited();
                $this->markTestSkipped('GitHub API rate limit reached: ' . $e->getMessage());
            }
            throw $e;
        }

        $this->assertNotEmpty($result->getRepositoryUrl());
        $this->assertEquals(
            $this->testExtensions['extensions']['georgringer/news']['github_url'],
            $result->getRepositoryUrl(),
        );

        // Should have some version information
        $this->assertGreaterThan(0, \count($result->getAvailableVersions()));

        // Health score should be reasonable for active repository
        $healthScore = $result->getHealthScore();
        $this->assertGreaterThan(0.5, $healthScore, 'Active repository should have good health score');
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryAnalyzer::analyzeExtension
     */
    public function testRepositoryAnalyzerWithArchivedExtension(): void
    {
        $extension = $this->createTestExtension(
            'gridelements',
            'typo3-extensions/gridelements',
        );

        // Set the repository URL from test data
        $extension->setRepositoryUrl($this->testExtensions['extensions']['typo3-extensions/gridelements']['github_url']);

        $typo3Version = new Version('12.4.0');

        try {
            $result = $this->repositoryAnalyzer->analyzeExtension($extension, $typo3Version);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'rate limit')) {
                $this->markRateLimited();
                $this->markTestSkipped('GitHub API rate limit reached: ' . $e->getMessage());
            }
            throw $e;
        }

        // Archived repository should have a lower health score
        $healthScore = $result->getHealthScore();
        $this->assertLessThan(0.7, $healthScore, 'Archived repository should have lower health score');

        // Should not have TYPO3 12 compatible versions
        $hasCompatibleVersion = $result->hasCompatibleVersion();
        $this->assertFalse($hasCompatibleVersion, 'Archived extension should not have TYPO3 12 compatibility');
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient::getRepositoryInfo
     */
    public function testHandleNonExistentRepository(): void
    {
        $this->expectException(\CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitProviderException::class);

        $nonExistentUrl = 'https://github.com/non-existent-user/non-existent-repo';
        $this->gitHubClient->getRepositoryInfo($nonExistentUrl);
    }

    /**
     * @coversNothing
     */
    public function testRateLimitHandling(): void
    {
        if (!$this->getGitHubToken()) {
            $this->markTestSkipped('Rate limit testing requires GitHub token');
        }

        $repositoryUrl = $this->testExtensions['extensions']['georgringer/news']['github_url'];

        // Make multiple requests to test rate limiting
        $requests = 0;
        $maxRequests = 5;

        while ($requests < $maxRequests) {
            $startTime = microtime(true);

            try {
                $response = $this->createAuthenticatedGitHubClient()->request(
                    'GET',
                    'https://api.github.com/repos/georgringer/news',
                );

                $headers = $response->getHeaders();
                $this->assertRateLimitRespected($headers);

                ++$requests;
            } catch (\Exception $e) {
                if (str_contains($e->getMessage(), 'rate limit')) {
                    $this->markTestSkipped('Rate limit reached during test');
                }
                throw $e;
            }

            // Add delay between requests
            sleep(1);
        }

        $this->assertEquals($maxRequests, $requests, 'All requests should complete successfully');
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient::getRepositoryInfo
     */
    public function testAuthenticatedVsUnauthenticatedAccess(): void
    {
        $repositoryUrl = $this->testExtensions['extensions']['georgringer/news']['github_url'];

        // Test unauthenticated access
        $unauthenticatedClient = new GitHubClient(
            $this->createHttpClientService(),
            $this->createLogger(),
            new RepositoryUrlHandler(),
        );

        try {
            $unauthenticatedMetadata = $unauthenticatedClient->getRepositoryInfo($repositoryUrl);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'rate limit')) {
                $this->markRateLimited();
                $this->markTestSkipped('GitHub API rate limit reached: ' . $e->getMessage());
            }
            throw $e;
        }
        $this->assertGitRepositoryMetadataValid($unauthenticatedMetadata);

        // Test authenticated access (if token available)
        if ($this->getGitHubToken()) {
            $authenticatedMetadata = $this->gitHubClient->getRepositoryInfo($repositoryUrl);
            $this->assertGitRepositoryMetadataValid($authenticatedMetadata);

            // Both should return same basic data
            $this->assertEquals($unauthenticatedMetadata->getName(), $authenticatedMetadata->getName());
            $this->assertEquals($unauthenticatedMetadata->isArchived(), $authenticatedMetadata->isArchived());
        }
    }

    /**
     * @group performance
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryAnalyzer::analyzeExtension
     */
    public function testRepositoryAnalysisPerformance(): void
    {
        $extensions = [
            $this->createTestExtension('news', 'georgringer/news'),
            $this->createTestExtension('extension_builder', 'friendsoftypo3/extension-builder'),
        ];

        // Set repository URLs from test data
        $extensions[0]->setRepositoryUrl($this->testExtensions['extensions']['georgringer/news']['github_url']);
        $extensions[1]->setRepositoryUrl($this->testExtensions['extensions']['friendsoftypo3/extension-builder']['github_url']);

        $typo3Version = new Version('12.4.0');
        $totalStartTime = microtime(true);

        foreach ($extensions as $extension) {
            $startTime = microtime(true);

            try {
                $this->repositoryAnalyzer->analyzeExtension($extension, $typo3Version);

                $analysisTime = microtime(true) - $startTime;
                $this->assertLessThan(
                    15.0,
                    $analysisTime,
                    "Extension analysis took too long: {$analysisTime}s for {$extension->getKey()}",
                );
            } catch (\Exception $e) {
                $this->fail("Extension analysis failed for {$extension->getKey()}: " . $e->getMessage());
            }
        }

        $totalTime = microtime(true) - $totalStartTime;
        $this->assertLessThan(30.0, $totalTime, "Total analysis time exceeded limit: {$totalTime}s");
    }
}
