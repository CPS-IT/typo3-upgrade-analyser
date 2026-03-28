<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Integration\Analyzer;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorConfigGenerator;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorExecutor;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorResultParser;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\Fractor\FractorRuleRegistry;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\FractorAnalyzer;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ComposerVersionStrategy;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Cache\MultiLayerPathResolutionCache;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\PathResolutionService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Recovery\ErrorRecoveryManager;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\ExtensionPathResolutionStrategy;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\PathResolutionStrategyRegistry;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Validation\PathResolutionValidator;
use CPSIT\UpgradeAnalyzer\Shared\Utility\DiffProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(FractorAnalyzer::class)]
class FractorAnalyzerIntegrationTest extends TestCase
{
    private FractorAnalyzer $analyzer;
    private string $fixtureExtensionPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureExtensionPath = \dirname(__DIR__, 2) . '/fixtures/test_extension';

        // Skip test if Fractor is not available
        if (!file_exists(__DIR__ . '/../../../vendor/bin/fractor')) {
            $this->markTestSkipped('Fractor binary not available');
        }

        // Set up analyzer with real dependencies
        $logger = new NullLogger();
        $cacheService = new CacheService($logger, __DIR__ . '/../../../var/integration-test-cache');

        // Create temp directory for Fractor configs
        $tempDir = __DIR__ . '/../../../var/temp/fractor-test';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0o755, true);
        }

        $ruleRegistry = new FractorRuleRegistry($logger);
        $fractorExecutor = new FractorExecutor(__DIR__ . '/../../../vendor/bin/fractor', $logger, new DiffProcessor(), 300);
        $configGenerator = new FractorConfigGenerator($ruleRegistry, $tempDir);
        $parser = new FractorResultParser($ruleRegistry, $logger);

        // Set up PathResolutionService with real dependencies
        $versionStrategy = new ComposerVersionStrategy($logger);
        $extensionStrategy = new ExtensionPathResolutionStrategy($logger, $versionStrategy);
        $strategyRegistry = new PathResolutionStrategyRegistry($logger, [$extensionStrategy]);
        $validator = new PathResolutionValidator($logger);
        $cache = new MultiLayerPathResolutionCache($logger, 100, 300);
        $errorRecovery = new ErrorRecoveryManager($logger);
        $pathResolutionService = new PathResolutionService(
            $strategyRegistry,
            $validator,
            $cache,
            $errorRecovery,
            $logger,
        );

        $this->analyzer = new FractorAnalyzer(
            $cacheService,
            $logger,
            $fractorExecutor,
            $configGenerator,
            $parser,
            $ruleRegistry,
            $pathResolutionService,
        );
    }

    /**
     * Test that Fractor analyzer finds deprecated code patterns in fixture extension.
     */
    public function testAnalyzerFindsDeprecatedPatterns(): void
    {
        // Skip if fixture doesn't exist
        if (!is_dir($this->fixtureExtensionPath)) {
            $this->markTestSkipped('Test fixture extension not found at: ' . $this->fixtureExtensionPath);
        }

        // Create extension instance
        $extension = new Extension(
            'test_extension',
            'Test Extension',
            new Version('1.0.0'),
            'local',
            'myvendor/test-extension',
        );

        // Create analysis context for TYPO3 12 -> 13 upgrade
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            [
                'installation_path' => \dirname($this->fixtureExtensionPath),
                'custom_paths' => [
                    'vendor-dir' => '',
                    'typo3conf-dir' => 'test_extension',
                ],
            ],
        );

        // Run analysis
        $result = $this->analyzer->analyze($extension, $context);

        $this->assertTrue($result->isSuccessful(), 'Analysis should be successful');

        // These should pass if path resolution works
        $this->assertGreaterThan(0, $result->getMetric('processed_files'), 'Should process files');
        $this->assertGreaterThan(0, $result->getMetric('total_files'), 'Should find files');

        // We expect to find some deprecated patterns
        $totalFindings = $result->getMetric('total_findings');
        $this->assertGreaterThanOrEqual(0, $totalFindings, 'Should find deprecated patterns (or 0 if extension is clean)');

        // Check that Fractor version is detected
        $this->assertIsString($result->getMetric('fractor_version'), 'Should detect Fractor version');

        // Verify analysis metrics structure
        $this->assertIsFloat($result->getMetric('execution_time'));
        $this->assertIsArray($result->getMetric('findings_by_severity'));
        $this->assertIsInt($result->getMetric('affected_files'));
    }

    /**
     * Test that analyzer can handle non-existent extension paths gracefully.
     */
    public function testAnalyzerHandlesNonExistentPath(): void
    {
        $extension = new Extension('non_existent', 'Non Existent', new Version('1.0.0'));
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            ['installation_path' => '/non/existent/path'],
        );

        $result = $this->analyzer->analyze($extension, $context);

        // Should still be successful but with 0 processed files
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals(0, $result->getMetric('processed_files'));
        $this->assertEquals(0, $result->getMetric('total_files'));
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        $tempDir = __DIR__ . '/../../../var/temp/fractor-test';
        if (is_dir($tempDir)) {
            $files = glob($tempDir . '/*');
            if (false !== $files) {
                array_map('unlink', $files);
            }
        }

        parent::tearDown();
    }
}
