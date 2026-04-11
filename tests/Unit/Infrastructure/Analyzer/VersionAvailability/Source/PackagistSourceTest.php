<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Analyzer\VersionAvailability\Source;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailability\Source\PackagistSource;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\PackagistClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
final class PackagistSourceTest extends TestCase
{
    private PackagistSource $source;
    private PackagistClient&MockObject $packagistClient;
    private LoggerInterface&MockObject $logger;
    private CacheService&MockObject $cacheService;
    private Extension $extension;
    private AnalysisContext $context;

    protected function setUp(): void
    {
        $this->packagistClient = $this->createMock(PackagistClient::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->cacheService = $this->createMock(CacheService::class);

        $this->source = new PackagistSource($this->packagistClient, $this->logger, $this->cacheService);

        $this->extension = new Extension('test_extension', 'Test Title', new Version('1.0.0'));
        $this->context = new AnalysisContext(
            new Version('11.5.0'),
            new Version('12.4.0'),
        );
    }

    public function testGetName(): void
    {
        self::assertSame('packagist', $this->source->getName());
    }

    public function testCheckAvailabilityReturnsFalseIfNoComposerName(): void
    {
        // Extension without composer name
        $this->extension = new Extension('test_extension', 'Test Title', new Version('1.0.0'), 'local', null);

        $this->packagistClient->expects(self::never())
            ->method('hasVersionFor');

        $this->cacheService->expects(self::never())->method('generateSimpleKey');

        $result = $this->source->checkAvailability($this->extension, $this->context);

        self::assertSame(['packagist_available' => false], $result);
    }

    public function testCheckAvailabilityReturnsCachedValue(): void
    {
        $this->extension = new Extension('test_extension', 'Test Title', new Version('1.0.0'), 'local', 'vendor/package');

        $this->cacheService->method('generateSimpleKey')->willReturn('cache_key');
        $this->cacheService->expects(self::once())->method('has')->with('cache_key')->willReturn(true);
        $this->cacheService->expects(self::once())->method('get')->with('cache_key')->willReturn(['packagist_available' => true]);

        $this->packagistClient->expects(self::never())->method('hasVersionFor');

        $result = $this->source->checkAvailability($this->extension, $this->context);

        self::assertSame(['packagist_available' => true], $result);
    }

    public function testCheckAvailabilityReturnsTrueWhenAvailable(): void
    {
        $this->extension = new Extension('test_extension', 'Test Title', new Version('1.0.0'), 'local', 'vendor/package');

        $this->cacheService->method('generateSimpleKey')->willReturn('cache_key');
        $this->cacheService->expects(self::once())->method('has')->with('cache_key')->willReturn(false);
        $this->cacheService->expects(self::once())->method('set')->with('cache_key', [
            'packagist_available' => true,
            'packagist_latest_version' => '2.0.0',
            'packagist_latest_compatible' => true,
        ]);

        $this->packagistClient->expects(self::once())
            ->method('getLatestVersionInfo')
            ->with('vendor/package', $this->context->getTargetVersion())
            ->willReturn([
                'latest_version' => '2.0.0',
                'is_compatible' => true,
            ]);

        $result = $this->source->checkAvailability($this->extension, $this->context);

        self::assertSame([
            'packagist_available' => true,
            'packagist_latest_version' => '2.0.0',
            'packagist_latest_compatible' => true,
        ], $result);
    }

    public function testCheckAvailabilityReturnsFalseWhenNotAvailable(): void
    {
        $this->extension = new Extension('test_extension', 'Test Title', new Version('1.0.0'), 'local', 'vendor/package');

        $this->cacheService->method('generateSimpleKey')->willReturn('cache_key');
        $this->cacheService->expects(self::once())->method('has')->with('cache_key')->willReturn(false);
        $this->cacheService->expects(self::once())->method('set')->with('cache_key', [
            'packagist_available' => false,
            'packagist_latest_version' => null,
            'packagist_latest_compatible' => false,
        ]);

        $this->packagistClient->expects(self::once())
            ->method('getLatestVersionInfo')
            ->with('vendor/package', $this->context->getTargetVersion())
            ->willReturn([
                'latest_version' => null,
                'is_compatible' => false,
            ]);

        $result = $this->source->checkAvailability($this->extension, $this->context);

        self::assertSame([
            'packagist_available' => false,
            'packagist_latest_version' => null,
            'packagist_latest_compatible' => false,
        ], $result);
    }

    public function testCheckAvailabilityHandlesExceptions(): void
    {
        $this->extension = new Extension('test_extension', 'Test Title', new Version('1.0.0'), 'local', 'vendor/package');

        $this->packagistClient->expects(self::once())
            ->method('getLatestVersionInfo')
            ->willThrowException(new \RuntimeException('API Error'));

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                'Packagist availability check failed',
                self::callback(function ($context) {
                    return 'test_extension' === $context['extension'] && 'API Error' === $context['error'];
                }),
            );

        $result = $this->source->checkAvailability($this->extension, $this->context);

        self::assertSame(['packagist_available' => false], $result);
    }
}
