<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Repository;

use CPSIT\UpgradeAnalyzer\Infrastructure\Repository\RepositoryUrlException;
use CPSIT\UpgradeAnalyzer\Infrastructure\Repository\RepositoryUrlHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RepositoryUrlHandler::class)]
class RepositoryUrlHandlerTest extends TestCase
{
    private RepositoryUrlHandler $subject;

    protected function setUp(): void
    {
        $this->subject = new RepositoryUrlHandler();
    }

    #[DataProvider('normalizeUrlProvider')]
    public function testNormalizeUrl(string $input, string $expected): void
    {
        $result = $this->subject->normalizeUrl($input);

        self::assertSame($expected, $result);
    }

    public static function normalizeUrlProvider(): array
    {
        return [
            // GitHub URLs
            ['git@github.com:user/repo.git', 'https://github.com/user/repo'],
            ['https://github.com/user/repo.git', 'https://github.com/user/repo'],
            ['http://github.com/user/repo', 'https://github.com/user/repo'],
            ['github.com/user/repo', 'https://github.com/user/repo'],

            // GitLab URLs
            ['git@gitlab.com:user/repo.git', 'https://gitlab.com/user/repo'],
            ['https://gitlab.com/user/repo.git', 'https://gitlab.com/user/repo'],
            ['gitlab.com/user/repo', 'https://gitlab.com/user/repo'],

            // Bitbucket URLs
            ['git@bitbucket.org:user/repo.git', 'https://bitbucket.org/user/repo'],
            ['https://bitbucket.org/user/repo.git', 'https://bitbucket.org/user/repo'],
            ['bitbucket.org/user/repo', 'https://bitbucket.org/user/repo'],

            // .git suffix removal
            ['https://example.com/repo.git', 'https://example.com/repo'],
            ['https://custom-git.com/path/to/repo.git', 'https://custom-git.com/path/to/repo'],

            // URLs without changes needed
            ['https://example.com/repo', 'https://example.com/repo'],
            ['https://custom.domain.com/user/project', 'https://custom.domain.com/user/project'],
        ];
    }

    #[DataProvider('isGitRepositoryProvider')]
    public function testIsGitRepository(string $url, bool $expected): void
    {
        $result = $this->subject->isGitRepository($url);

        self::assertSame($expected, $result);
    }

    public static function isGitRepositoryProvider(): array
    {
        return [
            // Git URLs
            ['https://github.com/user/repo', true],
            ['git@github.com:user/repo.git', true],
            ['https://gitlab.com/user/repo', true],
            ['https://bitbucket.org/user/repo', true],
            ['https://example.com/repo.git', true],
            ['git://example.com/repo', true],
            ['ssh://git@example.com/repo', true],
            ['https://git.example.com/repo', true],

            // Non-Git URLs
            ['https://example.com/page', false],
            ['https://docs.example.com', false],
            ['ftp://example.com/file', false],
        ];
    }

    #[DataProvider('extractRepositoryPathProvider')]
    public function testExtractRepositoryPath(string $url, array $expected): void
    {
        $result = $this->subject->extractRepositoryPath($url);

        self::assertSame($expected, $result);
    }

    public static function extractRepositoryPathProvider(): array
    {
        return [
            [
                'https://github.com/user/repo',
                ['owner' => 'user', 'name' => 'repo', 'host' => 'github.com'],
            ],
            [
                'git@gitlab.com:organization/project.git',
                ['owner' => 'organization', 'name' => 'project', 'host' => 'gitlab.com'],
            ],
            [
                'https://bitbucket.org/team/repository',
                ['owner' => 'team', 'name' => 'repository', 'host' => 'bitbucket.org'],
            ],
            [
                'https://custom.domain.com/user/project',
                ['owner' => 'user', 'name' => 'project', 'host' => 'custom.domain.com'],
            ],
        ];
    }

    public function testExtractRepositoryPathThrowsExceptionForInvalidUrl(): void
    {
        $this->expectException(RepositoryUrlException::class);
        $this->expectExceptionMessage('Repository URL must contain owner and name');

        $this->subject->extractRepositoryPath('invalid-url');
    }

    public function testExtractRepositoryPathThrowsExceptionForIncompleteUrl(): void
    {
        $this->expectException(RepositoryUrlException::class);
        $this->expectExceptionMessage('Repository URL must contain owner and name');

        $this->subject->extractRepositoryPath('https://github.com/user');
    }

    #[DataProvider('getProviderTypeProvider')]
    public function testGetProviderType(string $url, string $expected): void
    {
        $result = $this->subject->getProviderType($url);

        self::assertSame($expected, $result);
    }

