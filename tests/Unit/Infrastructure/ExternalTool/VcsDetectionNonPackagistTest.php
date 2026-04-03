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

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\VcsAvailability;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailability\Source\VcsSource;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\ComposerEnvironment;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\ComposerVersionResolver;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\VcsResolutionStatus;
use CPSIT\UpgradeAnalyzer\Infrastructure\Version\ComposerConstraintChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

/**
 * End-to-end test: non-Packagist package fixture that previously returned Unknown
 * now resolves to Available via the --working-dir fallback (RC-3 fix).
 */
#[CoversClass(VcsSource::class)]
#[CoversClass(ComposerVersionResolver::class)]
final class VcsDetectionNonPackagistTest extends TestCase
{
    private function makeSuccessProcess(array $data): Process&Stub
    {
        $process = $this->createStub(Process::class);
        $process->method('run')->willReturn(0);
        $process->method('isSuccessful')->willReturn(true);
        $process->method('getOutput')->willReturn(json_encode($data, JSON_THROW_ON_ERROR));
        $process->method('getErrorOutput')->willReturn('');

        return $process;
    }

    private function makeNotFoundProcess(): Process&Stub
    {
        $process = $this->createStub(Process::class);
        $process->method('run')->willReturn(1);
        $process->method('isSuccessful')->willReturn(false);
        $process->method('getOutput')->willReturn('');
        $process->method('getErrorOutput')->willReturn('Could not find package vendor/private-ext');

        return $process;
    }

    #[Test]
    public function nonPackagistPackageResolvedViaWorkingDirFallback(): void
    {
        // Fixture: private package not on Packagist, installed at /var/www/typo3
        // RC-2 fix: Extension now has repositoryUrl from source.url in composer.lock
        // RC-1 fix: ComposerVersionResolver falls back to --working-dir
        // RC-3 fix: VcsSource passes installationPath from AnalysisContext

        $compatiblePackageData = [
            'name' => 'vendor/private-ext',
            'versions' => ['2.1.0', '2.0.0'],
            'requires' => ['typo3/cms-core' => '^13.0'],
        ];

        $queue = [
            $this->makeNotFoundProcess(),                             // primary: not on Packagist
            $this->makeSuccessProcess($compatiblePackageData),        // fallback: found via --working-dir
        ];

        $factory = function (array $command) use (&$queue): Process {
            $process = array_shift($queue);
            $this->assertNotNull($process, 'Process queue exhausted for: ' . implode(' ', $command));

            return $process;
        };

        $composerEnv = $this->createStub(ComposerEnvironment::class);
        $composerEnv->method('isVersionSufficient')->willReturn(true);

        $resolver = new ComposerVersionResolver(
            new NullLogger(),
            new ComposerConstraintChecker(),
            $composerEnv,
            30,
            $factory,
        );

        $cacheService = $this->createMock(CacheService::class);
        $cacheService->method('generateSimpleKey')->willReturn('test_key');
        $cacheService->method('has')->willReturn(false);
        $cacheService->expects(self::once())->method('set');

        $source = new VcsSource($resolver, new NullLogger(), $cacheService);

        // RC-2 fix: repositoryUrl is populated from source.url
        $extension = new Extension('private_ext', 'Private Ext', new Version('2.0.0'), 'composer', 'vendor/private-ext');
        $extension->setRepositoryUrl('https://gitlab.example.com/vendor/private-ext.git');

        // RC-3 fix: installationPath is set in AnalysisContext
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            [],
            sys_get_temp_dir(),
        );

        $metrics = $source->checkAvailability($extension, $context);

        // Previously returned Unknown — now resolves to Available
        self::assertSame(VcsAvailability::Available, $metrics['vcs_available']);
        self::assertSame('2.1.0', $metrics['vcs_latest_version']);
        self::assertEmpty($queue, 'Both processes (primary + fallback) should have been consumed');
    }

    #[Test]
    public function nonPackagistPackageWithoutInstallationPathReturnsUnavailable(): void
    {
        // When installationPath is not set, primary NOT_FOUND → no fallback → Unavailable (CP-7).
        $queue = [
            $this->makeNotFoundProcess(), // primary: not on Packagist, no fallback
        ];

        $factory = function (array $command) use (&$queue): Process {
            return array_shift($queue) ?? $this->fail('Unexpected process: ' . implode(' ', $command));
        };

        $composerEnv = $this->createStub(ComposerEnvironment::class);
        $composerEnv->method('isVersionSufficient')->willReturn(true);

        $resolver = new ComposerVersionResolver(new NullLogger(), new ComposerConstraintChecker(), $composerEnv, 30, $factory);

        $cacheService = $this->createMock(CacheService::class);
        $cacheService->method('generateSimpleKey')->willReturn('test_key');
        $cacheService->method('has')->willReturn(false);

        $source = new VcsSource($resolver, new NullLogger(), $cacheService);

        $extension = new Extension('private_ext', 'Private Ext', new Version('2.0.0'), 'composer', 'vendor/private-ext');
        $extension->setRepositoryUrl('https://gitlab.example.com/vendor/private-ext.git');

        // No installationPath → NOT_FOUND from primary → Unavailable (definitive answer)
        $context = new AnalysisContext(new Version('12.4.0'), new Version('13.4.0'));

        $metrics = $source->checkAvailability($extension, $context);

        self::assertSame(VcsAvailability::Unavailable, $metrics['vcs_available']);
        self::assertEmpty($queue, 'Only one primary process should have been consumed');
    }

    #[Test]
    public function resolverReturnsFallbackStatus(): void
    {
        // Verify that ComposerVersionResolver.resolve() returns the correct VcsResolutionStatus
        // when primary is NOT_FOUND and fallback is available
        $compatiblePackageData = [
            'name' => 'vendor/private-ext',
            'versions' => ['1.5.0'],
            'requires' => ['typo3/cms-core' => '^13.4'],
        ];

        $queue = [
            $this->makeNotFoundProcess(),
            $this->makeSuccessProcess($compatiblePackageData),
        ];

        $factory = function (array $command) use (&$queue): Process {
            return array_shift($queue) ?? $this->fail('Unexpected process');
        };

        $composerEnv = $this->createStub(ComposerEnvironment::class);
        $composerEnv->method('isVersionSufficient')->willReturn(true);

        $resolver = new ComposerVersionResolver(new NullLogger(), new ComposerConstraintChecker(), $composerEnv, 30, $factory);

        $result = $resolver->resolve(
            'vendor/private-ext',
            'https://gitlab.example.com/vendor/private-ext.git',
            new Version('13.4.0'),
            sys_get_temp_dir(),
        );

        self::assertSame(VcsResolutionStatus::RESOLVED_COMPATIBLE, $result->status);
        self::assertSame('1.5.0', $result->latestCompatibleVersion);
    }
}
