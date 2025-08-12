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

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitProviderFactory;
use Psr\Log\LoggerInterface;

/**
 * Analyzes Git repositories for TYPO3 extension compatibility.
 */
class GitRepositoryAnalyzer
{
    public function __construct(
        private readonly GitProviderFactory $providerFactory,
        private readonly GitVersionParser $versionParser,
        private readonly LoggerInterface $logger,
        private readonly ?PackagistClient $packagistClient = null,
    ) {
    }

    /**
     * Analyze an extension's Git repository for version compatibility.
     */
    public function analyzeExtension(Extension $extension, Version $targetVersion): GitRepositoryInfo
    {
        $repositoryUrl = $this->extractRepositoryUrl($extension);

        if (!$repositoryUrl) {
            throw new GitAnalysisException('No Git repository URL found for extension: ' . $extension->getKey());
        }

        $this->logger->info('Starting Git repository analysis', [
            'extension' => $extension->getKey(),
            'repository_url' => $repositoryUrl,
            'target_version' => $targetVersion->toString(),
        ]);

        try {
            $provider = $this->providerFactory->createProvider($repositoryUrl);

            // Get repository information
            $repoInfo = $provider->getRepositoryInfo($repositoryUrl);

            // Get tags and analyze compatibility
            $tags = $provider->getTags($repositoryUrl);

            // Try to get composer.json for compatibility analysis
            $composerJson = null;
            try {
                $composerJson = $provider->getComposerJson($repositoryUrl);
            } catch (\Throwable $e) {
                $this->logger->debug('Could not retrieve composer.json from repository', [
                    'repository_url' => $repositoryUrl,
                    'error' => $e->getMessage(),
                ]);
            }

            $compatibleVersions = $this->versionParser->findCompatibleVersions($tags, $targetVersion, $composerJson);

            // Get repository health metrics
            $health = $provider->getRepositoryHealth($repositoryUrl);
            $healthScore = $health->calculateHealthScore();

            return new GitRepositoryInfo(
                $repositoryUrl,
                $repoInfo,
                $tags,
                $compatibleVersions,
                $healthScore,
                $composerJson,
                $health,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Git repository analysis failed', [
                'extension' => $extension->getKey(),
                'repository_url' => $repositoryUrl,
                'error' => $e->getMessage(),
            ]);

            throw new GitAnalysisException('Failed to analyze Git repository: ' . $e->getMessage(), $e);
        }
    }

    /**
     * Extract Git repository URL from extension metadata.
     */
    private function extractRepositoryUrl(Extension $extension): ?string
    {
        // Check if extension has direct Git repository information
        if ($extension->hasRepositoryUrl()) {
            $url = $extension->getRepositoryUrl();
            if ($url && $this->isGitRepository($url)) {
                return $url;
            }
        }

        // Check em_conf configuration for repository URL
        $emConfiguration = $extension->getEmConfiguration();
        if (!empty($emConfiguration['git_repository_url'])) {
            $url = $emConfiguration['git_repository_url'];
            if ($this->isGitRepository($url)) {
                return $url;
            }
        }

        // Try to extract from composer package via Packagist
        if ($extension->hasComposerName() && $this->packagistClient) {
            $composerName = $extension->getComposerName();
            if (null === $composerName) {
                return null; // hasComposerName() says true but getComposerName() returns null - edge case
            }

            try {
                $repositoryUrl = $this->packagistClient->getRepositoryUrl($composerName);
                if ($repositoryUrl && $this->isGitRepository($repositoryUrl)) {
                    $this->logger->debug('Found repository URL via Packagist', [
                        'extension' => $extension->getKey(),
                        'composer_name' => $composerName,
                        'repository_url' => $repositoryUrl,
                    ]);

                    return $repositoryUrl;
                }
            } catch (\Throwable $e) {
                $this->logger->debug('Failed to get repository URL from Packagist', [
                    'extension' => $extension->getKey(),
                    'composer_name' => $composerName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * Check if URL points to a Git repository.
     */
    private function isGitRepository(string $url): bool
    {
        // Check common Git URL patterns
        return preg_match('/\.(git)$/', $url)
               || str_contains($url, 'github.com')
               || str_contains($url, 'gitlab.com')
               || str_contains($url, 'bitbucket.org');
    }
}
