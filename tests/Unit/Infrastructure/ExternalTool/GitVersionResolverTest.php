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
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionResolver;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\VcsResolutionStatus;
use CPSIT\UpgradeAnalyzer\Infrastructure\Version\ComposerConstraintCheckerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

#[CoversClass(GitVersionResolver::class)]
final class GitVersionResolverTest extends TestCase
{
    private const PACKAGE = 'vendor/my-extension';
    private const VCS_URL_HTTPS = 'https://github.com/vendor/my-extension.git';
    private const VCS_URL_SSH = 'git@github.com:vendor/my-extension.git';

    private Version $targetVersion;
    /** @var ComposerConstraintCheckerInterface&Stub */
    private ComposerConstraintCheckerInterface $constraintChecker;

    protected function setUp(): void
    {
        $this->targetVersion = new Version('13.4.0');
        $this->constraintChecker = $this->createStub(ComposerConstraintCheckerInterface::class);
    }

    // -----------------------------------------------------------------------
    // Process stub helpers
    // -----------------------------------------------------------------------

    private function makeSuccessProcess(string $stdout, int $exitCode = 0): Process&Stub
    {
        $process = $this->createStub(Process::class);
        $process->method('run')->willReturn($exitCode);
        $process->method('isSuccessful')->willReturn(true);
        $process->method('getOutput')->willReturn($stdout);
        $process->method('getErrorOutput')->willReturn('');
        $process->method('getExitCode')->willReturn($exitCode);

        return $process;
    }

    private function makeFailProcess(string $stderr = '', int $exitCode = 1): Process&Stub
    {
        $process = $this->createStub(Process::class);
        $process->method('run')->willReturn($exitCode);
        $process->method('isSuccessful')->willReturn(false);
        $process->method('getOutput')->willReturn('');
        $process->method('getErrorOutput')->willReturn($stderr);
        $process->method('getExitCode')->willReturn($exitCode);

        return $process;
    }

    /**
     * Extract the git sub-command keyword from a command array.
     * Handles both `git <cmd>` and `git -C <dir> <cmd>` forms.
     */
    private function gitSubCommand(array $cmd): string
    {
        // git -C <dir> <subcommand> [args...]
        if (isset($cmd[1]) && '-C' === $cmd[1]) {
            return $cmd[3] ?? '';
        }

        return $cmd[1] ?? '';
    }

    /** Build ls-remote output lines for the given tag names. */
    private function makeLsRemoteOutput(string ...$tags): string
    {
        $lines = [];
        foreach ($tags as $tag) {
            $sha = str_repeat('a', 40);
            $lines[] = $sha . "\trefs/tags/" . $tag;
        }

        return implode("\n", $lines) . "\n";
    }

    /** Build minimal composer.json with the given TYPO3 constraint. */
    private function makeComposerJson(string $typo3Constraint = '^13.0'): string
    {
        return json_encode(['require' => ['typo3/cms-core' => $typo3Constraint]], JSON_THROW_ON_ERROR);
    }

    /**
     * Build a complete process factory that handles all git sub-commands.
     *
     * @param Process&Stub $composerJsonProcess Process to return for `git show` calls
     */
    private function makeFullFactory(
        string $lsRemoteOutput,
        Process $composerJsonProcess,
        ?string &$capturedCloneDir = null,
    ): \Closure {
        return function (array $cmd) use ($lsRemoteOutput, $composerJsonProcess, &$capturedCloneDir): Process {
            $sub = $this->gitSubCommand($cmd);

            if ('ls-remote' === $sub) {
                return $this->makeSuccessProcess($lsRemoteOutput);
            }

            if ('clone' === $sub) {
                // $cmd = ['git', 'clone', '--depth=1', '--quiet', $url, $tmpDir]
                $dir = $cmd[5] ?? null;
                if (null !== $dir) {
                    @mkdir($dir, 0o755, true);
                    $capturedCloneDir = $dir;
                }

                return $this->makeSuccessProcess('');
            }

            if ('fetch' === $sub) {
                return $this->makeSuccessProcess('');
            }

            if ('show' === $sub) {
                return $composerJsonProcess;
            }

            // rm -rf cleanup or any other command
            return $this->makeSuccessProcess('');
        };
    }

    // -----------------------------------------------------------------------
    // AC-5: null URL → NOT_FOUND
    // -----------------------------------------------------------------------

