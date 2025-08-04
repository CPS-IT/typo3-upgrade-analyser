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

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * HTTP client wrapper for TER API requests.
 */
class TerApiHttpClient
{
    private const API_BASE_URL = 'https://extensions.typo3.org/api/v1';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ?string $terToken = null,
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
