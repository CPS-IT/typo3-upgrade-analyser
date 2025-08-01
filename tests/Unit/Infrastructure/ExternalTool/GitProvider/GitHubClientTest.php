<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\ExternalTool\GitProvider;

use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitProviderException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Test case for GitHubClient
 *
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient
 */
class GitHubClientTest extends TestCase
{
    private GitHubClient $client;
    private HttpClientInterface&MockObject $httpClient;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        
        $this->client = new GitHubClient(
            $this->httpClient,
            $this->logger,
            'test-token'
        );
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient::getName
     */
    public function testGetName(): void
    {
        $this->assertEquals('github', $this->client->getName());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient::supports
     */
    public function testSupportsGitHubUrls(): void
    {
        $this->assertTrue($this->client->supports('https://github.com/user/repo'));
        $this->assertTrue($this->client->supports('git@github.com:user/repo.git'));
        $this->assertFalse($this->client->supports('https://gitlab.com/user/repo'));
        $this->assertFalse($this->client->supports('https://bitbucket.org/user/repo'));
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient::isAvailable
     */
    public function testIsAvailable(): void
    {
        $this->assertTrue($this->client->isAvailable());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient::getRepositoryInfo
     */
    public function testGetRepositoryInfo(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'data' => [
                'repository' => [
                    'name' => 'test-repo',
                    'description' => 'A test repository',
                    'isArchived' => false,
                    'isFork' => false,
                    'stargazerCount' => 15,
                    'forkCount' => 3,
                    'updatedAt' => '2024-01-15T10:00:00Z',
                    'defaultBranchRef' => [
                        'name' => 'main'
                    ]
                ]
            ]
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.github.com/graphql',
                $this->callback(function ($options) {
                    return isset($options['headers']['Authorization']) &&
                           $options['headers']['Authorization'] === 'Bearer test-token' &&
                           isset($options['json']['query']) &&
                           isset($options['json']['variables']['owner']) &&
                           $options['json']['variables']['owner'] === 'user' &&
                           isset($options['json']['variables']['name']) &&
                           $options['json']['variables']['name'] === 'repo';
                })
            )
            ->willReturn($response);

        $metadata = $this->client->getRepositoryInfo('https://github.com/user/repo.git');

        $this->assertEquals('test-repo', $metadata->getName());
        $this->assertEquals('A test repository', $metadata->getDescription());
        $this->assertFalse($metadata->isArchived());
        $this->assertFalse($metadata->isFork());
        $this->assertEquals(15, $metadata->getStarCount());
        $this->assertEquals(3, $metadata->getForkCount());
        $this->assertEquals('main', $metadata->getDefaultBranch());
        $this->assertEquals('2024-01-15T10:00:00+00:00', $metadata->getLastUpdated()->format('c'));
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient::getTags
     */
    public function testGetTags(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'data' => [
                'repository' => [
                    'refs' => [
                        'nodes' => [
                            [
                                'name' => 'v1.2.0',
                                'target' => [
                                    'committedDate' => '2024-01-15T10:00:00Z',
                                    'oid' => 'abc123'
                                ]
                            ],
                            [
                                'name' => 'v1.1.0',
                                'target' => [
                                    'tagger' => [
                                        'date' => '2024-01-01T10:00:00Z'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $tags = $this->client->getTags('https://github.com/user/repo');

        $this->assertCount(2, $tags);
        $this->assertEquals('v1.2.0', $tags[0]->getName());
        $this->assertEquals('2024-01-15T10:00:00+00:00', $tags[0]->getDate()->format('c'));
        $this->assertEquals('abc123', $tags[0]->getCommit());
        
        $this->assertEquals('v1.1.0', $tags[1]->getName());
        $this->assertEquals('2024-01-01T10:00:00+00:00', $tags[1]->getDate()->format('c'));
        $this->assertNull($tags[1]->getCommit());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient::getComposerJson
     */
    public function testGetComposerJson(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'content' => base64_encode(json_encode([
                'name' => 'vendor/package',
                'require' => [
                    'typo3/cms-core' => '^12.4'
                ]
            ]))
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'https://api.github.com/repos/user/repo/contents/composer.json?ref=main'
            )
            ->willReturn($response);

        $composerJson = $this->client->getComposerJson('https://github.com/user/repo');

        $this->assertIsArray($composerJson);
        $this->assertEquals('vendor/package', $composerJson['name']);
        $this->assertEquals('^12.4', $composerJson['require']['typo3/cms-core']);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient::getComposerJson
     */
    public function testGetComposerJsonNotFound(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willThrowException(
new GitProviderException('404 Not Found', 'github')
        );

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $composerJson = $this->client->getComposerJson('https://github.com/user/repo');

        $this->assertNull($composerJson);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient::getRepositoryHealth
     */
    public function testGetRepositoryHealth(): void
    {
        // Mock GraphQL response (without collaborators field)
        $graphqlResponse = $this->createMock(ResponseInterface::class);
        $graphqlResponse->method('toArray')->willReturn([
            'data' => [
                'repository' => [
                    'isArchived' => false,
                    'stargazerCount' => 25,
                    'forkCount' => 5,
                    'object' => [
                        'committedDate' => '2024-01-10T15:30:00Z'
                    ],
                    'issues' => [
                        'totalCount' => 3
                    ],
                    'closedIssues' => [
                        'totalCount' => 12
                    ],
                    'readme' => [
                        'id' => 'readme-id'
                    ],
                    'license' => [
                        'name' => 'MIT License'
                    ]
                ]
            ]
        ]);

        // Mock REST API response for contributors
        $contributorsResponse = $this->createMock(ResponseInterface::class);
        $contributorsResponse->method('toArray')->willReturn([
            ['login' => 'user1'],
            ['login' => 'user2'],
            ['login' => 'user3'],
            ['login' => 'user4']
        ]);
        $contributorsResponse->method('getHeaders')->willReturn([
            'link' => ['<https://api.github.com/repositories/123/contributors?per_page=1&page=4>; rel="last"']
        ]);

        $this->httpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($graphqlResponse, $contributorsResponse);

        $health = $this->client->getRepositoryHealth('https://github.com/user/repo');

        $this->assertEquals('2024-01-10T15:30:00+00:00', $health->getLastCommitDate()->format('c'));
        $this->assertEquals(25, $health->getStarCount());
        $this->assertEquals(5, $health->getForkCount());
        $this->assertEquals(3, $health->getOpenIssuesCount());
        $this->assertEquals(12, $health->getClosedIssuesCount());
        $this->assertFalse($health->isArchived());
        $this->assertTrue($health->hasReadme());
        $this->assertTrue($health->hasLicense());
        $this->assertEquals(4, $health->getContributorCount());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient::graphqlRequest
     */
    public function testGraphqlErrorHandling(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'errors' => [
                [
                    'message' => 'Repository not found',
                    'type' => 'NOT_FOUND'
                ]
            ]
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->expectException(GitProviderException::class);
        $this->expectExceptionMessage('GitHub GraphQL errors:');

        $this->client->getRepositoryInfo('https://github.com/user/nonexistent');
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\AbstractGitProvider::extractRepositoryPath
     */
    public function testExtractRepositoryPath(): void
    {
        $reflection = new \ReflectionClass($this->client);
        $method = $reflection->getMethod('extractRepositoryPath');
        $method->setAccessible(true);

        // Test HTTPS URL
        $result = $method->invoke($this->client, 'https://github.com/user/repo.git');
        $this->assertEquals(['host' => 'github.com', 'owner' => 'user', 'name' => 'repo'], $result);

        // Test SSH URL
        $result = $method->invoke($this->client, 'git@github.com:user/repo.git');
        $this->assertEquals(['host' => 'github.com', 'owner' => 'user', 'name' => 'repo'], $result);

        // Test URL without .git suffix
        $result = $method->invoke($this->client, 'https://github.com/user/repo');
        $this->assertEquals(['host' => 'github.com', 'owner' => 'user', 'name' => 'repo'], $result);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\AbstractGitProvider::extractRepositoryPath
     */
    public function testExtractRepositoryPathInvalid(): void
    {
        $reflection = new \ReflectionClass($this->client);
        $method = $reflection->getMethod('extractRepositoryPath');
        $method->setAccessible(true);

        $this->expectException(GitProviderException::class);
        $this->expectExceptionMessage('Unable to parse repository URL: https://github.com/invalid-url');

        $method->invoke($this->client, 'https://github.com/invalid-url');
    }
}