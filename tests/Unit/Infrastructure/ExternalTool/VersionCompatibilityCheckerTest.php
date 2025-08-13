<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\ExternalTool;

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\VersionCompatibilityChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;


#[CoversClass(VersionCompatibilityChecker::class)]
class VersionCompatibilityCheckerTest extends TestCase
{
    private VersionCompatibilityChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new VersionCompatibilityChecker();
    }

    public function testHasCompatibleVersionWithCompatibleVersion(): void
    {
        $versions = [
            ['number' => '1.0.0', 'typo3_versions' => [11, 12]],
            ['number' => '2.0.0', 'typo3_versions' => [10]],
        ];
        $typo3Version = new Version('12.4.0');

        $result = $this->checker->hasCompatibleVersion($versions, $typo3Version);

        self::assertTrue($result);
    }

    public function testHasCompatibleVersionWithNoCompatibleVersion(): void
    {
        $versions = [
            ['number' => '1.0.0', 'typo3_versions' => [10, 11]],
            ['number' => '2.0.0', 'typo3_versions' => [9]],
        ];
        $typo3Version = new Version('12.4.0');

        $result = $this->checker->hasCompatibleVersion($versions, $typo3Version);

        self::assertFalse($result);
    }

    public function testFindCompatibleVersionsWithMultipleCompatible(): void
    {
        $versions = [
            ['number' => '1.0.0', 'typo3_versions' => [11, 12]],
            ['number' => '2.0.0', 'typo3_versions' => [10, 11]],
            ['number' => '3.0.0', 'typo3_versions' => [12, 13]],
        ];
        $typo3Version = new Version('12.4.0');

        $result = $this->checker->findCompatibleVersions($versions, $typo3Version);

        self::assertSame(['1.0.0', '3.0.0'], $result);
    }

    public function testFindCompatibleVersionsWithNoCompatible(): void
    {
        $versions = [
            ['number' => '1.0.0', 'typo3_versions' => [10, 11]],
            ['number' => '2.0.0', 'typo3_versions' => [9]],
        ];
        $typo3Version = new Version('12.4.0');

        $result = $this->checker->findCompatibleVersions($versions, $typo3Version);

        self::assertSame([], $result);
    }

    public function testGetLatestCompatibleVersionWithVersions(): void
    {
        $versions = [
            ['number' => '1.0.0', 'typo3_versions' => [11, 12]],
            ['number' => '2.5.0', 'typo3_versions' => [12, 13]],
            ['number' => '2.0.0', 'typo3_versions' => [12]],
        ];
        $typo3Version = new Version('12.4.0');

        $result = $this->checker->getLatestCompatibleVersion($versions, $typo3Version);

        self::assertSame('2.5.0', $result);
    }

    public function testGetLatestCompatibleVersionWithNoCompatible(): void
    {
        $versions = [
            ['number' => '1.0.0', 'typo3_versions' => [10, 11]],
            ['number' => '2.0.0', 'typo3_versions' => [9]],
        ];
        $typo3Version = new Version('12.4.0');

        $result = $this->checker->getLatestCompatibleVersion($versions, $typo3Version);

        self::assertNull($result);
    }

    public function testIsVersionCompatibleWithIntegerVersions(): void
    {
        $versionData = ['number' => '1.0.0', 'typo3_versions' => [11, 12, 13]];
        $typo3Version = new Version('12.4.0');

        $result = $this->checker->isVersionCompatible($versionData, $typo3Version);

        self::assertTrue($result);
    }

    public function testIsVersionCompatibleWithUniversalCompatibility(): void
    {
        $versionData = ['number' => '1.0.0', 'typo3_versions' => ['*']];
        $typo3Version = new Version('12.4.0');

        $result = $this->checker->isVersionCompatible($versionData, $typo3Version);

        self::assertTrue($result);
    }

    public function testIsVersionCompatibleWithWildcardPattern(): void
    {
        $versionData = ['number' => '1.0.0', 'typo3_versions' => ['12.*']];
        $typo3Version = new Version('12.4.0');

        $result = $this->checker->isVersionCompatible($versionData, $typo3Version);

        self::assertTrue($result);
    }

    public function testIsVersionCompatibleWithExactVersionMatch(): void
    {
        $versionData = ['number' => '1.0.0', 'typo3_versions' => ['12.0']];
        $typo3Version = new Version('12.4.0');

        $result = $this->checker->isVersionCompatible($versionData, $typo3Version);

        self::assertTrue($result);
    }

    public function testIsVersionCompatibleWithPlainVersionNumber(): void
    {
        $versionData = ['number' => '1.0.0', 'typo3_versions' => ['12']];
        $typo3Version = new Version('12.4.0');

        $result = $this->checker->isVersionCompatible($versionData, $typo3Version);

        self::assertTrue($result);
    }

    public function testIsVersionCompatibleWithMissingTypo3Versions(): void
    {
        $versionData = ['number' => '1.0.0'];
        $typo3Version = new Version('12.4.0');

        $result = $this->checker->isVersionCompatible($versionData, $typo3Version);

        self::assertFalse($result);
    }

    public function testIsVersionCompatibleWithNoMatchingVersions(): void
    {
        $versionData = ['number' => '1.0.0', 'typo3_versions' => [10, 11]];
        $typo3Version = new Version('12.4.0');

        $result = $this->checker->isVersionCompatible($versionData, $typo3Version);

        self::assertFalse($result);
    }

    public function testIsVersionCompatibleWithMixedVersionTypes(): void
    {
        $versionData = ['number' => '1.0.0', 'typo3_versions' => [11, '12.*', '13.0']];
        $typo3Version = new Version('12.4.0');

        $result = $this->checker->isVersionCompatible($versionData, $typo3Version);

        self::assertTrue($result);
    }

    public function testIsVersionCompatibleWithUnsupportedStringFormat(): void
    {
        $versionData = ['number' => '1.0.0', 'typo3_versions' => ['12.4.0-dev']];
        $typo3Version = new Version('12.4.0');

        $result = $this->checker->isVersionCompatible($versionData, $typo3Version);

        self::assertFalse($result);
    }

    public function testFindCompatibleVersionsWithMissingNumberField(): void
    {
        $versions = [
            ['typo3_versions' => [12]], // Missing number field
            ['number' => '2.0.0', 'typo3_versions' => [12]],
        ];
        $typo3Version = new Version('12.4.0');

        $result = $this->checker->findCompatibleVersions($versions, $typo3Version);

        self::assertSame(['2.0.0'], $result);
    }
}
