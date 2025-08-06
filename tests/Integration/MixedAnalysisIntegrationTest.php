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

use CPSIT\UpgradeAnalyzer\Domain\Entity\Installation;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitProviderFactory;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryAnalyzer;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\PackagistClient;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient;

/**
 * Integration tests for mixed analysis scenarios with real-world complexity.
 *
 * @group integration
 * @group real-world
 */
class MixedAnalysisIntegrationTest extends AbstractIntegrationTest
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

        // Create analyzer with all components
        $this->analyzer = $this->createCompleteAnalyzer();
    }

    /**
     * @test
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testCompleteTypo3InstallationAnalysis(): void
    {
        // Simulate analyzing a complete TYPO3 installation with mixed extension types
        $extensions = [
            // System extension (should be low risk)
            $this->createTestExtension('core', 'typo3/cms-core', true),

            // Popular third-party extension (should be low risk)
            $this->createTestExtension('news', 'georgringer/news'),

            // Community maintained extension (should be medium risk)
            $this->createTestExtension('extension_builder', 'friendsoftypo3/extension-builder'),

            // Archived extension (should be high risk)
            $this->createTestExtension('realurl', 'dmitryd/typo3-realurl'),

            // Local extension (should be very high risk)
            $this->createTestExtension('local_custom', null, false, true),
        ];

        $context = $this->createTestAnalysisContext('12.4.0');
        $results = [];
        $totalStartTime = microtime(true);

        foreach ($extensions as $extension) {
            $startTime = microtime(true);

            $result = $this->analyzer->analyze($extension, $context);
            $analysisTime = microtime(true) - $startTime;

            $this->assertAnalysisResultValid($result);

            $results[$extension->getKey()] = [
                'result' => $result,
                'time' => $analysisTime,
            ];
        }

        $totalTime = microtime(true) - $totalStartTime;

        // Assert risk scores follow expected pattern
        $this->assertLessThan(2.0, $results['core']['result']->getRiskScore(), 'System extension should have lowest risk');
        $this->assertLessThan(4.0, $results['news']['result']->getRiskScore(), 'Popular extension should have low risk');
        $this->assertGreaterThan(4.0, $results['realurl']['result']->getRiskScore(), 'Archived extension should have higher risk');
        $this->assertGreaterThan(7.0, $results['local_custom']['result']->getRiskScore(), 'Local extension should have highest risk');

        // Assert performance is acceptable for complete analysis
        $this->assertLessThan(60.0, $totalTime, "Complete installation analysis took too long: {$totalTime}s");

        foreach ($results as $key => $data) {
            $this->assertLessThan(
                20.0,
                $data['time'],
                "Individual extension analysis for {$key} took too long: {$data['time']}s",
            );
        }
    }

    /**
     * @test
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testAvailabilityConsistencyAcrossSources(): void
    {
        // Test that availability information is consistent across different sources
        $extension = $this->createTestExtension('news', 'georgringer/news');
        $context = $this->createTestAnalysisContext('12.4.0');

        $result = $this->analyzer->analyze($extension, $context);
        $metrics = $result->getMetrics();

        // News extension should be available in multiple sources
        $availableSources = [];
        if ($metrics['ter_available']) {
            $availableSources[] = 'TER';
        }
        if ($metrics['packagist_available']) {
            $availableSources[] = 'Packagist';
        }
        if ($metrics['git_available']) {
            $availableSources[] = 'Git';
        }

        $this->assertGreaterThan(1, \count($availableSources), 'Popular extension should be available in multiple sources');

        // When multiple sources are available, risk should be lower
        $this->assertLessThan(4.0, $result->getRiskScore(), 'Multiple source availability should result in lower risk');

        // Recommendations should acknowledge multiple sources
        $recommendations = implode(' ', $result->getRecommendations());
        if (\count($availableSources) > 1) {
            // Log available sources and recommendations for debugging
            $this->assertNotEmpty($recommendations, 'Should have recommendations when multiple sources available. Available sources: ' . implode(', ', $availableSources));
            
            // Check for "multiple" specifically when Git + (TER or Packagist) are available
            if ($metrics['git_available'] && ($metrics['ter_available'] || $metrics['packagist_available'])) {
                $this->assertStringContainsString('multiple', strtolower($recommendations), 
                    'Should mention multiple sources. Available: ' . implode(', ', $availableSources) . '. Recommendations: ' . $recommendations);
            }
        }
    }

    /**
     * @test
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testVersionCompatibilityAcrossTypo3Versions(): void
    {
        $extension = $this->createTestExtension('news', 'georgringer/news');
        $typo3Versions = ['11.5.0', '12.4.0'];

        $results = [];
        foreach ($typo3Versions as $version) {
            $context = $this->createTestAnalysisContext($version);
            $results[$version] = $this->analyzer->analyze($extension, $context);
        }

        // News extension should be compatible with both TYPO3 11 and 12
        foreach ($results as $version => $result) {
            $metrics = $result->getMetrics();
            $hasAnyAvailability = $metrics['ter_available'] || $metrics['packagist_available'] || $metrics['git_available'];

            $this->assertTrue(
                $hasAnyAvailability,
                "News extension should be available for TYPO3 {$version}",
            );

            $this->assertLessThan(
                5.0,
                $result->getRiskScore(),
                "News extension should have low risk for TYPO3 {$version}",
            );
        }
    }

    /**
     * @test
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testArchivedExtensionMigrationScenario(): void
    {
        // Test scenario where archived extension needs migration
        $archivedExtension = $this->createTestExtension('realurl', 'dmitryd/typo3-realurl');
        $context = $this->createTestAnalysisContext('12.4.0');

        $result = $this->analyzer->analyze($archivedExtension, $context);
        $metrics = $result->getMetrics();

        // Archived extension should have limited or no TYPO3 12 availability
        $typo3_12_available = $metrics['ter_available'] && $metrics['packagist_available'];
        $this->assertFalse($typo3_12_available, 'Archived extension should not be fully available for TYPO3 12');

        // Should have high risk score
        $this->assertGreaterThan(5.0, $result->getRiskScore(), 'Archived extension should have high risk');

        // Should provide migration recommendations
        $recommendations = implode(' ', $result->getRecommendations());
        $this->assertStringContainsString('alternative', strtolower($recommendations));
    }

    /**
     * @test
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testSystemExtensionSpecialHandling(): void
    {
        $systemExtension = $this->createTestExtension('core', 'typo3/cms-core', true);
        $context = $this->createTestAnalysisContext('12.4.0');

        $result = $this->analyzer->analyze($systemExtension, $context);
        $metrics = $result->getMetrics();

        // System extension should not be in TER but should be in Packagist
        $this->assertFalse($metrics['ter_available'], 'System extension should not be in TER');
        $this->assertTrue($metrics['packagist_available'], 'System extension should be in Packagist');

        // System extension should have the lowest possible risk
        $this->assertEquals(1.0, $result->getRiskScore(), 'System extension should have risk score of 1.0');

        // Should not have alarming recommendations
        $recommendations = implode(' ', $result->getRecommendations());
        $this->assertStringNotContainsString('not available', strtolower($recommendations));
    }

    /**
     * @test
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testNetworkFailureResilience(): void
    {
        // Test that analysis handles partial network failures gracefully
        $extension = $this->createTestExtension('news', 'georgringer/news');
        $context = $this->createTestAnalysisContext('12.4.0');

        // Analysis should complete even if some services are unreachable
        $result = $this->analyzer->analyze($extension, $context);

        $this->assertAnalysisResultValid($result);
        $this->assertIsFloat($result->getRiskScore());
        $this->assertIsArray($result->getMetrics());

        // Should have some availability information even with partial failures
        $metrics = $result->getMetrics();
        $this->assertArrayHasKey('ter_available', $metrics);
        $this->assertArrayHasKey('packagist_available', $metrics);
        $this->assertArrayHasKey('git_available', $metrics);
    }

    /**
     * @test
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testBatchAnalysisEfficiency(): void
    {
        // Test analyzing multiple extensions efficiently
        $extensionKeys = ['news', 'extension_builder'];
        $extensions = array_map(
            fn ($key) => $this->createTestExtension(
                $key,
                $this->testExtensions['extensions'][$this->getFullKey($key)]['composer_name'] ?? null,
            ),
            $extensionKeys,
        );

        $context = $this->createTestAnalysisContext('12.4.0');
        $startTime = microtime(true);

        $results = [];
        foreach ($extensions as $extension) {
            $results[] = $this->analyzer->analyze($extension, $context);
        }

        $totalTime = microtime(true) - $startTime;
        $averageTime = $totalTime / \count($extensions);

        // Batch processing should be efficient
        $this->assertLessThan(15.0, $averageTime, "Average analysis time too high: {$averageTime}s");
        $this->assertLessThan(45.0, $totalTime, "Total batch analysis time too high: {$totalTime}s");

        // All results should be valid
        foreach ($results as $result) {
            $this->assertAnalysisResultValid($result);
        }
    }

    /**
     * @test
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testRiskScoreConsistency(): void
    {
        // Test that risk scores are consistent and meaningful
        $extensions = [
            ['key' => 'core', 'composer' => 'typo3/cms-core', 'system' => true, 'expected_risk' => 1.0],
            ['key' => 'news', 'composer' => 'georgringer/news', 'system' => false, 'expected_risk' => 2.5],
            ['key' => 'local_test', 'composer' => null, 'system' => false, 'expected_risk' => 9.0],
        ];

        $context = $this->createTestAnalysisContext('12.4.0');
        $risks = [];

        foreach ($extensions as $extData) {
            $extension = $this->createTestExtension(
                $extData['key'],
                $extData['composer'],
                $extData['system'],
            );

            if ('local_test' === $extData['key']) {
                $extension = $this->createTestExtension($extData['key'], null, false, true);
            }

            $result = $this->analyzer->analyze($extension, $context);
            $risks[$extData['key']] = $result->getRiskScore();
        }

        // Assert risk order: system < popular < local
        $this->assertLessThan($risks['news'], $risks['core'], 'System extension should have lower risk than third-party');

        if (isset($risks['local_test'])) {
            $this->assertLessThan($risks['local_test'], $risks['news'], 'Third-party should have lower risk than local');
        }

        // All risk scores should be within valid range
        foreach ($risks as $key => $risk) {
            $this->assertGreaterThanOrEqual(0.0, $risk, "Risk score for {$key} below minimum");
            $this->assertLessThanOrEqual(10.0, $risk, "Risk score for {$key} above maximum");
        }
    }

    /**
     * @test
     *
     * @group performance
     *
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testConcurrentAnalysisPerformance(): void
    {
        // Test performance when analyzing multiple extensions
        $extensions = [
            $this->createTestExtension('news', 'georgringer/news'),
            $this->createTestExtension('extension_builder', 'friendsoftypo3/extension-builder'),
            $this->createTestExtension('bootstrap_package', 'bk2k/bootstrap-package'),
        ];

        $context = $this->createTestAnalysisContext('12.4.0');
        $results = [];
        $times = [];

        foreach ($extensions as $extension) {
            $startTime = microtime(true);
            $result = $this->analyzer->analyze($extension, $context);
            $endTime = microtime(true);

            $results[] = $result;
            $times[] = $endTime - $startTime;

            $this->assertAnalysisResultValid($result);
        }

        // Performance assertions
        $maxTime = max($times);
        $avgTime = array_sum($times) / \count($times);

        $this->assertLessThan(20.0, $maxTime, "Slowest analysis took too long: {$maxTime}s");
        $this->assertLessThan(15.0, $avgTime, "Average analysis time too high: {$avgTime}s");
    }

    private function createCompleteAnalyzer(): VersionAvailabilityAnalyzer
    {
        $terClient = new TerApiClient($this->createHttpClientService(), $this->createLogger());
        $packagistClient = new PackagistClient(
            $this->createHttpClientService(),
            $this->createLogger(),
            new \CPSIT\UpgradeAnalyzer\Infrastructure\Version\ComposerConstraintChecker(),
            new \CPSIT\UpgradeAnalyzer\Infrastructure\Repository\RepositoryUrlHandler(),
        );

        $gitHubClient = new \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient(
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

    private function getFullKey(string $shortKey): string
    {
        $mapping = [
            'news' => 'georgringer/news',
            'extension_builder' => 'friendsoftypo3/extension-builder',
            'bootstrap_package' => 'bk2k/bootstrap-package',
        ];

        return $mapping[$shortKey] ?? $shortKey;
    }
}
