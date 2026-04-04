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
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\VcsAvailability;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailability\Source\VcsSource;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\VcsResolutionResult;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\VcsResolutionStatus;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\VcsResolverInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(VcsSource::class)]
final class VcsSourceTest extends TestCase
{
    private VcsSource $source;
    private VcsResolverInterface&MockObject $resolver;
    private LoggerInterface&MockObject $logger;
    private CacheService&MockObject $cacheService;
    private Extension $extension;
    private AnalysisContext $context;

    protected function setUp(): void
    {
        $this->resolver = $this->createMock(VcsResolverInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->cacheService = $this->createMock(CacheService::class);

        $this->source = new VcsSource($this->resolver, $this->logger, $this->cacheService);

        $this->extension = new Extension('test_extension', 'Test Title', new Version('1.0.0'), 'local', 'vendor/test-extension');
        $this->extension->setRepositoryUrl('https://github.com/vendor/test-extension');

        $this->context = new AnalysisContext(
            new Version('11.5.0'),
            new Version('12.4.0'),
        );
    }

    #[Test]
    public function returnsVcsAsName(): void
    {
        self::assertSame('vcs', $this->source->getName());
    }

    #[Test]
    public function resolvesWithoutRepositoryUrl(): void
    {
        $extension = new Extension('test_ext', 'Test', new Version('1.0.0'), 'local', 'vendor/pkg');
        // no repository URL set — resolver is still called, URL is null

        $result = new VcsResolutionResult(VcsResolutionStatus::RESOLVED_NO_MATCH, null, null);

        $this->cacheService->method('generateSimpleKey')->willReturn('cache_key');
        $this->cacheService->method('has')->willReturn(false);
        $this->cacheService->expects(self::once())->method('set');

        $this->resolver->expects(self::once())
            ->method('resolve')
            ->with('vendor/pkg', null, $this->context->getTargetVersion(), null)
            ->willReturn($result);

        $metrics = $this->source->checkAvailability($extension, $this->context);

        self::assertSame([
            'vcs_available' => VcsAvailability::Unavailable,
            'vcs_source_url' => null,
            'vcs_latest_version' => null,
        ], $metrics);
    }

    #[Test]
    public function returnsNullMetricsWhenNoComposerName(): void
    {
        $extension = new Extension('test_ext', 'Test', new Version('1.0.0'));
        $extension->setRepositoryUrl('https://github.com/vendor/repo');

        $this->resolver->expects(self::never())->method('resolve');

        $result = $this->source->checkAvailability($extension, $this->context);

        self::assertSame([
            'vcs_available' => VcsAvailability::Unknown,
            'vcs_source_url' => null,
            'vcs_latest_version' => null,
        ], $result);
    }

    #[Test]
    public function returnsCachedValueOnCacheHit(): void
    {
        $cached = ['vcs_available' => VcsAvailability::Available, 'vcs_source_url' => 'https://github.com/vendor/test-extension', 'vcs_latest_version' => '1.2.3'];

        $this->cacheService->method('generateSimpleKey')->willReturn('cache_key');
        $this->cacheService->expects(self::once())->method('has')->with('cache_key')->willReturn(true);
        $this->cacheService->expects(self::once())->method('get')->with('cache_key')->willReturn($cached);

        $this->resolver->expects(self::never())->method('resolve');

        $result = $this->source->checkAvailability($this->extension, $this->context);

        self::assertSame($cached, $result);
    }

    #[Test]
    public function returnsCompatibleMetricsOnResolvedCompatible(): void
    {
        $resolvedResult = new VcsResolutionResult(
            VcsResolutionStatus::RESOLVED_COMPATIBLE,
            'https://github.com/vendor/test-extension',
            '1.2.3',
        );

        $this->cacheService->method('generateSimpleKey')->willReturn('cache_key');
        $this->cacheService->expects(self::once())->method('has')->willReturn(false);
        $this->cacheService->expects(self::once())->method('set');

        $this->resolver->expects(self::once())
            ->method('resolve')
            ->with('vendor/test-extension', 'https://github.com/vendor/test-extension', $this->context->getTargetVersion(), null)
            ->willReturn($resolvedResult);

        $result = $this->source->checkAvailability($this->extension, $this->context);

        self::assertSame([
            'vcs_available' => VcsAvailability::Available,
            'vcs_source_url' => 'https://github.com/vendor/test-extension',
            'vcs_latest_version' => '1.2.3',
        ], $result);
    }

    #[Test]
    public function returnsNoMatchMetricsOnResolvedNoMatch(): void
    {
        $resolvedResult = new VcsResolutionResult(
            VcsResolutionStatus::RESOLVED_NO_MATCH,
            'https://github.com/vendor/test-extension',
            null,
        );

        $this->cacheService->method('generateSimpleKey')->willReturn('cache_key');
        $this->cacheService->expects(self::once())->method('has')->willReturn(false);
        $this->cacheService->expects(self::once())->method('set');

        $this->resolver->expects(self::once())->method('resolve')->willReturn($resolvedResult);

        $result = $this->source->checkAvailability($this->extension, $this->context);

        self::assertSame([
            'vcs_available' => VcsAvailability::Unavailable,
            'vcs_source_url' => 'https://github.com/vendor/test-extension',
            'vcs_latest_version' => null,
        ], $result);
    }

    #[Test]
    public function returnsNullMetricsAndLogsWarningOnFailure(): void
    {
        $resolvedResult = new VcsResolutionResult(
            VcsResolutionStatus::FAILURE,
            'https://github.com/vendor/test-extension',
            null,
        );

        $this->cacheService->method('generateSimpleKey')->willReturn('cache_key');
        $this->cacheService->expects(self::once())->method('has')->willReturn(false);
        $this->cacheService->expects(self::never())->method('set');

        $this->resolver->expects(self::once())->method('resolve')->willReturn($resolvedResult);

        $this->logger->expects(self::once())->method('warning')
            ->with(
                self::stringContains('could not be resolved'),
                self::callback(fn ($ctx): bool => 'vendor/test-extension' === $ctx['package'] && 'https://github.com/vendor/test-extension' === $ctx['url']),
            );

        $result = $this->source->checkAvailability($this->extension, $this->context);

        self::assertSame([
            'vcs_available' => VcsAvailability::Unknown,
            'vcs_source_url' => null,
            'vcs_latest_version' => null,
        ], $result);
    }

    #[Test]
    public function returnsUnavailableAndLogsDebugOnNotFound(): void
    {
        // CP-7: NOT_FOUND is a definitive answer → Unavailable (not Unknown).
        // Source URL is propagated; no warning logged (only debug).
        $resolvedResult = new VcsResolutionResult(
            VcsResolutionStatus::NOT_FOUND,
            'https://github.com/vendor/test-extension',
            null,
        );

        $this->cacheService->method('generateSimpleKey')->willReturn('cache_key');
        $this->cacheService->method('has')->willReturn(false);
        $this->cacheService->expects(self::once())->method('set');

        $this->resolver->method('resolve')->willReturn($resolvedResult);

        $this->logger->expects(self::never())->method('warning');
        $this->logger->expects(self::once())->method('debug');

        $result = $this->source->checkAvailability($this->extension, $this->context);

        self::assertSame([
            'vcs_available' => VcsAvailability::Unavailable,
            'vcs_source_url' => 'https://github.com/vendor/test-extension',
            'vcs_latest_version' => null,
        ], $result);
    }

    #[Test]
    public function emitsWarningOnlyOnceForSameUrl(): void
    {
        $resolvedResult = new VcsResolutionResult(VcsResolutionStatus::FAILURE, null, null);

        // Two extensions sharing the same repository URL
        $extension2 = new Extension('other_ext', 'Other', new Version('1.0.0'), 'local', 'vendor/other-pkg');
        $extension2->setRepositoryUrl('https://github.com/vendor/test-extension');

        $this->cacheService->method('generateSimpleKey')->willReturn('key1', 'key2');
        $this->cacheService->method('has')->willReturn(false);
        $this->resolver->method('resolve')->willReturn($resolvedResult);

        // Warning must fire exactly once for the same URL
        $this->logger->expects(self::once())->method('warning');

        $this->source->checkAvailability($this->extension, $this->context);
        $this->source->checkAvailability($extension2, $this->context);
    }

    #[Test]
    public function emitsWarningForEachDistinctUrl(): void
    {
        $resolvedResult = new VcsResolutionResult(VcsResolutionStatus::FAILURE, null, null);

        $extension2 = new Extension('other_ext', 'Other', new Version('1.0.0'), 'local', 'vendor/other-pkg');
        $extension2->setRepositoryUrl('https://gitlab.example.com/vendor/other-pkg');

        $this->cacheService->method('generateSimpleKey')->willReturn('key1', 'key2');
        $this->cacheService->method('has')->willReturn(false);
        $this->resolver->method('resolve')->willReturn($resolvedResult);

        // Warning must fire once per distinct URL
        $this->logger->expects(self::exactly(2))->method('warning');

        $this->source->checkAvailability($this->extension, $this->context);
        $this->source->checkAvailability($extension2, $this->context);
    }

    #[Test]
    public function doesNotCacheFailureResults(): void
    {
        $resolvedResult = new VcsResolutionResult(VcsResolutionStatus::FAILURE, 'https://github.com/vendor/test-extension', null);

        $this->cacheService->method('generateSimpleKey')->willReturn('cache_key');
        $this->cacheService->method('has')->willReturn(false);
        $this->cacheService->expects(self::never())->method('set');

        $this->resolver->method('resolve')->willReturn($resolvedResult);
        $this->logger->method('warning');

        $this->source->checkAvailability($this->extension, $this->context);
    }

    #[Test]
    public function sshFailureEmitsSshSpecificWarning(): void
    {
        $sshExtension = new Extension('ssh_ext', 'SSH Ext', new Version('1.0.0'), 'local', 'vendor/ssh-ext');
        $sshExtension->setRepositoryUrl('git@gitlab.example.com:vendor/ssh-ext.git');

        $resolvedResult = new VcsResolutionResult(VcsResolutionStatus::FAILURE, 'git@gitlab.example.com:vendor/ssh-ext.git', null);

        $this->cacheService->method('generateSimpleKey')->willReturn('ssh_cache_key');
        $this->cacheService->method('has')->willReturn(false);

        $this->resolver->method('resolve')->willReturn($resolvedResult);

        $this->logger->expects(self::once())->method('warning')
            ->with(
                self::stringContains('SSH authentication may not be configured'),
                self::callback(fn ($ctx): bool => 'vendor/ssh-ext' === $ctx['package']),
            );

        $this->source->checkAvailability($sshExtension, $this->context);
    }

    #[Test]
    public function installationPathPassedToResolver(): void
    {
        $contextWithPath = new AnalysisContext(
            new Version('11.5.0'),
            new Version('12.4.0'),
            [],
            [],
            '/var/www/typo3',
        );

        $resolvedResult = new VcsResolutionResult(VcsResolutionStatus::RESOLVED_COMPATIBLE, 'https://github.com/vendor/test-extension', '1.5.0');

        $this->cacheService->method('generateSimpleKey')->willReturn('path_cache_key');
        $this->cacheService->method('has')->willReturn(false);
        $this->cacheService->expects(self::once())->method('set');

        $this->resolver->expects(self::once())
            ->method('resolve')
            ->with('vendor/test-extension', 'https://github.com/vendor/test-extension', $contextWithPath->getTargetVersion(), '/var/www/typo3')
            ->willReturn($resolvedResult);

        $result = $this->source->checkAvailability($this->extension, $contextWithPath);

        self::assertSame(VcsAvailability::Available, $result['vcs_available']);
    }

    #[Test]
    public function returnsSshUnreachableAsUnknownWithHostLevelWarning(): void
    {
        $sshExtension = new Extension('ssh_ext', 'SSH Ext', new Version('1.0.0'), 'local', 'vendor/ssh-ext');
        $sshExtension->setRepositoryUrl('git@github.com:vendor/ssh-ext.git');

        $resolvedResult = new VcsResolutionResult(VcsResolutionStatus::SSH_UNREACHABLE, 'git@github.com:vendor/ssh-ext.git', null);

        $this->cacheService->method('generateSimpleKey')->willReturn('ssh_cache_key');
        $this->cacheService->method('has')->willReturn(false);
        $this->cacheService->expects(self::never())->method('set');

        $this->resolver->method('resolve')->willReturn($resolvedResult);

        $this->logger->expects(self::once())->method('warning')
            ->with(
                self::stringContains('not reachable'),
                self::callback(fn ($ctx): bool => 'github.com' === $ctx['host']),
            );

        $result = $this->source->checkAvailability($sshExtension, $this->context);

        self::assertSame(VcsAvailability::Unknown, $result['vcs_available']);
    }

    #[Test]
    public function sshHostWarningEmittedOnlyOncePerHost(): void
    {
        // Two packages on the same SSH host → one host-level warning, not two.
        $ext1 = new Extension('pkg1', 'Pkg1', new Version('1.0.0'), 'local', 'vendor/pkg1');
        $ext1->setRepositoryUrl('git@github.com:vendor/pkg1.git');

        $ext2 = new Extension('pkg2', 'Pkg2', new Version('1.0.0'), 'local', 'vendor/pkg2');
        $ext2->setRepositoryUrl('git@github.com:vendor/pkg2.git');

        $resolvedResult = new VcsResolutionResult(VcsResolutionStatus::SSH_UNREACHABLE, null, null);

        $this->cacheService->method('generateSimpleKey')->willReturn('key1', 'key2');
        $this->cacheService->method('has')->willReturn(false);
        $this->resolver->method('resolve')->willReturn($resolvedResult);

        // One warning for the host (not per package).
        $this->logger->expects(self::once())->method('warning');

        $this->source->checkAvailability($ext1, $this->context);
        $this->source->checkAvailability($ext2, $this->context);
    }
}
