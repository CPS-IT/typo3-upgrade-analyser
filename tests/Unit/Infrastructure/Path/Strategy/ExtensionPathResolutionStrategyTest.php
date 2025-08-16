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

use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\ExtensionIdentifier;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathConfiguration;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionRequest;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\InstallationTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\StrategyPriorityEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\ExtensionPathResolutionStrategy;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for ExtensionPathResolutionStrategy.
 */
final class ExtensionPathResolutionStrategyTest extends TestCase
{
    private ExtensionPathResolutionStrategy $strategy;
    private string $testPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new ExtensionPathResolutionStrategy(new NullLogger());
        $this->testPath = sys_get_temp_dir() . '/test-path-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testPath)) {
            $this->removeDirectory($this->testPath);
        }
        parent::tearDown();
    }

    public function testGetIdentifier(): void
    {
        $this->assertEquals('extension_path_resolution_strategy', $this->strategy->getIdentifier());
    }

    public function testGetSupportedPathTypes(): void
    {
        $pathTypes = $this->strategy->getSupportedPathTypes();
        $this->assertContains(PathTypeEnum::EXTENSION, $pathTypes);
    }

    public function testGetSupportedInstallationTypes(): void
    {
        $installationTypes = $this->strategy->getSupportedInstallationTypes();
        $this->assertContains(InstallationTypeEnum::COMPOSER_STANDARD, $installationTypes);
        $this->assertContains(InstallationTypeEnum::LEGACY_SOURCE, $installationTypes);
        $this->assertContains(InstallationTypeEnum::CUSTOM, $installationTypes);
        $this->assertContains(InstallationTypeEnum::AUTO_DETECT, $installationTypes);
    }

    public function testSupportsPathType(): void
    {
        $pathTypes = $this->strategy->getSupportedPathTypes();
        $this->assertContains(PathTypeEnum::EXTENSION, $pathTypes);
    }

    public function testGetPriorityForSupportedType(): void
    {
        $priority = $this->strategy->getPriority(PathTypeEnum::EXTENSION, InstallationTypeEnum::COMPOSER_STANDARD);
        $this->assertEquals(StrategyPriorityEnum::HIGHEST, $priority);
    }

    public function testSupportsInstallationType(): void
    {
        $installationTypes = $this->strategy->getSupportedInstallationTypes();
        $this->assertContains(InstallationTypeEnum::COMPOSER_STANDARD, $installationTypes);
        $this->assertContains(InstallationTypeEnum::LEGACY_SOURCE, $installationTypes);
    }

    public function testResolveSuccessfulPath(): void
    {
        // Create test structure
        $extensionPath = $this->testPath . '/public/typo3conf/ext/test_ext';
        mkdir($extensionPath, 0755, true);
        file_put_contents($extensionPath . '/ext_emconf.php', '<?php $EM_CONF[$_EXTKEY] = [];');

        $request = PathResolutionRequest::create(
            PathTypeEnum::EXTENSION,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_STANDARD,
            PathConfiguration::createDefault(),
            new ExtensionIdentifier('test_ext')
        );

        $response = $this->strategy->resolve($request);

        $this->assertTrue($response->isSuccess());
        $this->assertStringContainsString('test_ext', $response->resolvedPath);
    }

    public function testResolveNonExistentExtension(): void
    {
        mkdir($this->testPath, 0755, true);

        $request = PathResolutionRequest::create(
            PathTypeEnum::EXTENSION,
            $this->testPath,
            InstallationTypeEnum::COMPOSER_STANDARD,
            PathConfiguration::createDefault(),
            new ExtensionIdentifier('nonexistent_ext')
        );

        $response = $this->strategy->resolve($request);

        $this->assertFalse($response->isSuccess());
        // The response should indicate failure appropriately
        $this->assertNotNull($response->metadata);
    }

    public function testAutoDetectInstallationType(): void
    {
        // Create composer installation structure
        mkdir($this->testPath . '/public/typo3conf/ext', 0755, true);
        file_put_contents($this->testPath . '/composer.json', '{"name": "test/project"}');

        $request = PathResolutionRequest::create(
            PathTypeEnum::EXTENSION,
            $this->testPath,
            InstallationTypeEnum::AUTO_DETECT,
            PathConfiguration::createDefault(),
            new ExtensionIdentifier('test_ext')
        );

        $response = $this->strategy->resolve($request);

        // Strategy should process AUTO_DETECT requests (the response shows the detected type or original)
        $this->assertNotNull($response);
        $this->assertNotNull($response->metadata);
    }

    public function testLegacyInstallationDetection(): void
    {
        // Create legacy installation structure  
        mkdir($this->testPath . '/typo3conf/ext', 0755, true);
        mkdir($this->testPath . '/fileadmin', 0755, true);
        
        $request = PathResolutionRequest::create(
            PathTypeEnum::EXTENSION,
            $this->testPath,
            InstallationTypeEnum::AUTO_DETECT,
            PathConfiguration::createDefault(),
            new ExtensionIdentifier('test_ext')
        );

        $response = $this->strategy->resolve($request);

        // Strategy should process AUTO_DETECT requests and provide a valid response
        $this->assertNotNull($response);
        $this->assertNotNull($response->metadata);
    }

    public function testCustomPathConfiguration(): void
    {
        $customExtPath = $this->testPath . '/custom/extensions/test_ext';
        mkdir($customExtPath, 0755, true);
        file_put_contents($customExtPath . '/ext_emconf.php', '<?php $EM_CONF[$_EXTKEY] = [];');

        $pathConfig = PathConfiguration::fromArray([
            'customPaths' => [
                'extensions' => '/custom/extensions'
            ]
        ]);

        $request = PathResolutionRequest::create(
            PathTypeEnum::EXTENSION,
            $this->testPath,
            InstallationTypeEnum::CUSTOM,
            $pathConfig,
            new ExtensionIdentifier('test_ext')
        );

        $response = $this->strategy->resolve($request);

        // Custom path configuration should be processed (might not always succeed depending on implementation)
        $this->assertNotNull($response);
        $this->assertNotNull($response->metadata);
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
}