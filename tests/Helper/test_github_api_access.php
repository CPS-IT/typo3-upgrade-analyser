<?php

declare(strict_types=1);

/**
 * Simple GitHub API Access Test Script
 * Tests both REST and GraphQL endpoints without project dependencies.
 */
echo "🔍 GitHub API Access Test\n";
echo "========================\n\n";

// Get GitHub token from environment
$githubToken = getenv('GITHUB_TOKEN') ?: '';
$hasToken = !empty($githubToken);

echo '🔑 GitHub Token: ' . ($hasToken ? '✅ Provided' : '❌ Not set') . "\n";
if ($hasToken) {
    echo '   Token prefix: ' . substr($githubToken, 0, 8) . "...\n";
}
echo "\n";

// Test repository for API calls
$testRepo = 'georgringer/news';

/**
 * Make HTTP request with cURL.
 */
function makeRequest(string $url, array $headers = [], ?string $data = null): array
{
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'TYPO3-Upgrade-Analyzer-Test/1.0',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    if (null !== $data) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    if ($error) {
        throw new Exception("cURL error: {$error}");
    }

    $data = json_decode($response, true);

    return [
        'status' => $httpCode,
        'data' => $data,
        'raw' => $response,
    ];
}

/**
 * Test GitHub REST API.
 */
function testRestApi(string $token = ''): void
{
    echo "🌐 Testing GitHub REST API...\n";

    $headers = [
        'Accept: application/vnd.github.v3+json',
        'User-Agent: TYPO3-Upgrade-Analyzer-Test/1.0',
    ];

    if (!empty($token)) {
        $headers[] = "Authorization: Bearer {$token}";
    }

    try {
        // Test 1: Get repository info
        echo '  📋 Repository Info: ';
        $response = makeRequest('https://api.github.com/repos/georgringer/news', $headers);

        if (200 === $response['status']) {
            $repo = $response['data'];
            echo "✅ Success\n";
            echo "    - Name: {$repo['name']}\n";
            echo "    - Stars: {$repo['stargazers_count']}\n";
            echo "    - Forks: {$repo['forks_count']}\n";
            echo '    - Archived: ' . ($repo['archived'] ? 'Yes' : 'No') . "\n";
        } else {
            echo "❌ Failed (HTTP {$response['status']})\n";
            if (isset($response['data']['message'])) {
                echo "    Error: {$response['data']['message']}\n";
            }
        }

        // Test 2: Get repository tags
        echo '  🏷️  Repository Tags: ';
        $response = makeRequest('https://api.github.com/repos/georgringer/news/tags?per_page=5', $headers);

        if (200 === $response['status']) {
            $tags = $response['data'];
            echo '✅ Success (' . \count($tags) . " tags)\n";
            foreach (\array_slice($tags, 0, 3) as $tag) {
                echo "    - {$tag['name']}\n";
            }
        } else {
            echo "❌ Failed (HTTP {$response['status']})\n";
            if (isset($response['data']['message'])) {
                echo "    Error: {$response['data']['message']}\n";
            }
        }

        // Test 3: Get contributors (requires more permissions)
        echo '  👥 Contributors: ';
        $response = makeRequest('https://api.github.com/repos/georgringer/news/contributors?per_page=3', $headers);

        if (200 === $response['status']) {
            $contributors = $response['data'];
            echo '✅ Success (' . \count($contributors) . " contributors)\n";
            foreach ($contributors as $contributor) {
                echo "    - {$contributor['login']} ({$contributor['contributions']} contributions)\n";
            }
        } else {
            echo "❌ Failed (HTTP {$response['status']})\n";
            if (isset($response['data']['message'])) {
                echo "    Error: {$response['data']['message']}\n";
            }
        }

        // Test 4: Rate limit info
        echo '  📊 Rate Limit Status: ';
        $response = makeRequest('https://api.github.com/rate_limit', $headers);

        if (200 === $response['status']) {
            $rateLimit = $response['data']['rate'];
            echo "✅ Success\n";
            echo "    - Limit: {$rateLimit['limit']}\n";
            echo "    - Used: {$rateLimit['used']}\n";
            echo "    - Remaining: {$rateLimit['remaining']}\n";
            echo '    - Reset: ' . date('Y-m-d H:i:s', $rateLimit['reset']) . "\n";
        } else {
            echo "❌ Failed (HTTP {$response['status']})\n";
        }
    } catch (Exception $e) {
        echo "❌ Exception: {$e->getMessage()}\n";
    }

    echo "\n";
}

/**
 * Test GitHub GraphQL API.
 */
