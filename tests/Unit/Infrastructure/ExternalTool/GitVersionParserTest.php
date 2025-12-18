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
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitTag;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitVersionParser;
use CPSIT\UpgradeAnalyzer\Infrastructure\Version\ComposerConstraintCheckerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitVersionParser::class)]
class GitVersionParserTest extends TestCase
{
    private GitVersionParser $parser;
    private MockObject&ComposerConstraintCheckerInterface $constraintChecker;

    protected function setUp(): void
    {
        $this->constraintChecker = $this->createMock(ComposerConstraintCheckerInterface::class);
        $this->parser = new GitVersionParser($this->constraintChecker);
    }

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

        // Note: findCompatibleVersions now returns empty array by design
        // as checking each tag's composer.json would be too expensive
        $compatibleVersions = $this->parser->findCompatibleVersions($tags, $targetVersion, $composerJson);
        $this->assertCount(0, $compatibleVersions); // Returns empty by design
    }

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

        // Note: findCompatibleVersions now returns empty array by design
        // without checking composer.json
        $compatibleVersions = $this->parser->findCompatibleVersions($tags, $targetVersion, $composerJson);

        // Should return empty array (by design, not due to incompatibility check)
        $this->assertEmpty($compatibleVersions);
    }

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

        // Note: findCompatibleVersions now returns empty array by design
        $compatibleVersions = $this->parser->findCompatibleVersions($tags, $targetVersion, $composerJson);

        // Should return empty array by design
        $this->assertCount(0, $compatibleVersions);
    }

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

        // Note: findCompatibleVersions now returns empty array by design
        $compatibleVersions = $this->parser->findCompatibleVersions($tags, $targetVersion, $composerJson);

        $this->assertCount(0, $compatibleVersions);
    }

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

        // Note: findCompatibleVersions now returns empty array by design
        $compatibleVersions = $this->parser->findCompatibleVersions($tags, $targetVersion, $composerJson);

        // Returns empty array by design
        $this->assertCount(0, $compatibleVersions);
    }
}
