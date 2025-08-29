<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Path;

use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ComposerVersionStrategy;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Cache\MultiLayerPathResolutionCache;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathConfiguration;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionRequest;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\InstallationTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\ResolutionStatusEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\PathResolutionService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Recovery\ErrorRecoveryManager;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\ExtensionPathResolutionStrategy;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\PathResolutionStrategyRegistry;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Validation\PathResolutionValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests that demonstrate the missing path resolution strategies
 * by calling the service and capturing the exact error messages.
 */
final class MissingStrategiesTest extends TestCase
{
    private PathResolutionService $pathResolutionService;
    private string $testPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testPath = sys_get_temp_dir() . '/missing-strategies-test-' . uniqid();

        $logger = new NullLogger();
        $composerVersionStrategy = new ComposerVersionStrategy($logger);
        $strategy = new ExtensionPathResolutionStrategy($logger, $composerVersionStrategy);
        $strategyRegistry = new PathResolutionStrategyRegistry($logger, [$strategy]);

        $validator = new PathResolutionValidator($logger);
        $cache = new MultiLayerPathResolutionCache($logger);
        $errorRecoveryManager = new ErrorRecoveryManager($logger);

        $this->pathResolutionService = new PathResolutionService(
            $strategyRegistry,
            $validator,
            $cache,
            $errorRecoveryManager,
            $logger,
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testPath)) {
            $this->removeDirectory($this->testPath);
        }
        parent::tearDown();
    }

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

    /**
     * This test demonstrates that vendor_dir path type fails with specific error.
     */
    public function testVendorDirPathTypeFails(): void
    {
        $this->createComposerInstallation(['config' => ['vendor-dir' => 'app/vendor']]);

        $request = PathResolutionRequest::create(
            PathTypeEnum::VENDOR_DIR,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_CUSTOM,
            PathConfiguration::createDefault(),
            null,
        );

        $response = $this->pathResolutionService->resolvePath($request);

        // Assert that it fails with "no compatible strategy" error
        $this->assertSame(ResolutionStatusEnum::ERROR, $response->status);
        $this->assertContains(
            'No strategies registered for path type: vendor_dir',
            $response->errors,
        );
        $this->assertFalse($response->isSuccess());
    }

    /**
     * This test demonstrates that web_dir path type fails with specific error.
     */
    public function testWebDirPathTypeFails(): void
    {
        $this->createComposerInstallation([
            'extra' => ['typo3/cms' => ['web-dir' => 'app/web']],
        ]);

        $request = PathResolutionRequest::create(
            PathTypeEnum::WEB_DIR,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_CUSTOM,
            PathConfiguration::createDefault(),
            null,
        );

        $response = $this->pathResolutionService->resolvePath($request);

        // Assert that it fails with "no compatible strategy" error
        $this->assertSame(ResolutionStatusEnum::ERROR, $response->status);
        $this->assertContains(
            'No strategies registered for path type: web_dir',
            $response->errors,
        );
        $this->assertFalse($response->isSuccess());
    }

    /**
     * This test demonstrates that typo3conf_dir path type fails with specific error.
     */
    public function testTypo3ConfDirPathTypeFails(): void
    {
        $this->createComposerInstallation([
            'extra' => ['typo3/cms' => ['web-dir' => 'app/web']],
        ]);

        $request = PathResolutionRequest::create(
            PathTypeEnum::TYPO3CONF_DIR,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_CUSTOM,
            PathConfiguration::createDefault(),
            null,
        );

        $response = $this->pathResolutionService->resolvePath($request);

        // Assert that it fails with "no compatible strategy" error
        $this->assertSame(ResolutionStatusEnum::ERROR, $response->status);
        $this->assertContains(
            'No strategies registered for path type: typo3conf_dir',
            $response->errors,
        );
        $this->assertFalse($response->isSuccess());
    }

    /**
     * This test demonstrates that composer_installed path type fails with specific error.
     */
    public function testComposerInstalledPathTypeFails(): void
    {
        $this->createComposerInstallation(['config' => ['vendor-dir' => 'app/vendor']]);

        $request = PathResolutionRequest::create(
            PathTypeEnum::COMPOSER_INSTALLED,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_CUSTOM,
            PathConfiguration::createDefault(),
            null,
        );

        $response = $this->pathResolutionService->resolvePath($request);

        // Assert that it fails with "no compatible strategy" error
        $this->assertSame(ResolutionStatusEnum::ERROR, $response->status);
        $this->assertContains(
            'No strategies registered for path type: composer_installed',
            $response->errors,
        );
        $this->assertFalse($response->isSuccess());
    }

    /**
     * This test demonstrates that package_states path type fails with specific error.
     */
    public function testPackageStatesPathTypeFails(): void
    {
        $this->createComposerInstallation([
            'extra' => ['typo3/cms' => ['web-dir' => 'app/web']],
        ]);

        $request = PathResolutionRequest::create(
            PathTypeEnum::PACKAGE_STATES,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_CUSTOM,
            PathConfiguration::createDefault(),
            null,
        );

        $response = $this->pathResolutionService->resolvePath($request);

        // Assert that it fails with missing strategy error
        $this->assertSame(ResolutionStatusEnum::ERROR, $response->status);
        $this->assertContains(
            'No strategies registered for path type: package_states',
            $response->errors,
        );
        $this->assertFalse($response->isSuccess());
    }

    /**
     * This test demonstrates the full failure cascade: all path types needed for
     * the ihkof-bundle scenario fail because strategies are missing.
     */
    public function testFullIhkofBundleScenarioFails(): void
    {
        $this->createComposerInstallation([
            'config' => ['vendor-dir' => 'app/vendor'],
            'extra' => ['typo3/cms' => ['web-dir' => 'app/web']],
        ]);

        $failingPathTypes = [
            PathTypeEnum::VENDOR_DIR,
            PathTypeEnum::WEB_DIR,
            PathTypeEnum::TYPO3CONF_DIR,
            PathTypeEnum::COMPOSER_INSTALLED,
            PathTypeEnum::PACKAGE_STATES,
        ];

        foreach ($failingPathTypes as $pathType) {
            $request = PathResolutionRequest::create(
                $pathType,
                $this->testPath,
                InstallationTypeEnum::COMPOSER_CUSTOM,
                PathConfiguration::createDefault(),
                null,
            );

            $response = $this->pathResolutionService->resolvePath($request);

            $this->assertFalse(
                $response->isSuccess(),
                "Path type {$pathType->value} should fail but it succeeded. " .
                'Resolved path: ' . ($response->resolvedPath ?? 'null'),
            );
        }
    }

    private function createComposerInstallation(array $composerConfig): void
    {
        mkdir($this->testPath, 0o755, true);

        $defaultConfig = [
            'name' => 'test/installation',
            'type' => 'project',
            'require' => ['typo3/cms-core' => '^11.5'],
        ];

        $config = array_merge_recursive($defaultConfig, $composerConfig);

        file_put_contents(
            $this->testPath . '/composer.json',
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }
}
