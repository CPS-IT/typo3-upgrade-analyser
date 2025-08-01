<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\ExternalTool;

use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitTag;
use PHPUnit\Framework\TestCase;

/**
 * Test case for GitVersionParser
 *
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser
 */
class GitVersionParserTest extends TestCase
{
    private GitVersionParser $parser;

    protected function setUp(): void
    {
        $this->parser = new GitVersionParser();
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser::findCompatibleVersions
     */
    public function testFindCompatibleVersionsWithSemanticTags(): void
    {
        $tags = [
            new GitTag('v12.4.0', new \DateTimeImmutable('2024-01-15T10:00:00Z')),
            new GitTag('v11.5.10', new \DateTimeImmutable('2023-12-01T10:00:00Z')),
            new GitTag('v11.5.0', new \DateTimeImmutable('2023-10-01T10:00:00Z')),
            new GitTag('v10.4.0', new \DateTimeImmutable('2023-01-01T10:00:00Z'))
        ];

        $targetVersion = Version::fromString('12.4.0');
        $compatibleVersions = $this->parser->findCompatibleVersions($tags, $targetVersion);

        $this->assertCount(1, $compatibleVersions);
        $this->assertEquals('v12.4.0', $compatibleVersions[0]->getName());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser::findCompatibleVersions
     */
    public function testFindCompatibleVersionsWithNonSemanticTags(): void
    {
        $tags = [
            new GitTag('release-20240115', new \DateTimeImmutable('2024-01-15T10:00:00Z')),
            new GitTag('main', new \DateTimeImmutable('2024-01-10T10:00:00Z'))
        ];

        $targetVersion = Version::fromString('12.4.0');
        $compatibleVersions = $this->parser->findCompatibleVersions($tags, $targetVersion);

        // Should return empty array for non-semantic versions without composer analysis
        $this->assertEmpty($compatibleVersions);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser::isComposerCompatible
     */
    public function testIsComposerCompatibleWithCaretConstraint(): void
    {
        $composerJson = [
            'require' => [
                'typo3/cms-core' => '^12.4'
            ]
        ];

        $targetVersion = Version::fromString('12.4.0');
        $isCompatible = $this->parser->isComposerCompatible($composerJson, $targetVersion);

        $this->assertTrue($isCompatible);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser::isComposerCompatible
     */
    public function testIsComposerCompatibleWithTildeConstraint(): void
    {
        $composerJson = [
            'require' => [
                'typo3/cms-core' => '~12.4.0'
            ]
        ];

        $targetVersion = Version::fromString('12.4.0');
        $isCompatible = $this->parser->isComposerCompatible($composerJson, $targetVersion);

        $this->assertTrue($isCompatible);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser::isComposerCompatible
     */
    public function testIsComposerCompatibleWithIncompatibleVersion(): void
    {
        $composerJson = [
            'require' => [
                'typo3/cms-core' => '^11.5'
            ]
        ];

        $targetVersion = Version::fromString('12.4.0');
        $isCompatible = $this->parser->isComposerCompatible($composerJson, $targetVersion);

        $this->assertFalse($isCompatible);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser::isComposerCompatible
     */
    public function testIsComposerCompatibleWithoutTypo3Requirements(): void
    {
        $composerJson = [
            'require' => [
                'symfony/console' => '^5.0'
            ]
        ];

        $targetVersion = Version::fromString('12.4.0');
        $isCompatible = $this->parser->isComposerCompatible($composerJson, $targetVersion);

        $this->assertFalse($isCompatible);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser::isComposerCompatible
     */
    public function testIsComposerCompatibleWithNullInput(): void
    {
        $targetVersion = Version::fromString('12.4.0');
        $isCompatible = $this->parser->isComposerCompatible(null, $targetVersion);

        $this->assertFalse($isCompatible);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser::findCompatibleVersions
     */
    public function testFindCompatibleVersionsSortsByDate(): void
    {
        $tags = [
            new GitTag('v12.4.1', new \DateTimeImmutable('2024-02-15T10:00:00Z')),
            new GitTag('v12.4.0', new \DateTimeImmutable('2024-01-15T10:00:00Z')),
            new GitTag('v12.4.2', new \DateTimeImmutable('2024-03-15T10:00:00Z'))
        ];

        $targetVersion = Version::fromString('12.4.0');
        $compatibleVersions = $this->parser->findCompatibleVersions($tags, $targetVersion);

        $this->assertCount(3, $compatibleVersions);
        // Should be sorted by date (newest first)
        $this->assertEquals('v12.4.2', $compatibleVersions[0]->getName());
        $this->assertEquals('v12.4.1', $compatibleVersions[1]->getName());
        $this->assertEquals('v12.4.0', $compatibleVersions[2]->getName());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser::findCompatibleVersions
     */
    public function testFindCompatibleVersionsExcludesPreReleases(): void
    {
        $tags = [
            new GitTag('v12.4.0', new \DateTimeImmutable('2024-01-15T10:00:00Z')),
            new GitTag('v12.4.1-beta.1', new \DateTimeImmutable('2024-02-01T10:00:00Z'))
        ];

        $targetVersion = Version::fromString('12.4.0');
        $compatibleVersions = $this->parser->findCompatibleVersions($tags, $targetVersion);

        // Pre-release versions should be excluded for now
        $this->assertCount(1, $compatibleVersions);
        $this->assertEquals('v12.4.0', $compatibleVersions[0]->getName());
    }
}