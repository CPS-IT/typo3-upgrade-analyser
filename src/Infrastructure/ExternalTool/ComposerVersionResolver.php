<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool;

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Version\ComposerConstraintCheckerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Resolves version availability for VCS-hosted extensions via Composer CLI.
 *
 * Pre-condition: intended for packages that appear in a DeclaredRepository
 * (i.e. VCS-sourced extensions). May be called for any package name — a
 * NOT_FOUND result is the expected outcome for extensions that are not
 * Packagist-indexed.
 *
 * Resolution strategy:
 *   1. Primary: `composer show --all --format=json $package` (Packagist)
 *   2. Fallback (when primary returns NOT_FOUND and installationPath is set):
 *      For SSH-based VCS URLs: SSH connectivity pre-check (`ssh -T -o ConnectTimeout=5 <host>`)
 *      is performed once per host. If unreachable, fallback is skipped and SSH_UNREACHABLE is returned.
 *      Otherwise: `composer show --working-dir=$installationPath --format=json $package`.
 *      Reads from local vendor/lock — no VCS network access required.
 *      installationPath is validated via realpath()+is_dir() before use.
 */
class ComposerVersionResolver implements VcsResolverInterface
{
    private const MAX_LINEAR_SCAN_VERSIONS = 50;

    /** @var array<string, bool> Per-run SSH host reachability cache (true=reachable, false=unreachable). */
    private array $sshHostStatus = [];

    /**
     * @param (\Closure(list<string>): Process)|null $processFactory
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ComposerConstraintCheckerInterface $constraintChecker,
        private readonly ComposerEnvironment $composerEnvironment,
        private readonly int $timeoutSeconds = 30,
        private readonly ?\Closure $processFactory = null,
    ) {
    }

    public function resolve(string $packageName, ?string $vcsUrl, Version $targetVersion, ?string $installationPath = null): VcsResolutionResult
    {
        if (!$this->composerEnvironment->isVersionSufficient()) {
            return new VcsResolutionResult(VcsResolutionStatus::FAILURE, $vcsUrl, null);
        }

        $primaryResult = $this->runComposerShow(['composer', 'show', '--all', '--format=json', $packageName], $packageName, $vcsUrl, $targetVersion);

        $this->logger->info('VCS primary resolution for {package}: status={status}', [
            'package' => $packageName,
            'status' => $primaryResult->status->name,
        ]);

        if (!$primaryResult->shouldTryFallback()) {
            return $primaryResult;
        }

        // Fallback: --working-dir for non-Packagist packages (RC-1 fix)
        if (null === $installationPath) {
            $this->logger->info('VCS fallback skipped for {package}: no installationPath provided.', ['package' => $packageName]);

            return $primaryResult;
        }

        // CP-3: validate installationPath before passing to subprocess (realpath + is_dir)
        $resolvedPath = realpath($installationPath);
        if (false === $resolvedPath || !is_dir($resolvedPath)) {
            $this->logger->warning(
                'installationPath "{path}" does not exist or is not a directory — skipping --working-dir fallback.',
                ['path' => $installationPath],
            );

            return $primaryResult;
        }

        // AC-5: SSH connectivity pre-check for SSH-based VCS URLs.
        // Even though --working-dir reads local data, an unreachable SSH host may indicate
        // that the network (and thus any network-backed local path) is unavailable.
        // One `ssh -T` probe per host per analysis run avoids repeated 11-13s timeouts.
        if ($this->isSshUrl($vcsUrl)) {
            $host = $this->extractSshHost((string) $vcsUrl);
            if (null !== $host && !$this->isSshHostReachable($host)) {
                $this->logger->warning(
                    'SSH host "{host}" is not reachable (ConnectTimeout=5). Skipping --working-dir fallback for package {package}.',
                    ['host' => $host, 'package' => $packageName],
                );

                return new VcsResolutionResult(VcsResolutionStatus::SSH_UNREACHABLE, $vcsUrl, null);
            }
        }

        // Use --all to discover all tagged versions available via the VCS repository.
        // fetchVersionRequires will also use --working-dir so per-version deps are resolved locally.
        $fallbackCmd = ['composer', 'show', '--working-dir=' . $resolvedPath, '--all', '--format=json', $packageName];

        $this->logger->info('VCS fallback: running composer show --working-dir={path} for {package}', [
            'path' => $resolvedPath,
            'package' => $packageName,
        ]);

        $fallbackResult = $this->runComposerShow($fallbackCmd, $packageName, $vcsUrl, $targetVersion, $resolvedPath);

        $this->logger->info('VCS fallback resolution for {package}: status={status}, version={version}', [
            'package' => $packageName,
            'status' => $fallbackResult->status->name,
            'version' => $fallbackResult->latestCompatibleVersion ?? 'none',
        ]);

        return $fallbackResult;
    }

    /**
     * Run a `composer show` command and return a VcsResolutionResult.
     *
     * @param list<string> $command
     */
    private function runComposerShow(array $command, string $packageName, ?string $vcsUrl, Version $targetVersion, ?string $workingDir = null): VcsResolutionResult
    {
        $process = $this->createProcess($command);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $this->logger->warning('composer show timed out for package {package}', ['package' => $packageName]);

            return new VcsResolutionResult(VcsResolutionStatus::FAILURE, $vcsUrl, null);
        }

