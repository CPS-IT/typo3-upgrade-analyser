<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool;

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Http\HttpClientException;
use CPSIT\UpgradeAnalyzer\Infrastructure\Http\HttpClientServiceInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Repository\RepositoryUrlHandlerInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Version\ComposerConstraintCheckerInterface;
use Psr\Log\LoggerInterface;

/**
 * Client for interacting with the Packagist API.
 */
class PackagistClient
{
    private const API_BASE_URL = 'https://packagist.org/packages';

    public function __construct(
        private readonly HttpClientServiceInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ComposerConstraintCheckerInterface $constraintChecker,
        private readonly RepositoryUrlHandlerInterface $urlHandler,
    ) {
    }

    /**
     * Check if a version compatible with the target TYPO3 version exists.
     */
    public function hasVersionFor(string $packageName, Version $typo3Version): bool
    {
        try {
            $response = $this->httpClient->get(self::API_BASE_URL . '/' . $packageName . '.json');

            if (200 !== $response->getStatusCode()) {
                return false;
            }

            $data = $response->toArray();

            return $this->checkVersionCompatibility($data, $typo3Version);
        } catch (HttpClientException $e) {
            $this->logger->error('Packagist API request failed', [
                'package_name' => $packageName,
                'error' => $e->getMessage(),
            ]);

            throw new ExternalToolException(\sprintf('Failed to check Packagist for package "%s": %s', $packageName, $e->getMessage()), 'packagist_api', $e);
        }
    }

    /**
     * Get the latest version for a specific TYPO3 version.
     */
    public function getLatestVersion(string $packageName, Version $typo3Version): ?string
    {
        try {
            $response = $this->httpClient->get(self::API_BASE_URL . '/' . $packageName . '.json');

            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $data = $response->toArray();

            return $this->findLatestCompatibleVersion($data, $typo3Version);
        } catch (HttpClientException $e) {
            $this->logger->error('Packagist API request failed', [
                'package_name' => $packageName,
                'error' => $e->getMessage(),
            ]);

            throw new ExternalToolException(\sprintf('Failed to get latest version from Packagist for package "%s": %s', $packageName, $e->getMessage()), 'packagist_api', $e);
        }
    }

    /**
     * Get the repository URL for a package from Packagist.
     */
    public function getRepositoryUrl(string $packageName): ?string
    {
        try {
            $response = $this->httpClient->get(self::API_BASE_URL . '/' . $packageName . '.json');

            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $data = $response->toArray();

            // Extract repository URL from package data
            if (isset($data['package']['repository']) && \is_string($data['package']['repository'])) {
                return $this->urlHandler->normalizeUrl($data['package']['repository']);
            }

            return null;
        } catch (HttpClientException $e) {
            $this->logger->debug('Failed to get repository URL from Packagist', [
                'package_name' => $packageName,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function checkVersionCompatibility(array $packageData, Version $typo3Version): bool
    {
        if (!isset($packageData['package']['versions']) || !\is_array($packageData['package']['versions'])) {
            return false;
        }

        foreach ($packageData['package']['versions'] as $versionData) {
            if ($this->isVersionCompatible($versionData, $typo3Version)) {
                return true;
            }
        }

        return false;
    }

    private function findLatestCompatibleVersion(array $packageData, Version $typo3Version): ?string
    {
        if (!isset($packageData['package']['versions']) || !\is_array($packageData['package']['versions'])) {
            return null;
        }

        $compatibleVersions = [];

        foreach ($packageData['package']['versions'] as $version => $versionData) {
            if ($this->isVersionCompatible($versionData, $typo3Version)) {
                $compatibleVersions[] = $version;
            }
        }

        if (empty($compatibleVersions)) {
            return null;
        }

        // Filter out pre-release versions (dev, alpha, beta, rc, etc.) and sort
        $stableVersions = array_filter($compatibleVersions, function ($version) {
            $preReleaseMarkers = ['dev', 'alpha', 'beta', 'rc', 'snapshot'];
            foreach ($preReleaseMarkers as $marker) {
                if (str_contains(strtolower($version), $marker)) {
                    return false;
                }
            }

            return true;
        });

        if (!empty($stableVersions)) {
            usort($stableVersions, 'version_compare');

            return end($stableVersions);
        }

        // Fall back to dev versions if no stable versions available
        usort($compatibleVersions, 'version_compare');

        return end($compatibleVersions);
    }

    private function isVersionCompatible(array $versionData, Version $typo3Version): bool
    {
        if (!isset($versionData['require'])) {
            return false;
        }

        $packageName = $versionData['name'] ?? '';
        $packageVersion = $versionData['version'] ?? '';

        // Special handling for typo3/cms-core - its version IS the TYPO3 version
        if ('typo3/cms-core' === $packageName) {
            return $this->isCoreVersionCompatible($packageVersion, $typo3Version);
        }

        $requirements = $versionData['require'];
        $typo3Requirements = $this->constraintChecker->findTypo3Requirements($requirements);

        if (empty($typo3Requirements)) {
            // If no explicit TYPO3 requirement found, assume compatible
            return true;
        }

        // Check if any TYPO3 requirement is compatible with target version
        foreach ($typo3Requirements as $package => $constraint) {
            if ($this->constraintChecker->isConstraintCompatible($constraint, $typo3Version)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a typo3/cms-core version is compatible with target TYPO3 version.
     */
    private function isCoreVersionCompatible(string $packageVersion, Version $typo3Version): bool
    {
        // Remove 'v' prefix if present
        $normalizedVersion = ltrim($packageVersion, 'v');

        // Skip dev versions
        if (str_contains($normalizedVersion, 'dev')) {
            return false;
        }

        // For typo3/cms-core, the package version should match the TYPO3 version
        // Check if versions are in the same major.minor branch
        $versionParts = explode('.', $normalizedVersion);
        $targetParts = explode('.', $typo3Version->toString());

        if (\count($versionParts) < 2 || \count($targetParts) < 2) {
            return false;
        }

        // Check major.minor compatibility and that package version >= target version
        if ($versionParts[0] !== $targetParts[0] || $versionParts[1] !== $targetParts[1]) {
            return false;
        }

        // If we have patch versions, check that package version >= target version
        if (\count($versionParts) >= 3 && \count($targetParts) >= 3) {
            return (int) $versionParts[2] >= (int) $targetParts[2];
        }

        return true;
    }
}
