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
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\ComposerInstalledPathResolutionStrategy;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\ExtensionPathResolutionStrategy;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\PackageStatesPathResolutionStrategy;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\PathResolutionStrategyRegistry;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\Typo3ConfDirPathResolutionStrategy;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\VendorDirPathResolutionStrategy;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\WebDirPathResolutionStrategy;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Validation\PathResolutionValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for PathResolutionService testing integrated path resolution
 * with all available path resolution strategies working together.
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

        // Register all available strategies
        $strategies = [
            new ExtensionPathResolutionStrategy($logger, $composerVersionStrategy),
            new VendorDirPathResolutionStrategy($logger),
            new WebDirPathResolutionStrategy($logger),
            new Typo3ConfDirPathResolutionStrategy($logger),
            new ComposerInstalledPathResolutionStrategy($logger),
            new PackageStatesPathResolutionStrategy($logger),
        ];
        $strategyRegistry = new PathResolutionStrategyRegistry($logger, $strategies);

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
     * Test that PathResolutionService can resolve vendor_dir path type
     * by reading composer.json configuration with VendorDirPathResolutionStrategy.
     */
    public function testResolveVendorDirFromComposerConfigSucceeds(): void
    {
        // Arrange: Create a composer installation with custom vendor-dir
        $this->createComposerInstallation([
            'config' => [
                'vendor-dir' => 'app/vendor',
            ],
        ]);
        mkdir($this->testPath . '/app/vendor', 0o755, true);

        $request = PathResolutionRequest::create(
            PathTypeEnum::VENDOR_DIR,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_CUSTOM,
            PathConfiguration::createDefault(),
            null,
        );

        // Act
        $response = $this->pathResolutionService->resolvePath($request);

        // Assert: This should now succeed with VendorDirPathResolutionStrategy
        self::assertTrue($response->isSuccess());
        self::assertEquals($this->testPath . '/app/vendor', $response->resolvedPath);
        self::assertEmpty($response->errors);
    }

    /**
     * Test that PathResolutionService can resolve web_dir path type
     * by reading composer.json configuration with WebDirPathResolutionStrategy.
     */
    public function testResolveWebDirFromComposerConfigSucceeds(): void
    {
        // Arrange: Create a composer installation with custom web-dir
        $this->createComposerInstallation([
            'extra' => [
                'typo3/cms' => [
                    'web-dir' => 'app/web',
                ],
            ],
        ]);
        mkdir($this->testPath . '/app/web', 0o755, true);

        $request = PathResolutionRequest::create(
            PathTypeEnum::WEB_DIR,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_CUSTOM,
            PathConfiguration::createDefault(),
            null,
        );

        // Act
        $response = $this->pathResolutionService->resolvePath($request);

        // Assert: This should now succeed with WebDirPathResolutionStrategy
        self::assertTrue($response->isSuccess());
        self::assertEquals($this->testPath . '/app/web', $response->resolvedPath);
        self::assertEmpty($response->errors);
    }

    /**
     * Test that PathResolutionService can resolve typo3conf_dir path type
     * based on web_dir configuration with Typo3ConfDirPathResolutionStrategy.
     */
    public function testResolveTypo3ConfDirFromWebDirSucceeds(): void
    {
        // Arrange: Create a composer installation with custom web-dir
        $this->createComposerInstallation([
            'extra' => [
                'typo3/cms' => [
                    'web-dir' => 'app/web',
                ],
            ],
        ]);
        mkdir($this->testPath . '/app/web/typo3conf', 0o755, true);

        $request = PathResolutionRequest::create(
            PathTypeEnum::TYPO3CONF_DIR,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_CUSTOM,
            PathConfiguration::createDefault(),
            null,
        );

        // Act
        $response = $this->pathResolutionService->resolvePath($request);

        // Assert: This should now succeed with Typo3ConfDirPathResolutionStrategy
        self::assertTrue($response->isSuccess());
        self::assertEquals($this->testPath . '/app/web/typo3conf', $response->resolvedPath);
        self::assertEmpty($response->errors);
    }

    /**
     * Test that PathResolutionService can resolve composer_installed path type
     * based on vendor_dir configuration with ComposerInstalledPathResolutionStrategy.
     */
    public function testResolveComposerInstalledFromVendorDirSucceeds(): void
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

        // Assert: This should now succeed with ComposerInstalledPathResolutionStrategy
        self::assertTrue($response->isSuccess());
        self::assertEquals($this->testPath . '/app/vendor/composer/installed.json', $response->resolvedPath);
        self::assertEmpty($response->errors);
    }

    /**
     * Test that PathResolutionService can resolve package_states path type
     * based on typo3conf_dir configuration with PackageStatesPathResolutionStrategy.
     */
    public function testResolvePackageStatesFromTypo3ConfDirSucceeds(): void
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

        // Assert: This should now succeed with PackageStatesPathResolutionStrategy
        self::assertTrue($response->isSuccess());
        self::assertEquals($this->testPath . '/app/web/typo3conf/PackageStates.php', $response->resolvedPath);
        self::assertEmpty($response->errors);
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
