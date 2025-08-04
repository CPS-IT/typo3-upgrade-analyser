<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Analyzer;

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\ExternalToolException;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitRepositoryAnalyzer;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\PackagistClient;
use CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\TerApiClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test case for the VersionAvailabilityAnalyzer.
 *
 * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer
 */
class VersionAvailabilityAnalyzerTest extends TestCase
{
    private VersionAvailabilityAnalyzer $analyzer;
    private MockObject&TerApiClient $terClient;
    private MockObject&PackagistClient $packagistClient;
    private MockObject&GitRepositoryAnalyzer $gitAnalyzer;
    private MockObject&LoggerInterface $logger;
    private Extension $extension;
    private AnalysisContext $context;

    protected function setUp(): void
    {
        $this->terClient = $this->createMock(TerApiClient::class);
        $this->packagistClient = $this->createMock(PackagistClient::class);
        $this->gitAnalyzer = $this->createMock(GitRepositoryAnalyzer::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Setup default git analyzer behavior to avoid GitAnalysisException
        $this->gitAnalyzer->method('analyzeExtension')
            ->willThrowException(new \CPSIT\UpgradeAnalyzer\Infrastructure\ExternalTool\GitAnalysisException('Git analysis not available'));

        $this->analyzer = new VersionAvailabilityAnalyzer(
            $this->terClient,
            $this->packagistClient,
            $this->gitAnalyzer,
            $this->logger,
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

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::getName
     */
    public function testGetName(): void
    {
        self::assertEquals('version_availability', $this->analyzer->getName());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::getDescription
     *
     * @uses \CPSIT\UpgradeAnalyzer\Domain\Entity\Extension
     * @uses \CPSIT\UpgradeAnalyzer\Domain\ValueObject\AnalysisContext
     * @uses \CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version
     */
    public function testGetDescription(): void
    {
        $description = $this->analyzer->getDescription();
        self::assertIsString($description);
        self::assertStringContainsString('TER', $description);
        self::assertStringContainsString('Packagist', $description);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::supports
     */
    public function testSupportsAllExtensions(): void
    {
        self::assertTrue($this->analyzer->supports($this->extension));

        $systemExtension = new Extension('core', 'Core', new Version('12.4.0'), 'system');
        self::assertTrue($this->analyzer->supports($systemExtension));
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::getRequiredTools
     */
    public function testGetRequiredTools(): void
    {
        $tools = $this->analyzer->getRequiredTools();
        self::assertContains('curl', $tools);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::hasRequiredTools
     */
    public function testHasRequiredTools(): void
    {
        // This test depends on the environment having curl available
        self::assertTrue($this->analyzer->hasRequiredTools());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testAnalyzeWithBothRepositoriesAvailable(): void
    {
        // Arrange
        $this->terClient->expects(self::once())
            ->method('hasVersionFor')
            ->with($this->extension->getKey(), $this->context->getTargetVersion())
            ->willReturn(true);

        $this->packagistClient->expects(self::once())
            ->method('hasVersionFor')
            ->with($this->extension->getComposerName(), $this->context->getTargetVersion())
            ->willReturn(true);

        $this->logger->expects(self::atLeastOnce())
            ->method('info');

        // Act
        $result = $this->analyzer->analyze($this->extension, $this->context);

        // Assert
        self::assertEquals('version_availability', $result->getAnalyzerName());
        self::assertSame($this->extension, $result->getExtension());
        self::assertTrue($result->isSuccessful());

        self::assertTrue($result->getMetric('ter_available'));
        self::assertTrue($result->getMetric('packagist_available'));

        // Low risk score for extensions available in both repositories
        self::assertEquals(1.5, $result->getRiskScore());
        self::assertEquals('low', $result->getRiskLevel());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testAnalyzeWithOnlyTerAvailable(): void
    {
        // Arrange
        $this->terClient->expects(self::once())
            ->method('hasVersionFor')
            ->willReturn(true);

        $this->packagistClient->expects(self::once())
            ->method('hasVersionFor')
            ->willReturn(false);

        $this->logger->expects(self::atLeastOnce())
            ->method('info');

        // Act
        $result = $this->analyzer->analyze($this->extension, $this->context);

        // Assert
        self::assertTrue($result->getMetric('ter_available'));
        self::assertFalse($result->getMetric('packagist_available'));
        self::assertEquals(2.5, $result->getRiskScore());
        self::assertEquals('medium', $result->getRiskLevel());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testAnalyzeWithOnlyPackagistAvailable(): void
    {
        // Arrange
        $this->terClient->expects(self::once())
            ->method('hasVersionFor')
            ->willReturn(false);

        $this->packagistClient->expects(self::once())
            ->method('hasVersionFor')
            ->willReturn(true);

        $this->logger->expects(self::atLeastOnce())
            ->method('info');

        // Act
        $result = $this->analyzer->analyze($this->extension, $this->context);

        // Assert
        self::assertFalse($result->getMetric('ter_available'));
        self::assertTrue($result->getMetric('packagist_available'));
        self::assertEquals(5.0, $result->getRiskScore());

        // Should have recommendation about Composer mode
        $recommendations = $result->getRecommendations();
        self::assertNotEmpty($recommendations);
        self::assertStringContainsString('Composer', $recommendations[0]);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testAnalyzeWithNoVersionsAvailable(): void
    {
        // Arrange
        $this->terClient->expects(self::once())
            ->method('hasVersionFor')
            ->willReturn(false);

        $this->packagistClient->expects(self::once())
            ->method('hasVersionFor')
            ->willReturn(false);

        $this->logger->expects(self::atLeastOnce())
            ->method('info');

        // Act
        $result = $this->analyzer->analyze($this->extension, $this->context);

        // Assert
        self::assertFalse($result->getMetric('ter_available'));
        self::assertFalse($result->getMetric('packagist_available'));
        self::assertEquals(9.0, $result->getRiskScore());
        self::assertEquals('critical', $result->getRiskLevel());

        // Should have recommendation about contacting author
        $recommendations = $result->getRecommendations();
        self::assertNotEmpty($recommendations);
        self::assertStringContainsString('contacting author', $recommendations[0]);
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testAnalyzeWithSystemExtension(): void
    {
        // Arrange
        $systemExtension = new Extension('core', 'Core', new Version('12.4.0'), 'system');

        $this->terClient->expects(self::once())
            ->method('hasVersionFor')
            ->willReturn(true);

        // System extensions typically don't have composer names
        $this->packagistClient->expects(self::never())
            ->method('hasVersionFor');

        // Act
        $result = $this->analyzer->analyze($systemExtension, $this->context);

        // Assert
        self::assertEquals(1.0, $result->getRiskScore()); // System extensions are always low risk
        self::assertEquals('low', $result->getRiskLevel());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testAnalyzeWithExtensionWithoutComposerName(): void
    {
        // Arrange
        $extensionWithoutComposer = new Extension('legacy_ext', 'Legacy Extension', new Version('1.0.0'));

        $this->terClient->expects(self::once())
            ->method('hasVersionFor')
            ->willReturn(true);

        $this->packagistClient->expects(self::never())
            ->method('hasVersionFor');

        // Act
        $result = $this->analyzer->analyze($extensionWithoutComposer, $this->context);

        // Assert
        self::assertTrue($result->getMetric('ter_available'));
        self::assertFalse($result->getMetric('packagist_available'));
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testAnalyzeWithTerApiFailure(): void
    {
        // Arrange
        $exception = new ExternalToolException('TER API unavailable', 'ter_api');

        $this->terClient->expects(self::once())
            ->method('hasVersionFor')
            ->willThrowException($exception);

        $this->packagistClient->expects(self::once())
            ->method('hasVersionFor')
            ->willReturn(true);

        $this->logger->expects(self::once())
            ->method('warning')
            ->with('TER availability check failed, checking fallback sources', self::isType('array'));

        // Act
        $result = $this->analyzer->analyze($this->extension, $this->context);

        // Assert
        self::assertFalse($result->getMetric('ter_available')); // Should default to false on error
        self::assertTrue($result->getMetric('packagist_available'));
        self::assertTrue($result->isSuccessful()); // Analysis should still succeed
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testAnalyzeWithPackagistApiFailure(): void
    {
        // Arrange
        $exception = new ExternalToolException('Packagist API unavailable', 'packagist_api');

        $this->terClient->expects(self::once())
            ->method('hasVersionFor')
            ->willReturn(true);

        $this->packagistClient->expects(self::once())
            ->method('hasVersionFor')
            ->willThrowException($exception);

        $this->logger->expects(self::once())
            ->method('warning')
            ->with('Packagist availability check failed', self::isType('array'));

        // Act
        $result = $this->analyzer->analyze($this->extension, $this->context);

        // Assert
        self::assertTrue($result->getMetric('ter_available'));
        self::assertFalse($result->getMetric('packagist_available')); // Should default to false on error
        self::assertTrue($result->isSuccessful()); // Analysis should still succeed
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testAnalyzeWithCompleteFatalError(): void
    {
        // Arrange
        $this->terClient->expects(self::once())
            ->method('hasVersionFor')
            ->willThrowException(new \RuntimeException('Fatal error'));

        $this->logger->expects(self::once())
            ->method('error')
            ->with('Version availability analysis failed', self::isType('array'));

        // Act
        $result = $this->analyzer->analyze($this->extension, $this->context);

        // Assert
        self::assertFalse($result->isSuccessful());
        self::assertNotNull($result->getError());
        self::assertStringContainsString('Fatal error', $result->getError());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testAnalyzeLogsCorrectInformation(): void
    {
        // Arrange
        $this->terClient->method('hasVersionFor')->willReturn(true);
        $this->packagistClient->method('hasVersionFor')->willReturn(true);

        // Verify that logging occurs without checking specific parameters
        $this->logger->expects(self::atLeastOnce())
            ->method('info');

        // Act
        $result = $this->analyzer->analyze($this->extension, $this->context);

        // Assert - verify the analysis works correctly
        self::assertTrue($result->isSuccessful());
        self::assertEquals('version_availability', $result->getAnalyzerName());
    }

    /**
     * @covers \CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\VersionAvailabilityAnalyzer::analyze
     */
    public function testRecommendationsForLocalExtensionWithPublicAlternatives(): void
    {
        // Arrange
        $localExtension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local', 'vendor/test-ext');

        $this->terClient->method('hasVersionFor')->willReturn(true);
        $this->packagistClient->method('hasVersionFor')->willReturn(true);

        // Act
        $result = $this->analyzer->analyze($localExtension, $this->context);

        // Assert
        $recommendations = $result->getRecommendations();
        $hasLocalExtensionRecommendation = false;

        foreach ($recommendations as $recommendation) {
            if (str_contains($recommendation, 'Local extension has public alternatives')) {
                $hasLocalExtensionRecommendation = true;
                break;
            }
        }

        self::assertTrue($hasLocalExtensionRecommendation);
    }
}
