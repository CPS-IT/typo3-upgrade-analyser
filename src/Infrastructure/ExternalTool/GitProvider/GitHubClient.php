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

use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryHealth;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryMetadata;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitTag;

/**
 * GitHub API client for repository analysis.
 */
class GitHubClient extends AbstractGitProvider
{
    protected int $priority = 100; // High priority for GitHub

    private const GRAPHQL_ENDPOINT = 'https://api.github.com/graphql';
    private const REST_ENDPOINT = 'https://api.github.com';

    public function getName(): string
    {
        return 'github';
    }

    public function supports(string $repositoryUrl): bool
    {
        return str_contains($repositoryUrl, 'github.com');
    }

    public function isAvailable(): bool
    {
        // GitHub API works without authentication but has rate limits
        // With authentication, rate limits are much higher
        return parent::isAvailable();
    }

    public function getRepositoryInfo(string $repositoryUrl): GitRepositoryMetadata
    {
        $repoPath = $this->extractRepositoryPath($repositoryUrl);

        $query = '
            query($owner: String!, $name: String!) {
                repository(owner: $owner, name: $name) {
                    name
                    description
                    isArchived
                    isFork
                    stargazerCount
                    forkCount
                    updatedAt
                    defaultBranchRef {
                        name
                    }
                }
            }
        ';

        $response = $this->graphqlRequest($query, [
            'owner' => $repoPath['owner'],
            'name' => $repoPath['name'],
        ]);

        $repo = $response['data']['repository'];

        return new GitRepositoryMetadata(
            name: $repo['name'],
            description: $repo['description'] ?? '',
            isArchived: $repo['isArchived'],
            isFork: $repo['isFork'],
            starCount: $repo['stargazerCount'],
            forkCount: $repo['forkCount'],
            lastUpdated: $this->parseDate($repo['updatedAt']) ?? new \DateTimeImmutable(),
            defaultBranch: $repo['defaultBranchRef']['name'] ?? 'main',
        );
    }

    public function getTags(string $repositoryUrl): array
    {
        $repoPath = $this->extractRepositoryPath($repositoryUrl);

        $query = '
            query($owner: String!, $name: String!, $first: Int!) {
                repository(owner: $owner, name: $name) {
                    refs(refPrefix: "refs/tags/", first: $first, orderBy: {field: TAG_COMMIT_DATE, direction: DESC}) {
                        nodes {
                            name
                            target {
                                ... on Tag {
                                    tagger {
                                        date
                                    }
                                }
                                ... on Commit {
                                    committedDate
                                    oid
                                }
                            }
                        }
                    }
                }
            }
        ';

        $response = $this->graphqlRequest($query, [
            'owner' => $repoPath['owner'],
            'name' => $repoPath['name'],
            'first' => 100, // Get up to 100 most recent tags
        ]);

        $tags = [];
        foreach ($response['data']['repository']['refs']['nodes'] as $tagData) {
            $date = null;
            $commit = null;

            if (isset($tagData['target']['tagger']['date'])) {
                $date = $this->parseDate($tagData['target']['tagger']['date']);
            } elseif (isset($tagData['target']['committedDate'])) {
                $date = $this->parseDate($tagData['target']['committedDate']);
            }

            if (isset($tagData['target']['oid'])) {
                $commit = $tagData['target']['oid'];
            }

            $tags[] = new GitTag($tagData['name'], $date, $commit);
        }

        return $tags;
    }

    public function getBranches(string $repositoryUrl): array
    {
        $repoPath = $this->extractRepositoryPath($repositoryUrl);

        $query = '
            query($owner: String!, $name: String!, $first: Int!) {
                repository(owner: $owner, name: $name) {
                    refs(refPrefix: "refs/heads/", first: $first) {
                        nodes {
                            name
                        }
                    }
                }
            }
        ';

        $response = $this->graphqlRequest($query, [
            'owner' => $repoPath['owner'],
            'name' => $repoPath['name'],
            'first' => 50,
        ]);

        $branches = [];
        foreach ($response['data']['repository']['refs']['nodes'] as $branchData) {
            $branches[] = $branchData['name'];
        }

        return $branches;
    }

