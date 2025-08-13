<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\ExternalTool\GitProvider;

use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitAnalysisException;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitProviderFactory;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitProvider\GitProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(GitProviderFactory::class)]
class GitProviderFactoryTest extends TestCase
{
    private GitProviderFactory $factory;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->factory = new GitProviderFactory([], $this->logger);
    }

    public function testCreateProviderWithSupportedUrl(): void
    {
        $provider1 = $this->createMock(GitProviderInterface::class);
        $provider1->method('supports')->willReturn(false);
        $provider1->method('isAvailable')->willReturn(true);
        $provider1->method('getPriority')->willReturn(50);

        $provider2 = $this->createMock(GitProviderInterface::class);
        $provider2->method('supports')->willReturn(true);
        $provider2->method('isAvailable')->willReturn(true);
        $provider2->method('getPriority')->willReturn(100);

        $factory = new GitProviderFactory([$provider1, $provider2], $this->logger);

        $result = $factory->createProvider('https://github.com/user/repo');

        $this->assertSame($provider2, $result);
    }

    public function testCreateProviderWithUnsupportedUrl(): void
    {
        $provider = $this->createMock(GitProviderInterface::class);
        $provider->method('supports')->willReturn(false);
        $provider->method('isAvailable')->willReturn(true);

        $factory = new GitProviderFactory([$provider], $this->logger);

        $this->expectException(GitAnalysisException::class);
        $this->expectExceptionMessage('No suitable Git provider found for repository: https://custom.example.com/repo');

        $factory->createProvider('https://custom.example.com/repo');
    }

    public function testCreateProviderWithUnavailableProvider(): void
    {
        $provider = $this->createMock(GitProviderInterface::class);
        $provider->method('supports')->willReturn(true);
        $provider->method('isAvailable')->willReturn(false);

        $factory = new GitProviderFactory([$provider], $this->logger);

        $this->expectException(GitAnalysisException::class);
        $this->expectExceptionMessage('No suitable Git provider found for repository: https://github.com/user/repo');

        $factory->createProvider('https://github.com/user/repo');
    }

    public function testCreateProviderSelectsHighestPriority(): void
    {
        $lowPriorityProvider = $this->createMock(GitProviderInterface::class);
        $lowPriorityProvider->method('supports')->willReturn(true);
        $lowPriorityProvider->method('isAvailable')->willReturn(true);
        $lowPriorityProvider->method('getPriority')->willReturn(10);

        $highPriorityProvider = $this->createMock(GitProviderInterface::class);
        $highPriorityProvider->method('supports')->willReturn(true);
        $highPriorityProvider->method('isAvailable')->willReturn(true);
        $highPriorityProvider->method('getPriority')->willReturn(100);

        $mediumPriorityProvider = $this->createMock(GitProviderInterface::class);
        $mediumPriorityProvider->method('supports')->willReturn(true);
        $mediumPriorityProvider->method('isAvailable')->willReturn(true);
        $mediumPriorityProvider->method('getPriority')->willReturn(50);

        $factory = new GitProviderFactory([
            $lowPriorityProvider,
            $highPriorityProvider,
            $mediumPriorityProvider,
        ], $this->logger);

        $result = $factory->createProvider('https://github.com/user/repo');

        $this->assertSame($highPriorityProvider, $result);
    }

    public function testCreateProviderWithEmptyProviders(): void
    {
        $this->expectException(GitAnalysisException::class);
        $this->expectExceptionMessage('No suitable Git provider found for repository: https://github.com/user/repo');

        $this->factory->createProvider('https://github.com/user/repo');
    }

    public function testGetAvailableProviders(): void
    {
        $availableProvider = $this->createMock(GitProviderInterface::class);
        $availableProvider->method('isAvailable')->willReturn(true);
        $availableProvider->method('getName')->willReturn('available');

        $unavailableProvider = $this->createMock(GitProviderInterface::class);
        $unavailableProvider->method('isAvailable')->willReturn(false);
        $unavailableProvider->method('getName')->willReturn('unavailable');

        $factory = new GitProviderFactory([$availableProvider, $unavailableProvider], $this->logger);

        $available = $factory->getAvailableProviders();

        $this->assertCount(1, $available);
        $this->assertSame($availableProvider, $available[0]);
    }
}