    #[Test]
    public function nullRepositoryUrlReturnsNotFound(): void
    {
        $resolver = new GitVersionResolver(new NullLogger(), $this->constraintChecker);

        $result = $resolver->resolve(self::PACKAGE, null, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::NOT_FOUND, $result->status);
        self::assertNull($result->latestCompatibleVersion);
    }

    // -----------------------------------------------------------------------
    // AC-5: unsupported VCS URL scheme → FAILURE
    // -----------------------------------------------------------------------

    #[Test]
    public function unsupportedVcsSchemeReturnsFailure(): void
    {
        $resolver = new GitVersionResolver(new NullLogger(), $this->constraintChecker);

        $result = $resolver->resolve(self::PACKAGE, 'svn://example.com/repo', $this->targetVersion);

        self::assertSame(VcsResolutionStatus::FAILURE, $result->status);
    }

    #[Test]
    public function mercurialSchemeReturnsFailure(): void
    {
        $resolver = new GitVersionResolver(new NullLogger(), $this->constraintChecker);

        $result = $resolver->resolve(self::PACKAGE, 'hg://example.com/repo', $this->targetVersion);

        self::assertSame(VcsResolutionStatus::FAILURE, $result->status);
    }

    // -----------------------------------------------------------------------
    // AC-4: SSH pre-check unreachable → SSH_UNREACHABLE
    // -----------------------------------------------------------------------

