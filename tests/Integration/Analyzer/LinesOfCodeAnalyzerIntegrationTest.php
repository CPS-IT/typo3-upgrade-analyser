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
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\LinesOfCodeAnalyzer;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ComposerVersionStrategy;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Cache\MultiLayerPathResolutionCache;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\PathResolutionService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Recovery\ErrorRecoveryManager;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\ExtensionPathResolutionStrategy;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\PathResolutionStrategyRegistry;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Validation\PathResolutionValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Integration tests for LinesOfCodeAnalyzer with PathResolutionService.
 * Tests the complete integration including real file system operations.
 */
final class LinesOfCodeAnalyzerIntegrationTest extends TestCase
{
    private LinesOfCodeAnalyzer $analyzer;
    private string $testInstallationPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up the complete PathResolutionService with all dependencies
        $logger = new NullLogger();

        $composerVersionStrategy = new ComposerVersionStrategy($logger);
        $strategy = new ExtensionPathResolutionStrategy($logger, $composerVersionStrategy);
        $strategyRegistry = new PathResolutionStrategyRegistry($logger, [$strategy]);

        $validator = new PathResolutionValidator($logger);
        $cache = new MultiLayerPathResolutionCache($logger);
        $errorRecoveryManager = new ErrorRecoveryManager($logger);

        $pathResolutionService = new PathResolutionService(
            $strategyRegistry,
            $validator,
            $cache,
            $errorRecoveryManager,
            $logger,
        );

        $cacheService = $this->createMock(CacheService::class);

        $this->analyzer = new LinesOfCodeAnalyzer(
            $cacheService,
            $logger,
            $pathResolutionService,
        );

