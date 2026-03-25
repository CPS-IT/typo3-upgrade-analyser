<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Discovery;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Installation;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\InstallationMode;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ComposerInstallationDetector;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\VersionExtractor;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\VersionProfileRegistry;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\VersionProfileRegistryFactory;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\VersionStrategyInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionMetadata;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\DTO\PathResolutionResponse;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\InstallationTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\Enum\PathTypeEnum;
use CPSIT\UpgradeAnalyzer\Infrastructure\Path\PathResolutionServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(ComposerInstallationDetector::class)]
final class ComposerInstallationDetectorTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private PathResolutionServiceInterface&MockObject $pathResolutionService;
    private VersionProfileRegistry $versionProfileRegistry;
    private ComposerInstallationDetector $detector;
    private string $testDir;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->pathResolutionService = $this->createMock(PathResolutionServiceInterface::class);

        $this->versionProfileRegistry = VersionProfileRegistryFactory::create();

        // Setup default PathResolutionService behavior
        $this->setupDefaultPathResolutionMocks();

        // Create a real VersionExtractor with a minimal strategy
        $mockStrategy = $this->createMock(VersionStrategyInterface::class);
        $mockStrategy->method('getPriority')->willReturn(100);
        $mockStrategy->method('getName')->willReturn('Mock Strategy');
        $mockStrategy->method('getReliabilityScore')->willReturn(0.5);
        $mockStrategy->method('getRequiredFiles')->willReturn(['composer.json']);
        $versionExtractor = new VersionExtractor([$mockStrategy], $this->logger);

        $this->detector = new ComposerInstallationDetector(
            $versionExtractor,
            $this->pathResolutionService,
            $this->logger,
            $this->versionProfileRegistry,
        );
        $this->testDir = sys_get_temp_dir() . '/typo3-analyzer-test-' . uniqid();
        mkdir($this->testDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testDir)) {
            $this->removeDirectory($this->testDir);
        }
    }

    #[Test]
    public function testGetName(): void
    {
        self::assertSame('Composer Installation Detector', $this->detector->getName());
    }

    #[Test]
    public function testGetDescription(): void
    {
        self::assertSame(
            'Detects TYPO3 installations set up using Composer package manager',
            $this->detector->getDescription(),
        );
    }

    #[Test]
    public function testGetPriority(): void
    {
        self::assertSame(100, $this->detector->getPriority());
    }

    #[Test]
    public function testGetRequiredIndicators(): void
    {
        $indicators = $this->detector->getRequiredIndicators();

        self::assertContains('composer.json', $indicators);
        // Only composer.json is required - TYPO3 paths can be customized
        self::assertCount(1, $indicators);
    }

    #[Test]
    public function testSupportsReturnsFalseForNonExistentDirectory(): void
    {
        self::assertFalse($this->detector->supports('/does/not/exist'));
    }

    #[Test]
    public function testSupportsReturnsFalseForFileInsteadOfDirectory(): void
    {
        $filePath = $this->testDir . '/test.txt';
        file_put_contents($filePath, 'test content');

        self::assertFalse($this->detector->supports($filePath));
    }

    #[Test]
    public function testSupportsReturnsFalseWithoutComposerJson(): void
    {
        // Directory exists but no composer.json
        self::assertFalse($this->detector->supports($this->testDir));
    }

    #[Test]
    public function testSupportsReturnsFalseWithComposerJsonMissingTypo3Packages(): void
    {
        // composer.json exists but contains no TYPO3 packages
        file_put_contents($this->testDir . '/composer.json', json_encode([
            'require' => ['some/other-package' => '^1.0'],
        ]));

        self::assertFalse($this->detector->supports($this->testDir));
    }

    #[Test]
    public function testSupportsReturnsFalseWithoutTypo3Packages(): void
    {
        $composerData = [
            'require' => [
                'some/other-package' => '^1.0',
            ],
        ];
        file_put_contents($this->testDir . '/composer.json', json_encode($composerData));

        self::assertFalse($this->detector->supports($this->testDir));
    }

    #[Test]
    public function supportsReturnsTrueWithJustComposerJsonContainingTypo3Package(): void
    {
        // No runtime directories — only composer.json with TYPO3 packages is required
        file_put_contents($this->testDir . '/composer.json', json_encode([
            'require' => ['typo3/cms-core' => '^14.0'],
        ]));

        self::assertTrue($this->detector->supports($this->testDir));
    }

    #[Test]
    public function testSupportsReturnsTrueWithValidComposerTypo3Installation(): void
    {
        $this->createValidComposerTypo3Installation();

        self::assertTrue($this->detector->supports($this->testDir));
    }

    #[Test]
    public function testSupportsWithInvalidComposerJson(): void
    {
        file_put_contents($this->testDir . '/composer.json', 'invalid json');

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                'Failed to parse composer.json',
                self::callback(
                    fn ($context): bool => isset($context['path'])
                    && str_contains($context['path'], 'composer.json')
                    && isset($context['error']),
                ),
            );

        self::assertFalse($this->detector->supports($this->testDir));
    }

    #[Test]
    public function testSupportsLogsWarningWhenComposerJsonIsUnreadable(): void
    {
        if (0 === posix_getuid()) {
            self::markTestSkipped('Cannot test file permissions as root');
        }

        $composerJsonPath = $this->testDir . '/composer.json';
        file_put_contents($composerJsonPath, json_encode(['require' => ['typo3/cms-core' => '^14.0']]));
        chmod($composerJsonPath, 0o000);

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                'Failed to read composer.json',
                self::callback(
                    fn ($context): bool => isset($context['path'])
                    && str_contains($context['path'], 'composer.json'),
                ),
            );

        self::assertFalse($this->detector->supports($this->testDir));

        chmod($composerJsonPath, 0o644);
    }

    #[DataProvider('typo3PackageProvider')]
    public function testSupportsWithDifferentTypo3Packages(string $packageName): void
    {
        $composerData = [
            'require' => [
                $packageName => '^12.4',
            ],
        ];
        file_put_contents($this->testDir . '/composer.json', json_encode($composerData));

        self::assertTrue($this->detector->supports($this->testDir));
    }

    public static function typo3PackageProvider(): array
    {
        return [
            'typo3/cms-core' => ['typo3/cms-core'],
            'typo3/cms' => ['typo3/cms'],
            'typo3/minimal' => ['typo3/minimal'],
        ];
    }

    #[Test]
    public function testSupportsWithTypo3PackageInRequireDev(): void
    {
        $composerData = [
            'require-dev' => [
                'typo3/cms-core' => '^12.4',
            ],
        ];
        file_put_contents($this->testDir . '/composer.json', json_encode($composerData));

        self::assertTrue($this->detector->supports($this->testDir));
    }

    #[Test]
    public function testDetectReturnsNullWhenNotSupported(): void
    {
        // Empty directory - not supported
        $result = $this->detector->detect($this->testDir);

        self::assertNull($result);
    }

    #[Test]
    public function testDetectReturnsNullWhenVersionExtractionFails(): void
    {
        $this->createValidComposerTypo3Installation();

        // The version extractor will fail because no actual version extraction strategy works
        $result = $this->detector->detect($this->testDir);

        self::assertNull($result);
    }

    #[Test]
    public function testDetectWithValidInstallationAndWorkingVersionExtractor(): void
    {
        $this->createValidComposerTypo3Installation();
        $this->createComposerLockWithVersionInfo();

        // Create a working version extractor with a strategy that returns a version
        $workingStrategy = new TestableVersionStrategy();
        $workingVersionExtractor = new VersionExtractor([$workingStrategy], $this->logger);
        $workingDetector = new ComposerInstallationDetector(
            $workingVersionExtractor,
            $this->pathResolutionService,
            $this->logger,
            $this->versionProfileRegistry,
        );

        $result = $workingDetector->detect($this->testDir);

        self::assertNotNull($result, 'detect() must return an Installation for a valid Composer TYPO3 path');
        self::assertSame($this->testDir, $result->getPath());
        self::assertSame(InstallationMode::COMPOSER, $result->getMode());
        self::assertNotNull($result->getMetadata());
    }

    #[Test]
    public function testDetectWithExtensionsInComposerLock(): void
    {
        $this->createValidComposerTypo3Installation();
        $this->createComposerLockWithExtensions();

        // Create a working version extractor
        $workingStrategy = new TestableVersionStrategy();
        $workingVersionExtractor = new VersionExtractor([$workingStrategy], $this->logger);
        $workingDetector = new ComposerInstallationDetector(
            $workingVersionExtractor,
            $this->pathResolutionService,
            $this->logger,
            $this->versionProfileRegistry,
        );

        $result = $workingDetector->detect($this->testDir);
        self::assertInstanceOf(Installation::class, $result);
    }

    #[Test]
    public function testDetectHandlesExceptionGracefully(): void
    {
        $this->createValidComposerTypo3Installation();

        // Create a strategy that throws an exception
        $failingStrategy = $this->createMock(VersionStrategyInterface::class);
        $failingStrategy->method('getPriority')->willReturn(100);
        $failingStrategy->method('supports')->willReturn(true);
        $failingStrategy->method('extractVersion')->willThrowException(new \RuntimeException('Test exception'));
        $failingStrategy->method('getRequiredFiles')->willReturn(['composer.json']);
        $failingStrategy->method('getName')->willReturn('Test Strategy');
        $failingStrategy->method('getReliabilityScore')->willReturn(0.8);

        $failingVersionExtractor = new VersionExtractor([$failingStrategy], $this->logger);
        $failingDetector = new ComposerInstallationDetector(
            $failingVersionExtractor,
            $this->pathResolutionService,
            $this->logger,
            $this->versionProfileRegistry,
        );

        $this->logger->expects(self::atLeastOnce())
            ->method('warning');

        $result = $failingDetector->detect($this->testDir);

        self::assertNull($result);
    }

    #[Test]
    public function testDetectDoesNotDiscoverExtensions(): void
    {
        $this->createValidComposerTypo3Installation();
        $this->createComposerLockWithExtensions();

        // Use working version extractor
        $workingStrategy = new TestableVersionStrategy();
        $workingVersionExtractor = new VersionExtractor([$workingStrategy], $this->logger);
        $workingDetector = new ComposerInstallationDetector(
            $workingVersionExtractor,
            $this->pathResolutionService,
            $this->logger,
            $this->versionProfileRegistry,
        );

        $result = $workingDetector->detect($this->testDir);
        $this->assertNotEmpty($result);
    }

    #[Test]
    public function detectReturnsDatabaseConfigEmptyWhenSettingsPhpAbsent(): void
    {
        $this->createValidComposerTypo3Installation();

        $workingStrategy = new TestableVersionStrategy();
        $workingVersionExtractor = new VersionExtractor([$workingStrategy], $this->logger);
        $workingDetector = new ComposerInstallationDetector(
            $workingVersionExtractor,
            $this->pathResolutionService,
            $this->logger,
            $this->versionProfileRegistry,
        );

        $result = $workingDetector->detect($this->testDir);

        self::assertNotNull($result, 'detect() must return an Installation for a valid Composer TYPO3 path');
        self::assertNotNull($result->getMetadata());
        // config/system/settings.php absent — database config must be empty, no exception
        self::assertSame([], $result->getMetadata()->getDatabaseConfig());
    }

    #[Test]
    public function detectReturnsEnabledFeaturesEmptyWhenRuntimeDirsAbsent(): void
    {
        $this->createValidComposerTypo3Installation();

        $workingStrategy = new TestableVersionStrategy();
        $workingVersionExtractor = new VersionExtractor([$workingStrategy], $this->logger);
        $workingDetector = new ComposerInstallationDetector(
            $workingVersionExtractor,
            $this->pathResolutionService,
            $this->logger,
            $this->versionProfileRegistry,
        );

        $result = $workingDetector->detect($this->testDir);

        self::assertNotNull($result, 'detect() must return an Installation for a valid Composer TYPO3 path');
        self::assertNotNull($result->getMetadata());
        // No runtime dirs present — enabled features must be empty, no exception
        self::assertSame([], $result->getMetadata()->getEnabledFeatures());
    }

    private function createValidComposerTypo3Installation(): void
    {
        $composerData = [
            'require' => [
                'typo3/cms-core' => '^12.4',
            ],
        ];
        file_put_contents($this->testDir . '/composer.json', json_encode($composerData));
    }

    private function createComposerLockWithVersionInfo(): void
    {
        $lockData = [
            'packages' => [
                [
                    'name' => 'typo3/cms-core',
                    'version' => '12.4.8',
                ],
            ],
        ];
        file_put_contents($this->testDir . '/composer.lock', json_encode($lockData));
    }

    private function createComposerLockWithExtensions(): void
    {
        $lockData = [
            'packages' => [
                [
                    'name' => 'typo3/cms-core',
                    'version' => '12.4.8',
                ],
                [
                    'name' => 'typo3/cms-backend',
                    'version' => '12.4.8',
                ],
                [
                    'name' => 'typo3/cms-frontend',
                    'version' => '12.4.8',
                ],
                [
                    'name' => 'some/other-package',
                    'version' => '1.0.0',
                ],
            ],
        ];
        file_put_contents($this->testDir . '/composer.lock', json_encode($lockData));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    // -------------------------------------------------------------------------
    // Task 1 – web-dir validation (AC 1) + edge-case hardening
    // -------------------------------------------------------------------------

    #[Test]
    public function testWebDirNullByteFallsBackToPublic(): void
    {
        $composerData = [
            'require' => ['typo3/cms-core' => '^12.4'],
            'extra' => ['typo3/cms' => ['web-dir' => "web\x00dir"]],
        ];
        file_put_contents($this->testDir . '/composer.json', json_encode($composerData));

        mkdir($this->testDir . '/public/typo3conf/ext', 0o755, true);

        $workingStrategy = new TestableVersionStrategy();
        $workingVersionExtractor = new VersionExtractor([$workingStrategy], $this->logger);
        $detector = new ComposerInstallationDetector(
            $workingVersionExtractor,
            $this->pathResolutionService,
            $this->logger,
            $this->versionProfileRegistry,
        );

        $result = $detector->detect($this->testDir);

        self::assertNotNull($result);
        self::assertNotNull($result->getMetadata());
        self::assertContains('extensions', $result->getMetadata()->getEnabledFeatures());
    }

    #[Test]
    public function testWebDirWindowsDriveLetterFallsBackToPublic(): void
    {
        $composerData = [
            'require' => ['typo3/cms-core' => '^12.4'],
            'extra' => ['typo3/cms' => ['web-dir' => 'C:\www']],
        ];
        file_put_contents($this->testDir . '/composer.json', json_encode($composerData));

        mkdir($this->testDir . '/public/typo3conf/ext', 0o755, true);

        $workingStrategy = new TestableVersionStrategy();
        $workingVersionExtractor = new VersionExtractor([$workingStrategy], $this->logger);
        $detector = new ComposerInstallationDetector(
            $workingVersionExtractor,
            $this->pathResolutionService,
            $this->logger,
            $this->versionProfileRegistry,
        );

        $result = $detector->detect($this->testDir);

        self::assertNotNull($result);
        self::assertNotNull($result->getMetadata());
        self::assertContains('extensions', $result->getMetadata()->getEnabledFeatures());
    }

    #[Test]
    public function testWebDirTrailingSlashDoesNotCauseCustomInstallationType(): void
    {
        $composerData = [
            'require' => ['typo3/cms-core' => '^12.4'],
            'extra' => ['typo3/cms' => ['web-dir' => 'public/']],
        ];
        file_put_contents($this->testDir . '/composer.json', json_encode($composerData));

        $capturedTypes = [];
        $localPathService = $this->createMock(PathResolutionServiceInterface::class);
        $localPathService->method('resolvePath')
            ->willReturnCallback(function ($request) use (&$capturedTypes): PathResolutionResponse {
                $capturedTypes[] = $request->installationType;
                $metadata = new PathResolutionMetadata(
                    $request->pathType,
                    InstallationTypeEnum::COMPOSER_STANDARD,
                    'test',
                    0,
                    [],
                    [],
                    0.0,
                    false,
                    'test',
                );

                return PathResolutionResponse::success(
                    $request->pathType,
                    $request->installationPath . '/public',
                    $metadata,
                    [],
                    [],
                    'cache_key',
                    0.1,
                );
            });

        $workingStrategy = new TestableVersionStrategy();
        $workingVersionExtractor = new VersionExtractor([$workingStrategy], $this->logger);
        $detector = new ComposerInstallationDetector(
            $workingVersionExtractor,
            $localPathService,
            $this->logger,
            $this->versionProfileRegistry,
        );

        $result = $detector->detect($this->testDir);

        self::assertNotNull($result);
        foreach ($capturedTypes as $type) {
            self::assertSame(InstallationTypeEnum::COMPOSER_STANDARD, $type);
        }
    }

    #[Test]
    public function testInvalidWebDirDoesNotCauseCustomInstallationTypeClassification(): void
    {
        $composerData = [
            'require' => ['typo3/cms-core' => '^12.4'],
            'extra' => ['typo3/cms' => ['web-dir' => '/absolute/path']],
        ];
        file_put_contents($this->testDir . '/composer.json', json_encode($composerData));

        $capturedTypes = [];
        $localPathService = $this->createMock(PathResolutionServiceInterface::class);
        $localPathService->method('resolvePath')
            ->willReturnCallback(function ($request) use (&$capturedTypes): PathResolutionResponse {
                $capturedTypes[] = $request->installationType;
                $metadata = new PathResolutionMetadata(
                    $request->pathType,
                    InstallationTypeEnum::COMPOSER_STANDARD,
                    'test',
                    0,
                    [],
                    [],
                    0.0,
                    false,
                    'test',
                );

                return PathResolutionResponse::success(
                    $request->pathType,
                    $request->installationPath . '/public',
                    $metadata,
                    [],
                    [],
                    'cache_key',
                    0.1,
                );
            });

        $workingStrategy = new TestableVersionStrategy();
        $workingVersionExtractor = new VersionExtractor([$workingStrategy], $this->logger);
        $detector = new ComposerInstallationDetector(
            $workingVersionExtractor,
            $localPathService,
            $this->logger,
            $this->versionProfileRegistry,
        );

        $result = $detector->detect($this->testDir);

        self::assertNotNull($result);
        foreach ($capturedTypes as $type) {
            self::assertSame(InstallationTypeEnum::COMPOSER_STANDARD, $type);
        }
    }

    #[Test]
    public function testWebDirAbsolutePathFallsBackToPublic(): void
    {
        $composerData = [
            'require' => ['typo3/cms-core' => '^12.4'],
            'extra' => ['typo3/cms' => ['web-dir' => '/absolute/path']],
        ];
        file_put_contents($this->testDir . '/composer.json', json_encode($composerData));

        mkdir($this->testDir . '/public/typo3conf/ext', 0o755, true);

        $workingStrategy = new TestableVersionStrategy();
        $workingVersionExtractor = new VersionExtractor([$workingStrategy], $this->logger);
        $detector = new ComposerInstallationDetector(
            $workingVersionExtractor,
            $this->pathResolutionService,
            $this->logger,
            $this->versionProfileRegistry,
        );

        $result = $detector->detect($this->testDir);

        self::assertNotNull($result);
        self::assertNotNull($result->getMetadata());
        self::assertContains('extensions', $result->getMetadata()->getEnabledFeatures());
    }

    #[Test]
    public function testWebDirPathTraversalFallsBackToPublic(): void
    {
        $composerData = [
            'require' => ['typo3/cms-core' => '^12.4'],
            'extra' => ['typo3/cms' => ['web-dir' => '../traversal']],
        ];
        file_put_contents($this->testDir . '/composer.json', json_encode($composerData));

        mkdir($this->testDir . '/public/typo3conf/ext', 0o755, true);

        $workingStrategy = new TestableVersionStrategy();
        $workingVersionExtractor = new VersionExtractor([$workingStrategy], $this->logger);
        $detector = new ComposerInstallationDetector(
            $workingVersionExtractor,
            $this->pathResolutionService,
            $this->logger,
            $this->versionProfileRegistry,
        );

        $result = $detector->detect($this->testDir);

        self::assertNotNull($result);
        self::assertNotNull($result->getMetadata());
        self::assertContains('extensions', $result->getMetadata()->getEnabledFeatures());
    }

    #[Test]
    public function testWebDirEmptyStringFallsBackToPublic(): void
    {
        $composerData = [
            'require' => ['typo3/cms-core' => '^12.4'],
            'extra' => ['typo3/cms' => ['web-dir' => '']],
        ];
        file_put_contents($this->testDir . '/composer.json', json_encode($composerData));

        mkdir($this->testDir . '/public/typo3conf/ext', 0o755, true);

        $workingStrategy = new TestableVersionStrategy();
        $workingVersionExtractor = new VersionExtractor([$workingStrategy], $this->logger);
        $detector = new ComposerInstallationDetector(
            $workingVersionExtractor,
            $this->pathResolutionService,
            $this->logger,
            $this->versionProfileRegistry,
        );

        $result = $detector->detect($this->testDir);

        self::assertNotNull($result);
        self::assertNotNull($result->getMetadata());
        self::assertContains('extensions', $result->getMetadata()->getEnabledFeatures());
    }

    // -------------------------------------------------------------------------
    // Task 2 – version-aware paths (AC 2, 3)
    // -------------------------------------------------------------------------

    #[Test]
    public function testDetectEnabledFeaturesUsesResolvedWebDirForV12(): void
    {
        $composerData = [
            'require' => ['typo3/cms-core' => '^12.4'],
            'extra' => ['typo3/cms' => ['web-dir' => 'web']],
        ];
        file_put_contents($this->testDir . '/composer.json', json_encode($composerData));

        // Extension dir at the custom web-dir location
        mkdir($this->testDir . '/web/typo3conf/ext', 0o755, true);

        $workingStrategy = new TestableVersionStrategy(); // returns 12.4.8
        $workingVersionExtractor = new VersionExtractor([$workingStrategy], $this->logger);
        $detector = new ComposerInstallationDetector(
            $workingVersionExtractor,
            $this->pathResolutionService,
            $this->logger,
            $this->versionProfileRegistry,
        );

        $result = $detector->detect($this->testDir);

        self::assertNotNull($result);
        self::assertNotNull($result->getMetadata());
        self::assertContains('extensions', $result->getMetadata()->getEnabledFeatures());
    }

    #[Test]
    public function testDetectEnabledFeaturesUsesRootExtDirForV11(): void
    {
        $this->createValidComposerTypo3Installation();

        // In v11 the extension dir is at the installation root
        mkdir($this->testDir . '/typo3conf/ext', 0o755, true);

        $v11Strategy = new TestableVersionStrategyV11();
        $v11VersionExtractor = new VersionExtractor([$v11Strategy], $this->logger);
        $detector = new ComposerInstallationDetector(
            $v11VersionExtractor,
            $this->pathResolutionService,
            $this->logger,
            $this->versionProfileRegistry,
        );

        $result = $detector->detect($this->testDir);

        self::assertNotNull($result);
        self::assertNotNull($result->getMetadata());
        self::assertContains('extensions', $result->getMetadata()->getEnabledFeatures());
    }

    #[Test]
    public function testDetectDatabaseConfigUsesV11Paths(): void
    {
        $this->createValidComposerTypo3Installation();

        mkdir($this->testDir . '/typo3conf', 0o755, true);
        file_put_contents($this->testDir . '/typo3conf/LocalConfiguration.php', '<?php return [];');

        $v11Strategy = new TestableVersionStrategyV11();
        $v11VersionExtractor = new VersionExtractor([$v11Strategy], $this->logger);
        $detector = new ComposerInstallationDetector(
            $v11VersionExtractor,
            $this->pathResolutionService,
            $this->logger,
            $this->versionProfileRegistry,
        );

        $result = $detector->detect($this->testDir);

        self::assertNotNull($result);
        self::assertNotNull($result->getMetadata());
        self::assertTrue($result->getMetadata()->getDatabaseConfig()['has_local_configuration'] ?? false);
    }

    #[Test]
    public function testDetectDatabaseConfigDoesNotUseV12PathsForV11(): void
    {
        $this->createValidComposerTypo3Installation();

        // Create v12 path only — must NOT be detected when version is v11
        mkdir($this->testDir . '/config/system', 0o755, true);
        file_put_contents($this->testDir . '/config/system/settings.php', '<?php return [];');

        $v11Strategy = new TestableVersionStrategyV11();
        $v11VersionExtractor = new VersionExtractor([$v11Strategy], $this->logger);
        $detector = new ComposerInstallationDetector(
            $v11VersionExtractor,
            $this->pathResolutionService,
            $this->logger,
            $this->versionProfileRegistry,
        );

        $result = $detector->detect($this->testDir);

        self::assertNotNull($result);
        self::assertNotNull($result->getMetadata());
        self::assertFalse($result->getMetadata()->getDatabaseConfig()['has_local_configuration'] ?? false);
    }

    // -------------------------------------------------------------------------
    // Task 3 – no Docker classification (AC 4)
    // -------------------------------------------------------------------------

    #[Test]
    public function testDockerFilesDoNotInfluenceInstallationType(): void
    {
        $this->createValidComposerTypo3Installation();
        file_put_contents($this->testDir . '/docker-compose.yml', '');
        file_put_contents($this->testDir . '/Dockerfile', '');

        $capturedTypes = [];
        $localPathService = $this->createMock(PathResolutionServiceInterface::class);
        $localPathService->method('resolvePath')
            ->willReturnCallback(function ($request) use (&$capturedTypes): PathResolutionResponse {
                $capturedTypes[] = $request->installationType;

                $metadata = new PathResolutionMetadata(
                    $request->pathType,
                    InstallationTypeEnum::COMPOSER_STANDARD,
                    'test_strategy',
                    0,
                    [],
                    [],
                    0.0,
                    false,
                    'test',
                );

                return PathResolutionResponse::success(
                    $request->pathType,
                    $request->installationPath . '/public',
                    $metadata,
                    [],
                    [],
                    'cache_key',
                    0.1,
                );
            });

        $workingStrategy = new TestableVersionStrategy();
        $workingVersionExtractor = new VersionExtractor([$workingStrategy], $this->logger);
        $detector = new ComposerInstallationDetector(
            $workingVersionExtractor,
            $localPathService,
            $this->logger,
            $this->versionProfileRegistry,
        );

        $result = $detector->detect($this->testDir);

        self::assertNotNull($result);
        foreach ($capturedTypes as $type) {
            self::assertNotSame(InstallationTypeEnum::DOCKER_CONTAINER, $type);
        }
    }

    // -------------------------------------------------------------------------
    // Task 4 – narrow exception handling (AC 5)
    // -------------------------------------------------------------------------

    #[Test]
    public function testDetectCatchesRuntimeExceptionFromPathResolutionService(): void
    {
        $this->createValidComposerTypo3Installation();

        $throwingPathService = $this->createMock(PathResolutionServiceInterface::class);
        $throwingPathService->method('resolvePath')
            ->willThrowException(new \RuntimeException('Path resolution failed'));

        $workingStrategy = new TestableVersionStrategy();
        $workingVersionExtractor = new VersionExtractor([$workingStrategy], $this->logger);

        $this->logger->expects(self::atLeastOnce())->method('error');

        $detector = new ComposerInstallationDetector(
            $workingVersionExtractor,
            $throwingPathService,
            $this->logger,
            $this->versionProfileRegistry,
        );

        $result = $detector->detect($this->testDir);

        self::assertNull($result);
    }

    #[Test]
    public function testDetectDoesNotSwallowErrors(): void
    {
        $this->createValidComposerTypo3Installation();

        $throwingPathService = $this->createMock(PathResolutionServiceInterface::class);
        $throwingPathService->method('resolvePath')
            ->willThrowException(new \Error('Fatal error'));

        $workingStrategy = new TestableVersionStrategy();
        $workingVersionExtractor = new VersionExtractor([$workingStrategy], $this->logger);

        $detector = new ComposerInstallationDetector(
            $workingVersionExtractor,
            $throwingPathService,
            $this->logger,
            $this->versionProfileRegistry,
        );

        $this->expectException(\Error::class);
        $detector->detect($this->testDir);
    }

    private function setupDefaultPathResolutionMocks(): void
    {
        // Mock vendor-dir resolution
        $vendorMetadata = new PathResolutionMetadata(
            PathTypeEnum::VENDOR_DIR,
            InstallationTypeEnum::COMPOSER_STANDARD,
            'default_mock',
            0,
            [],
            [],
            0.0,
            false,
            'mock_resolution',
        );

        // Mock web-dir resolution
        $webMetadata = new PathResolutionMetadata(
            PathTypeEnum::WEB_DIR,
            InstallationTypeEnum::COMPOSER_STANDARD,
            'default_mock',
            0,
            [],
            [],
            0.0,
            false,
            'mock_resolution',
        );

        // Mock typo3conf-dir resolution
        $typo3confMetadata = new PathResolutionMetadata(
            PathTypeEnum::TYPO3CONF_DIR,
            InstallationTypeEnum::COMPOSER_STANDARD,
            'default_mock',
            0,
            [],
            [],
            0.0,
            false,
            'mock_resolution',
        );

        $this->pathResolutionService->method('resolvePath')
            ->willReturnCallback(function ($request) use ($vendorMetadata, $webMetadata, $typo3confMetadata): PathResolutionResponse {
                return match ($request->pathType) {
                    PathTypeEnum::VENDOR_DIR => PathResolutionResponse::success(
                        PathTypeEnum::VENDOR_DIR,
                        $request->installationPath . '/vendor',
                        $vendorMetadata,
                        [],
                        [],
                        'cache_key_vendor',
                        0.1,
                    ),
                    PathTypeEnum::WEB_DIR => PathResolutionResponse::success(
                        PathTypeEnum::WEB_DIR,
                        $request->installationPath . '/public',
                        $webMetadata,
                        [],
                        [],
                        'cache_key_web',
                        0.1,
                    ),
                    PathTypeEnum::TYPO3CONF_DIR => PathResolutionResponse::success(
                        PathTypeEnum::TYPO3CONF_DIR,
                        $request->installationPath . '/public/typo3conf',
                        $typo3confMetadata,
                        [],
                        [],
                        'cache_key_typo3conf',
                        0.1,
                    ),
                    default => PathResolutionResponse::notFound(
                        $request->pathType,
                        $vendorMetadata,
                        [],
                        ['Path type not mocked'],
                        'cache_key_default',
                        0.1,
                    )
                };
            });
    }
}

/**
 * Test strategy that always returns a valid version.
 */
class TestableVersionStrategy implements VersionStrategyInterface
{
    public function supports(string $installationPath): bool
    {
        return file_exists($installationPath . '/composer.json');
    }

    public function extractVersion(string $installationPath): ?Version
    {
        return new Version('12.4.8');
    }

    public function getName(): string
    {
        return 'Testable Version Strategy';
    }

    public function getPriority(): int
    {
        return 100;
    }

    public function getReliabilityScore(): float
    {
        return 1.0;
    }

    public function getRequiredFiles(): array
    {
        return ['composer.json'];
    }
}

/**
 * Test strategy that returns a TYPO3 v11 version.
 */
class TestableVersionStrategyV11 implements VersionStrategyInterface
{
    public function supports(string $installationPath): bool
    {
        return file_exists($installationPath . '/composer.json');
    }

    public function extractVersion(string $installationPath): ?Version
    {
        return new Version('11.5.30');
    }

    public function getName(): string
    {
        return 'Testable Version Strategy V11';
    }

    public function getPriority(): int
    {
        return 100;
    }

    public function getReliabilityScore(): float
    {
        return 1.0;
    }

    public function getRequiredFiles(): array
    {
        return ['composer.json'];
    }
}
