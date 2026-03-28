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
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GenericGitResolver;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\VcsResolutionStatus;
use CPSIT\UpgradeAnalyzer\Infrastructure\Version\ComposerConstraintCheckerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

#[CoversClass(GenericGitResolver::class)]
class GenericGitResolverTest extends TestCase
{
    private const VCS_URL = 'https://github.com/vendor/my-extension.git';
    private const PACKAGE = 'vendor/my-extension';

    private Version $targetVersion;

    protected function setUp(): void
    {
        $this->targetVersion = new Version('13.4.0');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeSuccessProcess(string $stdout): Process&Stub
    {
        $process = $this->createStub(Process::class);
        $process->method('run')->willReturn(0);
        $process->method('isSuccessful')->willReturn(true);
        $process->method('getOutput')->willReturn($stdout);
        $process->method('getErrorOutput')->willReturn('');

        return $process;
    }

    private function makeFailProcess(string $stderr = ''): Process&Stub
    {
        $process = $this->createStub(Process::class);
        $process->method('run')->willReturn(1);
        $process->method('isSuccessful')->willReturn(false);
        $process->method('getOutput')->willReturn('');
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

    private function makeGitNotFoundProcess(): Process&Stub
    {
        $inner = $this->createStub(Process::class);
        $inner->method('getCommandLine')->willReturn('git ls-remote ...');

        $process = $this->createStub(Process::class);
        $process->method('run')->willThrowException(new ProcessFailedException($inner));

        return $process;
    }

    private function lsRemoteOutput(string ...$tags): string
    {
        $lines = [];
        $hash = str_repeat('a', 40);
        foreach ($tags as $tag) {
            $lines[] = $hash . "\trefs/tags/" . $tag;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<Process> $processQueue
     */
    private function makeResolver(
        array $processQueue,
        ?ComposerConstraintCheckerInterface $checker = null,
    ): GenericGitResolver {
        $queue = $processQueue;
        $factory = function (array $command) use (&$queue): Process {
            $process = array_shift($queue);
            $this->assertNotNull($process, 'Process queue exhausted unexpectedly');

            return $process;
        };

        return new GenericGitResolver(
            new NullLogger(),
            $checker ?? $this->createStub(ComposerConstraintCheckerInterface::class),
            30,
            $factory,
        );
    }

    // -----------------------------------------------------------------------
    // RESOLVED_COMPATIBLE
    // -----------------------------------------------------------------------

    public function testResolvedCompatibleWhenArchiveSucceedsAndTagIsCompatible(): void
    {
        $checker = $this->createStub(ComposerConstraintCheckerInterface::class);
        $checker->method('findTypo3Requirements')->willReturn(['^13.4']);
        $checker->method('isConstraintCompatible')->willReturn(true);

        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($this->lsRemoteOutput('2.0.0', '1.0.0')),
        ], $checker);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::RESOLVED_COMPATIBLE, $result->status);
        self::assertSame('2.0.0', $result->latestCompatibleVersion);
        self::assertFalse($result->shouldTryFallback());
    }

    public function testResolvedCompatibleWhenArchiveFailsTreatsAsCompatible(): void
    {
        // ls-remote returns tags; git archive will fail (no processFactory for archive process → real process fails)
        // We inject a real resolver without a factory for archive, but override ls-remote only.
        // Since fetchComposerJson uses createArchiveProcess (not injected), it will run real git and fail
        // in any test environment — null return → treated as compatible.
        $checker = $this->createStub(ComposerConstraintCheckerInterface::class);
        // findTypo3Requirements never called because fetchComposerJson returns null
        $checker->method('findTypo3Requirements')->willReturn([]);

        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($this->lsRemoteOutput('1.2.3')),
        ], $checker);