        if (!$process->isSuccessful()) {
            return $this->handleNonZeroExit($packageName, $vcsUrl, $process->getErrorOutput());
        }

        try {
            /** @var array{versions?: list<string>, requires?: array<string, string>} $data */
            $data = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning(
                'Malformed JSON from composer show for package {package}: {message}',
                ['package' => $packageName, 'message' => $e->getMessage()],
            );

            return new VcsResolutionResult(VcsResolutionStatus::FAILURE, $vcsUrl, null);
        }

        $versions = $data['versions'] ?? [];
        $latestRequires = $data['requires'] ?? [];

        if ([] === $versions) {
            return new VcsResolutionResult(VcsResolutionStatus::RESOLVED_NO_MATCH, $vcsUrl, null);
        }

        if (null === $workingDir) {
            // Primary (Packagist): top-level requires = latest available version's deps. Fast path first.
            $latestVersion = $versions[0];
            if ($this->isCompatible($latestRequires, $targetVersion)) {
                return new VcsResolutionResult(VcsResolutionStatus::RESOLVED_COMPATIBLE, $vcsUrl, $latestVersion);
            }
            // Linear scan for newer compatible version (newest-to-oldest, start after index 0).
            // Binary search is available in git history on feature/2-2-composer-vcs-resolver for future
            // opt-in once real-world data confirms the monotone-compatibility precondition holds.
            $found = $this->linearScan($packageName, $versions, $targetVersion, 1, null);
        } else {
            // Fallback (--working-dir): top-level requires reflects the INSTALLED version, not the
            // latest available. Skip the fast path and use per-version queries for all versions.
            $found = $this->linearScan($packageName, $versions, $targetVersion, 0, $workingDir);
        }

        if (null !== $found) {
            return new VcsResolutionResult(VcsResolutionStatus::RESOLVED_COMPATIBLE, $vcsUrl, $found);
        }

