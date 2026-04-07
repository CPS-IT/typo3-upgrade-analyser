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
 * Resolves VCS-hosted extension version availability via native git operations.
 *
 * Resolution strategy per package:
 *   1. git ls-remote --tags <url>     — one network call, returns all tag refs
 *   2. Parse semver tags, filter to versions > $installedVersion (newest first)
 *   3. git clone --depth=1 <url>      — one clone per repository URL (cached)
 *   4. git fetch --tags               — fetches all tag objects into the clone
 *   5. git show <tag>:composer.json   — purely local, ~19ms per call
 *   6. Parse TYPO3 constraint; return first compatible version
 */
class GitVersionResolver implements VcsResolverInterface
{
    private const MAX_SCAN_VERSIONS = 50;
    private const SEMVER_TAG_PATTERN = '/^v?(\d+(?:\.\d+)*)$/';

    /** @var array<string, string> Maps repository URL → local tmpdir clone path. */
    private array $cloneCache = [];

    /** @var array<string, bool> Per-run SSH host reachability cache (true=reachable, false=unreachable). */
    private array $sshHostStatus = [];

    private bool $shutdownRegistered = false;

    /**
     * @param (\Closure(list<string>): Process)|null $processFactory
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ComposerConstraintCheckerInterface $constraintChecker,
        private readonly int $timeoutSeconds = 60,
        private readonly ?\Closure $processFactory = null,
    ) {
    }

    public function resolve(string $packageName, ?string $vcsUrl, Version $targetVersion, ?Version $installedVersion = null): VcsResolutionResult
    {
        if (null === $vcsUrl) {
            return new VcsResolutionResult(VcsResolutionStatus::NOT_FOUND, null, null);
        }

        if (!$this->isSupportedGitUrl($vcsUrl)) {
            $this->logger->warning(
                'Unsupported VCS URL scheme for package {package}: {url}. Only git (ssh/https) URLs are supported.',
                ['package' => $packageName, 'url' => $vcsUrl],
            );

            return new VcsResolutionResult(VcsResolutionStatus::FAILURE, $vcsUrl, null);
        }

        // SSH pre-check: one probe per host before any git operation.
        if ($this->isSshUrl($vcsUrl)) {
            $host = $this->extractSshHost($vcsUrl);
            if (null !== $host) {
                $probeTarget = $this->buildSshProbeTarget($vcsUrl, $host);
                if (!$this->isSshHostReachable($host, $probeTarget)) {
                    return new VcsResolutionResult(VcsResolutionStatus::SSH_UNREACHABLE, $vcsUrl, null);
                }
            }
        }

        // Step 1+2: list remote tags and filter candidates.
        $candidates = $this->fetchCandidateTags($packageName, $vcsUrl, $installedVersion);
        if (null === $candidates) {
            return new VcsResolutionResult(VcsResolutionStatus::FAILURE, $vcsUrl, null);
        }

        if ([] === $candidates) {
            return new VcsResolutionResult(VcsResolutionStatus::RESOLVED_NO_MATCH, $vcsUrl, null);
        }

        // Steps 3+4: clone (or reuse) and fetch tags.
        $cloneDir = $this->getOrCreateClone($packageName, $vcsUrl);
        if (null === $cloneDir) {
            return new VcsResolutionResult(VcsResolutionStatus::FAILURE, $vcsUrl, null);
        }

        // Step 5+6: iterate candidates (newest first) until a compatible version is found.
        foreach ($candidates as $tag => $normalizedVersion) {
            $composerJsonContent = $this->gitShow($cloneDir, $tag . ':composer.json', $packageName);
            if (null === $composerJsonContent) {
                continue;
            }

            try {
                /** @var array{require?: array<string, string>} $composerJson */
                $composerJson = json_decode($composerJsonContent, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $this->logger->debug('Could not parse composer.json for {package} tag {tag}', ['package' => $packageName, 'tag' => $tag]);
                continue;
            }

