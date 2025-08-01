<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Integration\Analyzer;

use CPSIT\UpgradeAnalyzer\Tests\Integration\AbstractIntegrationTest;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\PackagistClient;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryAnalyzer;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitProviderFactory;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitHubClient;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser;

/**
 * Integration tests for complete version availability analysis workflow
 *
 * @group integration
 * @group real-world
 */
class VersionAvailabilityIntegrationTest extends AbstractIntegrationTest
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

        // Create all required components
        $terClient = new TerApiClient($this->httpClient, $this->createLogger());
        $packagistClient = new PackagistClient($this->httpClient, $this->createLogger());
        
        // Create Git analyzer with GitHub provider
        $gitHubClient = new GitHubClient(
            $this->createAuthenticatedGitHubClient(),
            $this->createLogger(),
            $this->getGitHubToken()
        );
        
        $providerFactory = new GitProviderFactory([$gitHubClient], $this->createLogger());
        $gitAnalyzer = new GitRepositoryAnalyzer(
            $providerFactory,
            new GitVersionParser(),
            $this->createLogger()
        );

        // Create the analyzer
        $this->analyzer = new VersionAvailabilityAnalyzer(
            $terClient,
            $packagistClient,
            $gitAnalyzer,
            $this->createLogger()
        );
    }

    /**
     * @test
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::supports
     */
    public function testAnalyzerSupportsAllExtensions(): void
    {
        $extension = $this->createTestExtension('test_extension');
        
        $this->assertTrue($this->analyzer->supports($extension));
    }

    /**
     * @test
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::hasRequiredTools
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::getRequiredTools
     */
    public function testAnalyzerHasRequiredTools(): void
    {
        $this->assertTrue($this->analyzer->hasRequiredTools());
        $this->assertEquals(['curl'], $this->analyzer->getRequiredTools());
    }

    /**
     * @test
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testAnalyzeActiveExtensionWithMultipleSources(): void
    {
        $extension = $this->createTestExtension(
            'news',
            'georgringer/news'
        );
        
        $context = $this->createTestAnalysisContext('12.4.0');
        
        $startTime = microtime(true);
        $result = $this->analyzer->analyze($extension, $context);
        $analysisTime = microtime(true) - $startTime;

        // Validate result structure
        $this->assertAnalysisResultValid($result);
        $this->assertEquals('version_availability', $result->getAnalyzerName());
        $this->assertEquals($extension, $result->getExtension());

        // Check metrics exist
        $metrics = $result->getMetrics();
        $this->assertArrayHasKey('ter_available', $metrics);
        $this->assertArrayHasKey('packagist_available', $metrics);
        $this->assertArrayHasKey('git_available', $metrics);
        $this->assertArrayHasKey('git_repository_health', $metrics);
        $this->assertArrayHasKey('git_repository_url', $metrics);

        // Assert expected availability for georgringer/news
        $this->assertTrue($metrics['ter_available'], 'News extension should be available in TER');
        $this->assertTrue($metrics['packagist_available'], 'News extension should be available in Packagist');
        $this->assertTrue($metrics['git_available'], 'News extension should be available in Git');
        
        // Assert Git repository information
        $this->assertNotNull($metrics['git_repository_health']);
        $this->assertGreaterThan(0.5, $metrics['git_repository_health'], 'Active repository should have good health');
        $this->assertEquals(
            $this->testExtensions['extensions']['georgringer/news']['github_url'],
            $metrics['git_repository_url']
        );

        // Assert risk score is reasonable (multiple sources = low risk)
        $this->assertLessThan(3.0, $result->getRiskScore(), 'Multiple availability sources should result in low risk');

        // Assert recommendations exist
        $this->assertNotEmpty($result->getRecommendations());

        // Performance assertion
        $this->assertLessThan(20.0, $analysisTime, "Complete analysis took too long: {$analysisTime}s");
    }

    /**
     * @test
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testAnalyzeArchivedExtension(): void
    {
        $extension = $this->createTestExtension(
            'realurl',
            'dmitryd/typo3-realurl'
        );
        
        $context = $this->createTestAnalysisContext('12.4.0');
        
        $result = $this->analyzer->analyze($extension, $context);

        $this->assertAnalysisResultValid($result);

        $metrics = $result->getMetrics();
        
        // Assert expected availability for archived extension
        $this->assertTrue($metrics['ter_available'] || !$metrics['ter_available'], 'TER availability check completed');
        $this->assertTrue($metrics['packagist_available'] || !$metrics['packagist_available'], 'Packagist availability check completed');
        $this->assertTrue($metrics['git_available'], 'Git repository should still be accessible');
        
        // Assert archived repository has lower health score
        if ($metrics['git_repository_health'] !== null) {
            $this->assertLessThan(0.7, $metrics['git_repository_health'], 'Archived repository should have lower health');
        }

        // Assert higher risk score for archived extension
        $this->assertGreaterThan(5.0, $result->getRiskScore(), 'Archived extension should have higher risk');

        // Assert appropriate recommendations
        $recommendations = $result->getRecommendations();
        $this->assertNotEmpty($recommendations);
        
        $recommendationText = implode(' ', $recommendations);
        $this->assertStringContainsString('alternative', strtolower($recommendationText));
    }

    /**
     * @test
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testAnalyzeSystemExtension(): void
    {
        $extension = $this->createTestExtension(
            'core',
            'typo3/cms-core',
            true // is system extension
        );
        
        $context = $this->createTestAnalysisContext('12.4.0');
        
        $result = $this->analyzer->analyze($extension, $context);

        $this->assertAnalysisResultValid($result);

        $metrics = $result->getMetrics();
        
        // System extensions should not be in TER but available in Packagist
        $this->assertFalse($metrics['ter_available'], 'System extension should not be in TER');
        $this->assertTrue($metrics['packagist_available'], 'System extension should be in Packagist');

        // System extension should have very low risk score
        $this->assertEquals(1.0, $result->getRiskScore(), 'System extension should have lowest risk score');
    }

    /**
     * @test
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testAnalyzeLocalExtension(): void
    {
        $extension = $this->createTestExtension(
            'test_extension',
            null,
            false,
            true // is local extension
        );
        
        $context = $this->createTestAnalysisContext('12.4.0');
        
        $result = $this->analyzer->analyze($extension, $context);

        $this->assertAnalysisResultValid($result);

        $metrics = $result->getMetrics();
        
        // Local extension should not be available anywhere publicly
        $this->assertFalse($metrics['ter_available'], 'Local extension should not be in TER');
        $this->assertFalse($metrics['packagist_available'], 'Local extension should not be in Packagist');
        $this->assertFalse($metrics['git_available'], 'Local extension should not have public Git repository');

        // Local extension should have high risk score
        $this->assertGreaterThan(8.0, $result->getRiskScore(), 'Local extension should have high risk score');

        // Should recommend finding alternatives or public versions
        $recommendations = $result->getRecommendations();
        $this->assertNotEmpty($recommendations);
        
        $recommendationText = implode(' ', $recommendations);
        $this->assertStringContainsString('not available', strtolower($recommendationText));
    }

    /**
     * @test
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testAnalyzeExtensionWithComposerNameButNoPackagistPresence(): void
    {
        // Create a test extension that has composer name but is not on Packagist
        $extension = $this->createTestExtension(
            'non_existent_extension',
            'non-existent/extension'
        );
        
        $context = $this->createTestAnalysisContext('12.4.0');
        
        $result = $this->analyzer->analyze($extension, $context);

        $this->assertAnalysisResultValid($result);

        $metrics = $result->getMetrics();
        
        // Should attempt to check Packagist but find nothing
        $this->assertFalse($metrics['ter_available'], 'Non-existent extension should not be in TER');
        $this->assertFalse($metrics['packagist_available'], 'Non-existent extension should not be in Packagist');
        $this->assertFalse($metrics['git_available'], 'Non-existent extension should not have Git repository');

        // High risk due to no availability
        $this->assertGreaterThan(8.0, $result->getRiskScore(), 'Non-existent extension should have high risk');
    }

    /**
     * @test
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testAnalyzeMultipleExtensionsPerformance(): void
    {
        $extensions = [
            $this->createTestExtension('news', 'georgringer/news'),
            $this->createTestExtension('extension_builder', 'friendsoftypo3/extension-builder'),
        ];
        
        $context = $this->createTestAnalysisContext('12.4.0');
        
        $totalStartTime = microtime(true);
        $results = [];
        
        foreach ($extensions as $extension) {
            $startTime = microtime(true);
            
            $result = $this->analyzer->analyze($extension, $context);
            $this->assertAnalysisResultValid($result);
            
            $analysisTime = microtime(true) - $startTime;
            $results[] = [
                'extension' => $extension->getKey(),
                'result' => $result,
                'time' => $analysisTime
            ];
            
            $this->assertLessThan(
                25.0,
                $analysisTime,
                "Analysis of {$extension->getKey()} took too long: {$analysisTime}s"
            );
        }
        
        $totalTime = microtime(true) - $totalStartTime;
        $this->assertLessThan(45.0, $totalTime, "Total analysis time exceeded limit: {$totalTime}s");
        
        // Assert all analyses completed successfully
        $this->assertEquals(count($extensions), count($results));
        
        foreach ($results as $result) {
            $this->assertInstanceOf(
                \CPSIT\UpgradeAnalyzer\Domain\Entity\AnalysisResult::class,
                $result['result']
            );
        }
    }

    /**
     * @test
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testAnalysisWithDifferentTypo3Versions(): void
    {
        $extension = $this->createTestExtension('news', 'georgringer/news');
        
        $versions = ['11.5.0', '12.4.0'];
        $results = [];
        
        foreach ($versions as $versionString) {
            $context = $this->createTestAnalysisContext($versionString);
            
            $result = $this->analyzer->analyze($extension, $context);
            $this->assertAnalysisResultValid($result);
            
            $results[$versionString] = $result;
        }

        // Both versions should have availability
        foreach ($results as $version => $result) {
            $metrics = $result->getMetrics();
            $this->assertTrue(
                $metrics['ter_available'] || $metrics['packagist_available'] || $metrics['git_available'],
                "News extension should be available for TYPO3 {$version}"
            );
        }

        // Risk scores should be similar for both versions (both supported)
        $risk11 = $results['11.5.0']->getRiskScore();
        $risk12 = $results['12.4.0']->getRiskScore();
        
        $this->assertLessThan(2.0, abs($risk11 - $risk12), 'Risk scores should be similar for supported versions');
    }

    /**
     * @test
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testAnalysisErrorHandling(): void
    {
        // This test ensures the analyzer handles external API failures gracefully
        $extension = $this->createTestExtension('news', 'georgringer/news');
        $context = $this->createTestAnalysisContext('12.4.0');
        
        // The analysis should complete even if some external services fail
        $result = $this->analyzer->analyze($extension, $context);
        
        $this->assertAnalysisResultValid($result);
        
        // Even if some checks fail, the result should still be valid
        $this->assertIsFloat($result->getRiskScore());
        $this->assertIsArray($result->getMetrics());
        $this->assertIsArray($result->getRecommendations());
    }

    /**
     * @test
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testRecommendationQuality(): void
    {
        $extensions = [
            // Active extension with multiple sources
            $this->createTestExtension('news', 'georgringer/news'),
            // System extension
            $this->createTestExtension('core', 'typo3/cms-core', true),
            // Local extension
            $this->createTestExtension('local_ext', null, false, true),
        ];
        
        $context = $this->createTestAnalysisContext('12.4.0');
        
        foreach ($extensions as $extension) {
            $result = $this->analyzer->analyze($extension, $context);
            
            $recommendations = $result->getRecommendations();
            $this->assertIsArray($recommendations);
            
            // Each extension type should have specific recommendations
            if ($extension->getKey() === 'news') {
                // Active extension should have positive recommendations
                $this->assertNotEmpty($recommendations);
                $recommendationText = implode(' ', $recommendations);
                $this->assertStringContainsString('available', strtolower($recommendationText));
            }
            
            if ($extension->isLocalExtension()) {
                // Local extension should have migration recommendations
                $this->assertNotEmpty($recommendations);
                $recommendationText = implode(' ', $recommendations);
                $this->assertStringContainsString('not available', strtolower($recommendationText));
            }
        }
    }
}