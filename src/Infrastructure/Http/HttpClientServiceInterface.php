<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Http;

use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Interface for unified HTTP client service.
 */
interface HttpClientServiceInterface
{
    /**
     * Make HTTP request with unified error handling and logging.
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface;

    /**
     * Make GET request.
     */
    public function get(string $url, array $options = []): ResponseInterface;

    /**
     * Make POST request.
     */
    public function post(string $url, array $options = []): ResponseInterface;

    /**
     * Make authenticated request with Bearer token.
     */
    public function makeAuthenticatedRequest(
        string $method,
        string $url,
        ?string $token = null,
        array $options = []
    ): ResponseInterface;

    /**
     * Make request with automatic rate limit handling and retries.
     */
    public function makeRateLimitedRequest(
        string $method,
        string $url,
        array $options = [],
        int $maxRetries = 3,
        int $retryDelay = 1
    ): ResponseInterface;
}