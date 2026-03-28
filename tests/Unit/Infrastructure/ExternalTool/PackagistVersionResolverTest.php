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

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\PackagistVersionResolver;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\ResolutionStatus;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\VcsResolutionResult;
use CPSIT\UpgradeAnalyzer\Infrastructure\Version\ComposerConstraintCheckerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

#[CoversClass(PackagistVersionResolver::class)]
class PackagistVersionResolverTest extends TestCase
{
    private const PACKAGE = 'vendor/my-extension';
    private const VCS_URL = 'https://github.com/vendor/my-extension';

    private Version $targetVersion;

    protected function setUp(): void
    {
        $this->targetVersion = new Version('13.4.0');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Build a Process stub that simulates a successful process with given output.
     */
    private function makeSuccessProcess(string $stdout, string $stderr = ''): Process&Stub
    {
        $process = $this->createStub(Process::class);
        $process->method('run')->willReturn(0);
        $process->method('isSuccessful')->willReturn(true);
        $process->method('getOutput')->willReturn($stdout);
        $process->method('getErrorOutput')->willReturn($stderr);

        return $process;
    }

    /**
     * Build a Process stub that simulates a failed process.
     */
    private function makeFailProcess(string $stderr = '', string $stdout = ''): Process&Stub
    {
        $process = $this->createStub(Process::class);
        $process->method('run')->willReturn(1);
        $process->method('isSuccessful')->willReturn(false);
        $process->method('getOutput')->willReturn($stdout);
        $process->method('getErrorOutput')->willReturn($stderr);

        return $process;
    }

    /**
     * Build a Process stub that throws ProcessTimedOutException from run().
     */
    private function makeTimedOutProcess(): Process&Stub
    {
        $process = $this->createStub(Process::class);
        $process->method('run')->willThrowException(
            new ProcessTimedOutException($this->createStub(Process::class), ProcessTimedOutException::TYPE_GENERAL),
        );

        return $process;
    }

    /**
     * Composer version check response (successful, version 2.8.9).
     */
    private function composerVersionOutput(): string
    {
        return 'Composer version 2.8.9 2024-11-01 09:34:21 UTC';
    }

    /**
     * Build a resolver whose process factory returns processes from a FIFO queue.
     *
     * @param list<Process> $processQueue
     */
    private function makeResolver(array $processQueue, ?ComposerConstraintCheckerInterface $checker = null): PackagistVersionResolver
    {
        $queue = $processQueue;
        $factory = function (array $command) use (&$queue): Process {
            $process = array_shift($queue);
            $this->assertNotNull($process, 'Process queue exhausted unexpectedly');

            return $process;
        };

        return new PackagistVersionResolver(
            new NullLogger(),
            $checker ?? $this->createStub(ComposerConstraintCheckerInterface::class),
            30,
            $factory,
        );
    }

    /**
     * Encode a standard composer show response.
     *
     * @param list<string>          $versions
     * @param array<string, string> $requires
     */
    private function composerShowJson(array $versions, array $requires = []): string
    {
        return json_encode([
            'name' => self::PACKAGE,
            'versions' => $versions,
            'requires' => $requires,
        ], JSON_THROW_ON_ERROR);
    }

    // -----------------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------------

    public function testResolvedCompatibleLatestVersionIsCompatible(): void
    {
        $checker = $this->createStub(ComposerConstraintCheckerInterface::class);
        $checker->method('findTypo3Requirements')->willReturn(['^13.4']);
        $checker->method('isConstraintCompatible')->willReturn(true);

        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($this->composerVersionOutput()),
            $this->makeSuccessProcess($this->composerShowJson(['2.0.0', '1.0.0'], ['typo3/cms-core' => '^13.4'])),
        ], $checker);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(ResolutionStatus::RESOLVED_COMPATIBLE, $result->status);
        self::assertSame('2.0.0', $result->latestCompatibleVersion);
        self::assertFalse($result->shouldTryFallback());
    }

    public function testResolvedCompatibleBinarySearchFindsOlderVersion(): void
    {
        // Latest (2.0.0) incompatible; 1.5.0 compatible.
        $versions = ['2.0.0', '1.5.0', '1.0.0'];

        $checker = $this->createMock(ComposerConstraintCheckerInterface::class);
        $checker->method('findTypo3Requirements')->willReturn(['^13.4']);
        $checker
            ->expects(self::atLeast(2))
            ->method('isConstraintCompatible')
            ->willReturnOnConsecutiveCalls(false, true);

        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($this->composerVersionOutput()),
            $this->makeSuccessProcess($this->composerShowJson($versions, ['typo3/cms-core' => '^14.0'])),
            $this->makeSuccessProcess($this->composerShowJson(['1.5.0'], ['typo3/cms-core' => '^13.4'])),
        ], $checker);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(ResolutionStatus::RESOLVED_COMPATIBLE, $result->status);
        self::assertSame('1.5.0', $result->latestCompatibleVersion);
    }

    public function testResolvedNoMatch(): void
    {
        $checker = $this->createStub(ComposerConstraintCheckerInterface::class);
        $checker->method('findTypo3Requirements')->willReturn(['^12.4']);
        $checker->method('isConstraintCompatible')->willReturn(false);

        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($this->composerVersionOutput()),
            $this->makeSuccessProcess($this->composerShowJson(['1.0.0'], ['typo3/cms-core' => '^12.4'])),
        ], $checker);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(ResolutionStatus::RESOLVED_NO_MATCH, $result->status);
        self::assertNull($result->latestCompatibleVersion);
        self::assertFalse($result->shouldTryFallback());
    }

    public function testNotOnPackagistFromStderrNotFound(): void
    {
        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($this->composerVersionOutput()),
            $this->makeFailProcess('Package vendor/my-extension not found'),
        ]);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(ResolutionStatus::NOT_ON_PACKAGIST, $result->status);
        self::assertNull($result->latestCompatibleVersion);
        self::assertTrue($result->shouldTryFallback());
    }

    public function testNotOnPackagistFromStderrCouldNotFindPackage(): void
    {
        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($this->composerVersionOutput()),
            $this->makeFailProcess('Could not find package vendor/my-extension'),
        ]);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(ResolutionStatus::NOT_ON_PACKAGIST, $result->status);
        self::assertTrue($result->shouldTryFallback());
    }

    public function testFailureOnNonZeroExitWithoutNotFoundMessage(): void
    {
        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($this->composerVersionOutput()),
            $this->makeFailProcess('Authentication required'),
        ]);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(ResolutionStatus::FAILURE, $result->status);
        self::assertNull($result->latestCompatibleVersion);
        self::assertTrue($result->shouldTryFallback());
    }

    public function testFailureOnProcessTimeout(): void
    {
        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($this->composerVersionOutput()),
            $this->makeTimedOutProcess(),
        ]);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(ResolutionStatus::FAILURE, $result->status);
        self::assertTrue($result->shouldTryFallback());
    }

    public function testFailureOnMalformedJson(): void
    {
        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($this->composerVersionOutput()),
            $this->makeSuccessProcess('not valid json {{{'),
        ]);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(ResolutionStatus::FAILURE, $result->status);
        self::assertTrue($result->shouldTryFallback());
    }

    public function testFailureWhenComposerVersionBelowMinimum(): void
    {
        $resolver = $this->makeResolver([
            $this->makeSuccessProcess('Composer version 2.0.14 2021-05-21 17:03:37'),
        ]);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(ResolutionStatus::FAILURE, $result->status);
        self::assertTrue($result->shouldTryFallback());
    }

    public function testComposerVersionCheckIsCached(): void
    {
        $checker = $this->createStub(ComposerConstraintCheckerInterface::class);
        $checker->method('findTypo3Requirements')->willReturn([]);

        $callCount = 0;
        $factory = function (array $command) use (&$callCount): Process {
            ++$callCount;
            if (1 === $callCount) {
                return $this->makeSuccessProcess($this->composerVersionOutput());
            }

            return $this->makeSuccessProcess($this->composerShowJson(['1.0.0']));
        };

        $resolver = new PackagistVersionResolver(new NullLogger(), $checker, 30, $factory);

        $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);
        $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        // 1 version check + 2 show calls = 3 total
        self::assertSame(3, $callCount);
    }

    public function testResolvedNoMatchWhenVersionsListIsEmpty(): void
    {
        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($this->composerVersionOutput()),
            $this->makeSuccessProcess($this->composerShowJson([])),
        ]);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(ResolutionStatus::RESOLVED_NO_MATCH, $result->status);
        self::assertNull($result->latestCompatibleVersion);
        self::assertFalse($result->shouldTryFallback());
    }

    public function testResolvedNoMatchWhenAllVersionedSubprocessesFail(): void
    {
        // Latest version (1.0.0) is incompatible; all versioned calls for older versions fail.
        $checker = $this->createStub(ComposerConstraintCheckerInterface::class);
        $checker->method('findTypo3Requirements')->willReturn(['^12.4']);
        $checker->method('isConstraintCompatible')->willReturn(false);

        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($this->composerVersionOutput()),
            $this->makeSuccessProcess($this->composerShowJson(['1.0.0', '0.9.0'], ['typo3/cms-core' => '^12.4'])),
            $this->makeFailProcess('network error'),
        ], $checker);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(ResolutionStatus::RESOLVED_NO_MATCH, $result->status);
        self::assertNull($result->latestCompatibleVersion);
    }

    public function testFailureWhenComposerVersionOutputIsUnparseable(): void
    {
        $resolver = $this->makeResolver([
            $this->makeSuccessProcess('some unexpected output without version'),
        ]);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(ResolutionStatus::FAILURE, $result->status);
        self::assertTrue($result->shouldTryFallback());
    }

    public function testShouldTryFallbackReturnsTrueForNotOnPackagist(): void
    {
        $result = new VcsResolutionResult(ResolutionStatus::NOT_ON_PACKAGIST, self::VCS_URL, null);
        self::assertTrue($result->shouldTryFallback());
    }

    public function testShouldTryFallbackReturnsTrueForFailure(): void
    {
        $result = new VcsResolutionResult(ResolutionStatus::FAILURE, self::VCS_URL, null);
        self::assertTrue($result->shouldTryFallback());
    }

    public function testShouldTryFallbackReturnsFalseForResolvedCompatible(): void
    {
        $result = new VcsResolutionResult(ResolutionStatus::RESOLVED_COMPATIBLE, self::VCS_URL, '1.0.0');
        self::assertFalse($result->shouldTryFallback());
    }

    public function testShouldTryFallbackReturnsFalseForResolvedNoMatch(): void
    {
        $result = new VcsResolutionResult(ResolutionStatus::RESOLVED_NO_MATCH, self::VCS_URL, null);
        self::assertFalse($result->shouldTryFallback());
    }
}
