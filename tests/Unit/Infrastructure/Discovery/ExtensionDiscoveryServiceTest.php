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

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryResult;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ExtensionDiscoveryServiceTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private ConfigurationService&MockObject $configService;
    private CacheService&MockObject $cacheService;
    private ExtensionDiscoveryService $service;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->configService = $this->createMock(ConfigurationService::class);
        $this->cacheService = $this->createMock(CacheService::class);

        $this->service = new ExtensionDiscoveryService(
            $this->logger,
            $this->configService,
            $this->cacheService,
        );

        $this->tempDir = sys_get_temp_dir() . '/ext_discovery_test_' . uniqid();
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
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

    private function createPackageStatesFile(array $packages): string
    {
        $packageStatesPath = $this->tempDir . '/public/typo3conf/PackageStates.php';
        mkdir(\dirname($packageStatesPath), 0o755, true);

        $content = "<?php\nreturn " . var_export(['packages' => $packages], true) . ";\n";
        file_put_contents($packageStatesPath, $content);

        return $packageStatesPath;
    }

    private function createComposerInstalledFile(array $packages): string
    {
        $installedJsonPath = $this->tempDir . '/vendor/composer/installed.json';
        mkdir(\dirname($installedJsonPath), 0o755, true);

        $content = json_encode(['packages' => $packages], JSON_PRETTY_PRINT);
        file_put_contents($installedJsonPath, $content);

        return $installedJsonPath;
    }

    private function createExtEmconfFile(string $extensionKey, string $extensionPath, array $config): void
    {
        $emconfPath = $extensionPath . '/ext_emconf.php';
        mkdir(\dirname($emconfPath), 0o755, true);

        $content = "<?php\n\$EM_CONF['" . $extensionKey . "'] = " . var_export($config, true) . ";\n";
        file_put_contents($emconfPath, $content);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::__construct
     */
    public function testConstructorWithoutOptionalServices(): void
    {
        $service = new ExtensionDiscoveryService($this->logger, $this->configService, $this->cacheService);
        $this->assertInstanceOf(ExtensionDiscoveryService::class, $service);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testDiscoverExtensionsWithEmptyInstallation(): void
    {
        // Allow any logging calls - we focus on testing functionality, not logging details
        $this->logger->expects($this->any())->method('info');
        $this->logger->expects($this->any())->method('debug');

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertInstanceOf(ExtensionDiscoveryResult::class, $result);
        $this->assertTrue($result->isSuccessful());
        $this->assertCount(0, $result->getExtensions());
        $this->assertCount(0, $result->getSuccessfulMethods());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testDiscoverExtensionsFromPackageStatesOnly(): void
    {
        $packages = [
            'news' => [
                'packagePath' => 'typo3conf/ext/news/',
                'state' => 'active',
            ],
            'tt_address' => [
                'packagePath' => 'typo3conf/ext/tt_address/',
                'state' => 'inactive',
            ],
            'typo3/cms-backend' => [
                'packagePath' => 'typo3/sysext/backend/',
                'state' => 'active',
            ],
        ];

        $this->createPackageStatesFile($packages);

        // Create ext_emconf.php files
        $this->createExtEmconfFile('news', $this->tempDir . '/public/typo3conf/ext/news', [
            'title' => 'News System',
            'version' => '10.0.0',
        ]);
        $this->createExtEmconfFile('tt_address', $this->tempDir . '/public/typo3conf/ext/tt_address', [
            'title' => 'Address Management',
            'version' => '7.1.0',
        ]);

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(2, $result->getExtensions());
        $this->assertContains('PackageStates.php', $result->getSuccessfulMethods());

        $extensions = $result->getExtensions();
        $newsExtension = null;
        $addressExtension = null;

        foreach ($extensions as $extension) {
            if ('news' === $extension->getKey()) {
                $newsExtension = $extension;
            } elseif ('tt_address' === $extension->getKey()) {
                $addressExtension = $extension;
            }
        }

        $this->assertNotNull($newsExtension);
        $this->assertSame('News System', $newsExtension->getTitle());
        $this->assertSame('10.0.0', $newsExtension->getVersion()->toString());
        $this->assertTrue($newsExtension->isActive());
        $this->assertSame('local', $newsExtension->getType());

        $this->assertNotNull($addressExtension);
        $this->assertSame('Address Management', $addressExtension->getTitle());
        $this->assertSame('7.1.0', $addressExtension->getVersion()->toString());
        $this->assertFalse($addressExtension->isActive());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testDiscoverExtensionsFromComposerOnly(): void
    {
        $packages = [
            [
                'name' => 'georgringer/news',
                'type' => 'typo3-cms-extension',
                'version' => '10.0.0',
                'description' => 'Versatile news extension',
                'extra' => [
                    'typo3/cms' => [
                        'extension-key' => 'news',
                    ],
                ],
            ],
            [
                'name' => 'friendsoftypo3/tt-address',
                'type' => 'typo3-cms-extension',
                'version' => '7.1.0',
                'description' => 'Address management',
            ],
            [
                'name' => 'typo3/cms-backend',
                'type' => 'typo3-cms-framework',
                'version' => '12.4.0',
            ],
        ];

        $this->createComposerInstalledFile($packages);

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(2, $result->getExtensions());
        $this->assertContains('composer installed.json', $result->getSuccessfulMethods());

        $extensions = $result->getExtensions();
        $newsExtension = null;
        $addressExtension = null;

        foreach ($extensions as $extension) {
            if ('news' === $extension->getKey()) {
                $newsExtension = $extension;
            } elseif ('tt_address' === $extension->getKey()) {
                $addressExtension = $extension;
            }
        }

        $this->assertNotNull($newsExtension);
        $this->assertSame('georgringer/news', $newsExtension->getComposerName());
        $this->assertSame('Versatile news extension', $newsExtension->getTitle());
        $this->assertTrue($newsExtension->isActive());

        $this->assertNotNull($addressExtension);
        $this->assertSame('friendsoftypo3/tt-address', $addressExtension->getComposerName());
        $this->assertSame('tt_address', $addressExtension->getKey());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testDiscoverExtensionsFromBothSources(): void
    {
        // Package States with one extension
        $packages = [
            'local_extension' => [
                'packagePath' => 'typo3conf/ext/local_extension/',
                'state' => 'active',
            ],
            'news' => [
                'packagePath' => 'typo3conf/ext/news/',
                'state' => 'active',
            ],
        ];
        $this->createPackageStatesFile($packages);

        // Create ext_emconf.php for local extension
        $this->createExtEmconfFile('local_extension', $this->tempDir . '/public/typo3conf/ext/local_extension', [
            'title' => 'Local Extension',
            'version' => '1.0.0',
        ]);
        $this->createExtEmconfFile('news', $this->tempDir . '/public/typo3conf/ext/news', [
            'title' => 'News from PackageStates',
            'version' => '9.0.0',
        ]);

        // Composer installed with overlapping and unique extension
        $composerPackages = [
            [
                'name' => 'georgringer/news',
                'type' => 'typo3-cms-extension',
                'version' => '10.0.0',
                'description' => 'News from Composer',
                'extra' => [
                    'typo3/cms' => [
                        'extension-key' => 'news',
                    ],
                ],
            ],
            [
                'name' => 'vendor/composer-only',
                'type' => 'typo3-cms-extension',
                'version' => '2.0.0',
                'description' => 'Composer only extension',
            ],
        ];
        $this->createComposerInstalledFile($composerPackages);

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(3, $result->getExtensions()); // local_extension, news (from PackageStates), composer_only
        $this->assertContains('PackageStates.php', $result->getSuccessfulMethods());
        $this->assertContains('composer installed.json', $result->getSuccessfulMethods());

        $extensionKeys = array_map(fn ($ext): string => $ext->getKey(), $result->getExtensions());
        $this->assertContains('local_extension', $extensionKeys);
        $this->assertContains('news', $extensionKeys);
        $this->assertContains('composer_only', $extensionKeys);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testDiscoverExtensionsWithCustomPaths(): void
    {
        $customPaths = [
            'vendor-dir' => 'custom-vendor',
            'web-dir' => 'web',
            'typo3conf-dir' => 'web/conf',
        ];

        $packages = [
            'custom_ext' => [
                'packagePath' => 'web/conf/ext/custom_ext/',
                'state' => 'active',
            ],
        ];

        // Create with custom paths
        $packageStatesPath = $this->tempDir . '/web/conf/PackageStates.php';
        mkdir(\dirname($packageStatesPath), 0o755, true);
        $content = "<?php\nreturn " . var_export(['packages' => $packages], true) . ";\n";
        file_put_contents($packageStatesPath, $content);

        $this->createExtEmconfFile('custom_ext', $this->tempDir . '/web/conf/ext/custom_ext', [
            'title' => 'Custom Extension',
            'version' => '1.0.0',
        ]);

        $result = $this->service->discoverExtensions($this->tempDir, $customPaths);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(1, $result->getExtensions());
        $this->assertSame('custom_ext', $result->getExtensions()[0]->getKey());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testDiscoverExtensionsWithCacheEnabled(): void
    {
        $this->configService->expects($this->exactly(2))
            ->method('isResultCacheEnabled')
            ->willReturn(true);

        $this->cacheService->expects($this->exactly(2))
            ->method('generateKey')
            ->with('extension_discovery', $this->tempDir, ['custom_paths' => []])
            ->willReturn('cache_key_123');

        $this->cacheService->expects($this->once())
            ->method('get')
            ->with('cache_key_123')
            ->willReturn(null);

        $this->cacheService->expects($this->once())
            ->method('set')
            ->with('cache_key_123', $this->callback(function ($data): bool {
                return \is_array($data) && isset($data['cached_at']);
            }))
            ->willReturn(true);

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testDiscoverExtensionsWithCacheHit(): void
    {
        $cachedData = [
            'successful' => true,
            'extensions' => [
                [
                    'key' => 'cached_extension',
                    'title' => 'Cached Extension',
                    'version' => '1.0.0',
                    'type' => 'local',
                    'composer_name' => null,
                    'dependencies' => [],
                    'conflicts' => [],
                    'files' => [],
                    'repository_url' => null,
                    'em_configuration' => [],
                    'metadata' => null,
                    'is_active' => true,
                ],
            ],
            'successful_methods' => ['PackageStates.php'],
            'discovery_metadata' => [],
            'cached_at' => time(),
        ];

        $this->configService->expects($this->once())
            ->method('isResultCacheEnabled')
            ->willReturn(true);

        $this->cacheService->expects($this->once())
            ->method('generateKey')
            ->willReturn('cache_key_123');

        $this->cacheService->expects($this->once())
            ->method('get')
            ->with('cache_key_123')
            ->willReturn($cachedData);

        // Allow any logging calls for this test - focus on functionality
        $this->logger->expects($this->any())->method('debug');
        $this->logger->expects($this->any())->method('info');

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(1, $result->getExtensions());
        $this->assertSame('cached_extension', $result->getExtensions()[0]->getKey());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testDiscoverExtensionsWithCacheDisabled(): void
    {
        $this->configService->expects($this->exactly(2))
            ->method('isResultCacheEnabled')
            ->willReturn(false);

        $this->cacheService->expects($this->never())
            ->method('generateKey');

        $this->cacheService->expects($this->never())
            ->method('get');

        $this->cacheService->expects($this->never())
            ->method('set');

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testDiscoverExtensionsWithServiceException(): void
    {
        // Create an invalid PackageStates.php that will cause parsing to fail
        $packageStatesPath = $this->tempDir . '/public/typo3conf/PackageStates.php';
        mkdir(\dirname($packageStatesPath), 0o755, true);
        file_put_contents($packageStatesPath, '<?php throw new Exception("Parsing error");');

        // Create an invalid composer installed.json that will also fail
        $installedJsonPath = $this->tempDir . '/vendor/composer/installed.json';
        mkdir(\dirname($installedJsonPath), 0o755, true);
        file_put_contents($installedJsonPath, '{"packages": [invalid json');

        // Allow any error logging calls for this test - focus on functionality
        $this->logger->expects($this->any())->method('error');
        $this->logger->expects($this->any())->method('info');
        $this->logger->expects($this->any())->method('debug');

        $result = $this->service->discoverExtensions($this->tempDir);

        // Even with both methods failing, the service should still return a successful result
        // because it gracefully handles parsing errors and returns an empty result
        $this->assertTrue($result->isSuccessful());
        $this->assertCount(0, $result->getExtensions());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testDiscoverExtensionsWithInvalidPackageStates(): void
    {
        // Create invalid PackageStates.php content
        $packageStatesPath = $this->tempDir . '/public/typo3conf/PackageStates.php';
        mkdir(\dirname($packageStatesPath), 0o755, true);
        file_put_contents($packageStatesPath, '<?php return "invalid";');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Invalid PackageStates.php format');

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(0, $result->getExtensions());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testDiscoverExtensionsWithInvalidComposerInstalled(): void
    {
        // Create invalid composer installed.json content
        $installedJsonPath = $this->tempDir . '/vendor/composer/installed.json';
        mkdir(\dirname($installedJsonPath), 0o755, true);
        file_put_contents($installedJsonPath, '{"invalid": "format"}');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Invalid composer installed.json format');

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(0, $result->getExtensions());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testDiscoverExtensionsWithCorruptedJson(): void
    {
        // Create corrupted JSON file
        $installedJsonPath = $this->tempDir . '/vendor/composer/installed.json';
        mkdir(\dirname($installedJsonPath), 0o755, true);
        file_put_contents($installedJsonPath, '{"packages": [invalid json');

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to parse composer installed.json', $this->callback(function ($context): bool {
                return isset($context['error']);
            }));

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(0, $result->getExtensions());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testExtensionKeyExtractionFromComposerName(): void
    {
        $packages = [
            [
                'name' => 'vendor/extension-name',
                'type' => 'typo3-cms-extension',
                'version' => '1.0.0',
            ],
            [
                'name' => 'vendor/my-awesome-extension',
                'type' => 'typo3-cms-extension',
                'version' => '2.0.0',
                'extra' => [
                    'typo3/cms' => [
                        'extension-key' => 'my_awesome_ext',
                    ],
                ],
            ],
        ];

        $this->createComposerInstalledFile($packages);

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(2, $result->getExtensions());

        $extensionKeys = array_map(fn ($ext): string => $ext->getKey(), $result->getExtensions());
        $this->assertContains('extension_name', $extensionKeys);
        $this->assertContains('my_awesome_ext', $extensionKeys);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testExtensionTypeDetection(): void
    {
        $packages = [
            'system_ext' => [
                'packagePath' => 'typo3/sysext/system_ext/',
                'state' => 'active',
            ],
            'local_ext' => [
                'packagePath' => 'typo3conf/ext/local_ext/',
                'state' => 'active',
            ],
            'composer_ext' => [
                'packagePath' => 'vendor/vendor/composer_ext/',
                'state' => 'active',
            ],
        ];

        $this->createPackageStatesFile($packages);

        // Create ext_emconf.php files
        foreach (['system_ext', 'local_ext', 'composer_ext'] as $key) {
            $path = $packages[$key]['packagePath'];
            $this->createExtEmconfFile($key, $this->tempDir . '/' . $path, [
                'title' => ucfirst($key),
                'version' => '1.0.0',
            ]);
        }

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(3, $result->getExtensions());

        $extensionsByKey = [];
        foreach ($result->getExtensions() as $extension) {
            $extensionsByKey[$extension->getKey()] = $extension;
        }

        $this->assertSame('system', $extensionsByKey['system_ext']->getType());
        $this->assertSame('local', $extensionsByKey['local_ext']->getType());
        $this->assertSame('composer', $extensionsByKey['composer_ext']->getType());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testHandlingMissingExtEmconfFile(): void
    {
        $packages = [
            'extension_without_emconf' => [
                'packagePath' => 'typo3conf/ext/extension_without_emconf/',
                'state' => 'active',
            ],
        ];

        $this->createPackageStatesFile($packages);
        // Note: Not creating ext_emconf.php file

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(1, $result->getExtensions());

        $extension = $result->getExtensions()[0];
        $this->assertSame('extension_without_emconf', $extension->getKey());
        $this->assertSame('extension_without_emconf', $extension->getTitle());
        $this->assertSame('0.0.0', $extension->getVersion()->toString());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testHandlingPackageWithoutPath(): void
    {
        $packages = [
            'extension_without_path' => [
                'state' => 'active',
                // Missing packagePath
            ],
        ];

        $this->createPackageStatesFile($packages);

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(0, $result->getExtensions());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testHandlingCorruptedExtEmconfFile(): void
    {
        $packages = [
            'corrupted_emconf' => [
                'packagePath' => 'typo3conf/ext/corrupted_emconf/',
                'state' => 'active',
            ],
        ];

        $this->createPackageStatesFile($packages);

        // Create corrupted ext_emconf.php
        $emconfPath = $this->tempDir . '/public/typo3conf/ext/corrupted_emconf/ext_emconf.php';
        mkdir(\dirname($emconfPath), 0o755, true);
        file_put_contents($emconfPath, '<?php throw new Exception("Corrupted emconf");');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Failed to create extension from package data', $this->callback(function ($context): bool {
                return 'corrupted_emconf' === $context['package_key'] && isset($context['error']);
            }));

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(0, $result->getExtensions());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testDiscoveryMetadataTracking(): void
    {
        $this->createPackageStatesFile(['news' => ['packagePath' => 'typo3conf/ext/news/', 'state' => 'active']]);
        $this->createExtEmconfFile('news', $this->tempDir . '/typo3conf/ext/news', ['version' => '10.0.0']);

        $result = $this->service->discoverExtensions($this->tempDir);

        $metadata = $result->getDiscoveryMetadata();
        $this->assertCount(2, $metadata);

        // Check PackageStates metadata
        $packageStatesMetadata = null;
        $composerMetadata = null;
        foreach ($metadata as $item) {
            if ('PackageStates.php' === $item['method']) {
                $packageStatesMetadata = $item;
            } elseif ('composer installed.json' === $item['method']) {
                $composerMetadata = $item;
            }
        }

        $this->assertNotNull($packageStatesMetadata);
        $this->assertTrue($packageStatesMetadata['attempted']);
        $this->assertTrue($packageStatesMetadata['successful']);
        $this->assertSame(1, $packageStatesMetadata['extensions_found']);

        $this->assertNotNull($composerMetadata);
        $this->assertTrue($composerMetadata['attempted']);
        $this->assertFalse($composerMetadata['successful']);
        $this->assertSame(0, $composerMetadata['extensions_found']);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testServiceWithoutCachingServices(): void
    {
        $service = new ExtensionDiscoveryService($this->logger, $this->configService, $this->cacheService);

        $result = $service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(0, $result->getExtensions());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testDiscoverExtensionsWithInvalidInstallationPath(): void
    {
        $invalidPath = '/non/existent/path';

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Extension discovery failed: Installation path does not exist', [
                'path' => $invalidPath,
            ]);

        $result = $this->service->discoverExtensions($invalidPath);

        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('Installation path does not exist:', $result->getErrorMessage());
        $this->assertCount(0, $result->getExtensions());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testDiscoverExtensionsWithPackageStatesUnbalancedBrackets(): void
    {
        // Create PackageStates.php with unbalanced brackets
        $packageStatesPath = $this->tempDir . '/public/typo3conf/PackageStates.php';
        mkdir(\dirname($packageStatesPath), 0o755, true);
        file_put_contents($packageStatesPath, '<?php return ["packages" => ["news" => ["state" => "active"]];');

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to parse PackageStates.php', $this->callback(function ($context): bool {
                return isset($context['error']) && str_contains($context['error'], 'unbalanced brackets');
            }));

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(0, $result->getExtensions());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testDiscoverExtensionsWithPackageStatesNoPhpTag(): void
    {
        // Create PackageStates.php without PHP opening tag
        $packageStatesPath = $this->tempDir . '/public/typo3conf/PackageStates.php';
        mkdir(\dirname($packageStatesPath), 0o755, true);
        file_put_contents($packageStatesPath, 'return ["packages" => []];');

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to parse PackageStates.php', $this->callback(function ($context): bool {
                return isset($context['error']) && str_contains($context['error'], 'does not start with PHP opening tag');
            }));

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(0, $result->getExtensions());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testDiscoverExtensionsWithUnreadablePackageStatesFile(): void
    {
        // Create PackageStates.php that cannot be read
        $packageStatesPath = $this->tempDir . '/public/typo3conf/PackageStates.php';
        mkdir(\dirname($packageStatesPath), 0o755, true);
        touch($packageStatesPath);
        // Note: Cannot actually make file unreadable in test environment reliably
        // So we'll simulate by creating a valid structure but with file_get_contents failure

        // Instead, test with a file that has proper structure but returns false for file_get_contents
        // by creating a directory with the same name
        unlink($packageStatesPath);
        mkdir($packageStatesPath, 0o755, true);

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(0, $result->getExtensions());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testCreateExtensionFromPackageDataWithDifferentPathTypes(): void
    {
        $packages = [
            'system_ext' => [
                'packagePath' => 'typo3/sysext/system_ext/',
                'state' => 'active',
            ],
            'vendor_ext' => [
                'packagePath' => 'vendor/vendor/extension/',
                'state' => 'active',
            ],
            'unknown_path' => [
                'packagePath' => 'some/unknown/path/',
                'state' => 'active',
            ],
        ];

        $this->createPackageStatesFile($packages);

        // Create ext_emconf.php files for all extensions
        $this->createExtEmconfFile('system_ext', $this->tempDir . '/typo3/sysext/system_ext', [
            'title' => 'System Extension',
            'version' => '12.0.0',
        ]);
        $this->createExtEmconfFile('vendor_ext', $this->tempDir . '/vendor/vendor/extension', [
            'title' => 'Vendor Extension',
            'version' => '1.0.0',
        ]);
        $this->createExtEmconfFile('unknown_path', $this->tempDir . '/some/unknown/path', [
            'title' => 'Unknown Path Extension',
            'version' => '2.0.0',
        ]);

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(3, $result->getExtensions());

        $extensionsByKey = [];
        foreach ($result->getExtensions() as $extension) {
            $extensionsByKey[$extension->getKey()] = $extension;
        }

        $this->assertSame('system', $extensionsByKey['system_ext']->getType());
        $this->assertSame('composer', $extensionsByKey['vendor_ext']->getType());
        $this->assertSame('local', $extensionsByKey['unknown_path']->getType());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testCreateExtensionFromComposerDataWithComplexExtensionKey(): void
    {
        $packages = [
            [
                'name' => 'vendor/complex-extension-name',
                'type' => 'typo3-cms-extension',
                'version' => '1.0.0',
                'description' => 'Complex extension',
            ],
            [
                'name' => 'single-name-package',
                'type' => 'typo3-cms-extension',
                'version' => '2.0.0',
                'description' => 'Single name package',
            ],
            [
                'name' => 'vendor/extension-with/multiple/slashes',
                'type' => 'typo3-cms-extension',
                'version' => '3.0.0',
                'description' => 'Multiple slashes',
            ],
        ];

        $this->createComposerInstalledFile($packages);

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(3, $result->getExtensions());

        $extensionKeys = array_map(fn ($ext): string => $ext->getKey(), $result->getExtensions());
        $this->assertContains('complex_extension_name', $extensionKeys);
        $this->assertContains('single_name_package', $extensionKeys);
        $this->assertContains('vendor_extension_with_multiple_slashes', $extensionKeys);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testCreateExtensionFromComposerDataMissingName(): void
    {
        $packages = [
            [
                // Missing 'name' field
                'type' => 'typo3-cms-extension',
                'version' => '1.0.0',
                'description' => 'Extension without name',
            ],
        ];

        $this->createComposerInstalledFile($packages);

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(0, $result->getExtensions()); // Should be ignored
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testCreateExtensionFromComposerDataWithException(): void
    {
        $packages = [
            [
                'name' => 'vendor/problematic-extension',
                'type' => 'typo3-cms-extension',
                'version' => 'invalid-version-format',
                'description' => 'Extension that causes error',
            ],
        ];

        $this->createComposerInstalledFile($packages);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Failed to create extension from composer data', $this->callback(function ($context): bool {
                return 'vendor/problematic-extension' === $context['package_name']
                    && \is_string($context['error'])
                    && str_contains($context['error'], 'Invalid version format');
            }));

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(0, $result->getExtensions());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testDiscoverExtensionsWithMainDiscoveryException(): void
    {
        // Mock the configService to throw an exception during discovery
        $this->configService->expects($this->once())
            ->method('isResultCacheEnabled')
            ->willThrowException(new \RuntimeException('Configuration error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Extension discovery failed', [
                'error' => 'Configuration error',
            ]);

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('Configuration error', $result->getErrorMessage());
        $this->assertCount(0, $result->getExtensions());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService::discoverExtensions
     */
    public function testTypo3CoreExtensionsAreFilteredOut(): void
    {
        $packages = [
            'news' => [
                'packagePath' => 'typo3conf/ext/news/',
                'state' => 'active',
            ],
            'typo3/cms-backend' => [
                'packagePath' => 'typo3/sysext/backend/',
                'state' => 'active',
            ],
            'typo3/cms-core' => [
                'packagePath' => 'typo3/sysext/core/',
                'state' => 'active',
            ],
        ];

        $this->createPackageStatesFile($packages);
        $this->createExtEmconfFile('news', $this->tempDir . '/public/typo3conf/ext/news', [
            'title' => 'News System',
            'version' => '10.0.0',
        ]);

        // Also test composer packages filtering
        $composerPackages = [
            [
                'name' => 'georgringer/news',
                'type' => 'typo3-cms-extension',
                'version' => '10.0.0',
                'extra' => [
                    'typo3/cms' => [
                        'extension-key' => 'news',
                    ],
                ],
            ],
            [
                'name' => 'typo3/cms-frontend',
                'type' => 'typo3-cms-framework',
                'version' => '12.4.0',
            ],
        ];

        $this->createComposerInstalledFile($composerPackages);

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(1, $result->getExtensions()); // Only 'news' should be included
        $this->assertSame('news', $result->getExtensions()[0]->getKey());
    }
}
