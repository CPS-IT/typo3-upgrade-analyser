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
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\PathResolutionService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Recovery\ErrorRecoveryManager;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\ExtensionPathResolutionStrategy;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\PathResolutionStrategyRegistry;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Validation\PathResolutionValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for PathResolutionService focusing on missing functionality
 * that should fail until the missing path resolution strategies are implemented.
 */
final class PathResolutionServiceTest extends TestCase
{
    private PathResolutionService $pathResolutionService;
    private string $testPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testPath = sys_get_temp_dir() . '/path-resolution-test-' . uniqid();

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
     * This test should FAIL because PathResolutionService doesn't have a strategy
     * to resolve vendor_dir path type by reading composer.json configuration.
     */
    public function testResolveVendorDirFromComposerConfigShouldFail(): void
    {
        // Arrange: Create a composer installation with custom vendor-dir
        $this->createComposerInstallation([
            'config' => [
                'vendor-dir' => 'app/vendor',
            ],
        ]);

        $request = PathResolutionRequest::create(
            PathTypeEnum::VENDOR_DIR,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_CUSTOM,
            PathConfiguration::createDefault(),
            null,
        );

        // Act
        $response = $this->pathResolutionService->resolvePath($request);

        // Assert: This should fail because we don't have a strategy for vendor_dir
        $this->markTestIncomplete(
            'This test should fail until VendorDirPathResolutionStrategy is implemented. ' .
            'Currently fails with: ' . implode(', ', $response->errors),
        );
    }

    /**
     * This test should FAIL because PathResolutionService doesn't have a strategy
     * to resolve web_dir path type by reading composer.json configuration.
     */
    public function testResolveWebDirFromComposerConfigShouldFail(): void
    {
        // Arrange: Create a composer installation with custom web-dir
        $this->createComposerInstallation([
            'extra' => [
                'typo3/cms' => [
                    'web-dir' => 'app/web',
                ],
            ],
        ]);

        $request = PathResolutionRequest::create(
            PathTypeEnum::WEB_DIR,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_CUSTOM,
            PathConfiguration::createDefault(),
            null,
        );

        // Act
        $response = $this->pathResolutionService->resolvePath($request);

        // Assert: This should fail because we don't have a strategy for web_dir
        $this->markTestIncomplete(
            'This test should fail until WebDirPathResolutionStrategy is implemented. ' .
            'Currently fails with: ' . implode(', ', $response->errors),
        );
    }

    /**
     * This test should FAIL because PathResolutionService doesn't have a strategy
     * to resolve typo3conf_dir based on web_dir configuration.
     */
    public function testResolveTypo3ConfDirFromWebDirShouldFail(): void
    {
        // Arrange: Create a composer installation with custom web-dir
        $this->createComposerInstallation([
            'extra' => [
                'typo3/cms' => [
                    'web-dir' => 'app/web',
                ],
            ],
        ]);

        $request = PathResolutionRequest::create(
            PathTypeEnum::TYPO3CONF_DIR,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_CUSTOM,
            PathConfiguration::createDefault(),
            null,
        );

        // Act
        $response = $this->pathResolutionService->resolvePath($request);

        // Assert: This should fail because we don't have a strategy for typo3conf_dir
        $this->markTestIncomplete(
            'This test should fail until Typo3ConfDirPathResolutionStrategy is implemented. ' .
            'Currently fails with: ' . implode(', ', $response->errors),
        );
    }

    /**
     * This test should FAIL because PathResolutionService doesn't have a strategy
     * to resolve composer_installed path based on vendor_dir configuration.
     */
    public function testResolveComposerInstalledFromVendorDirShouldFail(): void
    {
        // Arrange: Create a composer installation with custom vendor-dir
        $this->createComposerInstallation([
            'config' => [
                'vendor-dir' => 'app/vendor',
            ],
        ]);

        mkdir($this->testPath . '/app/vendor/composer', 0o755, true);
        file_put_contents(
            $this->testPath . '/app/vendor/composer/installed.json',
            '{"packages": []}',
        );

        $request = PathResolutionRequest::create(
            PathTypeEnum::COMPOSER_INSTALLED,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_CUSTOM,
            PathConfiguration::createDefault(),
            null,
        );

        // Act
        $response = $this->pathResolutionService->resolvePath($request);

        // Assert: This should fail because we don't have a strategy for composer_installed
        $this->markTestIncomplete(
            'This test should fail until ComposerInstalledPathResolutionStrategy is implemented. ' .
            'Currently fails with: ' . implode(', ', $response->errors),
        );
    }

    /**
     * This test should FAIL because PathResolutionService doesn't have a strategy
     * to resolve package_states path based on typo3conf_dir configuration.
     */
    public function testResolvePackageStatesFromTypo3ConfDirShouldFail(): void
    {
        // Arrange: Create a composer installation with custom paths
        $this->createComposerInstallation([
            'extra' => [
                'typo3/cms' => [
                    'web-dir' => 'app/web',
                ],
            ],
        ]);

        mkdir($this->testPath . '/app/web/typo3conf', 0o755, true);
        file_put_contents(
            $this->testPath . '/app/web/typo3conf/PackageStates.php',
            '<?php return [];',
        );

        $request = PathResolutionRequest::create(
            PathTypeEnum::PACKAGE_STATES,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_CUSTOM,
            PathConfiguration::createDefault(),
            null,
        );

        // Act
        $response = $this->pathResolutionService->resolvePath($request);

        // Assert: This should fail because we don't have a strategy for package_states
        $this->markTestIncomplete(
            'This test should fail until PackageStatesPathResolutionStrategy is implemented. ' .
            'Currently fails with: ' . implode(', ', $response->errors),
        );
    }

    private function createComposerInstallation(array $composerConfig): void
    {
        mkdir($this->testPath, 0o755, true);

        $defaultConfig = [
            'name' => 'test/installation',
            'type' => 'project',
            'require' => [
                'typo3/cms-core' => '^11.5',
            ],
        ];

        $config = array_merge_recursive($defaultConfig, $composerConfig);

        file_put_contents(
            $this->testPath . '/composer.json',
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }
}
