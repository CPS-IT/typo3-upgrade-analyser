<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\ExternalTool\GitProvider;

use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\AbstractGitProvider;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitProviderException;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryHealth;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryMetadata;
use CPSIT\UpgradeAnalyzer\Infrastructure\Http\HttpClientServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;


#[CoversClass(AbstractGitProvider::class)]
final class AbstractGitProviderTest extends TestCase
{
    private \PHPUnit\Framework\MockObject\MockObject $httpClient;
    private \PHPUnit\Framework\MockObject\MockObject $logger;
    private TestableGitProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientServiceInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->provider = new TestableGitProvider($this->httpClient, $this->logger);
    }

    public function testConstructor(): void
    {
        $httpClient = $this->createMock(HttpClientServiceInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $provider = new TestableGitProvider($httpClient, $logger, 'access-token');
        $this->assertInstanceOf(AbstractGitProvider::class, $provider);
    }

    public function testGetPriority(): void
    {
        $this->assertSame(50, $this->provider->getPriority());
    }

    public function testSetCustomPriority(): void
    {
        $this->provider->setTestPriority(100);
        $this->assertSame(100, $this->provider->getPriority());
    }

    public function testIsAvailable(): void
    {
        $this->assertTrue($this->provider->isAvailable());
    }

    public function testExtractRepositoryPathWithHttpsUrl(): void
    {
        $result = $this->provider->testExtractRepositoryPath('https://github.com/owner/repository');

        $expected = [
            'host' => 'github.com',
            'owner' => 'owner',
            'name' => 'repository',
        ];

        $this->assertSame($expected, $result);
    }

    public function testExtractRepositoryPathWithHttpUrl(): void
    {
        $result = $this->provider->testExtractRepositoryPath('http://gitlab.example.com/group/project');

        $expected = [
            'host' => 'gitlab.example.com',
            'owner' => 'group',
            'name' => 'project',
        ];

        $this->assertSame($expected, $result);
    }

    public function testExtractRepositoryPathWithSshUrl(): void
    {
        $result = $this->provider->testExtractRepositoryPath('git@github.com:owner/repository');

        $expected = [
            'host' => 'github.com',
            'owner' => 'owner',
            'name' => 'repository',
        ];

        $this->assertSame($expected, $result);
    }

    public function testExtractRepositoryPathWithGitSuffix(): void
    {
        $result = $this->provider->testExtractRepositoryPath('https://github.com/owner/repository.git');

        $expected = [
            'host' => 'github.com',
            'owner' => 'owner',
            'name' => 'repository',
        ];

        $this->assertSame($expected, $result);
    }

    public function testExtractRepositoryPathWithTrailingSlash(): void
    {
        $result = $this->provider->testExtractRepositoryPath('https://github.com/owner/repository/');

        $expected = [
            'host' => 'github.com',
            'owner' => 'owner',
            'name' => 'repository',
        ];

        $this->assertSame($expected, $result);
    }

    public function testExtractRepositoryPathThrowsExceptionForInvalidUrl(): void
    {
        $this->expectException(GitProviderException::class);
        $this->expectExceptionMessage('Unable to parse repository URL: invalid-url');

        $this->provider->testExtractRepositoryPath('invalid-url');
    }

    public function testMakeRequestSuccess(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(200);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'https://api.example.com/test', [])
            ->willReturn($response);

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Making Git provider request', [
                'method' => 'GET',
                'url' => 'https://api.example.com/test',
                'provider' => 'test-provider',
            ]);

        $result = $this->provider->testMakeRequest('GET', 'https://api.example.com/test');
        $this->assertSame($response, $result);
    }

    public function testMakeRequestWithOptions(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(200);

        $options = ['headers' => ['Authorization' => 'Bearer token']];

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('POST', 'https://api.example.com/test', $options)
            ->willReturn($response);

        $result = $this->provider->testMakeRequest('POST', 'https://api.example.com/test', $options);
        $this->assertSame($response, $result);
    }

    public function testMakeRequestHandles4xxError(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(404);
        $response->expects($this->once())
            ->method('getContent')
            ->with(false)
            ->willReturn('Not Found');

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->expectException(GitProviderException::class);
        $this->expectExceptionMessage('Git provider request failed with status 404: Not Found');

        $this->provider->testMakeRequest('GET', 'https://api.example.com/test');
    }

    public function testMakeRequestHandlesRateLimit(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(403);
        $response->expects($this->once())
            ->method('getContent')
            ->with(false)
            ->willReturn('API rate limit exceeded');

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->expectException(GitProviderException::class);
        $this->expectExceptionMessage('API rate limit exceeded: API rate limit exceeded');

        $this->provider->testMakeRequest('GET', 'https://api.example.com/test');
    }

    public function testMakeRequestHandlesException(): void
    {
        $exception = new \RuntimeException('Network error');

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willThrowException($exception);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Git provider request failed', [
                'method' => 'GET',
                'url' => 'https://api.example.com/test',
                'provider' => 'test-provider',
                'error' => 'Network error',
            ]);

        $this->expectException(GitProviderException::class);
        $this->expectExceptionMessage('Git provider request failed: Network error');

        $this->provider->testMakeRequest('GET', 'https://api.example.com/test');
    }

    public function testMakeRequestReThrowsGitProviderException(): void
    {
        $gitException = new GitProviderException('Original error', 'test-provider');

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willThrowException($gitException);

        // Should not log as error since it's already a Git provider exception
        $this->logger->expects($this->never())
            ->method('error');

        $this->expectException(GitProviderException::class);
        $this->expectExceptionMessage('Original error');

        $this->provider->testMakeRequest('GET', 'https://api.example.com/test');
    }

    public function testParseComposerJsonSuccess(): void
    {
        $content = '{"name": "vendor/package", "description": "Test package"}';
        $result = $this->provider->testParseComposerJson($content);

        $expected = [
            'name' => 'vendor/package',
            'description' => 'Test package',
        ];

        $this->assertSame($expected, $result);
    }

    public function testParseComposerJsonInvalidJson(): void
    {
        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Failed to parse composer.json', $this->callback(function ($context): bool {
                return isset($context['error'])
                       && isset($context['provider'])
                       && 'test-provider' === $context['provider']
                       && str_contains($context['error'], 'Syntax error');
            }));

        $result = $this->provider->testParseComposerJson('{"invalid": json}');
        $this->assertNull($result);
    }

    public function testParseComposerJsonNotArray(): void
    {
        $result = $this->provider->testParseComposerJson('"string"');
        $this->assertNull($result);
    }

    public function testParseComposerJsonMissingName(): void
    {
        $result = $this->provider->testParseComposerJson('{"description": "Package without name"}');
        $this->assertNull($result);
    }

    public function testParseDateSuccess(): void
    {
        $result = $this->provider->testParseDate('2023-12-01T10:30:00Z');

        $this->assertInstanceOf(\DateTimeImmutable::class, $result);
        $this->assertSame('2023-12-01T10:30:00+00:00', $result->format('c'));
    }

    public function testParseDateWithDifferentFormat(): void
    {
        $result = $this->provider->testParseDate('2023-12-01 10:30:00');

        $this->assertInstanceOf(\DateTimeImmutable::class, $result);
        $this->assertSame('2023-12-01', $result->format('Y-m-d'));
    }

    public function testParseDateInvalidFormat(): void
    {
        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Failed to parse date', $this->callback(function ($context): bool {
                return isset($context['date_string'])
                       && isset($context['error'])
                       && 'invalid-date' === $context['date_string']
                       && str_contains($context['error'], 'Failed to parse');
            }));

        $result = $this->provider->testParseDate('invalid-date');
        $this->assertNull($result);
    }
}

