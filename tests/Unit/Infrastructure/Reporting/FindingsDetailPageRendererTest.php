<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Infrastructure\Reporting;

use CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\FindingsDetailPageRenderer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Twig\Environment;
use Twig\Loader\LoaderInterface;

class FindingsDetailPageRendererTest extends TestCase
{
    private FindingsDetailPageRenderer $subject;
    private MockObject $twig;
    private MockObject $twigLoader;

    protected function setUp(): void
    {
        $this->twig = $this->createMock(Environment::class);
        $this->twigLoader = $this->createMock(LoaderInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->twig->method('getLoader')->willReturn($this->twigLoader);

        $this->subject = new FindingsDetailPageRenderer($this->twig, $logger);
    }

    public function testSupportsAnalyzerWithExistingTemplates(): void
    {
        // Arrange
        $this->twigLoader->expects(self::exactly(2))
            ->method('exists')
            ->willReturnMap([
                ['html/partials/fractor-findings/summary-overview.html.twig', true],
                ['html/partials/fractor-findings/findings-table.html.twig', true],
            ]);

        // Act
        $result = $this->subject->supportsAnalyzer('fractor');

        // Assert
        self::assertTrue($result);
    }

    public function testSupportsAnalyzerWithMissingTemplates(): void
    {
        // Arrange - First template missing, so second won't be checked
        $this->twigLoader->expects($this->once())
            ->method('exists')
            ->with('html/partials/unknown-findings/summary-overview.html.twig')
            ->willReturn(false);

        // Act
        $result = $this->subject->supportsAnalyzer('unknown');

        // Assert
        self::assertFalse($result);
    }

    public function testGetSupportedAnalyzers(): void
    {
        // Arrange - Mock both rector and fractor as supported
        $this->twigLoader->method('exists')->willReturnMap([
            ['html/partials/rector-findings/summary-overview.html.twig', true],
            ['html/partials/rector-findings/findings-table.html.twig', true],
            ['html/partials/fractor-findings/summary-overview.html.twig', true],
            ['html/partials/fractor-findings/findings-table.html.twig', true],
        ]);

        // Act
        $result = $this->subject->getSupportedAnalyzers();

        // Assert
        self::assertContains('rector', $result);
        self::assertContains('fractor', $result);
    }

    public function testRenderDetailPagesThrowsExceptionForUnsupportedFormat(): void
    {
        // Arrange
        $context = ['extension_key' => 'test'];

        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Format "unsupported" not supported');

        $this->subject->renderDetailPages('fractor', $context, 'unsupported');
    }

    public function testRenderDetailPagesWithValidContext(): void
    {
        // Arrange
        $context = [
            'extension_key' => 'test_ext',
            'detailed_findings' => [
                'findings' => [],
                'summary' => ['total_findings' => 0],
                'metadata' => ['extension_key' => 'test_ext'],
            ],
        ];

        $this->twigLoader->method('exists')->willReturn(true);
        $this->twig->expects(self::once())
            ->method('render')
            ->with('html/analyzer-findings-detail.html.twig', self::anything())
            ->willReturn('<html>Test Detail Page</html>');

        // Act
        $result = $this->subject->renderDetailPages('fractor', $context);

        // Assert
        self::assertArrayHasKey('detail', $result);
        self::assertSame('<html>Test Detail Page</html>', $result['detail']);
    }

    public function testRenderDetailPagesThrowsExceptionForMissingTemplates(): void
    {
        // Arrange
        $context = ['extension_key' => 'test'];
        $this->twigLoader->method('exists')->willReturn(false);

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Required template .* does not exist for analyzer type/');

        $this->subject->renderDetailPages('missing', $context);
    }
}
