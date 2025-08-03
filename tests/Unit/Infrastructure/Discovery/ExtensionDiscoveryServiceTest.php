<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Discovery;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Configuration\ConfigurationService;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryResult;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ExtensionDiscoveryService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ExtensionDiscoveryServiceTest extends TestCase
{
    private LoggerInterface $logger;
    private ConfigurationService $configService;
    private CacheService $cacheService;
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
            $this->cacheService
        );

        $this->tempDir = sys_get_temp_dir() . '/ext_discovery_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
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
        mkdir(dirname($packageStatesPath), 0755, true);

        $content = "<?php\nreturn " . var_export(['packages' => $packages], true) . ";\n";
        file_put_contents($packageStatesPath, $content);

        return $packageStatesPath;
    }

    private function createComposerInstalledFile(array $packages): string
    {
        $installedJsonPath = $this->tempDir . '/vendor/composer/installed.json';
        mkdir(dirname($installedJsonPath), 0755, true);

        $content = json_encode(['packages' => $packages], JSON_PRETTY_PRINT);
        file_put_contents($installedJsonPath, $content);

        return $installedJsonPath;
    }

    private function createExtEmconfFile(string $extensionKey, string $extensionPath, array $config): void
    {
        $emconfPath = $extensionPath . '/ext_emconf.php';
        mkdir(dirname($emconfPath), 0755, true);

        $content = "<?php\n\$EM_CONF['" . $extensionKey . "'] = " . var_export($config, true) . ";\n";
        file_put_contents($emconfPath, $content);
    }

    public function testConstructorWithoutOptionalServices(): void
    {
        $service = new ExtensionDiscoveryService($this->logger);
        $this->assertInstanceOf(ExtensionDiscoveryService::class, $service);
    }

    public function testDiscoverExtensionsWithEmptyInstallation(): void
    {
        $this->logger->expects($this->exactly(2))
            ->method('info')
            ->withConsecutive(
                ['Starting extension discovery', ['path' => $this->tempDir]],
                ['Extension discovery completed', ['total_extensions' => 0]]
            );

        $this->logger->expects($this->exactly(2))
            ->method('debug')
            ->withConsecutive(
                ['PackageStates.php not found', ['path' => $this->tempDir . '/public/typo3conf/PackageStates.php']],
                ['composer installed.json not found', ['path' => $this->tempDir . '/vendor/composer/installed.json']]
            );

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertInstanceOf(ExtensionDiscoveryResult::class, $result);
        $this->assertTrue($result->isSuccessful());
        $this->assertCount(0, $result->getExtensions());
        $this->assertCount(0, $result->getSuccessfulMethods());
    }

    public function testDiscoverExtensionsFromPackageStatesOnly(): void
    {
        $packages = [
            'news' => [
                'packagePath' => 'typo3conf/ext/news/',
                'state' => 'active'
            ],
            'tt_address' => [
                'packagePath' => 'typo3conf/ext/tt_address/',
                'state' => 'inactive'
            ],
            'typo3/cms-backend' => [
                'packagePath' => 'typo3/sysext/backend/',
                'state' => 'active'
            ]
        ];

        $this->createPackageStatesFile($packages);

        // Create ext_emconf.php files
        $this->createExtEmconfFile('news', $this->tempDir . '/typo3conf/ext/news', [
            'title' => 'News System',
            'version' => '10.0.0'
        ]);
        $this->createExtEmconfFile('tt_address', $this->tempDir . '/typo3conf/ext/tt_address', [
            'title' => 'Address Management',
            'version' => '7.1.0'
        ]);

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(2, $result->getExtensions());
        $this->assertContains('PackageStates.php', $result->getSuccessfulMethods());

        $extensions = $result->getExtensions();
        $newsExtension = null;
        $addressExtension = null;

        foreach ($extensions as $extension) {
            if ($extension->getKey() === 'news') {
                $newsExtension = $extension;
            } elseif ($extension->getKey() === 'tt_address') {
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
                        'extension-key' => 'news'
                    ]
                ]
            ],
            [
                'name' => 'friendsoftypo3/tt-address',
                'type' => 'typo3-cms-extension',
                'version' => '7.1.0',
                'description' => 'Address management'
            ],
            [
                'name' => 'typo3/cms-backend',
                'type' => 'typo3-cms-framework',
                'version' => '12.4.0'
            ]
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
            if ($extension->getKey() === 'news') {
                $newsExtension = $extension;
            } elseif ($extension->getKey() === 'tt_address') {
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

    public function testDiscoverExtensionsFromBothSources(): void
    {
        // Package States with one extension
        $packages = [
            'local_extension' => [
                'packagePath' => 'typo3conf/ext/local_extension/',
                'state' => 'active'
            ],
            'news' => [
                'packagePath' => 'typo3conf/ext/news/',
                'state' => 'active'
            ]
        ];
        $this->createPackageStatesFile($packages);

        // Create ext_emconf.php for local extension
        $this->createExtEmconfFile('local_extension', $this->tempDir . '/typo3conf/ext/local_extension', [
            'title' => 'Local Extension',
            'version' => '1.0.0'
        ]);
        $this->createExtEmconfFile('news', $this->tempDir . '/typo3conf/ext/news', [
            'title' => 'News from PackageStates',
            'version' => '9.0.0'
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
                        'extension-key' => 'news'
                    ]
                ]
            ],
            [
                'name' => 'vendor/composer-only',
                'type' => 'typo3-cms-extension',
                'version' => '2.0.0',
                'description' => 'Composer only extension'
            ]
        ];
        $this->createComposerInstalledFile($composerPackages);

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(3, $result->getExtensions()); // local_extension, news (from PackageStates), composer_only
        $this->assertContains('PackageStates.php', $result->getSuccessfulMethods());
        $this->assertContains('composer installed.json', $result->getSuccessfulMethods());

        $extensionKeys = array_map(fn($ext) => $ext->getKey(), $result->getExtensions());
        $this->assertContains('local_extension', $extensionKeys);
        $this->assertContains('news', $extensionKeys);
        $this->assertContains('composer_only', $extensionKeys);
    }

    public function testDiscoverExtensionsWithCustomPaths(): void
    {
        $customPaths = [
            'vendor-dir' => 'custom-vendor',
            'web-dir' => 'web',
            'typo3conf-dir' => 'web/conf'
        ];

        $packages = [
            'custom_ext' => [
                'packagePath' => 'web/conf/ext/custom_ext/',
                'state' => 'active'
            ]
        ];

        // Create with custom paths
        $packageStatesPath = $this->tempDir . '/web/conf/PackageStates.php';
        mkdir(dirname($packageStatesPath), 0755, true);
        $content = "<?php\nreturn " . var_export(['packages' => $packages], true) . ";\n";
        file_put_contents($packageStatesPath, $content);

        $this->createExtEmconfFile('custom_ext', $this->tempDir . '/web/conf/ext/custom_ext', [
            'title' => 'Custom Extension',
            'version' => '1.0.0'
        ]);

        $result = $this->service->discoverExtensions($this->tempDir, $customPaths);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(1, $result->getExtensions());
        $this->assertSame('custom_ext', $result->getExtensions()[0]->getKey());
    }

    public function testDiscoverExtensionsWithCacheEnabled(): void
    {
        $this->configService->expects($this->once())
            ->method('isResultCacheEnabled')
            ->willReturn(true);

        $this->cacheService->expects($this->once())
            ->method('generateKey')
            ->with('extension_discovery', $this->tempDir, ['custom_paths' => []])
            ->willReturn('cache_key_123');

        $this->cacheService->expects($this->once())
            ->method('get')
            ->with('cache_key_123')
            ->willReturn(null);

        $this->cacheService->expects($this->once())
            ->method('set')
            ->with('cache_key_123', $this->callback(function ($data) {
                return is_array($data) && isset($data['cached_at']);
            }))
            ->willReturn(true);

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
    }

    public function testDiscoverExtensionsWithCacheHit(): void
    {
        $cachedData = [
            'success' => true,
            'extensions' => [
                [
                    'key' => 'cached_extension',
                    'title' => 'Cached Extension',
                    'version' => '1.0.0',
                    'type' => 'local',
                    'composerName' => null,
                    'active' => true,
                    'emConfiguration' => []
                ]
            ],
            'successful_methods' => ['PackageStates.php'],
            'discovery_metadata' => [],
            'cached_at' => time()
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

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Found cached extension discovery result', ['cache_key' => 'cache_key_123']);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Using cached extension discovery result', $this->callback(function ($context) {
                return isset($context['cached_at']) && isset($context['extensions_count']);
            }));

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(1, $result->getExtensions());
        $this->assertSame('cached_extension', $result->getExtensions()[0]->getKey());
    }

    public function testDiscoverExtensionsWithCacheDisabled(): void
    {
        $this->configService->expects($this->once())
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

    public function testDiscoverExtensionsWithServiceException(): void
    {
        // Create an invalid PackageStates.php that will cause parsing to fail
        $packageStatesPath = $this->tempDir . '/public/typo3conf/PackageStates.php';
        mkdir(dirname($packageStatesPath), 0755, true);
        file_put_contents($packageStatesPath, '<?php throw new Exception("Parsing error");');

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Extension discovery failed', $this->callback(function ($context) {
                return isset($context['error']);
            }));

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotEmpty($result->getErrorMessage());
    }

    public function testDiscoverExtensionsWithInvalidPackageStates(): void
    {
        // Create invalid PackageStates.php content
        $packageStatesPath = $this->tempDir . '/public/typo3conf/PackageStates.php';
        mkdir(dirname($packageStatesPath), 0755, true);
        file_put_contents($packageStatesPath, '<?php return "invalid";');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Invalid PackageStates.php format');

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(0, $result->getExtensions());
    }

    public function testDiscoverExtensionsWithInvalidComposerInstalled(): void
    {
        // Create invalid composer installed.json content
        $installedJsonPath = $this->tempDir . '/vendor/composer/installed.json';
        mkdir(dirname($installedJsonPath), 0755, true);
        file_put_contents($installedJsonPath, '{"invalid": "format"}');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Invalid composer installed.json format');

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(0, $result->getExtensions());
    }

    public function testDiscoverExtensionsWithCorruptedJson(): void
    {
        // Create corrupted JSON file
        $installedJsonPath = $this->tempDir . '/vendor/composer/installed.json';
        mkdir(dirname($installedJsonPath), 0755, true);
        file_put_contents($installedJsonPath, '{"packages": [invalid json');

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to parse composer installed.json', $this->callback(function ($context) {
                return isset($context['error']);
            }));

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(0, $result->getExtensions());
    }

    public function testExtensionKeyExtractionFromComposerName(): void
    {
        $packages = [
            [
                'name' => 'vendor/extension-name',
                'type' => 'typo3-cms-extension',
                'version' => '1.0.0'
            ],
            [
                'name' => 'vendor/my-awesome-extension',
                'type' => 'typo3-cms-extension',
                'version' => '2.0.0',
                'extra' => [
                    'typo3/cms' => [
                        'extension-key' => 'my_awesome_ext'
                    ]
                ]
            ]
        ];

        $this->createComposerInstalledFile($packages);

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(2, $result->getExtensions());

        $extensionKeys = array_map(fn($ext) => $ext->getKey(), $result->getExtensions());
        $this->assertContains('extension_name', $extensionKeys);
        $this->assertContains('my_awesome_ext', $extensionKeys);
    }

    public function testExtensionTypeDetection(): void
    {
        $packages = [
            'system_ext' => [
                'packagePath' => 'typo3/sysext/system_ext/',
                'state' => 'active'
            ],
            'local_ext' => [
                'packagePath' => 'typo3conf/ext/local_ext/',
                'state' => 'active'
            ],
            'composer_ext' => [
                'packagePath' => 'vendor/vendor/composer_ext/',
                'state' => 'active'
            ]
        ];

        $this->createPackageStatesFile($packages);

        // Create ext_emconf.php files
        foreach (['system_ext', 'local_ext', 'composer_ext'] as $key) {
            $path = $packages[$key]['packagePath'];
            $this->createExtEmconfFile($key, $this->tempDir . '/' . $path, [
                'title' => ucfirst($key),
                'version' => '1.0.0'
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

    public function testHandlingMissingExtEmconfFile(): void
    {
        $packages = [
            'extension_without_emconf' => [
                'packagePath' => 'typo3conf/ext/extension_without_emconf/',
                'state' => 'active'
            ]
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

    public function testHandlingPackageWithoutPath(): void
    {
        $packages = [
            'extension_without_path' => [
                'state' => 'active'
                // Missing packagePath
            ]
        ];

        $this->createPackageStatesFile($packages);

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(0, $result->getExtensions());
    }

    public function testHandlingCorruptedExtEmconfFile(): void
    {
        $packages = [
            'corrupted_emconf' => [
                'packagePath' => 'typo3conf/ext/corrupted_emconf/',
                'state' => 'active'
            ]
        ];

        $this->createPackageStatesFile($packages);

        // Create corrupted ext_emconf.php
        $emconfPath = $this->tempDir . '/typo3conf/ext/corrupted_emconf/ext_emconf.php';
        mkdir(dirname($emconfPath), 0755, true);
        file_put_contents($emconfPath, '<?php throw new Exception("Corrupted emconf");');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Failed to create extension from package data', $this->callback(function ($context) {
                return $context['package_key'] === 'corrupted_emconf' && isset($context['error']);
            }));

        $result = $this->service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(0, $result->getExtensions());
    }

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
            if ($item['method'] === 'PackageStates.php') {
                $packageStatesMetadata = $item;
            } elseif ($item['method'] === 'composer installed.json') {
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

    public function testServiceWithoutCachingServices(): void
    {
        $service = new ExtensionDiscoveryService($this->logger);

        $result = $service->discoverExtensions($this->tempDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(0, $result->getExtensions());
    }
}