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

use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitAnalysisException;
use Psr\Log\LoggerInterface;

/**
 * Factory for creating appropriate Git provider instances.
 */
class GitProviderFactory
{
    /**
     * @param array<GitProviderInterface> $providers
     */
    public function __construct(
        private readonly array $providers,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Create appropriate provider for the given repository URL.
     */
    public function createProvider(string $repositoryUrl): GitProviderInterface
    {
        $availableProviders = array_filter($this->providers, fn ($provider): bool => $provider->isAvailable());

        if (empty($availableProviders)) {
            throw new GitAnalysisException(\sprintf('No suitable Git provider found for repository: %s', $repositoryUrl));
        }

        // Sort by priority (highest first)
        usort($availableProviders, fn ($a, $b): int => $b->getPriority() <=> $a->getPriority());

        // Find the first provider that supports this repository URL
        foreach ($availableProviders as $provider) {
            if ($provider->supports($repositoryUrl)) {
                $this->logger->debug('Selected Git provider', [
                    'provider' => $provider->getName(),
                    'repository_url' => $repositoryUrl,
                ]);

                return $provider;
            }
        }

        throw new GitAnalysisException(\sprintf('No suitable Git provider found for repository: %s', $repositoryUrl));
    }

    /**
     * Get all available providers.
     *
     * @return array<GitProviderInterface>
     */
    public function getAvailableProviders(): array
    {
        return array_filter($this->providers, fn ($provider): bool => $provider->isAvailable());
    }

    /**
     * Get provider by name.
     */
    public function getProvider(string $name): ?GitProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->getName() === $name) {
                return $provider;
            }
        }

        return null;
    }
}
