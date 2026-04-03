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
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\ComposerEnvironment;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\ComposerVersionResolver;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\VcsResolutionResult;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\VcsResolutionStatus;
use CPSIT\UpgradeAnalyzer\Infrastructure\Version\ComposerConstraintCheckerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

#[CoversClass(ComposerVersionResolver::class)]
class ComposerVersionResolverTest extends TestCase
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

    private function makeSuccessProcess(string $stdout, string $stderr = ''): Process&Stub
    {
        $process = $this->createStub(Process::class);
        $process->method('run')->willReturn(0);
        $process->method('isSuccessful')->willReturn(true);
        $process->method('getOutput')->willReturn($stdout);
        $process->method('getErrorOutput')->willReturn($stderr);

        return $process;
    }

    private function makeFailProcess(string $stderr = '', string $stdout = ''): Process&Stub
    {
        $process = $this->createStub(Process::class);
        $process->method('run')->willReturn(1);
        $process->method('isSuccessful')->willReturn(false);
        $process->method('getOutput')->willReturn($stdout);
        $process->method('getErrorOutput')->willReturn($stderr);

        return $process;
    }

    private function makeTimedOutProcess(): Process&Stub
    {
        $process = $this->createStub(Process::class);
        $process->method('run')->willThrowException(
            new ProcessTimedOutException($this->createStub(Process::class), ProcessTimedOutException::TYPE_GENERAL),
        );

        return $process;
    }

    /**
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

    private function makeComposerEnvironment(bool $versionSufficient = true): ComposerEnvironment&Stub
    {
        $env = $this->createStub(ComposerEnvironment::class);
        $env->method('isVersionSufficient')->willReturn($versionSufficient);

        return $env;
    }

    /**
     * Build a resolver whose process factory returns processes from a FIFO queue.
     *
     * @param list<Process> $processQueue
     */
    private function makeResolver(
        array $processQueue,
        ?ComposerConstraintCheckerInterface $checker = null,
        bool $composerVersionSufficient = true,
    ): ComposerVersionResolver {
        $queue = $processQueue;
        $factory = function (array $command) use (&$queue): Process {
            $process = array_shift($queue);
            $this->assertNotNull($process, 'Process queue exhausted unexpectedly');

            return $process;
        };

        return new ComposerVersionResolver(
            new NullLogger(),
            $checker ?? $this->createStub(ComposerConstraintCheckerInterface::class),
            $this->makeComposerEnvironment($composerVersionSufficient),
            30,
            $factory,
        );
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
            $this->makeSuccessProcess($this->composerShowJson(['2.0.0', '1.0.0'], ['typo3/cms-core' => '^13.4'])),
        ], $checker);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::RESOLVED_COMPATIBLE, $result->status);
        self::assertSame('2.0.0', $result->latestCompatibleVersion);
        self::assertFalse($result->shouldTryFallback());
    }

    public function testResolvedCompatibleLinearScanFindsOlderVersion(): void
    {
        $versions = ['2.0.0', '1.5.0', '1.0.0'];

        $checker = $this->createMock(ComposerConstraintCheckerInterface::class);
        $checker->method('findTypo3Requirements')->willReturn(['^13.4']);
        $checker
            ->expects(self::atLeast(2))
            ->method('isConstraintCompatible')
            ->willReturnOnConsecutiveCalls(false, true);

        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($this->composerShowJson($versions, ['typo3/cms-core' => '^14.0'])),
            $this->makeSuccessProcess($this->composerShowJson(['1.5.0'], ['typo3/cms-core' => '^13.4'])),
        ], $checker);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::RESOLVED_COMPATIBLE, $result->status);
        self::assertSame('1.5.0', $result->latestCompatibleVersion);
    }

    public function testResolvedNoMatch(): void
    {
        $checker = $this->createStub(ComposerConstraintCheckerInterface::class);
        $checker->method('findTypo3Requirements')->willReturn(['^12.4']);
        $checker->method('isConstraintCompatible')->willReturn(false);

        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($this->composerShowJson(['1.0.0'], ['typo3/cms-core' => '^12.4'])),
        ], $checker);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::RESOLVED_NO_MATCH, $result->status);
        self::assertNull($result->latestCompatibleVersion);
        self::assertFalse($result->shouldTryFallback());
    }

    public function testResolvedNoMatchWhenVersionsListIsEmpty(): void
    {
        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($this->composerShowJson([])),
        ]);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::RESOLVED_NO_MATCH, $result->status);
        self::assertNull($result->latestCompatibleVersion);
        self::assertFalse($result->shouldTryFallback());
    }

    public function testResolvedNoMatchWhenAllVersionedSubprocessesFail(): void
    {
        $checker = $this->createStub(ComposerConstraintCheckerInterface::class);
        $checker->method('findTypo3Requirements')->willReturn(['^12.4']);
        $checker->method('isConstraintCompatible')->willReturn(false);

        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($this->composerShowJson(['1.0.0', '0.9.0'], ['typo3/cms-core' => '^12.4'])),
            $this->makeFailProcess('network error'),
        ], $checker);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::RESOLVED_NO_MATCH, $result->status);
        self::assertNull($result->latestCompatibleVersion);
    }

    public function testNotFoundFromStderrNotFound(): void
    {
        $resolver = $this->makeResolver([
            $this->makeFailProcess('Package vendor/my-extension not found'),
        ]);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::NOT_FOUND, $result->status);
        self::assertNull($result->latestCompatibleVersion);
        self::assertTrue($result->shouldTryFallback());
    }

    public function testNotFoundFromStderrCouldNotFindPackage(): void
    {
        $resolver = $this->makeResolver([
            $this->makeFailProcess('Could not find package vendor/my-extension'),
        ]);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::NOT_FOUND, $result->status);
        self::assertTrue($result->shouldTryFallback());
    }

    public function testFailureOnNonZeroExitWithoutNotFoundMessage(): void
    {
        $resolver = $this->makeResolver([
            $this->makeFailProcess('Authentication required'),
        ]);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::FAILURE, $result->status);
        self::assertNull($result->latestCompatibleVersion);
        self::assertTrue($result->shouldTryFallback());
    }

    public function testFailureOnProcessTimeout(): void
    {
        $resolver = $this->makeResolver([
            $this->makeTimedOutProcess(),
        ]);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::FAILURE, $result->status);
        self::assertTrue($result->shouldTryFallback());
    }

    public function testFailureOnMalformedJson(): void
    {
        $resolver = $this->makeResolver([
            $this->makeSuccessProcess('not valid json {{{'),
        ]);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::FAILURE, $result->status);
        self::assertTrue($result->shouldTryFallback());
    }

    public function testFailureWhenComposerEnvironmentReportsInsufficientVersion(): void
    {
        $resolver = $this->makeResolver([], null, false);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::FAILURE, $result->status);
        self::assertTrue($result->shouldTryFallback());
    }

    public function testShouldTryFallbackReturnsTrueForNotFound(): void
    {
        $result = new VcsResolutionResult(VcsResolutionStatus::NOT_FOUND, self::VCS_URL, null);
        self::assertTrue($result->shouldTryFallback());
    }

    public function testShouldTryFallbackReturnsTrueForFailure(): void
    {
        $result = new VcsResolutionResult(VcsResolutionStatus::FAILURE, self::VCS_URL, null);
        self::assertTrue($result->shouldTryFallback());
    }

    public function testShouldTryFallbackReturnsFalseForResolvedCompatible(): void
    {
        $result = new VcsResolutionResult(VcsResolutionStatus::RESOLVED_COMPATIBLE, self::VCS_URL, '1.0.0');
        self::assertFalse($result->shouldTryFallback());
    }

    public function testShouldTryFallbackReturnsFalseForResolvedNoMatch(): void
    {
        $result = new VcsResolutionResult(VcsResolutionStatus::RESOLVED_NO_MATCH, self::VCS_URL, null);
        self::assertFalse($result->shouldTryFallback());
    }

    // -----------------------------------------------------------------------
    // --working-dir fallback tests (AC-2, AC-7)
    // -----------------------------------------------------------------------

    public function testPrimaryFoundNoFallbackAttempted(): void
    {
        $checker = $this->createStub(ComposerConstraintCheckerInterface::class);
        $checker->method('findTypo3Requirements')->willReturn(['^13.4']);
        $checker->method('isConstraintCompatible')->willReturn(true);

        // Only one process in queue — proves fallback never called
        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($this->composerShowJson(['1.0.0'], ['typo3/cms-core' => '^13.4'])),
        ], $checker);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion, '/var/www/typo3');

        self::assertSame(VcsResolutionStatus::RESOLVED_COMPATIBLE, $result->status);
    }

    public function testFallbackSucceedsWhenPrimaryNotFound(): void
    {
        $checker = $this->createStub(ComposerConstraintCheckerInterface::class);
        $checker->method('findTypo3Requirements')->willReturn(['^13.4']);
        $checker->method('isConstraintCompatible')->willReturn(true);

        $resolver = $this->makeResolver([
            $this->makeFailProcess('Package vendor/my-extension not found'),       // primary NOT_FOUND
            $this->makeSuccessProcess($this->composerShowJson(['1.0.0'], ['typo3/cms-core' => '^13.4'])), // fallback
        ], $checker);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion, '/var/www/typo3');

        self::assertSame(VcsResolutionStatus::RESOLVED_COMPATIBLE, $result->status);
        self::assertSame('1.0.0', $result->latestCompatibleVersion);
    }

    public function testNoFallbackWhenInstallationPathIsNull(): void
    {
        // primary NOT_FOUND, no installation path -> returns NOT_FOUND without fallback
        $resolver = $this->makeResolver([
            $this->makeFailProcess('Package vendor/my-extension not found'),
        ]);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion, null);

        self::assertSame(VcsResolutionStatus::NOT_FOUND, $result->status);
    }

    public function testFallbackAlsoFailsReturnsFallbackResult(): void
    {
        $resolver = $this->makeResolver([
            $this->makeFailProcess('Package vendor/my-extension not found'), // primary NOT_FOUND
            $this->makeFailProcess('Could not find package vendor/my-extension'), // fallback also NOT_FOUND
        ]);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion, '/var/www/typo3');

        self::assertSame(VcsResolutionStatus::NOT_FOUND, $result->status);
    }

    public function testHttpsSourceUrlBypassesSshCheck(): void
    {
        $checker = $this->createStub(ComposerConstraintCheckerInterface::class);
        $checker->method('findTypo3Requirements')->willReturn(['^13.4']);
        $checker->method('isConstraintCompatible')->willReturn(true);

        // primary NOT_FOUND, HTTPS url -> fallback attempted without SSH check
        $resolver = $this->makeResolver([
            $this->makeFailProcess('Package vendor/my-extension not found'),
            $this->makeSuccessProcess($this->composerShowJson(['2.0.0'], ['typo3/cms-core' => '^13.4'])),
        ], $checker);

        $result = $resolver->resolve(self::PACKAGE, 'https://github.com/vendor/ext.git', $this->targetVersion, '/var/www/typo3');

        self::assertSame(VcsResolutionStatus::RESOLVED_COMPATIBLE, $result->status);
    }

    // -----------------------------------------------------------------------
    // SSH connectivity check tests (AC-5)
    // -----------------------------------------------------------------------

    public function testUnreachableSshHostSkipsFallback(): void
    {
        // Queue: primary NOT_FOUND, ssh check exit 255 (unreachable)
        // No fallback composer process should be consumed
        $sshProcess = $this->createStub(Process::class);
        $sshProcess->method('run')->willReturn(255);
        $sshProcess->method('isSuccessful')->willReturn(false);
        $sshProcess->method('getExitCode')->willReturn(255);
        $sshProcess->method('getOutput')->willReturn('');
        $sshProcess->method('getErrorOutput')->willReturn('ssh: connect to host gitlab.example.com port 22: Connection refused');

        $queue = [
            $this->makeFailProcess('Package vendor/my-extension not found'), // primary
            $sshProcess, // ssh -T check
        ];

        $factory = function (array $command) use (&$queue): Process {
            $process = array_shift($queue);
            $this->assertNotNull($process, 'Unexpected process request');

            return $process;
        };

        $resolver = new ComposerVersionResolver(
            new NullLogger(),
            $this->createStub(ComposerConstraintCheckerInterface::class),
            $this->makeComposerEnvironment(),
            30,
            $factory,
        );

        $result = $resolver->resolve(self::PACKAGE, 'git@gitlab.example.com:vendor/ext.git', $this->targetVersion, '/var/www/typo3');

        // Queue must be fully consumed (no extra processes)
        self::assertEmpty($queue, 'Not all expected processes were consumed');
        self::assertSame(VcsResolutionStatus::NOT_FOUND, $result->status);
    }

    public function testSshHostCheckIsCachedPerHost(): void
    {
        // Two packages on the same SSH host -> SSH check runs only once
        $checker = $this->createStub(ComposerConstraintCheckerInterface::class);
        $checker->method('findTypo3Requirements')->willReturn(['^13.4']);
        $checker->method('isConstraintCompatible')->willReturn(true);

        $sshProcess = $this->createStub(Process::class);
        $sshProcess->method('run')->willReturn(0);
        $sshProcess->method('isSuccessful')->willReturn(true);
        $sshProcess->method('getExitCode')->willReturn(1); // git hosts typically return 1 for "hi!"
        $sshProcess->method('getOutput')->willReturn('Hi vendor!');
        $sshProcess->method('getErrorOutput')->willReturn('');

        $queue = [
            // pkg1 primary NOT_FOUND
            $this->makeFailProcess('Package vendor/pkg1 not found'),
            // ssh -T check for github.com (cached after this)
            $sshProcess,
            // pkg1 fallback
            $this->makeSuccessProcess($this->composerShowJson(['1.0.0'], ['typo3/cms-core' => '^13.4'])),
            // pkg2 primary NOT_FOUND
            $this->makeFailProcess('Package vendor/pkg2 not found'),
            // pkg2 fallback (no extra ssh check)
            $this->makeSuccessProcess($this->composerShowJson(['2.0.0'], ['typo3/cms-core' => '^13.4'])),
        ];

        $factory = function (array $command) use (&$queue): Process {
            $process = array_shift($queue);
            $this->assertNotNull($process, 'Process queue exhausted unexpectedly for command: ' . implode(' ', $command));

            return $process;
        };

        $resolver = new ComposerVersionResolver(new NullLogger(), $checker, $this->makeComposerEnvironment(), 30, $factory);

        $result1 = $resolver->resolve('vendor/pkg1', 'git@github.com:vendor/pkg1.git', $this->targetVersion, '/var/www/typo3');
        $result2 = $resolver->resolve('vendor/pkg2', 'git@github.com:vendor/pkg2.git', $this->targetVersion, '/var/www/typo3');

        self::assertEmpty($queue, 'Not all expected processes were consumed');
        self::assertSame(VcsResolutionStatus::RESOLVED_COMPATIBLE, $result1->status);
        self::assertSame(VcsResolutionStatus::RESOLVED_COMPATIBLE, $result2->status);
    }
}
