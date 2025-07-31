<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Abstract base class for Git providers
 */
abstract class AbstractGitProvider implements GitProviderInterface
{
    protected int $priority = 50;

    public function __construct(
        protected readonly HttpClientInterface $httpClient,
        protected readonly LoggerInterface $logger,
        protected readonly ?string $accessToken = null
    ) {
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function isAvailable(): bool
    {
        // Check if HTTP client is available and optionally if access token is configured
        return true;
    }

    /**
     * Extract repository owner and name from URL
     */
    protected function extractRepositoryPath(string $repositoryUrl): array
    {
        // Remove .git suffix and normalize URL
        $url = preg_replace('/\.git$/', '', $repositoryUrl);
        
        // Handle different URL formats
        if (preg_match('#(?:https?://|git@)([^/:]+)[/:]([^/]+)/([^/]+?)(?:\.git)?/?$#', $url, $matches)) {
            return [
                'host' => $matches[1],
                'owner' => $matches[2],
                'name' => $matches[3]
            ];
        }
        
        throw new GitProviderException("Unable to parse repository URL: {$repositoryUrl}", $this->getName());
    }

    /**
     * Make HTTP request with error handling
     */
    protected function makeRequest(string $method, string $url, array $options = []): ResponseInterface
    {
        try {
            $this->logger->debug('Making Git provider request', [
                'method' => $method,
                'url' => $url,
                'provider' => $this->getName()
            ]);

            $response = $this->httpClient->request($method, $url, $options);
            
            if ($response->getStatusCode() >= 400) {
                throw new GitProviderException(
                    sprintf(
                        'Git provider request failed with status %d: %s',
                        $response->getStatusCode(),
                        $response->getContent(false)
                    ),
                    $this->getName()
                );
            }
            
            return $response;
            
        } catch (\Throwable $e) {
            $this->logger->error('Git provider request failed', [
                'method' => $method,
                'url' => $url,
                'provider' => $this->getName(),
                'error' => $e->getMessage()
            ]);
            
            throw new GitProviderException(
                sprintf('Git provider request failed: %s', $e->getMessage()),
                $this->getName(),
                $e
            );
        }
    }

    /**
     * Parse composer.json content safely
     */
    protected function parseComposerJson(string $content): ?array
    {
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            
            // Basic validation - must be an array with a name
            if (!is_array($data) || !isset($data['name'])) {
                return null;
            }
            
            return $data;
            
        } catch (\JsonException $e) {
            $this->logger->debug('Failed to parse composer.json', [
                'error' => $e->getMessage(),
                'provider' => $this->getName()
            ]);
            
            return null;
        }
    }

    /**
     * Convert date string to DateTimeImmutable
     */
    protected function parseDate(string $dateString): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($dateString);
        } catch (\Exception $e) {
            $this->logger->debug('Failed to parse date', [
                'date_string' => $dateString,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }
}