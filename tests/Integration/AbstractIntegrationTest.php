<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Base class for integration tests with real API access
 *
 * @group integration
 */
abstract class AbstractIntegrationTest extends TestCase
{
    protected HttpClientInterface $httpClient;
    protected bool $enableRealApiCalls;
    protected string $githubToken;
    protected string $terToken;
    protected int $apiRequestTimeout;
    protected int $rateLimitDelay;
    protected string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Load environment configuration - try both $_ENV and getenv() for PHPUnit compatibility
        $enableRealApiCallsValue = $_ENV['ENABLE_REAL_API_CALLS'] ?? getenv('ENABLE_REAL_API_CALLS') ?: 'false';
        $this->enableRealApiCalls = in_array(strtolower($enableRealApiCallsValue), ['true', '1', 'yes', 'on'], true);
        $this->githubToken = $_ENV['GITHUB_TOKEN'] ?? getenv('GITHUB_TOKEN') ?: '';
        $this->terToken = $_ENV['TER_TOKEN'] ?? getenv('TER_TOKEN') ?: '';
        $this->apiRequestTimeout = (int)(($_ENV['API_REQUEST_TIMEOUT'] ?? getenv('API_REQUEST_TIMEOUT')) ?: 10);
        $this->rateLimitDelay = (int)(($_ENV['API_RATE_LIMIT_DELAY'] ?? getenv('API_RATE_LIMIT_DELAY')) ?: 1);
        $this->cacheDir = $_ENV['INTEGRATION_TEST_CACHE_DIR'] ?? getenv('INTEGRATION_TEST_CACHE_DIR') ?: 'var/integration-test-cache';

        // Create HTTP client with appropriate timeout
        $this->httpClient = HttpClient::create([
            'timeout' => $this->apiRequestTimeout,
            'headers' => [
                'User-Agent' => 'TYPO3-Upgrade-Analyzer-Integration-Tests',
            ],
        ]);

