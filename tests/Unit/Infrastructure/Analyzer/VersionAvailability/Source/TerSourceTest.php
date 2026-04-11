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
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailability\Source\TerSource;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
final class TerSourceTest extends TestCase
{
    private TerSource $source;
    private TerApiClient&MockObject $terClient;
    private LoggerInterface&MockObject $logger;
    private CacheService&MockObject $cacheService;
    private Extension $extension;
    private AnalysisContext $context;

    protected function setUp(): void
    {
        $this->terClient = $this->createMock(TerApiClient::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->cacheService = $this->createMock(CacheService::class);

        $this->source = new TerSource($this->terClient, $this->logger, $this->cacheService);

        $this->extension = new Extension('test_extension', 'Test Title', new Version('1.0.0'));
        $this->context = new AnalysisContext(
            new Version('11.5.0'),
            new Version('12.4.0'),
        );
    }

    public function testGetName(): void
    {
        self::assertSame('ter', $this->source->getName());
    }

    public function testCheckAvailabilityReturnsCachedValue(): void
    {
        $this->cacheService->method('generateSimpleKey')->willReturn('cache_key');
        $this->cacheService->expects(self::once())->method('has')->with('cache_key')->willReturn(true);
        $this->cacheService->expects(self::once())->method('get')->with('cache_key')->willReturn(['ter_available' => true]);

        $this->terClient->expects(self::never())->method('hasVersionFor');

        $result = $this->source->checkAvailability($this->extension, $this->context);

        self::assertSame(['ter_available' => true], $result);
    }

    public function testCheckAvailabilityReturnsTrueWhenAvailable(): void
    {
        $this->cacheService->method('generateSimpleKey')->willReturn('cache_key');
        $this->cacheService->expects(self::once())->method('has')->with('cache_key')->willReturn(false);
        $this->cacheService->expects(self::once())->method('set')->with('cache_key', ['ter_available' => true]);

        $this->terClient->expects(self::once())
            ->method('hasVersionFor')
            ->with('test_extension', $this->context->getTargetVersion())
            ->willReturn(true);

        $result = $this->source->checkAvailability($this->extension, $this->context);

        self::assertSame(['ter_available' => true], $result);
    }

    public function testCheckAvailabilityReturnsFalseWhenNotAvailable(): void
    {
        $this->cacheService->method('generateSimpleKey')->willReturn('cache_key');
        $this->cacheService->expects(self::once())->method('has')->with('cache_key')->willReturn(false);
        $this->cacheService->expects(self::once())->method('set')->with('cache_key', ['ter_available' => false]);

        $this->terClient->expects(self::once())
            ->method('hasVersionFor')
            ->with('test_extension', $this->context->getTargetVersion())
            ->willReturn(false);

        $result = $this->source->checkAvailability($this->extension, $this->context);

        self::assertSame(['ter_available' => false], $result);
    }

    public function testCheckAvailabilityHandlesExceptions(): void
    {
        $this->terClient->expects(self::once())
            ->method('hasVersionFor')
            ->willThrowException(new \RuntimeException('API Error'));

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                'TER availability check failed, checking fallback sources',
                self::callback(function ($context) {
                    return 'test_extension' === $context['extension'] && 'API Error' === $context['error'];
                }),
            );

        $result = $this->source->checkAvailability($this->extension, $this->context);

        self::assertSame(['ter_available' => false], $result);
    }
}