        return new VcsResolutionResult(VcsResolutionStatus::RESOLVED_NO_MATCH, $vcsUrl, null);
    }

    private function handleNonZeroExit(string $packageName, ?string $vcsUrl, string $stderr): VcsResolutionResult
    {
        if (false !== stripos($stderr, 'not found') || false !== stripos($stderr, 'Could not find package')) {
            $this->logger->debug('Package {package} not found on Packagist', ['package' => $packageName]);

            return new VcsResolutionResult(VcsResolutionStatus::NOT_FOUND, $vcsUrl, null);
        }

        $this->logger->warning(
            'composer show failed for package {package}: {stderr}',
            ['package' => $packageName, 'stderr' => $stderr],
        );

        return new VcsResolutionResult(VcsResolutionStatus::FAILURE, $vcsUrl, null);
    }

    /**
     * Linear scan from startIndex to end, newest-to-oldest.
     * Capped at MAX_LINEAR_SCAN_VERSIONS to prevent unbounded subprocess spawning
     * for packages with very large version histories.
     *
     * @param list<string> $versions
     */
    private function linearScan(
        string $packageName,
        array $versions,
        Version $targetVersion,
        int $startIndex,
        ?string $workingDir = null,
    ): ?string {
        $count = \count($versions);
        $scanned = 0;
        for ($i = $startIndex; $i < $count; ++$i) {
            if ($scanned >= self::MAX_LINEAR_SCAN_VERSIONS) {
                $this->logger->warning(
                    'Linear version scan for package {package} stopped at {limit} versions — result may be incomplete.',
                    ['package' => $packageName, 'limit' => self::MAX_LINEAR_SCAN_VERSIONS],
                );
                break;
            }
            $requires = $this->fetchVersionRequires($packageName, $versions[$i], $workingDir);
            if (null === $requires) {
                continue;
            }
            ++$scanned;
            if ($this->isCompatible($requires, $targetVersion)) {
                return $versions[$i];
            }
        }

        return null;
    }

    /**
     * Fetch requires for a specific version. Returns null on subprocess failure.
     * When workingDir is set, queries the local installation instead of Packagist.
     *
     * @return array<string, string>|null
     */
    private function fetchVersionRequires(string $packageName, string $version, ?string $workingDir = null): ?array
    {
        $command = null !== $workingDir
            ? ['composer', 'show', '--working-dir=' . $workingDir, '--all', '--format=json', $packageName, $version]
            : ['composer', 'show', '--all', '--format=json', $packageName, $version];
        $process = $this->createProcess($command);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return null;
        }

        if (!$process->isSuccessful()) {
            return null;
        }

        try {
            /** @var array{requires?: array<string, string>} $data */
            $data = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return $data['requires'] ?? [];
    }

    /**
     * @param array<string, string> $requires
     */
    private function isCompatible(array $requires, Version $targetVersion): bool
    {
        $typo3Reqs = $this->constraintChecker->findTypo3Requirements($requires);
        if ([] === $typo3Reqs) {
            // No declared TYPO3 dependency — treat as compatible.
            return true;
        }

        foreach ($typo3Reqs as $constraint) {
            if ($this->constraintChecker->isConstraintCompatible($constraint, $targetVersion)) {
                return true;
            }
        }

        return false;
    }

    private function isSshUrl(?string $url): bool
    {
        if (null === $url) {
            return false;
        }

        return str_starts_with($url, 'ssh://') || (bool) preg_match('/^git@[^:]+:/', $url);
    }

    /**
     * Extract hostname from SSH VCS URL.
     * Supports `git@host:path.git` and `ssh://git@host/path.git`.
     * Returns null for unrecognized formats or invalid hostnames.
     */
    /**
     * Returns a probe target string suitable for passing directly to `ssh -T`.
     * For `git@host:path` URLs this is `git@host`; for `ssh://user@host/path` it is `user@host`
     * (falling back to bare `host` when no user is present in the URL).
     * The hostname portion is validated against RFC 952/1123 before any subprocess call.
     */
    private function extractSshHost(string $url): ?string
    {
        if (str_starts_with($url, 'ssh://')) {
            $parsed = parse_url($url);
            $host = $parsed['host'] ?? null;
            $userPrefix = isset($parsed['user']) ? $parsed['user'] . '@' : '';
        } elseif (preg_match('/^git@([^:]+):/', $url, $matches)) {
            $host = $matches[1];
            $userPrefix = 'git@';
        } else {
            return null;
        }

        if (null === $host || '' === $host) {
            return null;
        }

        // CP-2: validate hostname against RFC 952/1123 before any subprocess call.
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $host)) {
            $this->logger->warning('Extracted SSH host "{host}" is not a valid hostname — skipping SSH check.', ['host' => $host]);

            return null;
        }

        return $userPrefix . $host;
    }

    /**
     * Probe SSH connectivity to a host. Exit code 255 = unreachable; anything else = reachable.
     * Result is cached per host for the duration of this resolver instance.
     */
    private function isSshHostReachable(string $host): bool
    {
        if (isset($this->sshHostStatus[$host])) {
            return $this->sshHostStatus[$host];
        }

        $process = $this->createProcess(['ssh', '-T', '-o', 'ConnectTimeout=5', $host]);
        $process->setTimeout(10);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $this->logger->debug('SSH connectivity check timed out for host {host}', ['host' => $host]);
            $this->sshHostStatus[$host] = false;

            return false;
        }

        // Exit code 255 = connection refused / network unreachable.
        // Exit code 0 or 1 = host responded (e.g. GitHub returns 1 with "Hi user!").
        $reachable = 255 !== $process->getExitCode();
        $this->sshHostStatus[$host] = $reachable;

        $this->logger->debug(
            'SSH connectivity check for host {host}: {result} (exit={code})',
            ['host' => $host, 'result' => $reachable ? 'reachable' : 'unreachable', 'code' => $process->getExitCode()],
        );

        return $reachable;
    }

    /**
     * @param list<string> $command
     */
    private function createProcess(array $command): Process
    {
        return null !== $this->processFactory
            ? ($this->processFactory)($command)
            : new Process($command);
    }
}