    #[Test]
    public function sshPreCheckUnreachableReturnsSshUnreachable(): void
    {
        $sshProcess = $this->makeFailProcess('Connection refused', 255);

        $resolver = new GitVersionResolver(
            new NullLogger(),
            $this->constraintChecker,
            60,
            static fn (): Process => $sshProcess,
        );

        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL_SSH, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::SSH_UNREACHABLE, $result->status);
    }

    // -----------------------------------------------------------------------
    // AC-4: HTTPS URL bypasses SSH check
    // -----------------------------------------------------------------------

    #[Test]
    public function httpsUrlBypassesSshCheck(): void
    {
        // For HTTPS, the first call should be git ls-remote, not ssh.
        $lsRemoteOutput = $this->makeLsRemoteOutput('v1.0.0', 'v2.0.0');

        $callCount = 0;
        $factory = function (array $cmd) use ($lsRemoteOutput, &$callCount): Process {
            ++$callCount;

            if ('ls-remote' === $this->gitSubCommand($cmd)) {
                return $this->makeSuccessProcess($lsRemoteOutput);
            }

            // Clone/fetch/show: fail gracefully to stop resolution after ls-remote
            if ('ssh' === ($cmd[0] ?? '')) {
                // Should NOT be called for HTTPS
                self::fail('SSH probe must not be called for HTTPS URLs');
            }

            return $this->makeFailProcess('test stop');
        };

        $resolver = new GitVersionResolver(new NullLogger(), $this->constraintChecker, 60, $factory);
        $resolver->resolve(self::PACKAGE, self::VCS_URL_HTTPS, $this->targetVersion);

        self::assertGreaterThanOrEqual(1, $callCount, 'HTTPS should have reached git ls-remote without SSH check');
    }

    // -----------------------------------------------------------------------
    // AC-1+2: tags returned by ls-remote, lower-bound filter excludes older versions
    // -----------------------------------------------------------------------

    #[Test]
    public function lsRemoteTagsFilteredByInstalledVersion(): void
    {
        // Only v2.0.0 is strictly newer than installedVersion 1.5.0
        $lsRemoteOutput = $this->makeLsRemoteOutput('v1.0.0', 'v1.5.0', 'v2.0.0');

        $this->constraintChecker->method('findTypo3Requirements')->willReturn(['^13.0']);
        $this->constraintChecker->method('isConstraintCompatible')->willReturn(true);

        $composerJsonProcess = $this->makeSuccessProcess($this->makeComposerJson('^13.0'));
        $factory = $this->makeFullFactory($lsRemoteOutput, $composerJsonProcess);

        $resolver = new GitVersionResolver(new NullLogger(), $this->constraintChecker, 60, $factory);

        $result = $resolver->resolve(
            self::PACKAGE,
            self::VCS_URL_HTTPS,
            $this->targetVersion,
            new Version('1.5.0'),
        );

        // Should find v2.0.0 (the only tag strictly newer than 1.5.0)
        self::assertSame(VcsResolutionStatus::RESOLVED_COMPATIBLE, $result->status);
        self::assertSame('2.0.0', $result->latestCompatibleVersion);

        $resolver->reset();
    }

    // -----------------------------------------------------------------------
    // AC-1+2: newest compatible version returned first
    // -----------------------------------------------------------------------

    #[Test]
    public function newestCompatibleVersionIsReturned(): void
    {
        $lsRemoteOutput = $this->makeLsRemoteOutput('v1.0.0', 'v2.0.0', 'v3.0.0');

        $this->constraintChecker->method('findTypo3Requirements')->willReturn(['^13.0']);
        $this->constraintChecker->method('isConstraintCompatible')->willReturn(true);

        $composerJsonProcess = $this->makeSuccessProcess($this->makeComposerJson('^13.0'));
        $factory = $this->makeFullFactory($lsRemoteOutput, $composerJsonProcess);

        $resolver = new GitVersionResolver(new NullLogger(), $this->constraintChecker, 60, $factory);
        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL_HTTPS, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::RESOLVED_COMPATIBLE, $result->status);
        self::assertSame('3.0.0', $result->latestCompatibleVersion);

        $resolver->reset();
    }

    // -----------------------------------------------------------------------
    // No compatible version → RESOLVED_NO_MATCH
    // -----------------------------------------------------------------------

    #[Test]
    public function noCompatibleVersionReturnsResolvedNoMatch(): void
    {
        $lsRemoteOutput = $this->makeLsRemoteOutput('v1.0.0', 'v2.0.0');

        $this->constraintChecker->method('findTypo3Requirements')->willReturn(['^11.0']);
        $this->constraintChecker->method('isConstraintCompatible')->willReturn(false);

        $composerJsonProcess = $this->makeSuccessProcess($this->makeComposerJson('^11.0'));
        $factory = $this->makeFullFactory($lsRemoteOutput, $composerJsonProcess);

        $resolver = new GitVersionResolver(new NullLogger(), $this->constraintChecker, 60, $factory);
        $result = $resolver->resolve(self::PACKAGE, self::VCS_URL_HTTPS, $this->targetVersion);

        self::assertSame(VcsResolutionStatus::RESOLVED_NO_MATCH, $result->status);
        self::assertNull($result->latestCompatibleVersion);

        $resolver->reset();
    }

    // -----------------------------------------------------------------------
    // AC-3: clone reused for second package from same URL
    // -----------------------------------------------------------------------

    #[Test]
    public function cloneIsReusedForSameRepositoryUrl(): void
    {
        $lsRemoteOutput = $this->makeLsRemoteOutput('v1.0.0');
        $this->constraintChecker->method('findTypo3Requirements')->willReturn(['^13.0']);
        $this->constraintChecker->method('isConstraintCompatible')->willReturn(true);

        $cloneCallCount = 0;
        $composerJsonProcess = $this->makeSuccessProcess($this->makeComposerJson('^13.0'));

        $factory = function (array $cmd) use ($lsRemoteOutput, $composerJsonProcess, &$cloneCallCount): Process {
            $sub = $this->gitSubCommand($cmd);

            if ('ls-remote' === $sub) {
                return $this->makeSuccessProcess($lsRemoteOutput);
            }

            if ('clone' === $sub) {
                ++$cloneCallCount;
                $dir = $cmd[5] ?? null;
                if (null !== $dir) {
                    @mkdir($dir, 0o755, true);
                }

                return $this->makeSuccessProcess('');
            }

            if ('fetch' === $sub) {
                return $this->makeSuccessProcess('');
            }

            if ('show' === $sub) {
                return $composerJsonProcess;
            }

            return $this->makeSuccessProcess('');
        };

        $resolver = new GitVersionResolver(new NullLogger(), $this->constraintChecker, 60, $factory);

        // Two packages from the same repository URL
        $resolver->resolve('vendor/pkg-a', self::VCS_URL_HTTPS, $this->targetVersion);
        $resolver->resolve('vendor/pkg-b', self::VCS_URL_HTTPS, $this->targetVersion);

        self::assertSame(1, $cloneCallCount, 'Clone should happen only once for the same repository URL');

        $resolver->reset();
    }
}
