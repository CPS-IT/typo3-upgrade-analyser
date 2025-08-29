<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Path\Strategy;

use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathConfiguration;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionRequest;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\InstallationTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\ComposerInstalledPathResolutionStrategy;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\PackageStatesPathResolutionStrategy;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\Typo3ConfDirPathResolutionStrategy;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\VendorDirPathResolutionStrategy;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\WebDirPathResolutionStrategy;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for Composer configuration path resolution strategies.
 * Tests various path resolution strategies that read composer.json configurations.
 */
final class ComposerConfigPathResolutionTest extends TestCase
{
    private string $testPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testPath = sys_get_temp_dir() . '/composer-config-test-' . uniqid();
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
     * Test that vendor directory resolution from composer.json config works
     * with VendorDirPathResolutionStrategy.
     */
    public function testVendorDirStrategyResolvesDefaultVendorPath(): void
    {
        // Arrange: Create a composer installation with default vendor-dir
        $this->createComposerInstallation([]);
        mkdir($this->testPath . '/vendor', 0o755, true);

        $logger = new NullLogger();
        $strategy = new VendorDirPathResolutionStrategy($logger);

        $request = PathResolutionRequest::create(
            PathTypeEnum::VENDOR_DIR,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_CUSTOM,
            PathConfiguration::createDefault(),
            null,
        );

        // Act
        $response = $strategy->resolve($request);

        // Assert
        self::assertTrue($response->isSuccess());
        self::assertEquals($this->testPath . '/vendor', $response->resolvedPath);
        self::assertEmpty($response->errors);
    }

    /**
     * Test that web directory resolution from composer.json extra config works
     * with WebDirPathResolutionStrategy.
     */
    public function testWebDirStrategyResolvesCustomWebPath(): void
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

        $logger = new NullLogger();
        $strategy = new WebDirPathResolutionStrategy($logger);

        $request = PathResolutionRequest::create(
            PathTypeEnum::WEB_DIR,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_CUSTOM,
            PathConfiguration::createDefault(),
            null,
        );

        // Act
        $response = $strategy->resolve($request);

