<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Integration;

use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitProviderFactory;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryAnalyzer;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\PackagistClient;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient;

/**
 * Performance and reliability tests for integration scenarios.
 *
 * @group integration
 * @group performance
 * @group real-world
 */
class PerformanceReliabilityTestCase extends AbstractIntegrationTestCase
{
    private VersionAvailabilityAnalyzer $analyzer;
    private array $testExtensions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresRealApiCalls();
        $this->requiresTerToken();

        // Load test extension data
        $this->testExtensions = $this->loadTestData('known_extensions.json');

        // Create analyzer
        $this->analyzer = $this->createAnalyzer();
    }

    /**
     * @group performance
     *
     * @coversNothing
     */
    public function testApiResponseTimes(): void
    {
        $expectedTimes = $this->testExtensions['test_configurations']['expected_response_times'];

        // Test GitHub API response time
        if ($this->getGitHubToken()) {
            $startTime = microtime(true);

            $response = $this->createAuthenticatedGitHubClient()->request(
                'GET',
                'https://api.github.com/repos/georgringer/news',
            );

            $githubTime = microtime(true) - $startTime;

            $this->assertEquals(200, $response->getStatusCode());
            $this->assertLessThan(
                $expectedTimes['github_api_max'],
                $githubTime,
                "GitHub API response time too slow: {$githubTime}s",
            );
        }

        // Test TER API response time
        $startTime = microtime(true);

        $headers = [];
        if ($this->getTerToken()) {
            $headers['Authorization'] = 'Bearer ' . $this->getTerToken();
        }

        $response = $this->httpClient->request(
            'GET',
            'https://extensions.typo3.org/api/v1/extension/news',
            ['headers' => $headers],
        );

        $terTime = microtime(true) - $startTime;

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertLessThan(
            $expectedTimes['ter_api_max'],
            $terTime,
            "TER API response time too slow: {$terTime}s",
        );

        // Test Packagist API response time
        $startTime = microtime(true);

        $response = $this->httpClient->request(
            'GET',
            'https://packagist.org/packages/georgringer/news.json',
        );

        $packagistTime = microtime(true) - $startTime;

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertLessThan(
            $expectedTimes['packagist_api_max'],
            $packagistTime,
            "Packagist API response time too slow: {$packagistTime}s",
        );
    }

    /**
     * @group performance
     *
     * @coversNothing
     */
    public function testBulkExtensionAnalysisPerformance(): void
    {
        $extensions = [
            $this->createTestExtension('news', 'georgringer/news'),
            $this->createTestExtension('extension_builder', 'friendsoftypo3/extension-builder'),
            $this->createTestExtension('bootstrap_package', 'bk2k/bootstrap-package'),
        ];

        $context = $this->createTestAnalysisContext('12.4.0');

        $startTime = microtime(true);
        $results = [];

        foreach ($extensions as $extension) {
            $results[] = $this->analyzer->analyze($extension, $context);
        }

        $totalTime = microtime(true) - $startTime;
        $averageTime = $totalTime / \count($extensions);

        // Performance benchmarks
        $this->assertLessThan(60.0, $totalTime, "Bulk analysis took too long: {$totalTime}s");
        $this->assertLessThan(20.0, $averageTime, "Average analysis time too high: {$averageTime}s");

        // Memory usage check
        $memoryUsage = memory_get_peak_usage(true) / 1024 / 1024; // MB
        $this->assertLessThan(128, $memoryUsage, "Memory usage too high: {$memoryUsage}MB");

        // All analyses should succeed
        $this->assertCount(\count($extensions), $results);
        foreach ($results as $result) {
            $this->assertAnalysisResultValid($result);
        }
    }

    /**
     * @coversNothing
     */
    public function testApiRateLimitHandling(): void
    {
        if (!$this->getGitHubToken()) {
            $this->markTestSkipped('GitHub token required for rate limit testing');
        }

        $client = $this->createAuthenticatedGitHubClient();
        $requestCount = 0;
        $maxRequests = 10;
        $failures = 0;

        while ($requestCount < $maxRequests) {
            try {
                $response = $client->request('GET', 'https://api.github.com/repos/georgringer/news');

                $headers = $response->getHeaders();
                if (isset($headers['x-ratelimit-remaining'][0])) {
                    $remaining = (int) $headers['x-ratelimit-remaining'][0];

                    if ($remaining < 10) {
                        $this->markTestSkipped('Rate limit too low to continue testing');
                    }
                }

                ++$requestCount;
            } catch (\Exception $e) {
                ++$failures;

                if (str_contains($e->getMessage(), 'rate limit')) {
                    break; // Expected rate limiting
                }

                if ($failures > 2) {
                    $this->fail('Too many unexpected failures: ' . $e->getMessage());
                }
            }

            // Add delay to respect rate limits
            usleep(500000); // 0.5 seconds
        }

        $this->assertGreaterThan(5, $requestCount, 'Should complete at least 5 requests successfully');
        $this->assertLessThan(3, $failures, 'Should have minimal failures due to network issues');
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient::hasVersionFor
     */
    public function testNetworkTimeoutHandling(): void
    {
        // Create client with very short timeout to test timeout handling
        $shortTimeoutClient = \Symfony\Component\HttpClient\HttpClient::create([
            'timeout' => 0.1, // Very short timeout
        ]);

        $httpClientService = new \CPSIT\UpgradeAnalyzer\Infrastructure\Http\HttpClientService($shortTimeoutClient, $this->createLogger());
        $terClient = new TerApiClient($httpClientService, $this->createLogger());

        $extension = $this->createTestExtension('news');
        $context = $this->createTestAnalysisContext('12.4.0');

        // This should handle timeout gracefully
        try {
            $result = $terClient->hasVersionFor($extension->getKey(), $context->getTargetVersion());
            // If it succeeds with short timeout, that's also fine
            $this->assertTrue($result);
        } catch (\Exception $e) {
            // Should catch timeout and wrap in appropriate exception
            $this->assertStringContainsString('Failed to check TER', $e->getMessage());
        }
    }

    /***
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testConcurrentAnalysisStability(): void
    {
        $extensions = [
            $this->createTestExtension('news', 'georgringer/news'),
            $this->createTestExtension('extension_builder', 'friendsoftypo3/extension-builder'),
        ];

        $context = $this->createTestAnalysisContext('12.4.0');
        $iterations = 3;
        $results = [];

        for ($i = 0; $i < $iterations; ++$i) {
            foreach ($extensions as $extension) {
                $startTime = microtime(true);

                $result = $this->analyzer->analyze($extension, $context);

                $analysisTime = microtime(true) - $startTime;

                $results[] = [
                    'extension' => $extension->getKey(),
                    'iteration' => $i,
                    'result' => $result,
                    'time' => $analysisTime,
                ];

                $this->assertAnalysisResultValid($result);
            }
        }

        // Check consistency across iterations
        $extensionResults = [];
        foreach ($results as $data) {
            $key = $data['extension'];
            if (!isset($extensionResults[$key])) {
                $extensionResults[$key] = [];
            }
            $extensionResults[$key][] = $data;
        }

        foreach ($extensionResults as $extension => $iterationResults) {
            $riskScores = array_map(fn ($r): float => $r['result']->getRiskScore(), $iterationResults);
            $avgRisk = array_sum($riskScores) / \count($riskScores);

            // Risk scores should be consistent across iterations
            foreach ($riskScores as $risk) {
                $this->assertLessThan(
                    2.0,
                    abs($risk - $avgRisk),
                    "Risk score inconsistency for {$extension}: got {$risk}, average {$avgRisk}",
                );
            }
        }
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testMemoryLeakDetection(): void
    {
        $initialMemory = memory_get_usage(true);
        $maxMemoryIncrease = 10 * 1024 * 1024; // 10MB

        $extension = $this->createTestExtension('news', 'georgringer/news');
        $context = $this->createTestAnalysisContext('12.4.0');

        // Run multiple analyses to detect memory leaks
        for ($i = 0; $i < 5; ++$i) {
            $result = $this->analyzer->analyze($extension, $context);
            $this->assertAnalysisResultValid($result);

            // Force garbage collection
            gc_collect_cycles();
        }

        $finalMemory = memory_get_usage(true);
        $memoryIncrease = $finalMemory - $initialMemory;

        $this->assertLessThan(
            $maxMemoryIncrease,
            $memoryIncrease,
            'Memory leak detected: increased by ' . ($memoryIncrease / 1024 / 1024) . 'MB',
        );
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testApiErrorRecovery(): void
    {
        // Test recovery from API errors
        $extension = $this->createTestExtension('news', 'georgringer/news');
        $context = $this->createTestAnalysisContext('12.4.0');

        // First analysis should succeed
        $result1 = $this->analyzer->analyze($extension, $context);
        $this->assertAnalysisResultValid($result1);

        // Subsequent analysis should also succeed (testing error recovery)
        $result2 = $this->analyzer->analyze($extension, $context);
        $this->assertAnalysisResultValid($result2);

        // Results should be consistent
        $this->assertEquals(
            $result1->getRiskScore(),
            $result2->getRiskScore(),
            'Risk scores should be consistent across repeated analyses',
        );
    }

    /**
     * @group performance
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testLargeDatasetHandling(): void
    {
        // Simulate analyzing a large number of extensions
        $extensionCount = 10;
        $extensions = [];

        // Create test extensions
        for ($i = 0; $i < $extensionCount; ++$i) {
            $extensions[] = $this->createTestExtension("test_ext_{$i}", "vendor/test-ext-{$i}");
        }

        $context = $this->createTestAnalysisContext('12.4.0');
        $startTime = microtime(true);
        $successCount = 0;

        foreach ($extensions as $extension) {
            try {
                $result = $this->analyzer->analyze($extension, $context);
                $this->assertAnalysisResultValid($result);
                ++$successCount;
            } catch (\Exception $e) {
                // Some extensions might not exist, which is expected
                if (!str_contains($e->getMessage(), 'not found')
                    && !str_contains($e->getMessage(), 'Failed to check')) {
                    throw $e;
                }
            }
        }

        $totalTime = microtime(true) - $startTime;
        $averageTime = $totalTime / $extensionCount;

        // Performance should scale reasonably
        $this->assertLessThan(120.0, $totalTime, "Large dataset analysis took too long: {$totalTime}s");
        $this->assertLessThan(12.0, $averageTime, "Average analysis time too high: {$averageTime}s");

        // Should handle at least some extensions without crashing
        $this->assertGreaterThan(0, $successCount, 'Should complete at least some analyses successfully');
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\PackagistClient
     */
    public function testDiverseNetworkConditions(): void
    {
        // Test analysis under various simulated network conditions
        $clients = [
            'fast' => \Symfony\Component\HttpClient\HttpClient::create(['timeout' => 30]),
            'slow' => \Symfony\Component\HttpClient\HttpClient::create(['timeout' => 5]),
        ];

        $extension = $this->createTestExtension('news', 'georgringer/news');
        $context = $this->createTestAnalysisContext('12.4.0');

        $results = [];
        foreach ($clients as $condition => $client) {
            $httpClientService = new \CPSIT\UpgradeAnalyzer\Infrastructure\Http\HttpClientService($client, $this->createLogger());
            $terClient = new TerApiClient($httpClientService, $this->createLogger());

            $gitHubClient = new GitHubClient(
                $httpClientService,
                $this->createLogger(),
                new \CPSIT\UpgradeAnalyzer\Infrastructure\Repository\RepositoryUrlHandler(),
                $this->getGitHubToken(),
            );

            $providerFactory = new GitProviderFactory([$gitHubClient], $this->createLogger());
            $gitAnalyzer = new GitRepositoryAnalyzer(
                $providerFactory,
                new GitVersionParser(new \CPSIT\UpgradeAnalyzer\Infrastructure\Version\ComposerConstraintChecker()),
                $this->createLogger(),
            );

            $analyzer = new VersionAvailabilityAnalyzer(
                $this->createCacheService(),
                $this->createLogger(),
                $terClient,
                new PackagistClient(
                    $httpClientService,
                    $this->createLogger(),
                    new \CPSIT\UpgradeAnalyzer\Infrastructure\Version\ComposerConstraintChecker(),
                    new \CPSIT\UpgradeAnalyzer\Infrastructure\Repository\RepositoryUrlHandler(),
                ),
                $gitAnalyzer,
            );

            $startTime = microtime(true);

            try {
                $result = $analyzer->analyze($extension, $context);
                $analysisTime = microtime(true) - $startTime;

                $results[$condition] = [
                    'success' => true,
                    'result' => $result,
                    'time' => $analysisTime,
                ];

                $this->assertAnalysisResultValid($result);
            } catch (\Exception $e) {
                $results[$condition] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'time' => microtime(true) - $startTime,
                ];
            }
        }

        // At least one condition should succeed
        $successCount = \count(array_filter($results, fn ($r): bool => $r['success']));
        $this->assertGreaterThan(0, $successCount, 'Should succeed under at least one network condition');
    }

    private function createAnalyzer(): VersionAvailabilityAnalyzer
    {
        $terClient = new TerApiClient($this->createHttpClientService(), $this->createLogger());
        $packagistClient = new PackagistClient(
            $this->createHttpClientService(),
            $this->createLogger(),
            new \CPSIT\UpgradeAnalyzer\Infrastructure\Version\ComposerConstraintChecker(),
            new \CPSIT\UpgradeAnalyzer\Infrastructure\Repository\RepositoryUrlHandler(),
        );

        $gitHubClient = new GitHubClient(
            $this->createHttpClientService(),
            $this->createLogger(),
            new \CPSIT\UpgradeAnalyzer\Infrastructure\Repository\RepositoryUrlHandler(),
            $this->getGitHubToken(),
        );

        $providerFactory = new GitProviderFactory([$gitHubClient], $this->createLogger());
        $gitAnalyzer = new GitRepositoryAnalyzer(
            $providerFactory,
            new GitVersionParser(new \CPSIT\UpgradeAnalyzer\Infrastructure\Version\ComposerConstraintChecker()),
            $this->createLogger(),
        );

        return new VersionAvailabilityAnalyzer(
            $this->createCacheService(),
            $this->createLogger(),
            $terClient,
            $packagistClient,
            $gitAnalyzer,
        );
    }
}
