<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Integration\Infrastructure\Path;

use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ComposerVersionStrategy;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Cache\MultiLayerPathResolutionCache;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\ExtensionIdentifier;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathConfiguration;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionRequest;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\InstallationTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\PathResolutionService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Recovery\ErrorRecoveryManager;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\ExtensionPathResolutionStrategy;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Strategy\PathResolutionStrategyRegistry;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Validation\PathResolutionValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Test PathResolutionService with actual TYPO3 installation fixtures.
 * Validates that extensions are found correctly in different installations.
 */
final class PathResolutionServiceFixturesTest extends TestCase
{
    private PathResolutionService $pathResolutionService;
    private string $fixturesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturesPath = __DIR__ . '/../../Fixtures/TYPO3Installations';

        // Set up the complete service with all dependencies - create fresh instances each time
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

    /**
     * Test extension detection across all fixtures using their test-expectations.json files.
     */
    #[DataProvider('fixtureInstallationProvider')]
    public function testFixtureInstallationExtensionDetection(string $fixtureName): void
    {
        $installationPath = $this->fixturesPath . '/' . $fixtureName;
        $this->assertDirectoryExists($installationPath, "Fixture '{$fixtureName}' should exist");

        $expectationsFile = $installationPath . '/test-expectations.json';
        $this->assertFileExists($expectationsFile, "Test expectations file should exist for fixture '{$fixtureName}'");

        $expectationsContent = file_get_contents($expectationsFile);
        $this->assertNotFalse($expectationsContent, "Could not read expectations file for fixture '{$fixtureName}'");
        $expectations = json_decode($expectationsContent, true);
        $this->assertIsArray($expectations, "Test expectations should be valid JSON for fixture '{$fixtureName}'");

        // Get installation type and path configuration
        $installationType = InstallationTypeEnum::from($expectations['installation_type']);
        $pathConfig = $this->createPathConfiguration($expectations['path_configuration'] ?? 'default');

        foreach ($expectations['extensions'] as $extensionKey => $expectation) {
            $shouldExist = $expectation['should_exist'];
            $reason = $expectation['reason'] ?? '';

            $request = PathResolutionRequest::create(
                PathTypeEnum::EXTENSION,
                $installationPath,
                $installationType,
                $pathConfig,
                new ExtensionIdentifier($extensionKey),
            );

            $response = $this->pathResolutionService->resolvePath($request);

            if ($shouldExist) {
                $this->assertTrue(
                    $response->isSuccess(),
                    "Extension '{$extensionKey}' should be found in '{$fixtureName}'. Reason: {$reason}",
                );
                $this->assertNotNull(
                    $response->resolvedPath,
                    "Extension '{$extensionKey}' should have a resolved path",
                );
                $this->assertDirectoryExists(
                    $response->resolvedPath,
                    'Resolved path should be a valid directory',
                );
            } else {
                $this->assertFalse(
                    $response->isSuccess(),
                    "Extension '{$extensionKey}' should not be found in '{$fixtureName}'. Reason: {$reason}. Status: {$response->status->value}, Errors: " . json_encode($response->errors) . ', Alternatives: ' . json_encode($response->alternativePaths),
                );
            }
        }
    }

    /**
     * Data provider for fixture installations.
     */
    public static function fixtureInstallationProvider(): array
    {
        $fixturesPath = __DIR__ . '/../../Fixtures/TYPO3Installations';
        $fixtures = [];

        if (!is_dir($fixturesPath)) {
            return $fixtures;
        }

        foreach (scandir($fixturesPath) as $item) {
            if ('.' === $item || '..' === $item || !is_dir($fixturesPath . '/' . $item)) {
                continue;
            }

            // Only include fixtures that have test-expectations.json
            if (file_exists($fixturesPath . '/' . $item . '/test-expectations.json')) {
                $fixtures[$item] = [$item];
            }
        }

        return $fixtures;
    }

    /**
     * Create path configuration from expectations.
     */
    private function createPathConfiguration(string $configType): PathConfiguration
    {
        return match ($configType) {
            'web-dir-web' => PathConfiguration::fromArray([
                'customPaths' => ['web-dir' => 'web'],
            ]),
            'web-dir-public' => PathConfiguration::fromArray([
                'customPaths' => ['web-dir' => 'public'],
            ]),
            default => PathConfiguration::createDefault(),
        };
    }
}