        // Ensure cache directory exists
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->cacheDir);
    }

    protected function tearDown(): void
    {
        // Add rate limiting delay to prevent API abuse
        if ($this->enableRealApiCalls && $this->rateLimitDelay > 0) {
            sleep($this->rateLimitDelay);
        }

        parent::tearDown();
    }

    /**
     * Skip test if real API calls are disabled
     */
    protected function requiresRealApiCalls(): void
    {
        if (!$this->enableRealApiCalls) {
            $this->markTestSkipped('Real API calls are disabled (ENABLE_REAL_API_CALLS=false)');
        }
    }

    /**
     * Skip test if GitHub token is not available
     */
    protected function requiresGitHubToken(): void
    {
        if (empty($this->githubToken)) {
            $this->markTestSkipped('GitHub token not provided (GITHUB_TOKEN environment variable)');
        }
    }

    /**
     * Skip test if TER token is not available
     */
    protected function requiresTerToken(): void
    {
        if (empty($this->terToken)) {
            $this->markTestSkipped('TER token not provided (TER_TOKEN environment variable)');
        }
    }

    /**
     * Check if we're currently rate limited by GitHub
     */
    protected function isRateLimited(): bool
    {
        $rateLimitFile = $this->cacheDir . '/rate_limit_status.json';
        
        if (!file_exists($rateLimitFile)) {
            return false;
        }
        
        $data = json_decode(file_get_contents($rateLimitFile), true);
        $resetTime = $data['reset_time'] ?? 0;
        
        // If reset time has passed, we're no longer rate limited
        return time() < $resetTime;
    }

    /**
     * Mark that we've been rate limited
     */
    protected function markRateLimited(int $resetTime = null): void
    {
        $resetTime = $resetTime ?? (time() + 3600); // Default to 1 hour
        
        $data = [
            'rate_limited' => true,
            'reset_time' => $resetTime,
            'marked_at' => time()
        ];
        
        file_put_contents($this->cacheDir . '/rate_limit_status.json', json_encode($data));
    }

    /**
     * Get GitHub token for authenticated requests
     */
    protected function getGitHubToken(): string
    {
        return $this->githubToken;
    }

    /**
     * Get TER token for authenticated requests
     */
    protected function getTerToken(): string
    {
        return $this->terToken;
    }

    /**
     * Create HTTP client with GitHub authentication
     */
    protected function createAuthenticatedGitHubClient(): HttpClientInterface
    {
        if (empty($this->githubToken)) {
            return $this->httpClient;
        }

        return HttpClient::create([
            'timeout' => $this->apiRequestTimeout,
            'headers' => [
                'User-Agent' => 'TYPO3-Upgrade-Analyzer-Integration-Tests',
                'Authorization' => 'Bearer ' . $this->githubToken,
                'Accept' => 'application/vnd.github.v3+json',
            ],
        ]);
    }

    /**
     * Create a null logger for testing
     */
    protected function createLogger(): NullLogger
    {
        return new NullLogger();
    }

    /**
     * Get test data directory path
     */
    protected function getTestDataPath(): string
    {
        return __DIR__ . '/Fixtures';
    }

    /**
     * Load test data from JSON file
     */
    protected function loadTestData(string $fileName): array
    {
        $filePath = $this->getTestDataPath() . '/' . $fileName;
        
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Test data file not found: {$filePath}");
        }

        $content = file_get_contents($filePath);
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * Cache API response for repeated use in tests (supports arrays, scalars, and null)
     */
    protected function cacheApiResponse(string $cacheKey, callable $apiCall): mixed
    {
        $cacheFile = $this->cacheDir . '/' . md5($cacheKey) . '.json';

        if (file_exists($cacheFile)) {
            $cachedData = json_decode(file_get_contents($cacheFile), true, 512, JSON_THROW_ON_ERROR);
            return $cachedData;
        }

        $data = $apiCall();
        
        // Cache the result (including null values)
        file_put_contents($cacheFile, json_encode($data, JSON_PRETTY_PRINT));

        return $data;
    }

    /**
     * Assert that response time is within acceptable limits
     */
    protected function assertResponseTimeAcceptable(float $responseTime, float $maxTime = 5.0): void
    {
        $this->assertLessThan(
            $maxTime,
            $responseTime,
            "API response time ({$responseTime}s) exceeds maximum allowed time ({$maxTime}s)"
        );
    }

    /**
     * Assert that API rate limits are being respected
     */
    protected function assertRateLimitRespected(array $responseHeaders): void
    {
        if (isset($responseHeaders['x-ratelimit-remaining'][0])) {
            $remaining = (int)$responseHeaders['x-ratelimit-remaining'][0];
            $this->assertGreaterThan(
                0,
                $remaining,
                'API rate limit exceeded - remaining requests: ' . $remaining
            );
        }
    }

    /**
     * Create a test extension for integration testing
     */
    protected function createTestExtension(
        string $key,
        string $composerName = null,
        bool $isSystemExtension = false,
        bool $isLocalExtension = false
    ): \CPSIT\UpgradeAnalyzer\Domain\Entity\Extension {
        $type = 'local';
        if ($isSystemExtension) {
            $type = 'system';
        } elseif ($isLocalExtension) {
            $type = 'local';
        } elseif ($composerName) {
            $type = 'ter';
        }
        
        return new \CPSIT\UpgradeAnalyzer\Domain\Entity\Extension(
            key: $key,
            title: ucfirst(str_replace('_', ' ', $key)),
            version: new \CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version('1.0.0'),
            type: $type,
            composerName: $composerName
        );
    }

    /**
     * Create test analysis context
     */
    protected function createTestAnalysisContext(
        string $targetVersion = '12.4.0',
        string $currentVersion = '11.5.0'
    ): \CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext {
        return new \CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext(
            currentVersion: new \CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version($currentVersion),
            targetVersion: new \CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version($targetVersion)
        );
    }

    /**
     * Assert that git repository health metrics are reasonable
     */
    protected function assertGitRepositoryHealthValid(
        \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryHealth $health
    ): void {
        $this->assertInstanceOf(\DateTimeInterface::class, $health->getLastCommitDate());
        $this->assertGreaterThanOrEqual(0, $health->getStarCount());
        $this->assertGreaterThanOrEqual(0, $health->getForkCount());
        $this->assertGreaterThanOrEqual(0, $health->getOpenIssuesCount());
        $this->assertGreaterThanOrEqual(0, $health->getClosedIssuesCount());
        $this->assertGreaterThanOrEqual(0, $health->getContributorCount());
        $this->assertIsBool($health->isArchived());
        $this->assertIsBool($health->hasReadme());
        $this->assertIsBool($health->hasLicense());
    }

    /**
     * Assert that git repository metadata is valid
     */
    protected function assertGitRepositoryMetadataValid(
        \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryMetadata $metadata
    ): void {
        $this->assertNotEmpty($metadata->getName());
        $this->assertIsString($metadata->getDescription());
        $this->assertIsBool($metadata->isArchived());
        $this->assertIsBool($metadata->isFork());
        $this->assertGreaterThanOrEqual(0, $metadata->getStarCount());
        $this->assertGreaterThanOrEqual(0, $metadata->getForkCount());
        $this->assertInstanceOf(\DateTimeInterface::class, $metadata->getLastUpdated());
        $this->assertNotEmpty($metadata->getDefaultBranch());
    }

    /**
     * Assert that git tags collection is valid
     */
    protected function assertGitTagsValid(array $tags): void
    {
        $this->assertIsArray($tags);
        
        foreach ($tags as $tag) {
            $this->assertInstanceOf(\CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitTag::class, $tag);
            $this->assertNotEmpty($tag->getName());
            
            if ($tag->getDate()) {
                $this->assertInstanceOf(\DateTimeInterface::class, $tag->getDate());
            }
        }
    }

    /**
     * Validate that analysis result has required structure
     */
    protected function assertAnalysisResultValid(
        \CPSIT\UpgradeAnalyzer\Domain\Entity\AnalysisResult $result
    ): void {
        $this->assertNotEmpty($result->getAnalyzerName());
        $this->assertInstanceOf(\CPSIT\UpgradeAnalyzer\Domain\Entity\Extension::class, $result->getExtension());
        $this->assertIsFloat($result->getRiskScore());
        $this->assertGreaterThanOrEqual(0.0, $result->getRiskScore());
        $this->assertLessThanOrEqual(10.0, $result->getRiskScore());
        $this->assertIsArray($result->getMetrics());
        $this->assertIsArray($result->getRecommendations());
    }
}