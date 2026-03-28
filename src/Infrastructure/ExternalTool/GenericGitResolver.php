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
class GenericGitResolver
{
    /**
     * @param (\Closure(list<string>): Process)|null $processFactory
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ComposerConstraintCheckerInterface $constraintChecker,
        private readonly int $timeoutSeconds = 30,
        private readonly ?\Closure $processFactory = null,
    ) {
    }

    public function resolve(string $packageName, string $vcsUrl, Version $targetVersion): VcsResolutionResult
    {
        $output = $this->runLsRemote($vcsUrl);
        if (null === $output) {
            return new VcsResolutionResult(VcsResolutionStatus::FAILURE, $vcsUrl, null);
        }

        $tags = $this->parseTagsFromOutput($output);
        $stableTag = $this->findMostRecentStableTag($tags);

        if (null === $stableTag) {
            return new VcsResolutionResult(VcsResolutionStatus::RESOLVED_NO_MATCH, $vcsUrl, null);
        }

        $composerJson = $this->fetchComposerJson($vcsUrl, $stableTag);
        $requires = $composerJson['require'] ?? null;

        if (!$this->isCompatible($requires, $targetVersion)) {
            return new VcsResolutionResult(VcsResolutionStatus::RESOLVED_NO_MATCH, $vcsUrl, null);
        }

        return new VcsResolutionResult(VcsResolutionStatus::RESOLVED_COMPATIBLE, $vcsUrl, $stableTag);
    }

    /**
     * Runs `git ls-remote -t --refs <vcsUrl>` and returns stdout, or null on failure/timeout.
     */
    private function runLsRemote(string $vcsUrl): ?string
    {
        $process = $this->createProcess(['git', 'ls-remote', '-t', '--refs', $vcsUrl]);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $this->logger->warning(
                'git ls-remote timed out for {url}',
                ['url' => $vcsUrl],
            );

            return null;
        } catch (ProcessFailedException $e) {
            $this->logger->warning(
                'git binary not available — GenericGitResolver cannot resolve {url}',
                ['url' => $vcsUrl, 'error' => $e->getMessage()],
            );

            return null;
        }

        if (!$process->isSuccessful()) {
            $stderr = $process->getErrorOutput();
            $this->logger->warning(
                'git ls-remote failed for {url}: {reason}',
                ['url' => $vcsUrl, 'reason' => $stderr],
            );

            return null;
        }

        return $process->getOutput();
    }

    /**
     * Extracts semver version strings from `git ls-remote -t --refs` output.
     *
     * @return list<string>
     */
    private function parseTagsFromOutput(string $output): array
    {
        $versions = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ('' === $line || !str_contains($line, 'refs/tags/')) {
                continue;
            }

            $tagName = substr($line, strrpos($line, '/') + 1);
            if (1 === preg_match('/^v?(\d+\.\d+\.\d+(?:-[A-Za-z0-9.]+)?)$/', $tagName, $matches)) {
                $versions[] = $matches[1];
            }
        }

        return $versions;
    }

    /**
     * Returns the most recent stable tag (no pre-release suffix), or null if none exists.
     *
     * @param list<string> $versions
     */
    private function findMostRecentStableTag(array $versions): ?string
    {
        /** @var list<string> $stable */
        $stable = array_values(array_filter($versions, static fn (string $v): bool => !str_contains($v, '-')));

        if ([] === $stable) {
            return null;
        }

        usort($stable, static fn (string $a, string $b): int => version_compare($b, $a));

        return $stable[0];
    }

    /**
     * Fetches composer.json from the given tag via `git archive --remote | tar -xO`.
     * Returns decoded array or null on failure (caller treats null as compatible).
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
        return Process::fromShellCommandline($cmd);
    }
}
