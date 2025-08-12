<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider;

use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryHealth;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryMetadata;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitTag;

/**
 * Interface for Git repository providers.
 */
interface GitProviderInterface
{
    /**
     * Check if this provider supports the given repository URL.
     */
    public function supports(string $repositoryUrl): bool;

    /**
     * Get basic repository information.
     */
    public function getRepositoryInfo(string $repositoryUrl): GitRepositoryMetadata;

    /**
     * Get all tags from the repository.
     *
     * @return array<GitTag>
     */
    public function getTags(string $repositoryUrl): array;

    /**
     * Get all branches from the repository.
     *
     * @return array<string>
     */
    public function getBranches(string $repositoryUrl): array;

    /**
     * Get composer.json content from repository.
     */
    public function getComposerJson(string $repositoryUrl, string $ref = 'main'): ?array;

    /**
     * Get repository health metrics.
     */
    public function getRepositoryHealth(string $repositoryUrl): GitRepositoryHealth;

    /**
     * Get the provider name.
     */
    public function getName(): string;

    /**
     * Get priority for provider selection (higher = more preferred).
     */
    public function getPriority(): int;

    /**
     * Check if provider is properly configured and available.
     */
    public function isAvailable(): bool;
}