    public function getComposerJson(string $repositoryUrl, string $ref = 'main'): ?array
    {
        $repoPath = $this->extractRepositoryPath($repositoryUrl);

        try {
            // Use GitHub's REST API to get file content
            $url = \sprintf(
                '%s/repos/%s/%s/contents/composer.json?ref=%s',
                self::REST_ENDPOINT,
                $repoPath['owner'],
                $repoPath['name'],
                $ref,
            );

            $headers = [];
            if ($this->accessToken) {
                $headers['Authorization'] = 'Bearer ' . $this->accessToken;
            }

            $response = $this->makeRequest('GET', $url, ['headers' => $headers]);
            $data = $response->toArray();

            if (!isset($data['content'])) {
                return null;
            }

            // Content is base64 encoded
            $content = base64_decode($data['content'], true);

            return $this->parseComposerJson($content);
        } catch (GitProviderException $e) {
            if (str_contains($e->getMessage(), '404')) {
                // composer.json not found - this is normal for many repositories
                return null;
            }

            throw $e;
        }
    }

    public function getRepositoryHealth(string $repositoryUrl): GitRepositoryHealth
    {
        $repoPath = $this->extractRepositoryPath($repositoryUrl);

        $query = '
            query($owner: String!, $name: String!) {
                repository(owner: $owner, name: $name) {
                    isArchived
                    stargazerCount
                    forkCount
                    object(expression: "HEAD") {
                        ... on Commit {
                            committedDate
                        }
                    }
                    issues(states: OPEN) {
                        totalCount
                    }
                    closedIssues: issues(states: CLOSED) {
                        totalCount
                    }
                    readme: object(expression: "HEAD:README.md") {
                        id
                    }
                    license: licenseInfo {
                        name
                    }
                }
            }
        ';

        $response = $this->graphqlRequest($query, [
            'owner' => $repoPath['owner'],
            'name' => $repoPath['name'],
        ]);

        $repo = $response['data']['repository'];

        $lastCommitDate = null;
        if (isset($repo['object']['committedDate'])) {
            $lastCommitDate = $this->parseDate($repo['object']['committedDate']);
        }

        // Get contributor count separately using REST API if authenticated
        $contributorCount = $this->getContributorCount($repositoryUrl);

        return new GitRepositoryHealth(
            lastCommitDate: $lastCommitDate,
            starCount: $repo['stargazerCount'],
            forkCount: $repo['forkCount'],
            openIssuesCount: $repo['issues']['totalCount'],
            closedIssuesCount: $repo['closedIssues']['totalCount'],
            isArchived: $repo['isArchived'],
            hasReadme: isset($repo['readme']['id']),
            hasLicense: isset($repo['license']['name']),
            contributorCount: $contributorCount,
        );
    }

    /**
     * Get contributor count using the REST API (fallback when GraphQL collaborators field is not accessible).
     */
    private function getContributorCount(string $repositoryUrl): int
    {
        try {
            $repoPath = $this->extractRepositoryPath($repositoryUrl);

            $url = \sprintf(
                '%s/repos/%s/%s/contributors?per_page=1',
                self::REST_ENDPOINT,
                $repoPath['owner'],
                $repoPath['name'],
            );

            $headers = [];
            if ($this->accessToken) {
                $headers['Authorization'] = 'Bearer ' . $this->accessToken;
            }

            $response = $this->makeRequest('GET', $url, ['headers' => $headers]);
            $responseHeaders = $response->getHeaders();

            // Extract total count from Link header if available
            if (isset($responseHeaders['link'][0])) {
                $linkHeader = $responseHeaders['link'][0];
                if (preg_match('/[?&]page=(\d+)>; rel="last"/', $linkHeader, $matches)) {
                    return (int) $matches[1];
                }
            }

            // If we can't get exact count, return at least the number of contributors we got
            $contributors = $response->toArray();

            return \count($contributors);
        } catch (GitProviderException $e) {
            // If we can't get contributor count, return 0 (not critical for health metric)
            $this->logger->warning('Could not fetch contributor count', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Make GraphQL request to GitHub API.
     */
    private function graphqlRequest(string $query, array $variables = []): array
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($this->accessToken) {
            $headers['Authorization'] = 'Bearer ' . $this->accessToken;
        }

        $response = $this->makeRequest('POST', self::GRAPHQL_ENDPOINT, [
            'headers' => $headers,
            'json' => [
                'query' => $query,
                'variables' => $variables,
            ],
        ]);

        $data = $response->toArray();

        if (isset($data['errors'])) {
            throw new GitProviderException('GitHub GraphQL errors: ' . json_encode($data['errors']), 'github');
        }

        if (!isset($data['data'])) {
            throw new GitProviderException('Invalid GraphQL response from GitHub', 'github');
        }

        return $data;
    }
}
