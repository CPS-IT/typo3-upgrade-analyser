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

use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\ComposerSourceParser;
use CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\DTO\DeclaredRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(ComposerSourceParser::class)]
#[AllowMockObjectsWithoutExpectations]
class ComposerSourceParserTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private ComposerSourceParser $subject;

    private static function fixturePath(string $dir, string $file): string
    {
        return \dirname(__DIR__, 3) . '/Fixtures/ComposerSources/' . $dir . '/' . $file;
    }

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->subject = new ComposerSourceParser($this->logger);
    }

    public function testNominalGitLabSaasCase(): void
    {
        $result = $this->subject->parse(self::fixturePath('GitLabSaasPublic', 'composer.lock'));

        $this->assertCount(1, $result);
        $this->assertInstanceOf(DeclaredRepository::class, $result[0]);
        $this->assertSame('https://gitlab.com/myvendor/typo3-ext-news.git', $result[0]->url);
        $this->assertContains('myvendor/typo3-ext-news', $result[0]->packages);
    }

    public function testBitbucketCase(): void
    {
        $result = $this->subject->parse(self::fixturePath('BitbucketPublic', 'composer.lock'));

        $this->assertCount(1, $result);
        $this->assertStringContainsString('bitbucket.org', $result[0]->url);
    }

    public function testGitLabSelfHostedCase(): void
    {
        $result = $this->subject->parse(self::fixturePath('GitLabSelfHosted', 'composer.lock'));

        $this->assertCount(1, $result);
        $this->assertStringContainsString('git.example.com', $result[0]->url);
    }

    public function testSshUrlExtractedCorrectly(): void
    {
        $result = $this->subject->parse(self::fixturePath('SshUrl', 'composer.lock'));

        $this->assertCount(1, $result);
        $this->assertSame('git@github.com:vendor/ext-ssh.git', $result[0]->url);
        $this->assertContains('vendor/ext-ssh', $result[0]->packages);
    }

    public function testPathTypeIsSkipped(): void
    {
        $result = $this->subject->parse(self::fixturePath('PathType', 'composer.lock'));

        $this->assertSame([], $result);
    }

    public function testDistOnlyIsSkipped(): void
    {
        $result = $this->subject->parse(self::fixturePath('DistOnly', 'composer.lock'));

        $this->assertSame([], $result);
    }

    public function testPackagesDevIncluded(): void
    {
        $result = $this->subject->parse(self::fixturePath('PackagesDevOnly', 'composer.lock'));

        $this->assertCount(1, $result);
        $this->assertContains('vendor/dev-ext', $result[0]->packages);
    }

    public function testMultiplePackagesSameUrlGroupedIntoSingleRepository(): void
    {
        $result = $this->subject->parse(self::fixturePath('MultiPackageSameUrl', 'composer.lock'));

        $this->assertCount(1, $result);
        $this->assertSame('https://gitlab.com/myvendor/mono-repo.git', $result[0]->url);
        $this->assertCount(2, $result[0]->packages);
        $this->assertContains('vendor/ext-alpha', $result[0]->packages);
        $this->assertContains('vendor/ext-beta', $result[0]->packages);
    }

    public function testMalformedJsonLogsWarningAndReturnsEmpty(): void
    {
        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('composer.lock'));

        $result = $this->subject->parse(self::fixturePath('MalformedJson', 'composer.lock'));

        $this->assertSame([], $result);
    }

    public function testMalformedJsonInComposerJsonFallbackLogsWarningAndReturnsEmpty(): void
    {
        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('composer.json'));

        $lockPath = self::fixturePath('MalformedJsonFallback', 'composer.lock');
        $result = $this->subject->parse($lockPath);

        $this->assertSame([], $result);
    }

    public function testComposerJsonFallbackSkipsNonVcsRepositories(): void
    {
        $lockPath = self::fixturePath('MissingLockMixed', 'composer.lock');
        $result = $this->subject->parse($lockPath);

        $this->assertCount(1, $result);
        $this->assertSame('https://gitlab.com/myvendor/private-ext.git', $result[0]->url);
    }

    public function testMissingLockFallsBackToComposerJson(): void
    {
        $lockPath = self::fixturePath('MissingLock', 'composer.lock');
        $result = $this->subject->parse($lockPath);

        $this->assertCount(1, $result);
        $this->assertSame('https://gitlab.com/myvendor/private-ext.git', $result[0]->url);
        $this->assertSame([], $result[0]->packages);
    }

    public function testBothFilesAbsentReturnsEmpty(): void
    {
        $result = $this->subject->parse('/nonexistent/path/composer.lock');

        $this->assertSame([], $result);
    }

    public function testEmptyPackagesReturnsEmpty(): void
    {
        $result = $this->subject->parse(self::fixturePath('EmptyPackages', 'composer.lock'));

        $this->assertSame([], $result);
    }
}
