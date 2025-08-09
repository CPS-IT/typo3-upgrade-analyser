<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Repository;

/**
 * Unified service for handling and normalizing repository URLs.
 */
class RepositoryUrlHandler implements RepositoryUrlHandlerInterface
{
    /**
     * Normalize repository URL to standard HTTPS format.
     */
    public function normalizeUrl(string $url): string
    {
        // Convert GitHub URLs to HTTPS format
        if (preg_match('#github\.com[:/]([^/]+)/([^/\.]+)#', $url, $matches)) {
            return \sprintf('https://github.com/%s/%s', $matches[1], $matches[2]);
        }

        // Convert GitLab URLs to HTTPS format
        if (preg_match('#gitlab\.com[:/]([^/]+)/([^/\.]+)#', $url, $matches)) {
            return \sprintf('https://gitlab.com/%s/%s', $matches[1], $matches[2]);
        }

        // Convert Bitbucket URLs to HTTPS format
        if (preg_match('#bitbucket\.org[:/]([^/]+)/([^/\.]+)#', $url, $matches)) {
            return \sprintf('https://bitbucket.org/%s/%s', $matches[1], $matches[2]);
        }

        // Remove .git suffix if present
        if (str_ends_with($url, '.git')) {
            $url = substr($url, 0, -4);
        }

        return $url;
    }

    /**
     * Check if URL points to a Git repository.
     */
    public function isGitRepository(string $url): bool
    {
        // URLs ending with .git are definitely Git repositories
        if (1 === preg_match('/\.git$/', $url)) {
            return true;
        }

        // Check for repository URLs with owner/repo pattern
        if (preg_match('#(github\.com|gitlab\.com|bitbucket\.org)[:/]([^/]+)/([^/\.]+)#', $url)) {
            return true;
        }

        // Check for Git protocol URLs
        if (1 === preg_match('#^(git|ssh)://#', $url)) {
            return true;
        }

        // Check for URLs with git subdomain or containing 'git' in domain
        if (1 === preg_match('#^https?://git\.#', $url) || 1 === preg_match('#^https?://[^/]*\.git\.[^/]*/#', $url) || 1 === preg_match('#^https?://[^/]*git[^/]*\.com/#', $url)) {
            return true;
        }

        return false;
    }

    /**
     * Extract repository path components (owner/name) from URL.
     */
    public function extractRepositoryPath(string $url): array
    {
        // Normalize URL first
        $normalizedUrl = $this->normalizeUrl($url);

        // Extract path from normalized URL
        $parsed = parse_url($normalizedUrl);
        if (!isset($parsed['path'])) {
            throw new RepositoryUrlException('Invalid repository URL: ' . $url);
        }

        $pathParts = explode('/', trim($parsed['path'], '/'));

        if (\count($pathParts) < 2) {
            throw new RepositoryUrlException('Repository URL must contain owner and name: ' . $url);
        }

        return [
            'owner' => $pathParts[0],
            'name' => $pathParts[1],
            'host' => $parsed['host'] ?? '',
        ];
    }

    /**
     * Get the provider type from repository URL.
     */
    public function getProviderType(string $url): string
    {
        if (str_contains($url, 'github.com')) {
            return 'github';
        }

        if (str_contains($url, 'gitlab.com')) {
            return 'gitlab';
        }

        if (str_contains($url, 'bitbucket.org')) {
            return 'bitbucket';
        }

        return 'unknown';
    }

    /**
     * Validate repository URL format.
     */
    public function isValidRepositoryUrl(string $url): bool
    {
        try {
            // Check if it's a Git repository
            if (!$this->isGitRepository($url)) {
                return false;
            }

            // Try to extract path components
            $this->extractRepositoryPath($url);

            return true;
        } catch (RepositoryUrlException $e) {
            return false;
        }
    }

    /**
     * Convert various repository URL formats to API-friendly format.
     */
    public function convertToApiUrl(string $url, string $apiType = 'rest'): string
    {
        $path = $this->extractRepositoryPath($url);
        $provider = $this->getProviderType($url);

        switch ($provider) {
            case 'github':
                return match ($apiType) {
                    'graphql' => 'https://api.github.com/graphql',
                    'rest' => \sprintf('https://api.github.com/repos/%s/%s', $path['owner'], $path['name']),
                    default => throw new RepositoryUrlException('Unsupported API type: ' . $apiType),
                };

            case 'gitlab':
                // GitLab uses project ID or namespace/project format
                return \sprintf(
                    'https://gitlab.com/api/v4/projects/%s%%2F%s',
                    urlencode($path['owner']),
                    urlencode($path['name']),
                );

            case 'bitbucket':
                return \sprintf(
                    'https://api.bitbucket.org/2.0/repositories/%s/%s',
                    $path['owner'],
                    $path['name'],
                );

            default:
                throw new RepositoryUrlException('Unsupported repository provider: ' . $provider);
        }
    }
}
