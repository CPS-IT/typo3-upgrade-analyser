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
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Client for interacting with the Packagist API
 */
class PackagistClient
{
    private const API_BASE_URL = 'https://packagist.org/packages';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Check if a version compatible with the target TYPO3 version exists
     */
    public function hasVersionFor(string $packageName, Version $typo3Version): bool
    {
        try {
            $response = $this->httpClient->request('GET', self::API_BASE_URL . '/' . $packageName . '.json');
            
            if ($response->getStatusCode() !== 200) {
                return false;
            }

            $data = $response->toArray();
            
            return $this->checkVersionCompatibility($data, $typo3Version);
            
        } catch (\Throwable $e) {
            $this->logger->error('Packagist API request failed', [
                'package_name' => $packageName,
                'error' => $e->getMessage(),
            ]);
            
            throw new ExternalToolException(
                sprintf('Failed to check Packagist for package "%s": %s', $packageName, $e->getMessage()),
                'packagist_api',
                $e
            );
        }
    }

    /**
     * Get the latest version for a specific TYPO3 version
     */
    public function getLatestVersion(string $packageName, Version $typo3Version): ?string
    {
        try {
            $response = $this->httpClient->request('GET', self::API_BASE_URL . '/' . $packageName . '.json');
            
            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $data = $response->toArray();
            
            return $this->findLatestCompatibleVersion($data, $typo3Version);
            
        } catch (\Throwable $e) {
            $this->logger->error('Packagist API request failed', [
                'package_name' => $packageName,
                'error' => $e->getMessage(),
            ]);
            
            throw new ExternalToolException(
                sprintf('Failed to get latest version from Packagist for package "%s": %s', $packageName, $e->getMessage()),
                'packagist_api',
                $e
            );
        }
    }

    private function checkVersionCompatibility(array $packageData, Version $typo3Version): bool
    {
        if (!isset($packageData['package']['versions']) || !is_array($packageData['package']['versions'])) {
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
        if (!isset($packageData['package']['versions']) || !is_array($packageData['package']['versions'])) {
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

        // Filter out dev versions and sort
        $stableVersions = array_filter($compatibleVersions, fn($v) => !str_contains($v, 'dev'));
        
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

        $requirements = $versionData['require'];
        
        // Check for TYPO3 core requirements
        $typo3Requirements = [
            'typo3/cms-core',
            'typo3/cms',
            'typo3/minimal',
        ];

        foreach ($typo3Requirements as $requirement) {
            if (isset($requirements[$requirement])) {
                return $this->isConstraintCompatible($requirements[$requirement], $typo3Version);
            }
        }

        // If no explicit TYPO3 requirement found, assume compatible
        return true;
    }

    private function isConstraintCompatible(string $constraint, Version $typo3Version): bool
    {
        // Simplified constraint checking - in real implementation, use Composer's constraint parser
        $majorVersion = $typo3Version->getMajor();
        
        // Check for wildcard
        if (str_contains($constraint, '*')) {
            return true;
        }

        // Parse constraint for caret version ranges (e.g., ^12.0)
        if (preg_match('/\^(\d+)\./', $constraint, $matches)) {
            $constraintMajor = (int)$matches[1];
            return $constraintMajor === $majorVersion;
        }
        
        // Check for exact major version match (e.g., 12.0)
        if (preg_match('/^(\d+)\./', $constraint, $matches)) {
            $constraintMajor = (int)$matches[1];
            return $constraintMajor === $majorVersion;
        }

        return false;
    }
}