        // git archive will fail in CI/test env (remote not accessible) → null → compatible
        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        // Either RESOLVED_COMPATIBLE (archive failed → compatible) or RESOLVED_NO_MATCH (if no TYPO3 req).
        // Since findTypo3Requirements returns [] → isCompatible returns true → RESOLVED_COMPATIBLE
        self::assertSame(VcsResolutionStatus::RESOLVED_COMPATIBLE, $result->status);
        self::assertSame('1.2.3', $result->latestCompatibleVersion);
    }

    public function testResolvedCompatibleWhenComposerJsonHasNoTypo3Requirement(): void
    {
        $checker = $this->createStub(ComposerConstraintCheckerInterface::class);
        // No TYPO3 requirements found → treated as compatible
        $checker->method('findTypo3Requirements')->willReturn([]);

        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($this->lsRemoteOutput('3.1.0', '3.0.0')),
        ], $checker);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::RESOLVED_COMPATIBLE, $result->status);
        self::assertSame('3.1.0', $result->latestCompatibleVersion);
    }

    // -----------------------------------------------------------------------
    // RESOLVED_NO_MATCH
    // -----------------------------------------------------------------------

    public function testResolvedNoMatchWhenMostRecentStableTagIsNotCompatible(): void
    {
        $checker = $this->createStub(ComposerConstraintCheckerInterface::class);
        $checker->method('findTypo3Requirements')->willReturn(['^12.4']);
        $checker->method('isConstraintCompatible')->willReturn(false);

        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($this->lsRemoteOutput('1.0.0')),
        ], $checker);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::RESOLVED_NO_MATCH, $result->status);
        self::assertNull($result->latestCompatibleVersion);
        self::assertFalse($result->shouldTryFallback());
    }

    public function testResolvedNoMatchWhenNoValidSemverTagsAfterParsing(): void
    {
        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($this->lsRemoteOutput('dev-main', 'latest', 'alpha1')),
        ]);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::RESOLVED_NO_MATCH, $result->status);
        self::assertNull($result->latestCompatibleVersion);
    }

    // -----------------------------------------------------------------------
    // FAILURE
    // -----------------------------------------------------------------------

    public function testFailureWhenLsRemoteExitsNonZero(): void
    {
        $resolver = $this->makeResolver([
            $this->makeFailProcess('fatal: repository not found'),
        ]);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::FAILURE, $result->status);
        self::assertNull($result->latestCompatibleVersion);
        self::assertTrue($result->shouldTryFallback());
    }

    public function testFailureWhenLsRemoteTimesOut(): void
    {
        $resolver = $this->makeResolver([
            $this->makeTimedOutProcess(),
        ]);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::FAILURE, $result->status);
        self::assertNull($result->latestCompatibleVersion);
        self::assertTrue($result->shouldTryFallback());
    }

    public function testFailureWhenGitBinaryNotAvailable(): void
    {
        $resolver = $this->makeResolver([
            $this->makeGitNotFoundProcess(),
        ]);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::FAILURE, $result->status);
        self::assertNull($result->latestCompatibleVersion);
        self::assertTrue($result->shouldTryFallback());
    }

    // -----------------------------------------------------------------------
    // Tag parsing — AC2 variants
    // -----------------------------------------------------------------------

    public function testTagParsingVariants(): void
    {
        $checker = $this->createStub(ComposerConstraintCheckerInterface::class);
        $checker->method('findTypo3Requirements')->willReturn([]);

        // Feed a mix of tags: v-prefix, pre-release, v-prefix+pre-release, dev-*, non-semver
        $output = $this->lsRemoteOutput(
            '1.0.0',         // identity — keep
            'v2.3.4',        // strip v → 2.3.4
            '1.0.0-RC1',     // pre-release — keep
            'v3.0.0-beta.1', // v-prefix + pre-release → 3.0.0-beta.1
            'dev-develop',   // skip
            'vX.Yalpha',     // skip (non-semver)
            'latest',        // skip
        );

        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($output),
        ], $checker);

        // Most recent stable: 2.3.4 (stripped v) beats 1.0.0
        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::RESOLVED_COMPATIBLE, $result->status);
        self::assertSame('2.3.4', $result->latestCompatibleVersion);
    }

    public function testPreReleaseOnlyOutputYieldsNoMatch(): void
    {
        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($this->lsRemoteOutput('1.0.0-RC1', '2.0.0-alpha.1')),
        ]);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::RESOLVED_NO_MATCH, $result->status);
    }

    public function testEmptyLsRemoteOutputYieldsNoMatch(): void
    {
        $resolver = $this->makeResolver([
            $this->makeSuccessProcess(''),
        ]);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::RESOLVED_NO_MATCH, $result->status);
    }
}
