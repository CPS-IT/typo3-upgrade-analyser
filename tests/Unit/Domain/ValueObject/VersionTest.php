<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;

/**
 * Test case for the Version value object
 *
 * @covers \CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version
 */
class VersionTest extends TestCase
{
    public function testConstructorWithBasicVersion(): void
    {
        $version = new Version('1.2.3');
        
        self::assertEquals(1, $version->getMajor());
        self::assertEquals(2, $version->getMinor());
        self::assertEquals(3, $version->getPatch());
        self::assertNull($version->getSuffix());
        self::assertEquals('1.2.3', $version->toString());
    }

    public function testConstructorWithVersionSuffix(): void
    {
        $version = new Version('1.2.3-beta1');
        
        self::assertEquals(1, $version->getMajor());
        self::assertEquals(2, $version->getMinor());
        self::assertEquals(3, $version->getPatch());
        self::assertEquals('beta1', $version->getSuffix());
        self::assertEquals('1.2.3-beta1', $version->toString());
    }

    public function testConstructorWithTwoPartVersion(): void
    {
        $version = new Version('12.4');
        
        self::assertEquals(12, $version->getMajor());
        self::assertEquals(4, $version->getMinor());
        self::assertEquals(0, $version->getPatch());
        self::assertNull($version->getSuffix());
        self::assertEquals('12.4.0', $version->toString());
    }

    public function testConstructorWithComposerConstraints(): void
    {
        $testCases = [
            '^12.4' => [12, 4, 0, null],
            '~12.4.0' => [12, 4, 0, null],
            '>=12.4.8' => [12, 4, 8, null],
            '<13.0.0' => [13, 0, 0, null],
            'v12.4.8' => [12, 4, 8, null],
        ];
        
        foreach ($testCases as $versionString => [$major, $minor, $patch, $suffix]) {
            $version = new Version($versionString);
            
            self::assertEquals($major, $version->getMajor(), "Failed for version: $versionString");
            self::assertEquals($minor, $version->getMinor(), "Failed for version: $versionString");
            self::assertEquals($patch, $version->getPatch(), "Failed for version: $versionString");
            self::assertEquals($suffix, $version->getSuffix(), "Failed for version: $versionString");
        }
    }

    public function testFromStringStaticMethod(): void
    {
        $version = Version::fromString('2.1.0');
        
        self::assertInstanceOf(Version::class, $version);
        self::assertEquals(2, $version->getMajor());
        self::assertEquals(1, $version->getMinor());
        self::assertEquals(0, $version->getPatch());
    }

    public function testToStringMagicMethod(): void
    {
        $version = new Version('1.2.3-alpha');
        
        self::assertEquals('1.2.3-alpha', (string) $version);
    }

    public function testVersionComparison(): void
    {
        $version1 = new Version('1.2.3');
        $version2 = new Version('1.2.4');
        $version3 = new Version('1.3.0');
        $version4 = new Version('2.0.0');
        $version5 = new Version('1.2.3');
        
        // Test greater than
        self::assertTrue($version2->isGreaterThan($version1));
        self::assertTrue($version3->isGreaterThan($version1));
        self::assertTrue($version4->isGreaterThan($version1));
        self::assertFalse($version1->isGreaterThan($version2));
        
        // Test less than
        self::assertTrue($version1->isLessThan($version2));
        self::assertTrue($version1->isLessThan($version3));
        self::assertTrue($version1->isLessThan($version4));
        self::assertFalse($version2->isLessThan($version1));
        
        // Test equal
        self::assertTrue($version1->isEqual($version5));
        self::assertFalse($version1->isEqual($version2));
    }

    public function testVersionComparisonWithSuffixes(): void
    {
        $stableVersion = new Version('1.2.3');
        $betaVersion = new Version('1.2.3-beta');
        $alphaVersion = new Version('1.2.3-alpha');
        
        // Stable version should be greater than pre-release versions
        self::assertTrue($stableVersion->isGreaterThan($betaVersion));
        self::assertTrue($stableVersion->isGreaterThan($alphaVersion));
        
        // Compare suffixes alphabetically
        self::assertTrue($betaVersion->isGreaterThan($alphaVersion));
        self::assertTrue($alphaVersion->isLessThan($betaVersion));
    }

    public function testCompareMethod(): void
    {
        $version1 = new Version('1.2.3');
        $version2 = new Version('1.2.4');
        $version3 = new Version('1.2.3');
        
        self::assertEquals(-1, $version1->compare($version2));
        self::assertEquals(1, $version2->compare($version1));
        self::assertEquals(0, $version1->compare($version3));
    }

    public function testIsCompatibleWith(): void
    {
        $version12 = new Version('12.4.8');
        $version12Different = new Version('12.5.0');
        $version13 = new Version('13.0.0');
        
        // Same major version should be compatible
        self::assertTrue($version12->isCompatibleWith($version12Different));
        self::assertTrue($version12Different->isCompatibleWith($version12));
        
        // Different major version should not be compatible
        self::assertFalse($version12->isCompatibleWith($version13));
        self::assertFalse($version13->isCompatibleWith($version12));
    }

    public function testInvalidVersionFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid version format: invalid.version');
        
        new Version('invalid.version');
    }

    public function testVersionWithComplexSuffix(): void
    {
        $version = new Version('1.0.0-beta.1');
        
        self::assertEquals(1, $version->getMajor());
        self::assertEquals(0, $version->getMinor());
        self::assertEquals(0, $version->getPatch());
        self::assertEquals('beta.1', $version->getSuffix());
        self::assertEquals('1.0.0-beta.1', $version->toString());
    }

    public function testComparisonEdgeCases(): void
    {
        $v1 = new Version('1.0.0-alpha');
        $v2 = new Version('1.0.0-beta');
        $v3 = new Version('1.0.0');
        
        // Alpha < Beta < Stable
        self::assertTrue($v1->isLessThan($v2));
        self::assertTrue($v2->isLessThan($v3));
        self::assertTrue($v1->isLessThan($v3));
    }

    public function testVersionWithLeadingV(): void
    {
        $version1 = new Version('v1.2.3');
        $version2 = new Version('1.2.3');
        
        self::assertEquals($version1->getMajor(), $version2->getMajor());
        self::assertEquals($version1->getMinor(), $version2->getMinor());
        self::assertEquals($version1->getPatch(), $version2->getPatch());
        self::assertTrue($version1->isEqual($version2));
    }
}