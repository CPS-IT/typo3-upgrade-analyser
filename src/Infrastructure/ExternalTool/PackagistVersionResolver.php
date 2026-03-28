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
 * NOT_ON_PACKAGIST result is the expected outcome for extensions that are
 * not Packagist-indexed.
 *
 * Note: --working-dir is never used in any subprocess call. It adds 11–13 s
 * overhead per call (VcsResolutionSpike.md §8).
 */
class PackagistVersionResolver
{
    private ?bool $composerVersionOk = null;

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
        if (!$this->checkComposerVersion()) {
            return new VcsResolutionResult(ResolutionStatus::FAILURE, $vcsUrl, null);
        }

        $process = $this->createProcess(['composer', 'show', '--all', '--format=json', $packageName]);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $this->logger->warning('composer show timed out for package {package}', ['package' => $packageName]);

            return new VcsResolutionResult(ResolutionStatus::FAILURE, $vcsUrl, null);
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

            return new VcsResolutionResult(ResolutionStatus::FAILURE, $vcsUrl, null);
        }

        $versions = $data['versions'] ?? [];
        $latestRequires = $data['requires'] ?? [];

        if ([] === $versions) {
            return new VcsResolutionResult(ResolutionStatus::RESOLVED_NO_MATCH, $vcsUrl, null);
        }

        // Check the latest version first (single-call fast path).
        $latestVersion = $versions[0];
        if ($this->isCompatible($latestRequires, $targetVersion)) {
            return new VcsResolutionResult(ResolutionStatus::RESOLVED_COMPATIBLE, $vcsUrl, $latestVersion);
        }

        // Linear scan for newest compatible version (newest-to-oldest, start after index 0).
        // Binary search is available as binarySearchCompatible() for future opt-in once
        // real-world data confirms the monotone-compatibility precondition holds.
        $found = $this->linearScan($packageName, $versions, $targetVersion, 1);
        if (null !== $found) {
            return new VcsResolutionResult(ResolutionStatus::RESOLVED_COMPATIBLE, $vcsUrl, $found);
        }

        return new VcsResolutionResult(ResolutionStatus::RESOLVED_NO_MATCH, $vcsUrl, null);
    }

    private function checkComposerVersion(): bool
    {
        if (null !== $this->composerVersionOk) {
            return $this->composerVersionOk;
        }

        $process = $this->createProcess(['composer', '--version']);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return $this->composerVersionOk = false;
        }

        if (!$process->isSuccessful()) {
            return $this->composerVersionOk = false;
        }

        if (!preg_match('/version (\d+\.\d+)/', $process->getOutput(), $m)) {
            $this->logger->warning('Could not determine Composer version from output; assuming incompatible');

            return $this->composerVersionOk = false;
        }

        if (version_compare($m[1], '2.1', '<')) {
            $this->logger->warning(
                \sprintf('Composer 2.1+ required for stable JSON output; found %s', $m[1]),
            );

            return $this->composerVersionOk = false;
        }

        return $this->composerVersionOk = true;
    }

    private function handleNonZeroExit(string $packageName, string $vcsUrl, string $stderr): VcsResolutionResult
    {
        if (false !== stripos($stderr, 'not found') || false !== stripos($stderr, 'Could not find package')) {
            $this->logger->debug('Package {package} not found on Packagist', ['package' => $packageName]);

            return new VcsResolutionResult(ResolutionStatus::NOT_ON_PACKAGIST, $vcsUrl, null);
        }

        $this->logger->warning(
            'composer show failed for package {package}: {stderr}',
            ['package' => $packageName, 'stderr' => $stderr],
        );

        return new VcsResolutionResult(ResolutionStatus::FAILURE, $vcsUrl, null);
    }

    /**
     * Linear scan from startIndex to end, newest-to-oldest.
     *
     * @param list<string> $versions
     */
    private function linearScan(
        string $packageName,
        array $versions,
        Version $targetVersion,
        int $startIndex,
    ): ?string {
        $count = \count($versions);
        for ($i = $startIndex; $i < $count; ++$i) {
            $requires = $this->fetchVersionRequires($packageName, $versions[$i]);
            if (null !== $requires && $this->isCompatible($requires, $targetVersion)) {
                return $versions[$i];
            }
        }

        return null;
    }

    /**
     * Fetch requires for a specific version. Returns null on subprocess failure.
     *
     * @return array<string, string>|null
     */
    private function fetchVersionRequires(string $packageName, string $version): ?array
    {
        $process = $this->createProcess(['composer', 'show', '--all', '--format=json', $packageName, $version]);
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