            $requires = $composerJson['require'] ?? [];
            if ($this->isCompatible($requires, $targetVersion)) {
                return new VcsResolutionResult(VcsResolutionStatus::RESOLVED_COMPATIBLE, $vcsUrl, $normalizedVersion);
            }
        }

        return new VcsResolutionResult(VcsResolutionStatus::RESOLVED_NO_MATCH, $vcsUrl, null);
    }

    /**
     * Clear clone cache and remove clone directories immediately.
     * Useful for long-running processes between analysis runs.
     */
    public function reset(): void
    {
        foreach ($this->cloneCache as $cloneDir) {
            $this->removeDirectory($cloneDir);
        }
        $this->cloneCache = [];
        $this->sshHostStatus = [];
    }

    /**
     * Fetch all semver tags via git ls-remote, filter by lower bound, sort newest-first.
     * Returns null on process failure; returns [] when no candidates match.
     *
     * @return array<string, string>|null map of tag-name → normalized-version-string, newest-first
     */
    private function fetchCandidateTags(string $packageName, string $vcsUrl, ?Version $installedVersion): ?array
    {
        $process = $this->createProcess(['git', 'ls-remote', '--tags', $vcsUrl]);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $this->logger->warning('git ls-remote timed out for package {package}', ['package' => $packageName]);

            return null;
        }

        if (!$process->isSuccessful()) {
            $this->logger->warning(
                'git ls-remote failed for package {package}: {stderr}',
                ['package' => $packageName, 'stderr' => $process->getErrorOutput()],
            );

            return null;
        }

        // Parse output: "<sha>\trefs/tags/<tagname>" — skip peeled refs (^{})
        $tags = [];
        foreach (explode("\n", $process->getOutput()) as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }

            // Skip peeled tag refs (e.g. refs/tags/v1.0.0^{})
            if (str_ends_with($line, '^{}')) {
                continue;
            }

            if (!preg_match('#\trefs/tags/(.+)$#', $line, $matches)) {
                continue;
            }

            $tagName = $matches[1];
            if (!preg_match(self::SEMVER_TAG_PATTERN, $tagName, $versionMatches)) {
                continue;
            }

            $normalizedVersion = $versionMatches[1];

            // Apply lower-bound filter.
            if (null !== $installedVersion && version_compare($normalizedVersion, $installedVersion->toString(), '<=')) {
                continue;
            }

            $tags[$tagName] = $normalizedVersion;
        }

        if ([] === $tags) {
            return [];
        }

        // Sort newest-first using version_compare.
        uasort($tags, static fn (string $a, string $b): int => version_compare($b, $a));

        if (\count($tags) > self::MAX_SCAN_VERSIONS) {
            $this->logger->warning(
                'Version scan for package {package} capped at {limit} versions.',
                ['package' => $packageName, 'limit' => self::MAX_SCAN_VERSIONS],
            );
            $tags = \array_slice($tags, 0, self::MAX_SCAN_VERSIONS, true);
        }

        return $tags;
    }

    /**
     * Return the clone directory for a given URL, creating it if not already cached.
     */
    private function getOrCreateClone(string $packageName, string $vcsUrl): ?string
    {
        $normalizedUrl = preg_replace('/\.git$/', '', $vcsUrl) ?? $vcsUrl;

        if (isset($this->cloneCache[$normalizedUrl])) {
            return $this->cloneCache[$normalizedUrl];
        }

        $tmpDir = sys_get_temp_dir() . '/typo3-analyzer-git-' . substr(md5($normalizedUrl), 0, 8) . '-' . getmypid();

        if (is_dir($tmpDir)) {
            $this->removeDirectory($tmpDir);
        }

        // Step 3: clone
        $cloneProcess = $this->createProcess(['git', 'clone', '--depth=1', '--quiet', $vcsUrl, $tmpDir]);
        $cloneProcess->setTimeout($this->timeoutSeconds);

        try {
            $cloneProcess->run();
        } catch (ProcessTimedOutException) {
            $this->logger->warning('git clone timed out for package {package}', ['package' => $packageName]);

            return null;
        }

        if (!$cloneProcess->isSuccessful()) {
            $this->logger->warning(
                'git clone failed for package {package}: {stderr}',
                ['package' => $packageName, 'stderr' => $cloneProcess->getErrorOutput()],
            );

            return null;
        }

        // Step 4: fetch all tags into the clone
        $fetchProcess = $this->createProcess(['git', '-C', $tmpDir, 'fetch', '--tags', '--quiet']);
        $fetchProcess->setTimeout($this->timeoutSeconds);

        try {
            $fetchProcess->run();
        } catch (ProcessTimedOutException) {
            $this->logger->warning('git fetch --tags timed out for package {package}', ['package' => $packageName]);
            $this->removeDirectory($tmpDir);

            return null;
        }

        if (!$fetchProcess->isSuccessful()) {
            $this->logger->warning(
                'git fetch --tags failed for package {package}: {stderr}',
                ['package' => $packageName, 'stderr' => $fetchProcess->getErrorOutput()],
            );
            $this->removeDirectory($tmpDir);

            return null;
        }

        $this->cloneCache[$normalizedUrl] = $tmpDir;

        // Register shutdown cleanup once (covers all clones).
        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function(function (): void {
                foreach ($this->cloneCache as $dir) {
                    $this->removeDirectory($dir);
                }
            });
        }

        return $tmpDir;
    }

    /**
     * Run git show <ref> in the given clone directory.
     * Returns null on failure (e.g. composer.json not at repo root).
     */
    private function gitShow(string $cloneDir, string $ref, string $packageName): ?string
    {
        $process = $this->createProcess(['git', '-C', $cloneDir, 'show', $ref]);
        $process->setTimeout(30);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $this->logger->debug('git show timed out for {package} ref {ref}', ['package' => $packageName, 'ref' => $ref]);

            return null;
        }

        if (!$process->isSuccessful()) {
            $this->logger->debug(
                'git show failed for {package} ref {ref} (likely composer.json not at root)',
                ['package' => $packageName, 'ref' => $ref],
            );

            return null;
        }

        return $process->getOutput();
    }

    /**
     * @param array<string, string> $requires
     */
    private function isCompatible(array $requires, Version $targetVersion): bool
    {
        $typo3Reqs = $this->constraintChecker->findTypo3Requirements($requires);
        if ([] === $typo3Reqs) {
            return true;
        }

        foreach ($typo3Reqs as $constraint) {
            if ($this->constraintChecker->isConstraintCompatible($constraint, $targetVersion)) {
                return true;
            }
        }

        return false;
    }

    private function isSupportedGitUrl(string $url): bool
    {
        return str_starts_with($url, 'https://')
            || str_starts_with($url, 'ssh://')
            || (bool) preg_match('/^git@[^:]+:/', $url);
    }

    private function isSshUrl(string $url): bool
    {
        return str_starts_with($url, 'ssh://') || (bool) preg_match('/^git@[^:]+:/', $url);
    }

    /**
     * Returns just the hostname (without user prefix) for use as a cache key.
     * Returns null when the hostname fails RFC 952/1123 validation.
     */
    private function extractSshHost(string $url): ?string
    {
        if (str_starts_with($url, 'ssh://')) {
            $parsed = parse_url($url);
            $host = $parsed['host'] ?? null;
        } elseif (preg_match('/^git@([^:]+):/', $url, $matches)) {
            $host = $matches[1];
        } else {
            return null;
        }

        if (null === $host || '' === $host) {
            return null;
        }

        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $host)) {
            $this->logger->warning('Extracted SSH host "{host}" is not a valid hostname — skipping SSH check.', ['host' => $host]);

            return null;
        }

        return $host;
    }

    /**
     * Build the full SSH connection string (user@host) used for the probe command.
     */
    private function buildSshProbeTarget(string $url, string $hostname): string
    {
        if (str_starts_with($url, 'ssh://')) {
            $parsed = parse_url($url);
            $userPrefix = isset($parsed['user']) ? $parsed['user'] . '@' : '';
        } else {
            // git@host: form
            $userPrefix = 'git@';
        }

        return $userPrefix . $hostname;
    }

    /**
     * Probe SSH connectivity. Exit code 255 = unreachable; 0 or 1 = reachable.
     * Cache key is the bare hostname; probe uses the full user@host connection string.
     */
    private function isSshHostReachable(string $host, string $probeTarget): bool
    {
        if (isset($this->sshHostStatus[$host])) {
            return $this->sshHostStatus[$host];
        }

        $process = $this->createProcess(['ssh', '-T', '-o', 'ConnectTimeout=5', $probeTarget]);
        $process->setTimeout(10);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $this->logger->debug('SSH connectivity check timed out for host {host}', ['host' => $host]);
            $this->sshHostStatus[$host] = false;

            return false;
        }

        $exitCode = $process->getExitCode();
        $reachable = null !== $exitCode && 255 !== $exitCode;
        $this->sshHostStatus[$host] = $reachable;

        $this->logger->debug(
            'SSH connectivity check for host {host}: {result} (exit={code})',
            ['host' => $host, 'result' => $reachable ? 'reachable' : 'unreachable', 'code' => $exitCode],
        );

        return $reachable;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $process = $this->createProcess(['rm', '-rf', $dir]);
        $process->run();
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
