<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Discovery\DTO;

use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\DTO\DeclaredRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeclaredRepository::class)]
class DeclaredRepositoryTest extends TestCase
{
    public function testConstructorAndPublicFields(): void
    {
        $repo = new DeclaredRepository(
            url: 'https://gitlab.com/myvendor/typo3-ext-news.git',
            packages: ['myvendor/typo3-ext-news'],
        );

        $this->assertSame('https://gitlab.com/myvendor/typo3-ext-news.git', $repo->url);
        $this->assertSame(['myvendor/typo3-ext-news'], $repo->packages);
    }

    public function testMultiplePackages(): void
    {
        $repo = new DeclaredRepository(
            url: 'https://gitlab.com/myvendor/repo.git',
            packages: ['vendor/pkg-a', 'vendor/pkg-b'],
        );

        $this->assertCount(2, $repo->packages);
        $this->assertContains('vendor/pkg-a', $repo->packages);
        $this->assertContains('vendor/pkg-b', $repo->packages);
    }

    public function testEmptyPackages(): void
    {
        $repo = new DeclaredRepository(
            url: 'https://example.com/repo.git',
            packages: [],
        );

        $this->assertSame([], $repo->packages);
    }

    public function testIsReadonly(): void
    {
        $repo = new DeclaredRepository(
            url: 'https://gitlab.com/myvendor/repo.git',
            packages: ['vendor/pkg'],
        );

        $reflection = new \ReflectionClass($repo);
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testNoTypeField(): void
    {
        $repo = new DeclaredRepository(
            url: 'https://gitlab.com/myvendor/repo.git',
            packages: [],
        );

        $reflection = new \ReflectionClass($repo);
        $properties = array_map(
            fn (\ReflectionProperty $p): string => $p->getName(),
            $reflection->getProperties(),
        );

        $this->assertContains('url', $properties);
        $this->assertContains('packages', $properties);
        $this->assertNotContains('type', $properties);
        $this->assertCount(2, $properties);
    }
}