        // Create test installation directory structure
        $this->testInstallationPath = sys_get_temp_dir() . '/typo3-loc-test-' . uniqid();
        $this->createTestInstallationWithExtension($this->testInstallationPath);
    }

    protected function tearDown(): void
    {
        // Clean up test installation
        if (is_dir($this->testInstallationPath)) {
            $this->removeDirectory($this->testInstallationPath);
        }
        parent::tearDown();
    }

    public function testAnalyzeWithRealExtensionFiles(): void
    {
        // Arrange: Create test extension with known content
        $extension = new Extension('test_extension', 'Test Extension', new Version('1.0.0'), 'local');
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            ['installation_path' => $this->testInstallationPath],
        );

        // Act: Analyze the extension
        $result = $this->analyzer->analyze($extension, $context);

        // Assert: Verify the analysis results
        $this->assertTrue($result->isSuccessful());
        $this->assertSame('lines_of_code', $result->getAnalyzerName());
        $this->assertSame($extension, $result->getExtension());

        // Verify metrics are calculated correctly
        $totalLines = $result->getMetric('total_lines');
        $phpFiles = $result->getMetric('php_files');
        $codeLines = $result->getMetric('code_lines');

        // The test extension should have meaningful lines of code
        $this->assertGreaterThan(0, $totalLines);
        $this->assertGreaterThan(0, $phpFiles);
        $this->assertGreaterThan(0, $codeLines);

        // Verify we have at least the files we know exist
        $this->assertGreaterThanOrEqual(3, $phpFiles); // ext_emconf.php, TestController.php, TestService.php

        // Verify risk assessment
        $riskScore = $result->getRiskScore();
        $this->assertGreaterThanOrEqual(0.0, $riskScore);
        $this->assertLessThanOrEqual(10.0, $riskScore);

        // Verify recommendations are generated
        $recommendations = $result->getRecommendations();
        $this->assertNotEmpty($recommendations);
        $this->assertStringContainsString('lines', $recommendations[0]);
    }

    public function testAnalyzeWithCustomPathConfiguration(): void
    {
        // Arrange: Create test installation with custom paths similar to ihkof-bundle
        $customInstallationPath = sys_get_temp_dir() . '/typo3-custom-test-' . uniqid();
        $this->createCustomInstallationWithExtension($customInstallationPath);

        $extension = new Extension('test_extension', 'Test Extension', new Version('1.0.0'), 'local');
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            [
                'installation_path' => $customInstallationPath,
                'custom_paths' => [
                    'web-dir' => 'app/web',
                    'vendor-dir' => 'app/vendor',
                    'typo3conf-dir' => 'app/web/typo3conf',
                ],
            ],
        );

        // Act: Analyze with custom paths
        $result = $this->analyzer->analyze($extension, $context);

        // Assert: Should find extension in custom location
        $this->assertTrue($result->isSuccessful());
        $this->assertGreaterThan(0, $result->getMetric('total_lines'));
        $this->assertGreaterThan(0, $result->getMetric('php_files'));

        // Clean up
        $this->removeDirectory($customInstallationPath);
    }

    public function testAnalyzeWithNonExistentExtension(): void
    {
        // Arrange: Extension that doesn't exist in the test installation
        $extension = new Extension('non_existent_ext', 'Non Existent Extension', new Version('1.0.0'), 'local');
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            ['installation_path' => $this->testInstallationPath],
        );

        // Act: Analyze non-existent extension
        $result = $this->analyzer->analyze($extension, $context);

        // Assert: Should return zero metrics but still be successful
        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getMetric('total_lines'));
        $this->assertSame(0, $result->getMetric('php_files'));
        $this->assertSame(0, $result->getMetric('classes'));
    }

    public function testPathResolutionServiceIntegration(): void
    {
        // This test validates that PathResolutionService is properly integrated
        $extension = new Extension('test_extension', 'Test Extension', new Version('1.0.0'), 'local');
        $context = new AnalysisContext(
            new Version('12.4.0'),
            new Version('13.4.0'),
            [],
            ['installation_path' => $this->testInstallationPath],
        );

        // The key assertion is that the analyzer can find and analyze the extension
        // through the PathResolutionService, eliminating code duplication
        $result = $this->analyzer->analyze($extension, $context);

        $this->assertTrue($result->isSuccessful());
        $this->assertGreaterThan(0, $result->getMetric('total_lines'));

        // Verify that various file types are counted correctly
        $this->assertIsInt($result->getMetric('php_files'));
        $this->assertIsInt($result->getMetric('classes'));
        $this->assertIsInt($result->getMetric('methods'));
        $this->assertIsInt($result->getMetric('code_lines'));
        $this->assertIsInt($result->getMetric('comment_lines'));
        $this->assertIsInt($result->getMetric('blank_lines'));
    }

    /**
     * Create a test TYPO3 installation structure with a test extension.
     */
    private function createTestInstallationWithExtension(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0o755, true);
        }

        // Create legacy TYPO3 structure (extensions in typo3conf/ext, no composer.json)
        mkdir($path . '/typo3conf', 0o755, true);
        mkdir($path . '/typo3conf/ext', 0o755, true);

        // Copy the test extension from fixtures
        $sourceExtPath = __DIR__ . '/../../Fixtures/test_extension';
        $targetExtPath = $path . '/typo3conf/ext/test_extension';

        $this->copyDirectory($sourceExtPath, $targetExtPath);

        // Create legacy TYPO3 installation markers
        mkdir($path . '/typo3', 0o755, true);
        mkdir($path . '/typo3/sysext', 0o755, true);
        file_put_contents($path . '/typo3/index.php', '<?php // TYPO3 legacy installation');

        // Don't create composer.json - this should be detected as legacy installation
    }

    /**
     * Create a test installation with custom paths (similar to ihkof-bundle).
     */
    private function createCustomInstallationWithExtension(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0o755, true);
        }

        // Create custom structure with custom paths
        mkdir($path . '/app', 0o755, true);
        mkdir($path . '/app/web', 0o755, true);
        mkdir($path . '/app/web/typo3conf', 0o755, true);
        mkdir($path . '/app/web/typo3conf/ext', 0o755, true);
        mkdir($path . '/app/vendor', 0o755, true);

        // Copy the test extension from fixtures
        $sourceExtPath = __DIR__ . '/../../Fixtures/test_extension';
        $targetExtPath = $path . '/app/web/typo3conf/ext/test_extension';

        $this->copyDirectory($sourceExtPath, $targetExtPath);

        // Create TYPO3 v11 Composer installation with custom paths
        // v11 Composer mode allows extensions in typo3conf/ext
        file_put_contents($path . '/composer.json', json_encode([
            'name' => 'test/typo3-custom-installation',
            'require' => [
                'typo3/cms-core' => '^11.5',  // v11 allows extensions in typo3conf/ext
            ],
            'extra' => [
                'typo3/cms' => [
                    'web-dir' => 'app/web',
                ],
            ],
            'config' => [
                'vendor-dir' => 'app/vendor',
            ],
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Copy a directory recursively.
     */
    private function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            return;
        }

        if (!is_dir($destination)) {
            mkdir($destination, 0o755, true);
        }

        $files = array_diff(scandir($source), ['.', '..']);
        foreach ($files as $file) {
            $sourcePath = $source . '/' . $file;
            $destPath = $destination . '/' . $file;

            if (is_dir($sourcePath)) {
                $this->copyDirectory($sourcePath, $destPath);
            } else {
                copy($sourcePath, $destPath);
            }
        }
    }

    /**
     * Recursively remove a directory and its contents.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
