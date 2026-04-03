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
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Tier 2 fallback resolver: uses `git ls-remote` to discover available tags for
 * VCS-hosted extensions that could not be resolved via Packagist (Story 2.3).
 *
 * No Composer dependency — relies on the git binary available in the host environment.
 */
readonly class GenericGitResolver implements VcsResolverInterface
{
    /**
     * @param (\Closure(list<string>): Process)|null $processFactory        Factory for git ls-remote subprocess
     * @param (\Closure(string): Process)|null       $archiveProcessFactory Factory for git archive subprocess (injectable for testing)
     */
    public function __construct(
        private LoggerInterface $logger,
        private ComposerConstraintCheckerInterface $constraintChecker,
        private int $timeoutSeconds = 30,
        private ?\Closure $processFactory = null,
        private ?\Closure $archiveProcessFactory = null,
    ) {
    }

    public function resolve(string $packageName, ?string $vcsUrl, Version $targetVersion): VcsResolutionResult
    {
        if (null === $vcsUrl) {
            return new VcsResolutionResult(VcsResolutionStatus::NOT_FOUND, null, null);
        }

        $output = $this->runLsRemote($vcsUrl, $packageName);
        if (null === $output) {
            return new VcsResolutionResult(VcsResolutionStatus::FAILURE, $vcsUrl, null);
        }

        $tags = $this->parseTagsFromOutput($output);
        $stableTagPair = $this->findMostRecentStableTag($tags);

        if (null === $stableTagPair) {
            return new VcsResolutionResult(VcsResolutionStatus::RESOLVED_NO_MATCH, $vcsUrl, null);
        }

        // Use original tag name for git operations, normalized version for the result.
        $composerJson = $this->fetchComposerJson($vcsUrl, $stableTagPair['tag']);
        $requires = $composerJson['require'] ?? null;

        if (!$this->isCompatible($requires, $targetVersion)) {
            return new VcsResolutionResult(VcsResolutionStatus::RESOLVED_NO_MATCH, $vcsUrl, null);
        }

        return new VcsResolutionResult(VcsResolutionStatus::RESOLVED_COMPATIBLE, $vcsUrl, $stableTagPair['version']);
    }

    /**
     * Runs `git ls-remote -q -t --refs <vcsUrl>` and returns stdout, or null on failure/timeout.
     * `-q` suppresses the "From <url>" informational line that some git versions emit to stdout.
     */
    private function runLsRemote(string $vcsUrl, string $packageName): ?string
    {
        $process = $this->createProcess(['git', 'ls-remote', '-q', '-t', '--refs', $vcsUrl]);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $this->logger->warning(
                'git ls-remote timed out for {package} at {url}',
                ['package' => $packageName, 'url' => $vcsUrl],
            );

            return null;
        } catch (ProcessFailedException $e) {
            $this->logger->warning(
                'git binary not available — GenericGitResolver cannot resolve {package} at {url}',
                ['package' => $packageName, 'url' => $vcsUrl, 'error' => $e->getMessage()],
            );

            return null;
        }

        if (!$process->isSuccessful()) {
            $stderr = $process->getErrorOutput();
            $this->logger->warning(
                'git ls-remote failed for {package} at {url}: {reason}',
                ['package' => $packageName, 'url' => $vcsUrl, 'reason' => $stderr],
            );

            return null;
        }

        return $process->getOutput();
    }

    /**
     * Extracts semver version strings from `git ls-remote -q -t --refs` output.
     * Returns pairs of original tag name (for git operations) and normalized version string (for comparisons).
     *
     * @return list<array{version: string, tag: string}>
     */
    private function parseTagsFromOutput(string $output): array
    {
        $tags = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ('' === $line || !str_contains($line, 'refs/tags/')) {
                continue;
            }

            $tagName = substr($line, strrpos($line, '/') + 1);
            if (1 === preg_match('/^v?(\d+\.\d+\.\d+(?:-[A-Za-z0-9.]+)?)$/', $tagName, $matches)) {
                $tags[] = ['version' => $matches[1], 'tag' => $tagName];
            }
        }

        return $tags;
    }

    /**
     * Returns the most recent stable tag pair (no pre-release suffix), or null if none exists.
     *
     * @param list<array{version: string, tag: string}> $tags
     *
     * @return array{version: string, tag: string}|null
     */
    private function findMostRecentStableTag(array $tags): ?array
    {
        /** @var list<array{version: string, tag: string}> $stable */
        $stable = array_values(array_filter(
            $tags,
            static fn (array $t): bool => !str_contains($t['version'], '-'),
        ));

        if ([] === $stable) {
            return null;
        }

        usort($stable, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));

        return $stable[0];
    }

    /**
     * Fetches composer.json from the given tag via `git archive --remote | tar -xO`.
     * Returns decoded array or null on failure (caller treats null as compatible).
     *
     * @param string $tag Original tag name as it appears in the git repository (e.g. `v2.3.4`)
     *
     * @return array{require?: array<string, string>}|null
     */
    private function fetchComposerJson(string $vcsUrl, string $tag): ?array
    {
        $cmd = \sprintf(
            'git archive --remote=%s refs/tags/%s -- composer.json | tar -xO',
            escapeshellarg($vcsUrl),
            escapeshellarg($tag),
        );

        $process = $this->createArchiveProcess($cmd);
        $process->setTimeout($this->timeoutSeconds);
        $process->run();

        if (!$process->isSuccessful() || '' === trim($process->getOutput())) {
            $this->logger->debug(
                'git archive unavailable for {url} tag {tag} — treating as compatible',
                ['url' => $vcsUrl, 'tag' => $tag],
            );

            return null;
        }

        try {
            /** @var array{require?: array<string, string>} $data */
            $data = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            return $data;
        } catch (\JsonException $e) {
            $this->logger->warning(
                'Malformed composer.json from git archive for {url} tag {tag}: {msg}',
                ['url' => $vcsUrl, 'tag' => $tag, 'msg' => $e->getMessage()],
            );

            return null;
        }
    }

    /**
     * Checks whether the given requires array is compatible with the target TYPO3 version.
     * Extensions with no declared TYPO3 requirement are treated as compatible.
     *
     * @param array<string, string>|null $requires
     */
    private function isCompatible(?array $requires, Version $targetVersion): bool
    {
        $typo3Reqs = $this->constraintChecker->findTypo3Requirements($requires ?? []);
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

    /**
     * @param list<string> $command
     */
    private function createProcess(array $command): Process
    {
        return null !== $this->processFactory
            ? ($this->processFactory)($command)
            : new Process($command);
    }

    private function createArchiveProcess(string $cmd): Process
    {
        return null !== $this->archiveProcessFactory
            ? ($this->archiveProcessFactory)($cmd)
            : Process::fromShellCommandline($cmd);
    }
}
