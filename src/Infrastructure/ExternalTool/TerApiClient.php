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
    private readonly TerApiHttpClient $httpClient;
    private readonly TerApiResponseParser $responseParser;
    private readonly VersionCompatibilityChecker $compatibilityChecker;

    public function __construct(
        HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {
        // Load TER token from environment
        $terToken = $_ENV['TER_TOKEN'] ?? getenv('TER_TOKEN') ?: null;
        
        $this->httpClient = new TerApiHttpClient($httpClient, $logger, $terToken);
        $this->responseParser = new TerApiResponseParser();
        $this->compatibilityChecker = new VersionCompatibilityChecker();
    }

    /**
     * Check if a version compatible with the target TYPO3 version exists
     */
    public function hasVersionFor(string $extensionKey, Version $typo3Version): bool
    {
        try {
            $extensionWithVersions = $this->getExtensionWithVersions($extensionKey);
            
            if ($extensionWithVersions['versions'] === null) {
                return false;
            }
            
            $versions = $this->responseParser->parseVersionsData($extensionWithVersions['versions']);
            if ($versions === null) {
                return false;
            }
            
            return $this->compatibilityChecker->hasCompatibleVersion($versions, $typo3Version);
            
        } catch (TerExtensionNotFoundException $e) {
            // Extension doesn't exist - return false gracefully
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('TER API request failed', [
                'extension_key' => $extensionKey,
                'error' => $e->getMessage(),
            ]);
            
            throw new TerApiException(
                sprintf('Failed to check TER for extension "%s": %s', $extensionKey, $e->getMessage()),
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
            $extensionWithVersions = $this->getExtensionWithVersions($extensionKey);
            
            if ($extensionWithVersions['versions'] === null) {
                return null;
            }
            
            $versions = $this->responseParser->parseVersionsData($extensionWithVersions['versions']);
            if ($versions === null) {
                return null;
            }
            
            return $this->compatibilityChecker->getLatestCompatibleVersion($versions, $typo3Version);
            
        } catch (TerExtensionNotFoundException $e) {
            // Extension doesn't exist - return null gracefully
            return null;
        } catch (\Throwable $e) {
            $this->logger->error('TER API request failed', [
                'extension_key' => $extensionKey,
                'error' => $e->getMessage(),
            ]);
            
            throw new TerApiException(
                sprintf('Failed to get latest version from TER for extension "%s": %s', $extensionKey, $e->getMessage()),
                $e
            );
        }
    }
    
    /**
     * Get extension and versions data in a single operation
     * This reduces the N+1 API calls problem
     */
    private function getExtensionWithVersions(string $extensionKey): array
    {
        $data = $this->httpClient->getExtensionWithVersions($extensionKey);
        
        if ($data['extension'] === null) {
            throw new TerExtensionNotFoundException($extensionKey);
        }
        
        return $data;
    }
}