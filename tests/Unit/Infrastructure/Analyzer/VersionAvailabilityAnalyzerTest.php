<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Analyzer;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Extension\ExtensionDistribution;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailability\VersionSourceInterface;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer;
use CPSIT\UpgradeAnalyzer\Infrastructure\Cache\CacheService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test case for the VersionAvailabilityAnalyzer.
 */
#[CoversClass(VersionAvailabilityAnalyzer::class)]
class VersionAvailabilityAnalyzerTest extends TestCase
{
    private VersionAvailabilityAnalyzer $analyzer;
    private MockObject&LoggerInterface $logger;
    private MockObject&VersionSourceInterface $terSource;
    private MockObject&VersionSourceInterface $packagistSource;
    private MockObject&VersionSourceInterface $gitSource;
    private Extension $extension;
    private AnalysisContext $context;

    protected function setUp(): void
    {
        $cacheService = $this->createMock(CacheService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->terSource = $this->createMock(VersionSourceInterface::class);
        $this->terSource->method('getName')->willReturn('ter');

        $this->packagistSource = $this->createMock(VersionSourceInterface::class);
        $this->packagistSource->method('getName')->willReturn('packagist');

        $this->gitSource = $this->createMock(VersionSourceInterface::class);
        $this->gitSource->method('getName')->willReturn('git');

        $this->analyzer = new VersionAvailabilityAnalyzer(
            $cacheService,
            $this->logger,
            [$this->terSource, $this->packagistSource, $this->gitSource],
        );

        $this->extension = new Extension(
            'test_extension',
            'Test Extension',
            new Version('1.0.0'),
            'local',
            'vendor/test-extension',
        );

        $this->context = new AnalysisContext(
            new Version('11.5.0'),
            new Version('12.4.0'),
        );
    }

    public function testGetName(): void
    {
        self::assertEquals('version_availability', $this->analyzer->getName());
    }

    public function testAnalyzeWithAllSourcesEnabled(): void
    {
        // Arrange
        $this->terSource->expects(self::once())
            ->method('checkAvailability')
            ->willReturn(['ter_available' => true]);

        $this->packagistSource->expects(self::once())
            ->method('checkAvailability')
            ->willReturn(['packagist_available' => true]);

        $this->gitSource->expects(self::once())
            ->method('checkAvailability')
            ->willReturn(['git_available' => true, 'git_repository_health' => 0.8]);

        // Act
        $result = $this->analyzer->analyze($this->extension, $this->context);

        // Assert
        self::assertTrue($result->isSuccessful());
        self::assertTrue($result->getMetric('ter_available'));
        self::assertTrue($result->getMetric('packagist_available'));
        self::assertTrue($result->getMetric('git_available'));
        self::assertEquals(0.8, $result->getMetric('git_repository_health'));
    }

    public function testAnalyzeWithOnlyTerEnabled(): void
    {
        // Arrange
        $config = [
            'analysis' => [
                'analyzers' => [
                    'version_availability' => [
                        'sources' => ['ter'],
                    ],
                ],
            ],
        ];
        $context = $this->context->withConfiguration($config);

        $this->terSource->expects(self::once())
            ->method('checkAvailability')
            ->willReturn(['ter_available' => true]);

        $this->packagistSource->expects(self::never())->method('checkAvailability');
        $this->gitSource->expects(self::never())->method('checkAvailability');

        // Act
        $result = $this->analyzer->analyze($this->extension, $context);

        // Assert
        self::assertTrue($result->getMetric('ter_available'));
        self::assertFalse($result->hasMetric('packagist_available'));
    }

    public function testAnalyzeWithGithubMapping(): void
    {
        // Arrange
        $config = [
            'analysis' => [
                'analyzers' => [
                    'version_availability' => [
                        'sources' => ['github'], // Should map to 'git'
                    ],
                ],
            ],
        ];
        $context = $this->context->withConfiguration($config);

        $this->gitSource->expects(self::once())
            ->method('checkAvailability')
            ->willReturn(['git_available' => true]);

        // Act
        $result = $this->analyzer->analyze($this->extension, $context);

        // Assert
        self::assertTrue($result->getMetric('git_available'));
    }

    public function testAnalyzeSkipsPathDistribution(): void
    {
        // Arrange
        $extension = new Extension(
            'path_extension',
            'Path Extension',
            new Version('1.0.0'),
            'local',
            'vendor/path-extension',
            new ExtensionDistribution('path', '/path/to/extension'),
        );

        // Expect no calls to sources
        $this->terSource->expects(self::never())->method('checkAvailability');
        $this->packagistSource->expects(self::never())->method('checkAvailability');
        $this->gitSource->expects(self::never())->method('checkAvailability');

        // Act
        $result = $this->analyzer->analyze($extension, $this->context);

        // Assert
        self::assertTrue($result->isSuccessful());
        self::assertTrue($result->getMetric('skipped'));
        self::assertEquals('External version checks skipped for local extension', $result->getMetric('skip_reason'));
        self::assertEquals(1.0, $result->getRiskScore());
        self::assertContains('Use Rector and Fractor to upgrade the extension and do the rest by hand.', $result->getRecommendations());
    }

    public function testScoreWithPackagistOnly(): void
    {
        // Packagist only enabled
        $config = ['analysis' => ['analyzers' => ['version_availability' => ['sources' => ['packagist']]]]];
        $context = $this->context->withConfiguration($config);

        $this->packagistSource->method('checkAvailability')->willReturn(['packagist_available' => true]);

        // These should not be called, but even if they return null/false, they shouldn't affect score
        $this->terSource->method('checkAvailability')->willReturn([]);
        $this->gitSource->method('checkAvailability')->willReturn([]);

        $result = $this->analyzer->analyze($this->extension, $context);

        // Max Score = 3. Availability = 3. Ratio = 1.0. Risk should be 1.5.
        $this->assertEquals(1.5, $result->getRiskScore(), 'Packagist-only availability should yield low risk');
    }

    public function testScoreWithTerOnly(): void
    {
        // TER only enabled
        $config = ['analysis' => ['analyzers' => ['version_availability' => ['sources' => ['ter']]]]];
        $context = $this->context->withConfiguration($config);

        $this->terSource->method('checkAvailability')->willReturn(['ter_available' => true]);

        $result = $this->analyzer->analyze($this->extension, $context);

        // Max Score = 4. Availability = 4. Ratio = 1.0. Risk should be 1.5.
        $this->assertEquals(1.5, $result->getRiskScore(), 'TER-only availability should yield low risk');
    }

    public function testScoreWithGitOnly(): void
    {
        // Git only enabled
        $config = ['analysis' => ['analyzers' => ['version_availability' => ['sources' => ['git']]]]];
        $context = $this->context->withConfiguration($config);

        $this->gitSource->method('checkAvailability')->willReturn([
            'git_available' => true,
            'git_repository_health' => 1.0, // Perfect health
        ]);

        $result = $this->analyzer->analyze($this->extension, $context);

        // Max Score = 2. Availability = 2. Ratio = 1.0. Risk should be 1.5.
        $this->assertEquals(1.5, $result->getRiskScore(), 'Git-only availability should yield low risk');
    }

    public function testScoreWithPackagistAndGitPartial(): void
    {
        // Packagist + Git enabled
        $config = ['analysis' => ['analyzers' => ['version_availability' => ['sources' => ['packagist', 'git']]]]];
        $context = $this->context->withConfiguration($config);

        // Packagist available (3 pts), Git not available (0 pts). Total 3/5 = 0.6.
        // Threshold for 2.5 is 0.44 * 5 = 2.2.
        // Threshold for 1.5 is 0.66 * 5 = 3.3.
        // So risk should be 2.5.

        $this->packagistSource->method('checkAvailability')->willReturn(['packagist_available' => true]);
        $this->gitSource->method('checkAvailability')->willReturn(['git_available' => false]);

        $result = $this->analyzer->analyze($this->extension, $context);

        $this->assertEquals(2.5, $result->getRiskScore(), 'Partial availability should yield medium risk');
    }
}
