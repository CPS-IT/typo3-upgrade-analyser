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
 * Client for interacting with the TYPO3 Extension Repository (TER) API
 */
class TerApiClient
{
    private const API_BASE_URL = 'https://extensions.typo3.org/api/v1';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Check if a version compatible with the target TYPO3 version exists
     */
    public function hasVersionFor(string $extensionKey, Version $typo3Version): bool
    {
        try {
            $response = $this->httpClient->request('GET', self::API_BASE_URL . '/extension/' . $extensionKey);
            
            if ($response->getStatusCode() !== 200) {
                return false;
            }

            $data = $response->toArray();
            
            return $this->checkVersionCompatibility($data, $typo3Version);
            
        } catch (\Throwable $e) {
            $this->logger->error('TER API request failed', [
                'extension_key' => $extensionKey,
                'error' => $e->getMessage(),
            ]);
            
            throw new ExternalToolException(
                sprintf('Failed to check TER for extension "%s": %s', $extensionKey, $e->getMessage()),
                'ter_api',
                $e
            );
        }
    }

    /**
     * Get the latest version for a specific TYPO3 version
     */
    public function getLatestVersion(string $extensionKey, Version $typo3Version): ?string
    {
        try {
            $response = $this->httpClient->request('GET', self::API_BASE_URL . '/extension/' . $extensionKey);
            
            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $data = $response->toArray();
            
            return $this->findLatestCompatibleVersion($data, $typo3Version);
            
        } catch (\Throwable $e) {
            $this->logger->error('TER API request failed', [
                'extension_key' => $extensionKey,
                'error' => $e->getMessage(),
            ]);
            
            throw new ExternalToolException(
                sprintf('Failed to get latest version from TER for extension "%s": %s', $extensionKey, $e->getMessage()),
                'ter_api',
                $e
            );
        }
    }

    private function checkVersionCompatibility(array $extensionData, Version $typo3Version): bool
    {
        if (!isset($extensionData['versions']) || !is_array($extensionData['versions'])) {
            return false;
        }

        foreach ($extensionData['versions'] as $versionData) {
            if ($this->isVersionCompatible($versionData, $typo3Version)) {
                return true;
            }
        }

        return false;
    }

    private function findLatestCompatibleVersion(array $extensionData, Version $typo3Version): ?string
    {
        if (!isset($extensionData['versions']) || !is_array($extensionData['versions'])) {
            return null;
        }

        $compatibleVersions = [];
        
        foreach ($extensionData['versions'] as $versionData) {
            if ($this->isVersionCompatible($versionData, $typo3Version)) {
                $compatibleVersions[] = $versionData['version'];
            }
        }

        if (empty($compatibleVersions)) {
            return null;
        }

        // Sort versions and return the latest
        usort($compatibleVersions, 'version_compare');
        
        return end($compatibleVersions);
    }

    private function isVersionCompatible(array $versionData, Version $typo3Version): bool
    {
        if (!isset($versionData['typo3_versions'])) {
            return false;
        }

        $typo3Versions = $versionData['typo3_versions'];
        $majorVersion = $typo3Version->getMajor();
        
        // Check if the extension supports the target TYPO3 major version
        return in_array($majorVersion . '.0', $typo3Versions, true) ||
               in_array($majorVersion . '.*', $typo3Versions, true) ||
               in_array('*', $typo3Versions, true);
    }
}