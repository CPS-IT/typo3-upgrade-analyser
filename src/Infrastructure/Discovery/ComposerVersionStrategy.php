<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Discovery;

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use Psr\Log\LoggerInterface;

/**
 * TYPO3 version extraction strategy for Composer installations.
 *
 * This strategy extracts TYPO3 version information from Composer files:
 * 1. composer.lock (highest reliability) - exact installed version
 * 2. composer.json (lower reliability) - version constraints only
 *
 * The strategy prioritizes composer.lock as it contains the exact installed
 * versions, while composer.json only contains version constraints.
 */
final class ComposerVersionStrategy implements VersionStrategyInterface
{
    private const TYPO3_CORE_PACKAGES = [
        'typo3/cms-core',
        'typo3/cms',
        'typo3/minimal',
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function extractVersion(string $installationPath): ?Version
    {
        $this->logger->debug('Starting Composer version extraction', ['path' => $installationPath]);

        // Try composer.lock first (most reliable)
        $version = $this->extractFromComposerLock($installationPath);
        if (null !== $version) {
            $this->logger->debug('Version extracted from composer.lock', ['version' => $version->toString()]);

            return $version;
        }

        // Fall back to composer.json (less reliable, constraint-based)
        $version = $this->extractFromComposerJson($installationPath);
        if (null !== $version) {
            $this->logger->debug('Version extracted from composer.json', ['version' => $version->toString()]);

            return $version;
        }

        $this->logger->debug('No TYPO3 version found in Composer files');

        return null;
    }

    public function supports(string $installationPath): bool
    {
        $composerLockPath = $installationPath . '/composer.lock';
        $composerJsonPath = $installationPath . '/composer.json';

        return file_exists($composerLockPath) || file_exists($composerJsonPath);
    }

    public function getPriority(): int
    {
        return 100; // Highest priority for Composer installations
    }

    public function getName(): string
    {
        return 'Composer Version Strategy';
    }

    public function getRequiredFiles(): array
    {
        return ['composer.lock', 'composer.json']; // Either file is sufficient
    }

    public function getReliabilityScore(): float
    {
        return 0.95; // Very high reliability for Composer installations
    }

    /**
     * Extract TYPO3 version from composer.lock file.
     *
     * @param string $installationPath Installation path
     *
     * @return Version|null Extracted version or null
     */
    private function extractFromComposerLock(string $installationPath): ?Version
    {
        $lockFilePath = $installationPath . '/composer.lock';

        if (!file_exists($lockFilePath)) {
            return null;
        }

        try {
            $json = file_get_contents($lockFilePath);
            if (false === $json) {
                $this->logger->warning('Failed to read composer.lock', ['lockFilePath' => $lockFilePath]);

                return null;
            }

            $lockData = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            if (!\is_array($lockData) || !isset($lockData['packages'])) {
                $this->logger->warning('Invalid composer.lock structure', ['path' => $lockFilePath]);

                return null;
            }

            // Search for TYPO3 core packages in installed packages
            foreach ($lockData['packages'] as $package) {
                if (!\is_array($package) || !isset($package['name'], $package['version'])) {
                    continue;
                }

                if (\in_array($package['name'], self::TYPO3_CORE_PACKAGES, true)) {
                    $versionString = $this->normalizeVersionString($package['version']);

                    if (null !== $versionString) {
                        $this->logger->debug('Found TYPO3 package in composer.lock', [
                            'package' => $package['name'],
                            'version' => $versionString,
                        ]);

                        return Version::fromString($versionString);
                    }
                }
            }

            $this->logger->debug('No TYPO3 core packages found in composer.lock');

            return null;
        } catch (\JsonException $e) {
            $this->logger->warning('Failed to parse composer.lock', [
                'path' => $lockFilePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('Error reading composer.lock', [
                'path' => $lockFilePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Extract TYPO3 version from composer.json file.
     *
     * Note: This provides less reliable results as it only contains version constraints,
     * not the exact installed version.
     *
     * @param string $installationPath Installation path
     *
     * @return Version|null Extracted version or null
     */
    private function extractFromComposerJson(string $installationPath): ?Version
    {
        $jsonFilePath = $installationPath . '/composer.json';

        if (!file_exists($jsonFilePath)) {
            return null;
        }

        try {
            $json = file_get_contents($jsonFilePath);
            if (false === $json) {
                $this->logger->warning('Failed to read composer.json', ['jsonFilePath' => $jsonFilePath]);

                return null;
            }

            $jsonData = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            if (!\is_array($jsonData)) {
                $this->logger->warning('Invalid composer.json structure', ['path' => $jsonFilePath]);

                return null;
            }

            // Check require and require-dev sections
            $requirements = array_merge(
                $jsonData['require'] ?? [],
                $jsonData['require-dev'] ?? [],
            );

            foreach (self::TYPO3_CORE_PACKAGES as $packageName) {
                if (!isset($requirements[$packageName])) {
                    continue;
                }

                $constraint = $requirements[$packageName];
                $version = $this->extractVersionFromConstraint($constraint);

                if (null !== $version) {
                    $this->logger->debug('Found TYPO3 constraint in composer.json', [
                        'package' => $packageName,
                        'constraint' => $constraint,
                        'extracted_version' => $version->toString(),
                    ]);

                    return $version;
                }
            }

            $this->logger->debug('No TYPO3 core packages found in composer.json requirements');

            return null;
        } catch (\JsonException $e) {
            $this->logger->warning('Failed to parse composer.json', [
                'path' => $jsonFilePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('Error reading composer.json', [
                'path' => $jsonFilePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Normalize version string from composer.lock.
     *
     * Removes 'v' prefix and handles dev versions.
     *
     * @param string $version Raw version string
     *
     * @return string|null Normalized version string
     */
    private function normalizeVersionString(string $version): ?string
    {
        // Remove 'v' prefix if present
        $version = ltrim($version, 'v');

        // Handle dev versions (e.g., "dev-main", "dev-12.4")
        if (str_starts_with($version, 'dev-')) {
            $devVersion = substr($version, 4);

            // If dev version looks like a version number, use it
            if (preg_match('/^\d+\.\d+(?:\.\d+)?/', $devVersion)) {
                return $devVersion;
            }

            // Skip non-version dev branches
            return null;
        }

        // Validate version format
        if (!preg_match('/^\d+\.\d+(?:\.\d+)?(?:-[a-zA-Z0-9\-\.]+)?$/', $version)) {
            return null;
        }

        return $version;
    }

    /**
     * Extract version from Composer constraint.
     *
     * Attempts to extract a meaningful version from version constraints.
     * This is less reliable than composer.lock versions.
     *
     * @param string $constraint Version constraint (e.g., "^12.4", "~11.5.0")
     *
     * @return Version|null Extracted version
     */
    private function extractVersionFromConstraint(string $constraint): ?Version
    {
        // Remove common constraint operators
        $constraint = preg_replace('/^[\^~>=<]+/', '', $constraint);
        if (null === $constraint) {
            $constraint = '';
        }
        $constraint = trim($constraint);

        // Handle version ranges (take the lower bound)
        if (str_contains($constraint, '|')) {
            $parts = explode('|', $constraint);
            $constraint = trim($parts[0]);
        }

        if (str_contains($constraint, ' ')) {
            $parts = explode(' ', $constraint);
            $constraint = trim($parts[0]);
        }

        // Remove 'v' prefix if present
        $constraint = ltrim($constraint, 'v');

        // Validate and normalize the extracted version
        if (preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $constraint, $matches)) {
            $major = $matches[1];
            $minor = $matches[2];
            $patch = $matches[3] ?? '0';

            return Version::fromString("{$major}.{$minor}.{$patch}");
        }

        return null;
    }
}
