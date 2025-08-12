<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Repository;

/**
 * Interface for handling repository URLs.
 */
interface RepositoryUrlHandlerInterface
{
    /**
     * Normalize repository URL to standard HTTPS format.
     */
    public function normalizeUrl(string $url): string;

    /**
     * Check if URL points to a Git repository.
     */
    public function isGitRepository(string $url): bool;

    /**
     * Extract repository path components (owner/name) from URL.
     */
    public function extractRepositoryPath(string $url): array;

    /**
     * Get the provider type from repository URL.
     */
    public function getProviderType(string $url): string;

    /**
     * Validate repository URL format.
     */
    public function isValidRepositoryUrl(string $url): bool;

    /**
     * Convert repository URL to API-friendly format.
     */
    public function convertToApiUrl(string $url, string $apiType = 'rest'): string;
}
