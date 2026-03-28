<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Analyzer\VersionAvailability\Source;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailability\Source\GitSource;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitAnalysisException;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryAnalyzer;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryInfo;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitTag;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class GitSourceTest extends TestCase
{
    private GitSource $source;
    private GitRepositoryAnalyzer&MockObject $gitAnalyzer;
    private LoggerInterface&MockObject $logger;
    private CacheService&MockObject $cacheService;
    private Extension $extension;
    private AnalysisContext $context;

    protected function setUp(): void
    {
        $this->gitAnalyzer = $this->createMock(GitRepositoryAnalyzer::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->cacheService = $this->createMock(CacheService::class);

        $this->source = new GitSource($this->gitAnalyzer, $this->logger, $this->cacheService);

        $this->extension = new Extension('test_extension', 'Test Title', new Version('1.0.0'));
        $this->context = new AnalysisContext(
            new Version('11.5.0'),
            new Version('12.4.0'),
        );
    }

    public function testGetName(): void
    {
        self::assertSame('git', $this->source->getName());
    }

    public function testCheckAvailabilityReturnsCachedValue(): void
    {
        $this->cacheService->method('generateSimpleKey')->willReturn('cache_key');
        $this->cacheService->expects(self::once())->method('has')->with('cache_key')->willReturn(true);
        $this->cacheService->expects(self::once())->method('get')->with('cache_key')->willReturn(['git_available' => true]);

        $this->gitAnalyzer->expects(self::never())->method('analyzeExtension');

        $result = $this->source->checkAvailability($this->extension, $this->context);

        self::assertSame(['git_available' => true], $result);
    }

    public function testCheckAvailabilityReturnsInfoWhenAvailable(): void
    {
        $gitInfo = $this->createMock(GitRepositoryInfo::class);
        $gitInfo->method('hasCompatibleVersion')->willReturn(true);
        $gitInfo->method('getHealthScore')->willReturn(0.8);
        $gitInfo->method('getRepositoryUrl')->willReturn('https://github.com/vendor/repo');

        $tag = $this->createMock(GitTag::class);
        $tag->method('getName')->willReturn('1.2.3');
        $gitInfo->method('getLatestCompatibleVersion')->willReturn($tag);

        $this->cacheService->method('generateSimpleKey')->willReturn('cache_key');
        $this->cacheService->expects(self::once())->method('has')->with('cache_key')->willReturn(false);
        $expectedResult = [
            'git_available' => true,
            'git_repository_health' => 0.8,
            'git_repository_url' => 'https://github.com/vendor/repo',
            'git_latest_version' => '1.2.3',
        ];
        $this->cacheService->expects(self::once())->method('set')->with('cache_key', $expectedResult);

        $this->gitAnalyzer->expects(self::once())
            ->method('analyzeExtension')
            ->with($this->extension, $this->context->getTargetVersion())
            ->willReturn($gitInfo);

        $result = $this->source->checkAvailability($this->extension, $this->context);

        self::assertSame($expectedResult, $result);
    }

    public function testCheckAvailabilityReturnsInfoWhenAvailableWithoutLatestVersion(): void
    {
        $gitInfo = $this->createMock(GitRepositoryInfo::class);
        $gitInfo->method('hasCompatibleVersion')->willReturn(false);
        $gitInfo->method('getHealthScore')->willReturn(0.5);
        $gitInfo->method('getRepositoryUrl')->willReturn('https://github.com/vendor/repo');
        $gitInfo->method('getLatestCompatibleVersion')->willReturn(null);

        $this->cacheService->method('generateSimpleKey')->willReturn('cache_key');
        $this->cacheService->expects(self::once())->method('has')->with('cache_key')->willReturn(false);
        $expectedResult = [
            'git_available' => false,
            'git_repository_health' => 0.5,
            'git_repository_url' => 'https://github.com/vendor/repo',
        ];
        $this->cacheService->expects(self::once())->method('set')->with('cache_key', $expectedResult);

        $this->gitAnalyzer->expects(self::once())
            ->method('analyzeExtension')
            ->with($this->extension, $this->context->getTargetVersion())
            ->willReturn($gitInfo);

        $result = $this->source->checkAvailability($this->extension, $this->context);

        self::assertSame($expectedResult, $result);
    }

    public function testCheckAvailabilityHandlesGitAnalysisException(): void
    {
        $this->gitAnalyzer->expects(self::once())
            ->method('analyzeExtension')
            ->willThrowException(new GitAnalysisException('Not a git repo'));

        $this->logger->expects(self::once())
            ->method('info')
            ->with(
                'Git analysis skipped for extension',
                self::callback(function ($context) {
                    return 'test_extension' === $context['extension'] && 'Not a git repo' === $context['reason'];
                }),
            );

        $result = $this->source->checkAvailability($this->extension, $this->context);

        self::assertSame([
            'git_available' => false,
            'git_repository_health' => null,
            'git_repository_url' => null,
            'git_latest_version' => null,
        ], $result);
    }

    public function testCheckAvailabilityHandlesOtherExceptions(): void
    {
        $this->gitAnalyzer->expects(self::once())
            ->method('analyzeExtension')
            ->willThrowException(new \RuntimeException('Unexpected error'));

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                'Git availability check failed',
                self::callback(function ($context) {
                    return 'test_extension' === $context['extension'] && 'Unexpected error' === $context['error'];
                }),
            );

        $result = $this->source->checkAvailability($this->extension, $this->context);

        self::assertSame([
            'git_available' => false,
            'git_repository_health' => null,
            'git_repository_url' => null,
            'git_latest_version' => null,
        ], $result);
    }
}
