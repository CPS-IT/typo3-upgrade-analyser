<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\ExternalTool;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitAnalysisException;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitProviderFactory;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitProviderInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryAnalyzer;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryHealth;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryMetadata;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitTag;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test case for GitRepositoryAnalyzer.
 *
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryAnalyzer
 */
class GitRepositoryAnalyzerTest extends TestCase
{
    private GitRepositoryAnalyzer $analyzer;
    private GitProviderFactory&MockObject $providerFactory;
    private GitVersionParser&MockObject $versionParser;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->providerFactory = $this->createMock(GitProviderFactory::class);
        $this->versionParser = $this->createMock(GitVersionParser::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->analyzer = new GitRepositoryAnalyzer(
            $this->providerFactory,
            $this->versionParser,
            $this->logger,
        );
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryAnalyzer::analyzeExtension
     */
    public function testAnalyzeExtensionWithGitRepositoryUrl(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', Version::fromString('1.0.0'));
        $extension->setRepositoryUrl('https://github.com/user/test-ext');
        $targetVersion = Version::fromString('12.4.0');

        $provider = $this->createMock(GitProviderInterface::class);
        $this->providerFactory->expects($this->once())
            ->method('createProvider')
            ->with('https://github.com/user/test-ext')
            ->willReturn($provider);

        $metadata = new GitRepositoryMetadata(
            name: 'test-ext',
            description: 'Test extension',
            isArchived: false,
            isFork: false,
            starCount: 10,
            forkCount: 2,
            lastUpdated: new \DateTimeImmutable('2024-01-15'),
            defaultBranch: 'main',
        );

        $health = new GitRepositoryHealth(
            lastCommitDate: new \DateTimeImmutable('2024-01-10'),
            starCount: 10,
            forkCount: 2,
            openIssuesCount: 3,
            closedIssuesCount: 15,
            isArchived: false,
            hasReadme: true,
            hasLicense: true,
            contributorCount: 5,
        );

        $tags = [
            new GitTag('v12.4.0', new \DateTimeImmutable('2024-01-15')),
            new GitTag('v11.5.0', new \DateTimeImmutable('2023-12-01')),
        ];

        $compatibleTags = [
            new GitTag('v12.4.0', new \DateTimeImmutable('2024-01-15')),
        ];

        $provider->expects($this->once())
            ->method('getRepositoryInfo')
            ->with('https://github.com/user/test-ext')
            ->willReturn($metadata);

        $provider->expects($this->once())
            ->method('getRepositoryHealth')
            ->with('https://github.com/user/test-ext')
            ->willReturn($health);

        $provider->expects($this->once())
            ->method('getTags')
            ->with('https://github.com/user/test-ext')
            ->willReturn($tags);

        $this->versionParser->expects($this->once())
            ->method('findCompatibleVersions')
            ->with($tags, $targetVersion)
            ->willReturn($compatibleTags);

        $result = $this->analyzer->analyzeExtension($extension, $targetVersion);

        $this->assertEquals('https://github.com/user/test-ext', $result->getRepositoryUrl());
        $this->assertEquals($metadata, $result->getMetadata());
        $this->assertEquals($health, $result->getHealth());
        $this->assertEquals($compatibleTags, $result->getCompatibleVersions());
        $this->assertTrue($result->hasCompatibleVersion());
        $this->assertEquals('v12.4.0', $result->getLatestCompatibleVersion()->getName());
        $this->assertGreaterThan(0, $result->getHealthScore());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryAnalyzer::analyzeExtension
     */
    public function testAnalyzeExtensionWithoutRepositoryUrl(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', Version::fromString('1.0.0'));
        $targetVersion = Version::fromString('12.4.0');

        $this->expectException(GitAnalysisException::class);
        $this->expectExceptionMessage('No Git repository URL found for extension: test_ext');

        $this->analyzer->analyzeExtension($extension, $targetVersion);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryAnalyzer::analyzeExtension
     */
    public function testAnalyzeExtensionWithUnsupportedRepository(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', Version::fromString('1.0.0'));
        $extension->setRepositoryUrl('https://custom-git.example.com/user/repo.git');
        $targetVersion = Version::fromString('12.4.0');

        $this->providerFactory->expects($this->once())
            ->method('createProvider')
            ->with('https://custom-git.example.com/user/repo.git')
            ->willThrowException(new GitAnalysisException('No suitable Git provider found'));

        $this->expectException(GitAnalysisException::class);
        $this->expectExceptionMessage('No suitable Git provider found');

        $this->analyzer->analyzeExtension($extension, $targetVersion);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryAnalyzer::analyzeExtension
     */
    public function testAnalyzeExtensionWithProviderError(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', Version::fromString('1.0.0'));
        $extension->setRepositoryUrl('https://github.com/user/test-ext');
        $targetVersion = Version::fromString('12.4.0');

        $provider = $this->createMock(GitProviderInterface::class);
        $this->providerFactory->expects($this->once())
            ->method('createProvider')
            ->willReturn($provider);

        $provider->expects($this->once())
            ->method('getRepositoryInfo')
            ->willThrowException(new \RuntimeException('API rate limit exceeded'));

        $this->expectException(GitAnalysisException::class);
        $this->expectExceptionMessage('Failed to analyze Git repository: API rate limit exceeded');

        $this->analyzer->analyzeExtension($extension, $targetVersion);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryAnalyzer::analyzeExtension
     */
    public function testExtractRepositoryUrlFromEmExtConf(): void
    {
        $extension = new Extension('test_ext', 'Test Extension', Version::fromString('1.0.0'));
        $extension->setEmConfiguration([
            'CGLcompliance' => '',
            'CGLcompliance_note' => '',
            'constraints' => [
                'depends' => [
                    'typo3' => '11.5.0-12.4.99',
                ],
            ],
            'state' => 'stable',
            'uploadfolder' => 0,
            'createDirs' => '',
            'clearCacheOnLoad' => 0,
            'lockType' => '',
            'author' => 'Test Author',
            'author_email' => 'test@example.com',
            'author_company' => 'Test Company',
            'version' => '1.0.0',
            'description' => 'Test extension',
            'title' => 'Test Extension',
            'category' => 'misc',
            'shy' => 0,
            'dependencies' => '',
            'conflicts' => '',
            'priority' => '',
            'module' => '',
            'doNotLoadInFE' => 0,
            'docPath' => '',
            'sourceforge_username' => '',
            'github_username' => '',
            'git_repository_url' => 'https://github.com/user/test-ext.git',
        ]);

        $targetVersion = Version::fromString('12.4.0');

        $provider = $this->createMock(GitProviderInterface::class);
        $this->providerFactory->expects($this->once())
            ->method('createProvider')
            ->with('https://github.com/user/test-ext.git')
            ->willReturn($provider);

        // Mock successful analysis
        $provider->method('getRepositoryInfo')->willReturn(
            new GitRepositoryMetadata('test-ext', '', false, false, 0, 0, new \DateTimeImmutable(), 'main'),
        );
        $provider->method('getRepositoryHealth')->willReturn(
            new GitRepositoryHealth(new \DateTimeImmutable(), 0, 0, 0, 0, false, false, false, 0),
        );
        $provider->method('getTags')->willReturn([]);
        $this->versionParser->method('findCompatibleVersions')->willReturn([]);

        $result = $this->analyzer->analyzeExtension($extension, $targetVersion);

        $this->assertEquals('https://github.com/user/test-ext.git', $result->getRepositoryUrl());
    }
}