    public static function getProviderTypeProvider(): array
    {
        return [
            ['https://github.com/user/repo', 'github'],
            ['git@github.com:user/repo.git', 'github'],
            ['https://gitlab.com/user/repo', 'gitlab'],
            ['git@gitlab.com:user/repo.git', 'gitlab'],
            ['https://bitbucket.org/user/repo', 'bitbucket'],
            ['git@bitbucket.org:user/repo.git', 'bitbucket'],
            ['https://custom.example.com/repo', 'unknown'],
            ['git@custom.domain.com:repo.git', 'unknown'],
        ];
    }

    #[DataProvider('isValidRepositoryUrlProvider')]
    public function testIsValidRepositoryUrl(string $url, bool $expected): void
    {
        $result = $this->subject->isValidRepositoryUrl($url);

        self::assertSame($expected, $result);
    }

    public static function isValidRepositoryUrlProvider(): array
    {
        return [
            // Valid URLs
            ['https://github.com/user/repo', true],
            ['git@github.com:user/repo.git', true],
            ['https://gitlab.com/user/repo', true],
            ['https://bitbucket.org/user/repo', true],
            ['https://example.com/user/repo.git', true],

            // Invalid URLs
            ['https://example.com/page', false],
            ['invalid-url', false],
            ['https://github.com/user', false],
            ['ftp://example.com/file', false],
        ];
    }

    #[DataProvider('convertToApiUrlProvider')]
    public function testConvertToApiUrl(string $url, string $apiType, string $expected): void
    {
        $result = $this->subject->convertToApiUrl($url, $apiType);

        self::assertSame($expected, $result);
    }

    public static function convertToApiUrlProvider(): array
    {
        return [
            // GitHub REST API
            [
                'https://github.com/user/repo',
                'rest',
                'https://api.github.com/repos/user/repo',
            ],
            // GitHub GraphQL API
            [
                'https://github.com/user/repo',
                'graphql',
                'https://api.github.com/graphql',
            ],
            // GitLab API
            [
                'https://gitlab.com/user/repo',
                'rest',
                'https://gitlab.com/api/v4/projects/user%2Frepo',
            ],
            // Bitbucket API
            [
                'https://bitbucket.org/user/repo',
                'rest',
                'https://api.bitbucket.org/2.0/repositories/user/repo',
            ],
        ];
    }

    public function testConvertToApiUrlWithDefaultApiType(): void
    {
        $result = $this->subject->convertToApiUrl('https://github.com/user/repo');

        self::assertSame('https://api.github.com/repos/user/repo', $result);
    }

    public function testConvertToApiUrlThrowsExceptionForUnsupportedApiType(): void
    {
        $this->expectException(RepositoryUrlException::class);
        $this->expectExceptionMessage('Unsupported API type: unsupported');

        $this->subject->convertToApiUrl('https://github.com/user/repo', 'unsupported');
    }

    public function testConvertToApiUrlThrowsExceptionForUnsupportedProvider(): void
    {
        $this->expectException(RepositoryUrlException::class);
        $this->expectExceptionMessage('Unsupported repository provider: unknown');

        $this->subject->convertToApiUrl('https://custom.example.com/user/repo');
    }

    public function testConvertToApiUrlWithSpecialCharactersInGitLab(): void
    {
        $result = $this->subject->convertToApiUrl('https://gitlab.com/my-org/my-project');

        self::assertSame('https://gitlab.com/api/v4/projects/my-org%2Fmy-project', $result);
    }

    public function testNormalizeUrlHandlesVariousGitHubFormats(): void
    {
        $formats = [
            'git@github.com:user/repo.git',
            'https://github.com/user/repo.git',
            'http://github.com/user/repo',
            'github.com:user/repo',
            'github.com/user/repo',
        ];

        foreach ($formats as $format) {
            $result = $this->subject->normalizeUrl($format);
            self::assertSame('https://github.com/user/repo', $result);
        }
    }

    public function testExtractRepositoryPathHandlesComplexPaths(): void
    {
        $result = $this->subject->extractRepositoryPath('https://github.com/org/repo-name-with-dashes');

        self::assertSame([
            'owner' => 'org',
            'name' => 'repo-name-with-dashes',
            'host' => 'github.com',
        ], $result);
    }

    public function testIsGitRepositoryHandlesEdgeCases(): void
    {
        $testCases = [
            ['https://github.com', false], // No repository path
            ['https://example.git.com/repo', true], // Domain contains 'git'
            ['git://git.example.com/repo.git', true], // Multiple git indicators
        ];

        foreach ($testCases as [$url, $expected]) {
            $result = $this->subject->isGitRepository($url);
            self::assertSame($expected, $result, "Failed for URL: $url");
        }
    }
}
