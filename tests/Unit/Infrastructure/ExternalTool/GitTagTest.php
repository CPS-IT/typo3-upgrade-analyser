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

use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitTag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;


#[CoversClass(GitTag::class)]
class GitTagTest extends TestCase
{
    public function testGetSemanticVersionFromVersionPrefix(): void
    {
        $tag = new GitTag('v1.2.3');

        $this->assertEquals('1.2.3', $tag->getSemanticVersion());
        $this->assertTrue($tag->isSemanticVersion());
    }
    public function testGetSemanticVersionFromReleasePrefix(): void
    {
        $tag = new GitTag('release-2.1.0');

        $this->assertEquals('2.1.0', $tag->getSemanticVersion());
        $this->assertTrue($tag->isSemanticVersion());
    }
    public function testGetSemanticVersionWithPreRelease(): void
    {
        $tag = new GitTag('v1.2.3-beta.1');

        $this->assertEquals('1.2.3-beta.1', $tag->getSemanticVersion());
        $this->assertTrue($tag->isSemanticVersion());
        $this->assertTrue($tag->isPreRelease());
    }
    public function testGetSemanticVersionWithoutPrefix(): void
    {
        $tag = new GitTag('1.2.3');

        $this->assertEquals('1.2.3', $tag->getSemanticVersion());
        $this->assertTrue($tag->isSemanticVersion());
    }
    public function testGetSemanticVersionWithNonSemantic(): void
    {
        $tag = new GitTag('main');

        $this->assertNull($tag->getSemanticVersion());
        $this->assertFalse($tag->isSemanticVersion());
        $this->assertFalse($tag->isPreRelease());
    }
    public function testGetMajorVersion(): void
    {
        $tag = new GitTag('v12.4.5');

        $this->assertEquals(12, $tag->getMajorVersion());
    }
    public function testGetMinorVersion(): void
    {
        $tag = new GitTag('v12.4.5');

        $this->assertEquals(4, $tag->getMinorVersion());
    }
    public function testGetVersionsWithNonSemantic(): void
    {
        $tag = new GitTag('main');

        $this->assertNull($tag->getMajorVersion());
        $this->assertNull($tag->getMinorVersion());
    }
    public function testIsNewerThanWithDates(): void
    {
        $newerTag = new GitTag('v1.2.0', new \DateTimeImmutable('2024-02-01'));
        $olderTag = new GitTag('v1.1.0', new \DateTimeImmutable('2024-01-01'));

        $this->assertTrue($newerTag->isNewerThan($olderTag));
        $this->assertFalse($olderTag->isNewerThan($newerTag));
    }
    public function testIsNewerThanWithoutDates(): void
    {
        $newerTag = new GitTag('v1.2.0');
        $olderTag = new GitTag('v1.1.0');

        // Should fall back to version comparison
        $this->assertTrue($newerTag->isNewerThan($olderTag));
        $this->assertFalse($olderTag->isNewerThan($newerTag));
    }
    public function testGettersWithAllData(): void
    {
        $date = new \DateTimeImmutable('2024-01-15T10:00:00Z');
        $tag = new GitTag('v1.2.3', $date, 'abc123');

        $this->assertEquals('v1.2.3', $tag->getName());
        $this->assertEquals($date, $tag->getDate());
        $this->assertEquals('abc123', $tag->getCommit());
    }
    public function testGettersWithMinimalData(): void
    {
        $tag = new GitTag('v1.2.3');

        $this->assertEquals('v1.2.3', $tag->getName());
        $this->assertNull($tag->getDate());
        $this->assertNull($tag->getCommit());
    }
}
