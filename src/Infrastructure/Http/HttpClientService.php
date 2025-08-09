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

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Unified HTTP client service with consistent error handling and logging.
 */
class HttpClientService implements HttpClientServiceInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        try {
            $this->logger->debug('HTTP request', [
                'method' => $method,
                'url' => $url,
                'options' => $this->sanitizeOptions($options),
            ]);

            $response = $this->httpClient->request($method, $url, $options);

            $this->logger->debug('HTTP response', [
                'method' => $method,
                'url' => $url,
                'status_code' => $response->getStatusCode(),
            ]);

            return $response;
        } catch (\Throwable $e) {
            $this->logger->error('HTTP request failed', [
                'method' => $method,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw new HttpClientException(\sprintf('HTTP %s request to %s failed: %s', $method, $url, $e->getMessage()), $e->getCode(), $e);
        }
    }

    public function get(string $url, array $options = []): ResponseInterface
    {
        return $this->request('GET', $url, $options);
    }

    public function post(string $url, array $options = []): ResponseInterface
    {
        return $this->request('POST', $url, $options);
    }

    public function makeAuthenticatedRequest(
        string $method,
        string $url,
        ?string $token = null,
        array $options = [],
    ): ResponseInterface {
        if ($token) {
            $options['headers'] = array_merge($options['headers'] ?? [], [
                'Authorization' => 'Bearer ' . $token,
            ]);
        }

        return $this->request($method, $url, $options);
    }

    public function makeRateLimitedRequest(
        string $method,
        string $url,
        array $options = [],
        int $maxRetries = 3,
        int $retryDelay = 1,
    ): ResponseInterface {
        $attempt = 0;
        $lastException = null;

        while ($attempt <= $maxRetries) {
            try {
                $response = $this->request($method, $url, $options);

                // Check for rate limiting
                if (429 === $response->getStatusCode()) {
                    ++$attempt;
                    if ($attempt <= $maxRetries) {
                        $this->logger->warning('Rate limited, retrying', [
                            'attempt' => $attempt,
                            'url' => $url,
                            'delay' => $retryDelay,
                        ]);
                        sleep($retryDelay * $attempt); // Exponential backoff
                        continue;
                    }
                }

                return $response;
            } catch (HttpClientException $e) {
                $lastException = $e;

                // Only retry on certain HTTP errors
                if (str_contains($e->getMessage(), '429') || str_contains($e->getMessage(), '503')) {
                    ++$attempt;
                    if ($attempt <= $maxRetries) {
                        $this->logger->warning('HTTP error, retrying', [
                            'attempt' => $attempt,
                            'url' => $url,
                            'error' => $e->getMessage(),
                            'delay' => $retryDelay,
                        ]);
                        sleep($retryDelay * $attempt);
                        continue;
                    }
                }

                throw $e;
            }
        }

        throw $lastException ?? new HttpClientException('Max retries exceeded');
    }

    /**
     * Sanitize options for logging (remove sensitive data).
     */
    private function sanitizeOptions(array $options): array
    {
        $sanitized = $options;

        // Remove sensitive headers
        if (isset($sanitized['headers'])) {
            foreach ($sanitized['headers'] as $key => $value) {
                if (false !== stripos($key, 'authorization') || false !== stripos($key, 'token')) {
                    $sanitized['headers'][$key] = '***';
                }
            }
        }

        // Remove request body for security
        if (isset($sanitized['body'])) {
            $sanitized['body'] = '[REDACTED]';
        }

        if (isset($sanitized['json'])) {
            $sanitized['json'] = '[REDACTED]';
        }

        return $sanitized;
    }
}