function testGraphQLApi(string $token = ''): void
{
    echo "🔮 Testing GitHub GraphQL API...\n";

    $headers = [
        'Content-Type: application/json',
        'User-Agent: TYPO3-Upgrade-Analyzer-Test/1.0',
    ];

    if (!empty($token)) {
        $headers[] = "Authorization: Bearer {$token}";
    }

    try {
        // Test 1: Basic repository query (without sensitive fields)
        echo '  📋 Repository Query (Basic): ';
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

        $payload = json_encode([
            'query' => $query,
            'variables' => [
                'owner' => 'georgringer',
                'name' => 'news',
            ],
        ]);

        $response = makeRequest('https://api.github.com/graphql', $headers, $payload);

        if (200 === $response['status'] && !isset($response['data']['errors'])) {
            $repo = $response['data']['data']['repository'];
            echo "✅ Success\n";
            echo "    - Name: {$repo['name']}\n";
            echo "    - Stars: {$repo['stargazerCount']}\n";
            echo "    - Forks: {$repo['forkCount']}\n";
            echo '    - Archived: ' . ($repo['isArchived'] ? 'Yes' : 'No') . "\n";
            echo "    - Default Branch: {$repo['defaultBranchRef']['name']}\n";
        } else {
            echo "❌ Failed (HTTP {$response['status']})\n";
            if (isset($response['data']['errors'])) {
                foreach ($response['data']['errors'] as $error) {
                    echo "    GraphQL Error: {$error['message']}\n";
                }
            }
        }

        // Test 2: Repository tags query
        echo '  🏷️  Tags Query: ';
        $tagsQuery = '
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

        $payload = json_encode([
            'query' => $tagsQuery,
            'variables' => [
                'owner' => 'georgringer',
                'name' => 'news',
                'first' => 5,
            ],
        ]);

        $response = makeRequest('https://api.github.com/graphql', $headers, $payload);

        if (200 === $response['status'] && !isset($response['data']['errors'])) {
            $tags = $response['data']['data']['repository']['refs']['nodes'];
            echo '✅ Success (' . \count($tags) . " tags)\n";
            foreach (\array_slice($tags, 0, 3) as $tag) {
                echo "    - {$tag['name']}\n";
            }
        } else {
            echo "❌ Failed (HTTP {$response['status']})\n";
            if (isset($response['data']['errors'])) {
                foreach ($response['data']['errors'] as $error) {
                    echo "    GraphQL Error: {$error['message']}\n";
                }
            }
        }

        // Test 3: Repository health query (with potentially restricted fields)
        echo '  🏥 Health Query (Advanced): ';
        $healthQuery = '
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

        $payload = json_encode([
            'query' => $healthQuery,
            'variables' => [
                'owner' => 'georgringer',
                'name' => 'news',
            ],
        ]);

        $response = makeRequest('https://api.github.com/graphql', $headers, $payload);

        if (200 === $response['status'] && !isset($response['data']['errors'])) {
            $repo = $response['data']['data']['repository'];
            echo "✅ Success\n";
            echo "    - Open Issues: {$repo['issues']['totalCount']}\n";
            echo "    - Closed Issues: {$repo['closedIssues']['totalCount']}\n";
            echo '    - Has README: ' . (isset($repo['readme']['id']) ? 'Yes' : 'No') . "\n";
            echo '    - License: ' . ($repo['license']['name'] ?? 'None') . "\n";
        } else {
            echo "❌ Failed (HTTP {$response['status']})\n";
            if (isset($response['data']['errors'])) {
                foreach ($response['data']['errors'] as $error) {
                    echo "    GraphQL Error: {$error['message']}\n";
                    if (isset($error['path'])) {
                        echo '    Path: ' . implode(' → ', $error['path']) . "\n";
                    }
                }
            }
        }
    } catch (Exception $e) {
        echo "❌ Exception: {$e->getMessage()}\n";
    }

    echo "\n";
}

/**
 * Test API without authentication.
 */
function testUnauthenticatedAccess(): void
{
    echo "🔓 Testing Unauthenticated Access...\n";

    try {
        $response = makeRequest('https://api.github.com/repos/georgringer/news');

        if (200 === $response['status']) {
            echo "  ✅ Unauthenticated REST API works\n";
            echo "  📊 Rate Limit: Basic (60 requests/hour)\n";
        } else {
            echo "  ❌ Unauthenticated access failed (HTTP {$response['status']})\n";
        }
    } catch (Exception $e) {
        echo "  ❌ Exception: {$e->getMessage()}\n";
    }

    echo "\n";
}

// Run tests
echo "🚀 Starting API Tests...\n\n";

// Test without authentication first
testUnauthenticatedAccess();

// Test REST API
testRestApi($githubToken);

// Test GraphQL API
testGraphQLApi($githubToken);

// Summary
echo "📋 Test Summary:\n";
echo "================\n";

if ($hasToken) {
    echo "✅ Tested with GitHub token\n";
    echo "💡 If you see permission errors, your token may need additional scopes\n";
    echo "💡 Required scopes: repo (for public repositories)\n";
    echo "💡 Optional scopes: read:org (for organization data)\n";
} else {
    echo "⚠️  Tested without GitHub token (rate limited)\n";
    echo "💡 Set GITHUB_TOKEN environment variable for higher rate limits\n";
    echo "💡 Example: export GITHUB_TOKEN=ghp_your_token_here\n";
}

echo "\n🎯 Token Creation Guide:\n";
echo "1. Go to: https://github.com/settings/tokens\n";
echo "2. Click 'Generate new token (classic)'\n";
echo "3. Select scopes: 'repo' (Full control of private repositories)\n";
echo "4. Copy token and set: export GITHUB_TOKEN=your_token\n";

echo "\n✨ Test completed!\n";
