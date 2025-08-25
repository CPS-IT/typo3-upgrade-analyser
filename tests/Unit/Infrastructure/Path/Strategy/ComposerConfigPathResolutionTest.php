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
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for missing Composer configuration path resolution strategies.
 * These tests should fail until the missing strategies are implemented.
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
     * Test that vendor directory resolution from composer.json config fails
     * because VendorDirPathResolutionStrategy doesn't exist.
     */
    public function testVendorDirStrategyDoesNotExist(): void
    {
        $this->markTestIncomplete(
            'VendorDirPathResolutionStrategy class does not exist yet. ' .
            'This strategy should read composer.json config.vendor-dir and resolve vendor directory path.',
        );

        // This test would fail to even load the class:
        // $strategy = new VendorDirPathResolutionStrategy($logger);
    }

    /**
     * Test that web directory resolution from composer.json extra config fails
     * because WebDirPathResolutionStrategy doesn't exist.
     */
    public function testWebDirStrategyDoesNotExist(): void
    {
        $this->markTestIncomplete(
            'WebDirPathResolutionStrategy class does not exist yet. ' .
            'This strategy should read composer.json extra.typo3/cms.web-dir and resolve web directory path.',
        );

        // This test would fail to even load the class:
        // $strategy = new WebDirPathResolutionStrategy($logger);
    }

    /**
     * Test that typo3conf directory resolution based on web-dir fails
     * because Typo3ConfDirPathResolutionStrategy doesn't exist.
     */
    public function testTypo3ConfDirStrategyDoesNotExist(): void
    {
        $this->markTestIncomplete(
            'Typo3ConfDirPathResolutionStrategy class does not exist yet. ' .
            'This strategy should resolve {web-dir}/typo3conf path.',
        );

        // This test would fail to even load the class:
        // $strategy = new Typo3ConfDirPathResolutionStrategy($logger);
    }

    /**
     * Test that composer installed.json path resolution fails
     * because ComposerInstalledPathResolutionStrategy doesn't exist.
     */
    public function testComposerInstalledStrategyDoesNotExist(): void
    {
        $this->markTestIncomplete(
            'ComposerInstalledPathResolutionStrategy class does not exist yet. ' .
            'This strategy should resolve {vendor-dir}/composer/installed.json path.',
        );

        // This test would fail to even load the class:
        // $strategy = new ComposerInstalledPathResolutionStrategy($logger);
    }

    /**
     * Test that PackageStates.php path resolution fails
     * because PackageStatesPathResolutionStrategy doesn't exist.
     */
    public function testPackageStatesStrategyDoesNotExist(): void
    {
        $this->markTestIncomplete(
            'PackageStatesPathResolutionStrategy class does not exist yet. ' .
            'This strategy should resolve {typo3conf-dir}/PackageStates.php path.',
        );

        // This test would fail to even load the class:
        // $strategy = new PackageStatesPathResolutionStrategy($logger);
    }

    /**
     * Test realistic scenario: ihkof-bundle-like installation with custom paths.
     * This test should fail because none of the required strategies exist.
     */
    public function testIhkofBundleLikeInstallationShouldFail(): void
    {
        // Arrange: Create installation similar to ihkof-bundle
        $this->createIhkofBundleLikeInstallation();

        $pathConfig = PathConfiguration::createDefault();

        // Test each path type that should be resolvable
        $pathTypesToTest = [
            PathTypeEnum::VENDOR_DIR,      // Should resolve to app/vendor
            PathTypeEnum::WEB_DIR,         // Should resolve to app/web
            PathTypeEnum::TYPO3CONF_DIR,   // Should resolve to app/web/typo3conf
            PathTypeEnum::COMPOSER_INSTALLED, // Should resolve to app/vendor/composer/installed.json
            PathTypeEnum::PACKAGE_STATES,  // Should resolve to app/web/typo3conf/PackageStates.php
        ];

        foreach ($pathTypesToTest as $pathType) {
            $request = PathResolutionRequest::create(
                $pathType,
                $this->testPath,
                InstallationTypeEnum::COMPOSER_CUSTOM,
                $pathConfig,
                null,
            );

            // This should fail because we don't have strategies for these path types
            $this->markTestIncomplete(
                "Path resolution for {$pathType->value} should fail until corresponding strategy is implemented. " .
                "Installation structure: {$this->testPath}",
            );
        }
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
}
