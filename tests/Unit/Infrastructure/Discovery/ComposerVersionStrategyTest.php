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

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ComposerVersionStrategy;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ComposerVersionStrategy
 */
final class ComposerVersionStrategyTest extends TestCase
{
    private \PHPUnit\Framework\MockObject\MockObject $logger;
    private ComposerVersionStrategy $strategy;
    private string $testDir;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->strategy = new ComposerVersionStrategy($this->logger);
        $this->testDir = sys_get_temp_dir() . '/typo3-analyzer-test-' . uniqid();
        mkdir($this->testDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testDir)) {
            $this->removeDirectory($this->testDir);
        }
    }

    public function testGetName(): void
    {
        self::assertSame('Composer Version Strategy', $this->strategy->getName());
    }

    public function testGetPriority(): void
    {
        self::assertSame(100, $this->strategy->getPriority());
    }

    public function testGetReliabilityScore(): void
    {
        self::assertSame(0.95, $this->strategy->getReliabilityScore());
    }

    public function testGetRequiredFiles(): void
    {
        $requiredFiles = $this->strategy->getRequiredFiles();

        self::assertCount(2, $requiredFiles);
        self::assertContains('composer.lock', $requiredFiles);
        self::assertContains('composer.json', $requiredFiles);
    }

    public function testSupportsWithComposerLock(): void
    {
        $lockPath = $this->testDir . '/composer.lock';
        file_put_contents($lockPath, '{}');

        self::assertTrue($this->strategy->supports($this->testDir));
    }

    public function testSupportsWithComposerJson(): void
    {
        $jsonPath = $this->testDir . '/composer.json';
        file_put_contents($jsonPath, '{}');

        self::assertTrue($this->strategy->supports($this->testDir));
    }

    public function testSupportsWithBothFiles(): void
    {
        $lockPath = $this->testDir . '/composer.lock';
        $jsonPath = $this->testDir . '/composer.json';
        file_put_contents($lockPath, '{}');
        file_put_contents($jsonPath, '{}');

        self::assertTrue($this->strategy->supports($this->testDir));
    }

    public function testSupportsWithNoComposerFiles(): void
    {
        self::assertFalse($this->strategy->supports($this->testDir));
    }

    public function testExtractVersionFromComposerLockWithTypo3CmsCore(): void
    {
        $lockData = [
            'packages' => [
                [
                    'name' => 'typo3/cms-core',
                    'version' => '12.4.8',
                ],
            ],
        ];

        $lockPath = $this->testDir . '/composer.lock';
        file_put_contents($lockPath, json_encode($lockData));

        $version = $this->strategy->extractVersion($this->testDir);

        self::assertInstanceOf(Version::class, $version);
        self::assertSame('12.4.8', $version->toString());
    }

    public function testExtractVersionFromComposerLockWithTypo3Cms(): void
    {
        $lockData = [
            'packages' => [
                [
                    'name' => 'typo3/cms',
                    'version' => 'v11.5.23',
                ],
            ],
        ];

        $lockPath = $this->testDir . '/composer.lock';
        file_put_contents($lockPath, json_encode($lockData));

        $version = $this->strategy->extractVersion($this->testDir);

        self::assertInstanceOf(Version::class, $version);
        self::assertSame('11.5.23', $version->toString());
    }

    public function testExtractVersionFromComposerLockWithTypo3Minimal(): void
    {
        $lockData = [
            'packages' => [
                [
                    'name' => 'typo3/minimal',
                    'version' => '13.0.0',
                ],
            ],
        ];

        $lockPath = $this->testDir . '/composer.lock';
        file_put_contents($lockPath, json_encode($lockData));

        $version = $this->strategy->extractVersion($this->testDir);

        self::assertInstanceOf(Version::class, $version);
        self::assertSame('13.0.0', $version->toString());
    }

    public function testExtractVersionFromComposerLockWithDevVersion(): void
    {
        $lockData = [
            'packages' => [
                [
                    'name' => 'typo3/cms-core',
                    'version' => 'dev-12.4',
                ],
            ],
        ];

        $lockPath = $this->testDir . '/composer.lock';
        file_put_contents($lockPath, json_encode($lockData));

        $version = $this->strategy->extractVersion($this->testDir);

        self::assertInstanceOf(Version::class, $version);
        self::assertSame('12.4.0', $version->toString());
    }

    public function testExtractVersionFromComposerLockWithInvalidDevVersion(): void
    {
        $lockData = [
            'packages' => [
                [
                    'name' => 'typo3/cms-core',
                    'version' => 'dev-main',
                ],
            ],
        ];

        $lockPath = $this->testDir . '/composer.lock';
        file_put_contents($lockPath, json_encode($lockData));

        $version = $this->strategy->extractVersion($this->testDir);

        self::assertNull($version);
    }

    public function testExtractVersionFromComposerLockWithMultiplePackages(): void
    {
        $lockData = [
            'packages' => [
                [
                    'name' => 'some/other-package',
                    'version' => '1.0.0',
                ],
                [
                    'name' => 'typo3/cms-core',
                    'version' => '12.4.8',
                ],
                [
                    'name' => 'another/package',
                    'version' => '2.0.0',
                ],
            ],
        ];

        $lockPath = $this->testDir . '/composer.lock';
        file_put_contents($lockPath, json_encode($lockData));

        $version = $this->strategy->extractVersion($this->testDir);

        self::assertInstanceOf(Version::class, $version);
        self::assertSame('12.4.8', $version->toString());
    }

    public function testExtractVersionFromComposerLockWithInvalidJson(): void
    {
        $lockPath = $this->testDir . '/composer.lock';
        file_put_contents($lockPath, 'invalid json');

        $version = $this->strategy->extractVersion($this->testDir);

        self::assertNull($version);
    }

    public function testExtractVersionFromComposerLockWithMissingPackages(): void
    {
        $lockData = [
            'some-other-key' => [],
        ];

        $lockPath = $this->testDir . '/composer.lock';
        file_put_contents($lockPath, json_encode($lockData));

        $version = $this->strategy->extractVersion($this->testDir);

        self::assertNull($version);
    }

    public function testExtractVersionFromComposerLockWithInvalidPackageStructure(): void
    {
        $lockData = [
            'packages' => [
                'invalid-package-structure',
                [
                    'name' => 'typo3/cms-core',
                    // missing version
                ],
                [
                    'version' => '12.4.8',
                    // missing name
                ],
            ],
        ];

        $lockPath = $this->testDir . '/composer.lock';
        file_put_contents($lockPath, json_encode($lockData));

        $version = $this->strategy->extractVersion($this->testDir);

        self::assertNull($version);
    }

    public function testExtractVersionFromComposerJsonWithRequire(): void
    {
        $jsonData = [
            'require' => [
                'typo3/cms-core' => '^12.4',
            ],
        ];

        $jsonPath = $this->testDir . '/composer.json';
        file_put_contents($jsonPath, json_encode($jsonData));

        $version = $this->strategy->extractVersion($this->testDir);

        self::assertInstanceOf(Version::class, $version);
        self::assertSame('12.4.0', $version->toString());
    }

    public function testExtractVersionFromComposerJsonWithRequireDev(): void
    {
        $jsonData = [
            'require-dev' => [
                'typo3/cms' => '~11.5.0',
            ],
        ];

        $jsonPath = $this->testDir . '/composer.json';
        file_put_contents($jsonPath, json_encode($jsonData));

        $version = $this->strategy->extractVersion($this->testDir);

        self::assertInstanceOf(Version::class, $version);
        self::assertSame('11.5.0', $version->toString());
    }

    public function testExtractVersionFromComposerJsonWithVersionRange(): void
    {
        $jsonData = [
            'require' => [
                'typo3/minimal' => '>=12.0 <13.0',
            ],
        ];

        $jsonPath = $this->testDir . '/composer.json';
        file_put_contents($jsonPath, json_encode($jsonData));

        $version = $this->strategy->extractVersion($this->testDir);

        self::assertInstanceOf(Version::class, $version);
        self::assertSame('12.0.0', $version->toString());
    }

    public function testExtractVersionFromComposerJsonWithComplexConstraint(): void
    {
        $jsonData = [
            'require' => [
                'typo3/cms-core' => '^12.4.5 || ^13.0',
            ],
        ];

        $jsonPath = $this->testDir . '/composer.json';
        file_put_contents($jsonPath, json_encode($jsonData));

        $version = $this->strategy->extractVersion($this->testDir);

        self::assertInstanceOf(Version::class, $version);
        self::assertSame('12.4.5', $version->toString());
    }

    public function testExtractVersionFromComposerJsonWithInvalidConstraint(): void
    {
        $jsonData = [
            'require' => [
                'typo3/cms-core' => 'invalid-constraint',
            ],
        ];

        $jsonPath = $this->testDir . '/composer.json';
        file_put_contents($jsonPath, json_encode($jsonData));

        $version = $this->strategy->extractVersion($this->testDir);

        self::assertNull($version);
    }

    public function testExtractVersionFromComposerJsonWithNoTypo3Packages(): void
    {
        $jsonData = [
            'require' => [
                'some/other-package' => '^1.0',
            ],
        ];

        $jsonPath = $this->testDir . '/composer.json';
        file_put_contents($jsonPath, json_encode($jsonData));

        $version = $this->strategy->extractVersion($this->testDir);

        self::assertNull($version);
    }

    public function testExtractVersionFromComposerJsonWithInvalidJson(): void
    {
        $jsonPath = $this->testDir . '/composer.json';
        file_put_contents($jsonPath, 'invalid json');

        $version = $this->strategy->extractVersion($this->testDir);

        self::assertNull($version);
    }

    public function testExtractVersionFromComposerJsonWithInvalidStructure(): void
    {
        $jsonPath = $this->testDir . '/composer.json';
        file_put_contents($jsonPath, '"not-an-object"');

        $version = $this->strategy->extractVersion($this->testDir);

        self::assertNull($version);
    }

    public function testExtractVersionPreferredOrderLockOverJson(): void
    {
        // composer.lock has higher priority and should be used
        $lockData = [
            'packages' => [
                [
                    'name' => 'typo3/cms-core',
                    'version' => '12.4.8',
                ],
            ],
        ];

        $jsonData = [
            'require' => [
                'typo3/cms-core' => '^11.5',
            ],
        ];

        $lockPath = $this->testDir . '/composer.lock';
        $jsonPath = $this->testDir . '/composer.json';
        file_put_contents($lockPath, json_encode($lockData));
        file_put_contents($jsonPath, json_encode($jsonData));

        $version = $this->strategy->extractVersion($this->testDir);

        self::assertInstanceOf(Version::class, $version);
        self::assertSame('12.4.8', $version->toString()); // Version from composer.lock, not composer.json
    }

    public function testExtractVersionFallbackFromLockToJson(): void
    {
        // composer.lock exists but has no TYPO3 packages, should fall back to composer.json
        $lockData = [
            'packages' => [
                [
                    'name' => 'some/other-package',
                    'version' => '1.0.0',
                ],
            ],
        ];

        $jsonData = [
            'require' => [
                'typo3/cms-core' => '^12.4',
            ],
        ];

        $lockPath = $this->testDir . '/composer.lock';
        $jsonPath = $this->testDir . '/composer.json';
        file_put_contents($lockPath, json_encode($lockData));
        file_put_contents($jsonPath, json_encode($jsonData));

        $version = $this->strategy->extractVersion($this->testDir);

        self::assertInstanceOf(Version::class, $version);
        self::assertSame('12.4.0', $version->toString());
    }

    public function testExtractVersionWithNoComposerFiles(): void
    {
        $version = $this->strategy->extractVersion($this->testDir);

        self::assertNull($version);
    }

    public function testExtractVersionWithUnreadableFile(): void
    {
        $lockPath = $this->testDir . '/composer.lock';
        file_put_contents($lockPath, json_encode(['packages' => []]));

        // Make file unreadable (this might not work on all systems)
        if (chmod($lockPath, 0o000)) {
            $version = $this->strategy->extractVersion($this->testDir);
            self::assertNull($version);

            // Restore permissions for cleanup
            chmod($lockPath, 0o644);
        } else {
            self::markTestSkipped('Cannot make file unreadable on this system');
        }
    }

    /**
     * @dataProvider versionNormalizationProvider
     */
    public function testVersionNormalization(string $inputVersion, ?string $expectedVersion): void
    {
        $lockData = [
            'packages' => [
                [
                    'name' => 'typo3/cms-core',
                    'version' => $inputVersion,
                ],
            ],
        ];

        $lockPath = $this->testDir . '/composer.lock';
        file_put_contents($lockPath, json_encode($lockData));

        $version = $this->strategy->extractVersion($this->testDir);

        if (null === $expectedVersion) {
            self::assertNull($version);
        } else {
            self::assertInstanceOf(Version::class, $version);
            self::assertSame($expectedVersion, $version->toString());
        }
    }

    public static function versionNormalizationProvider(): array
    {
        return [
            'standard version' => ['12.4.8', '12.4.8'],
            'version with v prefix' => ['v12.4.8', '12.4.8'],
            'version with suffix' => ['12.4.8-alpha1', '12.4.8-alpha1'],
            'dev version with number' => ['dev-12.4', '12.4.0'],
            'dev version with patch' => ['dev-12.4.0', '12.4.0'],
            'dev version non-numeric' => ['dev-main', null],
            'dev version branch' => ['dev-feature-branch', null],
            'invalid version format' => ['not-a-version', null],
            'empty version' => ['', null],
        ];
    }

    /**
     * @dataProvider constraintExtractionProvider
     */
    public function testConstraintExtraction(string $constraint, ?string $expectedVersion): void
    {
        $jsonData = [
            'require' => [
                'typo3/cms-core' => $constraint,
            ],
        ];

        $jsonPath = $this->testDir . '/composer.json';
        file_put_contents($jsonPath, json_encode($jsonData));

        $version = $this->strategy->extractVersion($this->testDir);

        if (null === $expectedVersion) {
            self::assertNull($version);
        } else {
            self::assertInstanceOf(Version::class, $version);
            self::assertSame($expectedVersion, $version->toString());
        }
    }

    public static function constraintExtractionProvider(): array
    {
        return [
            'caret constraint' => ['^12.4', '12.4.0'],
            'tilde constraint' => ['~11.5.0', '11.5.0'],
            'exact version' => ['12.4.8', '12.4.8'],
            'version with v prefix' => ['v12.4.8', '12.4.8'],
            'greater than' => ['>=12.0', '12.0.0'],
            'less than' => ['<13.0', '13.0.0'], // Extracts the version number
            'range with pipe' => ['^12.4 | ^13.0', '12.4.0'],
            'range with space' => ['>=12.0 <13.0', '12.0.0'],
            'complex constraint' => ['^12.4.5 || ^13.0', '12.4.5'],
            'invalid constraint' => ['invalid', null],
            'empty constraint' => ['', null],
        ];
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
}
