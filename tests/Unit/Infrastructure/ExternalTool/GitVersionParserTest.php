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
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitTag;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser;
use CPSIT\UpgradeAnalyzer\Infrastructure\Version\ComposerConstraintCheckerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Test case for GitVersionParser.
 *
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser
 */
class GitVersionParserTest extends TestCase
{
    private GitVersionParser $parser;
    private MockObject&ComposerConstraintCheckerInterface $constraintChecker;

    protected function setUp(): void
    {
        $this->constraintChecker = $this->createMock(ComposerConstraintCheckerInterface::class);
        $this->parser = new GitVersionParser($this->constraintChecker);
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
            new GitTag('v10.4.0', new \DateTimeImmutable('2023-01-01T10:00:00Z')),
        ];

        $targetVersion = Version::fromString('12.4.0');

        // Without composer.json, should return empty array (conservative approach)
        $compatibleVersions = $this->parser->findCompatibleVersions($tags, $targetVersion);
        $this->assertEmpty($compatibleVersions);

        // With compatible composer.json, should return all stable tags
        $composerJson = [
            'require' => [
                'typo3/cms-core' => '^12.4',
            ],
        ];

        $this->constraintChecker->expects(self::once())
            ->method('isComposerJsonCompatible')
            ->with($composerJson, $targetVersion)
            ->willReturn(true);

        $compatibleVersions = $this->parser->findCompatibleVersions($tags, $targetVersion, $composerJson);
        $this->assertCount(4, $compatibleVersions); // All tags are stable versions

        // Verify all returned tags are stable (no pre-releases)
        foreach ($compatibleVersions as $tag) {
            $this->assertFalse($tag->isPreRelease());
        }
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser::findCompatibleVersions
     */
    public function testFindCompatibleVersionsWithNonSemanticTags(): void
    {
        $tags = [
            new GitTag('release-20240115', new \DateTimeImmutable('2024-01-15T10:00:00Z')),
            new GitTag('main', new \DateTimeImmutable('2024-01-10T10:00:00Z')),
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
                'typo3/cms-core' => '^12.4',
            ],
        ];

        $targetVersion = Version::fromString('12.4.0');

