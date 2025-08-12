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
use CPSIT\UpgradeAnalyzer\Infrastructure\Http\HttpClientServiceInterface;
use CPSIT\UpgradeAnalyzer\Shared\Configuration\EnvironmentLoader;
use Psr\Log\LoggerInterface;

/**
 * Client for interacting with the TYPO3 Extension Repository (TER) API.
 */
class TerApiClient
{
    private readonly TerApiHttpClient $httpClient;
    private readonly TerApiResponseParser $responseParser;
    private readonly VersionCompatibilityChecker $compatibilityChecker;

    public function __construct(
        HttpClientServiceInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
        // Load TER token from environment
        $terToken = EnvironmentLoader::get('TER_ACCESS_TOKEN');

        if (!$terToken) {
            $this->logger->warning('TER_ACCESS_TOKEN not found in environment variables. TER API requests may be rate-limited. Set TER_ACCESS_TOKEN in .env.local for better performance.');
        } else {
            $this->logger->debug('TER_ACCESS_TOKEN found, using authenticated TER API requests');
        }

        $this->httpClient = new TerApiHttpClient($httpClient, $logger, $terToken);
        $this->responseParser = new TerApiResponseParser();
        $this->compatibilityChecker = new VersionCompatibilityChecker();
    }

    /**
     * Check if a version compatible with the target TYPO3 version exists.
     */
    public function hasVersionFor(string $extensionKey, Version $typo3Version): bool
    {
        try {
            $extensionWithVersions = $this->getExtensionWithVersions($extensionKey);

            if (null === $extensionWithVersions['versions']) {
                return false;
            }

            $versions = $this->responseParser->parseVersionsData($extensionWithVersions['versions']);
            if (null === $versions) {
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

            throw new TerApiException(\sprintf('Failed to check TER for extension "%s": %s', $extensionKey, $e->getMessage()), $e);
        }
    }

    /**
     * Get the latest version for a specific TYPO3 version.
     */
    public function getLatestVersion(string $extensionKey, Version $typo3Version): ?string
    {
        try {
            $extensionWithVersions = $this->getExtensionWithVersions($extensionKey);

            if (null === $extensionWithVersions['versions']) {
                return null;
            }

            $versions = $this->responseParser->parseVersionsData($extensionWithVersions['versions']);
            if (null === $versions) {
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

            throw new TerApiException(\sprintf('Failed to get latest version from TER for extension "%s": %s', $extensionKey, $e->getMessage()), $e);
        }
    }

    /**
     * Get extension and versions data in a single operation
     * This reduces the N+1 API calls problem.
     */
    private function getExtensionWithVersions(string $extensionKey): array
    {
        $data = $this->httpClient->getExtensionWithVersions($extensionKey);

        if (null === $data['extension']) {
            throw new TerExtensionNotFoundException($extensionKey);
        }

        return $data;
    }
}
