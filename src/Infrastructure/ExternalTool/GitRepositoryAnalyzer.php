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

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitProviderFactory;
use Psr\Log\LoggerInterface;

/**
 * Analyzes Git repositories for TYPO3 extension compatibility
 */
class GitRepositoryAnalyzer
{
    public function __construct(
        private readonly GitProviderFactory $providerFactory,
        private readonly GitVersionParser $versionParser,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Analyze an extension's Git repository for version compatibility
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
            'target_version' => $targetVersion->toString()
        ]);

        try {
            $provider = $this->providerFactory->createProvider($repositoryUrl);
            
            // Get repository information
            $repoInfo = $provider->getRepositoryInfo($repositoryUrl);
            
            // Get tags and analyze compatibility
            $tags = $provider->getTags($repositoryUrl);
            $compatibleVersions = $this->versionParser->findCompatibleVersions($tags, $targetVersion);
            
            // Get repository health metrics
            $healthScore = $this->calculateRepositoryHealth($provider, $repositoryUrl);
            
            // Try to get composer.json for additional compatibility info
            $composerJson = null;
            try {
                $composerJson = $provider->getComposerJson($repositoryUrl);
            } catch (\Throwable $e) {
                $this->logger->debug('Could not retrieve composer.json from repository', [
                    'repository_url' => $repositoryUrl,
                    'error' => $e->getMessage()
                ]);
            }

            return new GitRepositoryInfo(
                $repositoryUrl,
                $repoInfo,
                $tags,
                $compatibleVersions,
                $healthScore,
                $composerJson
            );

        } catch (\Throwable $e) {
            $this->logger->error('Git repository analysis failed', [
                'extension' => $extension->getKey(),
                'repository_url' => $repositoryUrl,
                'error' => $e->getMessage()
            ]);
            
            throw new GitAnalysisException(
                'Failed to analyze Git repository: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Extract Git repository URL from extension metadata
     */
    private function extractRepositoryUrl(Extension $extension): ?string
    {
        // Check if extension has direct Git repository information
        if (method_exists($extension, 'getRepositoryUrl')) {
            $url = $extension->getRepositoryUrl();
            if ($url && $this->isGitRepository($url)) {
                return $url;
            }
        }

        // Try to extract from composer name/metadata
        if ($extension->hasComposerName()) {
            // Check if the extension was installed from a VCS source
            // This would be available in composer.lock or other metadata
            $composerName = $extension->getComposerName();
            
            // For now, we'll need additional metadata to determine Git sources
            // This will be enhanced when Extension entity is extended with more metadata
        }

        return null;
    }

    /**
     * Check if URL points to a Git repository
     */
    private function isGitRepository(string $url): bool
    {
        // Check common Git URL patterns
        return preg_match('/\.(git)$/', $url) ||
               str_contains($url, 'github.com') ||
               str_contains($url, 'gitlab.com') ||
               str_contains($url, 'bitbucket.org');
    }

    /**
     * Calculate repository health score based on various metrics
     */
    private function calculateRepositoryHealth(object $provider, string $repositoryUrl): float
    {
        try {
            $health = $provider->getRepositoryHealth($repositoryUrl);
            
            $score = 0.0;
            $maxScore = 0.0;

            // Repository activity (40% of score)
            if ($health->getLastCommitDate()) {
                $daysSinceLastCommit = (new \DateTime())->diff($health->getLastCommitDate())->days;
                if ($daysSinceLastCommit <= 30) {
                    $score += 0.4;
                } elseif ($daysSinceLastCommit <= 90) {
                    $score += 0.3;
                } elseif ($daysSinceLastCommit <= 365) {
                    $score += 0.2;
                } else {
                    $score += 0.1;
                }
            }
            $maxScore += 0.4;

            // Repository popularity (20% of score)
            $stars = $health->getStarCount();
            if ($stars > 100) {
                $score += 0.2;
            } elseif ($stars > 50) {
                $score += 0.15;
            } elseif ($stars > 10) {
                $score += 0.1;
            } elseif ($stars > 0) {
                $score += 0.05;
            }
            $maxScore += 0.2;

            // Issue management (20% of score)
            $openIssues = $health->getOpenIssuesCount();
            $closedIssues = $health->getClosedIssuesCount();
            if ($closedIssues > 0) {
                $issueRatio = $closedIssues / ($openIssues + $closedIssues);
                if ($issueRatio > 0.8) {
                    $score += 0.2;
                } elseif ($issueRatio > 0.6) {
                    $score += 0.15;
                } elseif ($issueRatio > 0.4) {
                    $score += 0.1;
                } else {
                    $score += 0.05;
                }
            }
            $maxScore += 0.2;

            // Repository maintenance indicators (20% of score)
            if (!$health->isArchived()) {
                $score += 0.1;
            }
            if ($health->hasReadme()) {
                $score += 0.05;
            }
            if ($health->hasLicense()) {
                $score += 0.05;
            }
            $maxScore += 0.2;

            return $maxScore > 0 ? $score / $maxScore : 0.0;

        } catch (\Throwable $e) {
            $this->logger->warning('Could not calculate repository health', [
                'repository_url' => $repositoryUrl,
                'error' => $e->getMessage()
            ]);
            
            return 0.5; // Default neutral score
        }
    }
}