/**
 * Testable implementation of AbstractGitProvider for unit testing.
 */
class TestableGitProvider extends AbstractGitProvider
{
    public function getName(): string
    {
        return 'test-provider';
    }

    public function supports(string $repositoryUrl): bool
    {
        return true;
    }

    public function getRepositoryInfo(string $repositoryUrl): GitRepositoryMetadata
    {
        return new GitRepositoryMetadata(
            'test-repo',
            'Test repository',
            false,
            false,
            0,
            0,
            new \DateTimeImmutable(),
            'main',
        );
    }

    public function getTags(string $repositoryUrl): array
    {
        return [];
    }

    public function getBranches(string $repositoryUrl): array
    {
        return ['main'];
    }

    public function getComposerJson(string $repositoryUrl, string $ref = 'main'): ?array
    {
        return null;
    }

    public function getRepositoryHealth(string $repositoryUrl): GitRepositoryHealth
    {
        return new GitRepositoryHealth(
            new \DateTimeImmutable(),
            0,
            0,
            0,
            0,
            false,
            false,
            false,
            0,
        );
    }

    // Test helpers to access protected methods
    public function testExtractRepositoryPath(string $repositoryUrl): array
    {
        return $this->extractRepositoryPath($repositoryUrl);
    }

    public function testMakeRequest(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->makeRequest($method, $url, $options);
    }

    public function testParseComposerJson(string $content): ?array
    {
        return $this->parseComposerJson($content);
    }

    public function testParseDate(string $dateString): ?\DateTimeImmutable
    {
        return $this->parseDate($dateString);
    }

    public function setTestPriority(int $priority): void
    {
        $this->priority = $priority;
    }
}
