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

use CPSIT\UpgradeAnalyzer\Infrastructure\Http\HttpClientServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * HTTP client wrapper for TER API requests.
 */
readonly class TerApiHttpClient
{
    private const string API_BASE_URL = 'https://extensions.typo3.org/api/v1';

    public function __construct(
        private HttpClientServiceInterface $httpClient,
        private LoggerInterface $logger,
        private ?string $terToken = null,
    ) {
    }

    /**
     * Get extension data from TER API.
     */
    public function getExtensionData(string $extensionKey): ?array
    {
        $response = $this->makeRequest('/extension/' . $extensionKey);

        if (!$this->isSuccessfulResponse($response)) {
            return null;
        }

        return $response->toArray();
    }

    /**
     * Get versions data from TER API.
     *
     * Known issue (as of 2026-04): The TER API returns `"typo3_versions": []` for all extension
     * versions, making compatibility checks always return false. Tracked in:
     *   https://git.typo3.org/services/t3o-sites/extensions.typo3.org/ter/-/issues/650
     *   https://git.typo3.org/services/t3o-sites/extensions.typo3.org/ter/-/issues/653
     * TER source checks are effectively non-functional until this is resolved upstream.
     */
    public function getVersionsData(string $extensionKey): ?array
    {
        $response = $this->makeRequest('/extension/' . $extensionKey . '/versions');

        if (!$this->isSuccessfulResponse($response)) {
            return null;
        }

        return $response->toArray();
    }

    /**
     * Get both extension and versions data in a single call
     * This reduces the N+1 problem by batching related requests.
     */
    public function getExtensionWithVersions(string $extensionKey): array
    {
        $extensionData = $this->getExtensionData($extensionKey);
        $versionsData = null;

        // Only fetch versions if extension exists
        if (null !== $extensionData) {
            $versionsData = $this->getVersionsData($extensionKey);
        }

        return [
            'extension' => $extensionData,
            'versions' => $versionsData,
        ];
    }

    /**
     * Make HTTP request to TER API endpoint.
     */
    private function makeRequest(string $endpoint): ResponseInterface
    {
        $headers = [];
        if ($this->terToken) {
            $headers['Authorization'] = 'Bearer ' . $this->terToken;
        }

        return $this->httpClient->request('GET', self::API_BASE_URL . $endpoint, [
            'headers' => $headers,
        ]);
    }

    /**
     * Check if response is successful.
     */
    private function isSuccessfulResponse(ResponseInterface $response): bool
    {
        $statusCode = $response->getStatusCode();

        if (500 === $statusCode) {
            $this->logger->warning('TER API server error (500)', [
                'status_code' => 500,
            ]);

            return false;
        }

        if (400 === $statusCode) {
            // TER returns 400 for non-existent extensions instead of 404
            return false;
        }

        return 200 === $statusCode;
    }
}