        // Assert
        self::assertTrue($response->isSuccess());
        self::assertEquals($this->testPath . '/app/web', $response->resolvedPath);
        self::assertEmpty($response->errors);
    }

    /**
     * Test that typo3conf directory resolution based on web-dir works
     * with Typo3ConfDirPathResolutionStrategy.
     */
    public function testTypo3ConfDirStrategyResolvesTypo3ConfPath(): void
    {
        // Arrange: Create a composer installation with default paths
        $this->createComposerInstallation([]);
        mkdir($this->testPath . '/public/typo3conf', 0o755, true);

        $logger = new NullLogger();
        $strategy = new Typo3ConfDirPathResolutionStrategy($logger);

        $request = PathResolutionRequest::create(
            PathTypeEnum::TYPO3CONF_DIR,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_CUSTOM,
            PathConfiguration::createDefault(),
            null,
        );

        // Act
        $response = $strategy->resolve($request);

        // Assert
        self::assertTrue($response->isSuccess());
        self::assertEquals($this->testPath . '/public/typo3conf', $response->resolvedPath);
        self::assertEmpty($response->errors);
    }

    /**
     * Test that composer installed.json path resolution works
     * with ComposerInstalledPathResolutionStrategy.
     */
    public function testComposerInstalledStrategyResolvesInstalledJsonPath(): void
    {
        // Arrange: Create a composer installation with installed.json
        $this->createComposerInstallation([]);
        mkdir($this->testPath . '/vendor/composer', 0o755, true);
        file_put_contents(
            $this->testPath . '/vendor/composer/installed.json',
            json_encode(['packages' => []], JSON_PRETTY_PRINT),
        );

        $logger = new NullLogger();
        $strategy = new ComposerInstalledPathResolutionStrategy($logger);

        $request = PathResolutionRequest::create(
            PathTypeEnum::COMPOSER_INSTALLED,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_CUSTOM,
            PathConfiguration::createDefault(),
            null,
        );

        // Act
        $response = $strategy->resolve($request);

        // Assert
        self::assertTrue($response->isSuccess());
        self::assertEquals($this->testPath . '/vendor/composer/installed.json', $response->resolvedPath);
        self::assertEmpty($response->errors);
    }

    /**
     * Test that PackageStates.php path resolution works
     * with PackageStatesPathResolutionStrategy.
     */
    public function testPackageStatesStrategyResolvesPackageStatesPath(): void
    {
        // Arrange: Create a composer installation with PackageStates.php
        $this->createComposerInstallation([]);
        mkdir($this->testPath . '/public/typo3conf', 0o755, true);
        file_put_contents(
            $this->testPath . '/public/typo3conf/PackageStates.php',
            '<?php return [];',
        );

        $logger = new NullLogger();
        $strategy = new PackageStatesPathResolutionStrategy($logger);

        $request = PathResolutionRequest::create(
            PathTypeEnum::PACKAGE_STATES,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_CUSTOM,
            PathConfiguration::createDefault(),
            null,
        );

        // Act
        $response = $strategy->resolve($request);

        // Assert
        self::assertTrue($response->isSuccess());
        self::assertEquals($this->testPath . '/public/typo3conf/PackageStates.php', $response->resolvedPath);
        self::assertEmpty($response->errors);
    }

    /**
     * Test realistic scenario: ihkof-bundle-like installation with custom paths.
     * All path resolution strategies should now work correctly.
     */
    public function testIhkofBundleLikeInstallationPathResolution(): void
    {
        // Arrange: Create installation similar to ihkof-bundle
        $this->createIhkofBundleLikeInstallation();

        $pathConfig = PathConfiguration::createDefault();
        $logger = new NullLogger();

        // Test vendor directory resolution
        $vendorStrategy = new VendorDirPathResolutionStrategy($logger);
        $vendorRequest = PathResolutionRequest::create(
            PathTypeEnum::VENDOR_DIR,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_CUSTOM,
            $pathConfig,
            null,
        );
        $vendorResponse = $vendorStrategy->resolve($vendorRequest);
        self::assertTrue($vendorResponse->isSuccess());
        self::assertEquals($this->testPath . '/app/vendor', $vendorResponse->resolvedPath);

        // Test web directory resolution
        $webStrategy = new WebDirPathResolutionStrategy($logger);
        $webRequest = PathResolutionRequest::create(
            PathTypeEnum::WEB_DIR,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_CUSTOM,
            $pathConfig,
            null,
        );
        $webResponse = $webStrategy->resolve($webRequest);
        self::assertTrue($webResponse->isSuccess());
        self::assertEquals($this->testPath . '/app/web', $webResponse->resolvedPath);

        // Test composer installed resolution
        $installedStrategy = new ComposerInstalledPathResolutionStrategy($logger);
        $installedRequest = PathResolutionRequest::create(
            PathTypeEnum::COMPOSER_INSTALLED,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_CUSTOM,
            $pathConfig,
            null,
        );
        $installedResponse = $installedStrategy->resolve($installedRequest);
        self::assertTrue($installedResponse->isSuccess());
        self::assertEquals($this->testPath . '/app/vendor/composer/installed.json', $installedResponse->resolvedPath);
    }

    private function createIhkofBundleLikeInstallation(): void
    {
        mkdir($this->testPath, 0o755, true);

        // Create composer.json with custom paths like ihkof-bundle
        $composerConfig = [
            'name' => 'test/ihkof-like-installation',
            'type' => 'project',
            'require' => [
                'typo3/cms-core' => '^11.5',
                'georgringer/news' => '^9.3',
            ],
            'config' => [
                'vendor-dir' => 'app/vendor',
            ],
            'extra' => [
                'typo3/cms' => [
                    'web-dir' => 'app/web',
                ],
            ],
        ];

        file_put_contents(
            $this->testPath . '/composer.json',
            json_encode($composerConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        // Create directory structure
        mkdir($this->testPath . '/app/vendor/composer', 0o755, true);
        mkdir($this->testPath . '/app/web/typo3conf/ext/news', 0o755, true);

        // Create installed.json
        $installedJson = [
            'packages' => [
                [
                    'name' => 'georgringer/news',
                    'version' => '9.3.1',
                    'type' => 'typo3-cms-extension',
                    'extra' => [
                        'typo3/cms' => [
                            'extension-key' => 'news',
                        ],
                    ],
                    'install-path' => '../../app/web/typo3conf/ext/news',
                ],
            ],
        ];

        file_put_contents(
            $this->testPath . '/app/vendor/composer/installed.json',
            json_encode($installedJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        // Create PackageStates.php
        file_put_contents(
            $this->testPath . '/app/web/typo3conf/PackageStates.php',
            '<?php return [];',
        );

        // Create extension
        file_put_contents(
            $this->testPath . '/app/web/typo3conf/ext/news/ext_emconf.php',
            '<?php $EM_CONF[$_EXTKEY] = [];',
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