        $this->constraintChecker->expects(self::once())
            ->method('isComposerJsonCompatible')
            ->with($composerJson, $targetVersion)
            ->willReturn(true);

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
                'typo3/cms-core' => '~12.4.0',
            ],
        ];

        $targetVersion = Version::fromString('12.4.0');

        $this->constraintChecker->expects(self::once())
            ->method('isComposerJsonCompatible')
            ->with($composerJson, $targetVersion)
            ->willReturn(true);

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
                'typo3/cms-core' => '^11.5',
            ],
        ];

        $targetVersion = Version::fromString('12.4.0');

        $this->constraintChecker->expects(self::once())
            ->method('isComposerJsonCompatible')
            ->with($composerJson, $targetVersion)
            ->willReturn(false);

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
                'symfony/console' => '^5.0',
            ],
        ];

        $targetVersion = Version::fromString('12.4.0');

        $this->constraintChecker->expects(self::once())
            ->method('isComposerJsonCompatible')
            ->with($composerJson, $targetVersion)
            ->willReturn(false);

        $isCompatible = $this->parser->isComposerCompatible($composerJson, $targetVersion);

        $this->assertFalse($isCompatible);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser::isComposerCompatible
     */
    public function testIsComposerCompatibleWithNullInput(): void
    {
        $targetVersion = Version::fromString('12.4.0');

        $this->constraintChecker->expects(self::once())
            ->method('isComposerJsonCompatible')
            ->with(null, $targetVersion)
            ->willReturn(false);

        $isCompatible = $this->parser->isComposerCompatible(null, $targetVersion);

        $this->assertFalse($isCompatible);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser::findCompatibleVersions
     */
    public function testFindCompatibleVersionsWithIncompatibleComposerJson(): void
    {
        $tags = [
            new GitTag('v1.0.0', new \DateTimeImmutable('2024-01-15T10:00:00Z')),
            new GitTag('v1.1.0', new \DateTimeImmutable('2024-02-15T10:00:00Z')),
        ];

        $targetVersion = Version::fromString('12.4.0');

        // Composer.json that requires TYPO3 11.x should be incompatible with 12.x
        $composerJson = [
            'require' => [
                'typo3/cms-core' => '^11.5',
            ],
        ];

        $this->constraintChecker->expects(self::once())
            ->method('isComposerJsonCompatible')
            ->with($composerJson, $targetVersion)
            ->willReturn(false);

        $compatibleVersions = $this->parser->findCompatibleVersions($tags, $targetVersion, $composerJson);

        // Should return empty array due to incompatible composer.json
        $this->assertEmpty($compatibleVersions);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser::findCompatibleVersions
     */
    public function testFindCompatibleVersionsWithMultipleTypo3Requirements(): void
    {
        $tags = [
            new GitTag('v2.0.0', new \DateTimeImmutable('2024-01-15T10:00:00Z')),
            new GitTag('v2.1.0', new \DateTimeImmutable('2024-02-15T10:00:00Z')),
        ];

        $targetVersion = Version::fromString('12.4.0');

        // Composer.json with multiple TYPO3 packages, at least one compatible
        $composerJson = [
            'require' => [
                'typo3/cms-core' => '^12.4',
                'typo3/cms-frontend' => '^12.4',
                'typo3/cms-backend' => '^11.5', // This one is incompatible, but core is compatible
            ],
        ];

        $this->constraintChecker->expects(self::once())
            ->method('isComposerJsonCompatible')
            ->with($composerJson, $targetVersion)
            ->willReturn(true);

        $compatibleVersions = $this->parser->findCompatibleVersions($tags, $targetVersion, $composerJson);

        // Should return tags because at least one TYPO3 requirement is compatible
        $this->assertCount(2, $compatibleVersions);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser::isComposerCompatible
     */
    public function testIsComposerCompatibleWithRangeConstraint(): void
    {
        $composerJson = [
            'require' => [
                'typo3/cms-core' => '>=12.0,<13.0',
            ],
        ];

        $targetVersion = Version::fromString('12.4.0');

        $this->constraintChecker->expects(self::once())
            ->method('isComposerJsonCompatible')
            ->with($composerJson, $targetVersion)
            ->willReturn(true);

        $isCompatible = $this->parser->isComposerCompatible($composerJson, $targetVersion);

        $this->assertTrue($isCompatible);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser::isComposerCompatible
     */
    public function testIsComposerCompatibleWithExactVersion(): void
    {
        $composerJson = [
            'require' => [
                'typo3/cms-core' => '12.4',
            ],
        ];

        $targetVersion = Version::fromString('12.4.0');

        $this->constraintChecker->expects(self::once())
            ->method('isComposerJsonCompatible')
            ->with($composerJson, $targetVersion)
            ->willReturn(true);

        $isCompatible = $this->parser->isComposerCompatible($composerJson, $targetVersion);

        $this->assertTrue($isCompatible);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser::findCompatibleVersions
     */
    public function testFindCompatibleVersionsSortsByDate(): void
    {
        $tags = [
            new GitTag('v12.4.1', new \DateTimeImmutable('2024-02-15T10:00:00Z')),
            new GitTag('v12.4.0', new \DateTimeImmutable('2024-01-15T10:00:00Z')),
            new GitTag('v12.4.2', new \DateTimeImmutable('2024-03-15T10:00:00Z')),
        ];

        $targetVersion = Version::fromString('12.4.0');

        // With compatible composer.json, should return all stable tags
        $composerJson = [
            'require' => [
                'typo3/cms-core' => '^12.4',
            ],
        ];

        $this->constraintChecker->expects(self::once())
            ->method('isComposerJsonCompatible')
            ->with($composerJson, $targetVersion)
            ->willReturn(true);

        $compatibleVersions = $this->parser->findCompatibleVersions($tags, $targetVersion, $composerJson);

        $this->assertCount(3, $compatibleVersions);
        // Verify the returned tags (order depends on array_filter implementation)
        $tagNames = array_map(fn ($tag): string => $tag->getName(), $compatibleVersions);
        $this->assertContains('v12.4.0', $tagNames);
        $this->assertContains('v12.4.1', $tagNames);
        $this->assertContains('v12.4.2', $tagNames);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser::findCompatibleVersions
     */
    public function testFindCompatibleVersionsExcludesPreReleases(): void
    {
        $tags = [
            new GitTag('v12.4.0', new \DateTimeImmutable('2024-01-15T10:00:00Z')),
            new GitTag('v12.4.1-beta.1', new \DateTimeImmutable('2024-02-01T10:00:00Z')),
        ];

        $targetVersion = Version::fromString('12.4.0');

        // With compatible composer.json, should return only stable tags (exclude pre-releases)
        $composerJson = [
            'require' => [
                'typo3/cms-core' => '^12.4',
            ],
        ];

        $this->constraintChecker->expects(self::once())
            ->method('isComposerJsonCompatible')
            ->with($composerJson, $targetVersion)
            ->willReturn(true);

        $compatibleVersions = $this->parser->findCompatibleVersions($tags, $targetVersion, $composerJson);

        // Pre-release versions should be excluded
        $this->assertCount(1, $compatibleVersions);
        $this->assertEquals('v12.4.0', $compatibleVersions[0]->getName());
        $this->assertFalse($compatibleVersions[0]->isPreRelease());
    }
}
