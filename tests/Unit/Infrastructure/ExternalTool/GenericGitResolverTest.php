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
        ?\Closure $archiveFactory = null,
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
            $archiveFactory,
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

        $composerJsonContent = '{"require":{"typo3/cms-core":"^13.4"}}';
        $archiveFactory = fn (string $cmd): Process => $this->makeSuccessProcess($composerJsonContent);

        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($this->lsRemoteOutput('2.0.0', '1.0.0')),
        ], $checker, $archiveFactory);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::RESOLVED_COMPATIBLE, $result->status);
        self::assertSame('2.0.0', $result->latestCompatibleVersion);
        self::assertFalse($result->shouldTryFallback());
    }

    public function testResolvedCompatibleWhenArchiveFailsTreatsAsCompatible(): void
    {
        $checker = $this->createStub(ComposerConstraintCheckerInterface::class);
        // When fetchComposerJson returns null, isCompatible(null, ...) calls findTypo3Requirements([]).
        // Stub returns [] (no TYPO3 requirement) → treated as compatible.
        $checker->method('findTypo3Requirements')->willReturn([]);

        $archiveFactory = fn (string $cmd): Process => $this->makeFailProcess();

        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($this->lsRemoteOutput('1.2.3')),
        ], $checker, $archiveFactory);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::RESOLVED_COMPATIBLE, $result->status);
        self::assertSame('1.2.3', $result->latestCompatibleVersion);
    }

    public function testResolvedCompatibleWhenComposerJsonHasNoTypo3Requirement(): void
    {
        $checker = $this->createStub(ComposerConstraintCheckerInterface::class);
        // No TYPO3 requirements found → treated as compatible
        $checker->method('findTypo3Requirements')->willReturn([]);

        $composerJsonContent = '{"require":{"php":"^8.3"}}';
        $archiveFactory = fn (string $cmd): Process => $this->makeSuccessProcess($composerJsonContent);

        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($this->lsRemoteOutput('3.1.0', '3.0.0')),
        ], $checker, $archiveFactory);

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

        $composerJsonContent = '{"require":{"typo3/cms-core":"^12.4"}}';
        $archiveFactory = fn (string $cmd): Process => $this->makeSuccessProcess($composerJsonContent);

        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($this->lsRemoteOutput('1.0.0')),
        ], $checker, $archiveFactory);

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

        $archiveFactory = fn (string $cmd): Process => $this->makeFailProcess();

        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($output),
        ], $checker, $archiveFactory);

        // Most recent stable: 2.3.4 (stripped v) beats 1.0.0
        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::RESOLVED_COMPATIBLE, $result->status);
        self::assertSame('2.3.4', $result->latestCompatibleVersion);
    }

    /**
     * Verifies that when a tag has a `v` prefix (e.g. `v2.3.4`), the git archive subprocess
     * is invoked with the original tag name (`v2.3.4`), not the normalized version (`2.3.4`).
     * The result's latestCompatibleVersion must still be the normalized form.
     */
    public function testVPrefixedTagUsesOriginalTagNameForGitArchive(): void
    {
        $checker = $this->createStub(ComposerConstraintCheckerInterface::class);
        $checker->method('findTypo3Requirements')->willReturn([]);

        $capturedCmd = '';
        $archiveFactory = function (string $cmd) use (&$capturedCmd): Process {
            $capturedCmd = $cmd;

            return $this->makeFailProcess(); // archive fails → null → no TYPO3 req → compatible
        };

        $resolver = $this->makeResolver([
            $this->makeSuccessProcess($this->lsRemoteOutput('v2.3.4')),
        ], $checker, $archiveFactory);

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::RESOLVED_COMPATIBLE, $result->status);
        // Normalized version (no v prefix) in result
        self::assertSame('2.3.4', $result->latestCompatibleVersion);
        // Original tag name (with v prefix) used in the git archive command
        self::assertStringContainsString('v2.3.4', $capturedCmd);
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
