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

use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryMetadata;
use PHPUnit\Framework\TestCase;

/**
 * Test case for GitRepositoryMetadata
 *
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryMetadata
 */
class GitRepositoryMetadataTest extends TestCase
{
    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryMetadata::__construct
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryMetadata::getName
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryMetadata::getDescription
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryMetadata::isArchived
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryMetadata::isFork
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryMetadata::getStarCount
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryMetadata::getForkCount
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryMetadata::getLastUpdated
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryMetadata::getDefaultBranch
     */
    public function testConstructorAndGetters(): void
    {
        $lastUpdated = new \DateTimeImmutable('2024-01-15T10:00:00Z');
        
        $metadata = new GitRepositoryMetadata(
            name: 'test-repo',
            description: 'A test repository',
            isArchived: false,
            isFork: true,
            starCount: 15,
            forkCount: 3,
            lastUpdated: $lastUpdated,
            defaultBranch: 'main'
        );

        $this->assertEquals('test-repo', $metadata->getName());
        $this->assertEquals('A test repository', $metadata->getDescription());
        $this->assertFalse($metadata->isArchived());
        $this->assertTrue($metadata->isFork());
        $this->assertEquals(15, $metadata->getStarCount());
        $this->assertEquals(3, $metadata->getForkCount());
        $this->assertSame($lastUpdated, $metadata->getLastUpdated());
        $this->assertEquals('main', $metadata->getDefaultBranch());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryMetadata::__construct
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryMetadata::getDescription
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryMetadata::getDefaultBranch
     */
    public function testWithEmptyDescription(): void
    {
        $metadata = new GitRepositoryMetadata(
            name: 'test-repo',
            description: '',
            isArchived: false,
            isFork: false,
            starCount: 0,
            forkCount: 0,
            lastUpdated: new \DateTimeImmutable(),
            defaultBranch: 'master'
        );

        $this->assertEquals('', $metadata->getDescription());
        $this->assertEquals('master', $metadata->getDefaultBranch());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryMetadata::__construct
     */
    public function testReadonlyProperties(): void
    {
        $metadata = new GitRepositoryMetadata(
            name: 'test-repo',
            description: 'Test',
            isArchived: false,
            isFork: false,
            starCount: 10,
            forkCount: 2,
            lastUpdated: new \DateTimeImmutable(),
            defaultBranch: 'main'
        );

        // Properties should be readonly - this test verifies the class structure
        $reflection = new \ReflectionClass($metadata);
        
        $nameProperty = $reflection->getProperty('name');
        $this->assertTrue($nameProperty->isReadOnly());
        
        $descriptionProperty = $reflection->getProperty('description');
        $this->assertTrue($descriptionProperty->isReadOnly());
        
        $isArchivedProperty = $reflection->getProperty('isArchived');
        $this->assertTrue($isArchivedProperty->isReadOnly());
    }
}