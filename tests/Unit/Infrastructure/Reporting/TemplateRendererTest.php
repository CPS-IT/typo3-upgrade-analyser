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

use CPSIT\UpgradeAnalyzer\Domain\Entity\Extension;
use CPSIT\UpgradeAnalyzer\Domain\Entity\Installation;
use CPSIT\UpgradeAnalyzer\Domain\ValueObject\Version;
use CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\TemplateRenderer;
use PHPUnit\Framework\TestCase;
use Twig\Environment as TwigEnvironment;

class TemplateRendererTest extends TestCase
{
    private TemplateRenderer $subject;
    private \PHPUnit\Framework\MockObject\MockObject $twig;

    protected function setUp(): void
    {
        $this->twig = $this->createMock(TwigEnvironment::class);
        $this->subject = new TemplateRenderer($this->twig);
    }

    public function testServiceCanBeInstantiated(): void
    {
        self::assertInstanceOf(TemplateRenderer::class, $this->subject);
    }

    public function testRenderMainReportMarkdown(): void
    {
        // Arrange
        $context = ['test' => 'context'];
        $expectedContent = '# Main Report Content';

        $this->twig->expects(self::once())
            ->method('render')
            ->with('md/main-report.md.twig', $context)
            ->willReturn($expectedContent);

        // Act
        $result = $this->subject->renderMainReport($context, 'markdown');

        // Assert
        self::assertArrayHasKey('content', $result);
        self::assertArrayHasKey('filename', $result);
        self::assertSame($expectedContent, $result['content']);
        self::assertSame('analysis-report.md', $result['filename']);
    }

    public function testRenderMainReportHtml(): void
    {
        // Arrange
        $context = ['test' => 'context'];
        $expectedContent = '<h1>Main Report Content</h1>';

        $this->twig->expects(self::once())
            ->method('render')
            ->with('html/main-report.html.twig', $context)
            ->willReturn($expectedContent);

        // Act
        $result = $this->subject->renderMainReport($context, 'html');

        // Assert
        self::assertSame($expectedContent, $result['content']);
        self::assertSame('analysis-report.html', $result['filename']);
    }

    public function testRenderMainReportJson(): void
    {
        // Arrange
        $context = [
            'installation' => 'test',
            'extension_data' => ['ext1', 'ext2'], // This should be removed
            'other_data' => 'kept',
        ];

        // Act
        $result = $this->subject->renderMainReport($context, 'json');

        // Assert
        self::assertArrayHasKey('content', $result);
        self::assertArrayHasKey('filename', $result);
        self::assertSame('analysis-report.json', $result['filename']);

        // Verify JSON content excludes extension_data
        $decodedContent = json_decode($result['content'], true);
        self::assertArrayHasKey('installation', $decodedContent);
        self::assertArrayHasKey('other_data', $decodedContent);
        self::assertArrayNotHasKey('extension_data', $decodedContent);
    }

    public function testRenderMainReportUnsupportedFormat(): void
    {
        // Arrange
        $context = ['test' => 'context'];

        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported report format: unsupported');

        $this->subject->renderMainReport($context, 'unsupported');
    }

    public function testRenderExtensionReportsMarkdown(): void
    {
        // Arrange
        $extension1 = new Extension('ext1', 'Extension 1', new Version('1.0.0'), 'local');
        $extension2 = new Extension('ext2', 'Extension 2', new Version('2.0.0'), 'local');

        $context = [
            'installation' => new Installation('/test', new Version('12.0.0')),
            'target_version' => '13.0',
            'generated_at' => '2023-01-01T00:00:00+00:00',
            'extension_data' => [
                ['extension' => $extension1, 'data' => 'test1'],
                ['extension' => $extension2, 'data' => 'test2'],
            ],
        ];

        $this->twig->expects(self::exactly(2))
            ->method('render')
            ->with('md/extension-detail.md.twig', self::anything())
            ->willReturnOnConsecutiveCalls(
                '# Extension 1 Details',
                '# Extension 2 Details',
            );

        // Act
        $result = $this->subject->renderExtensionReports($context, 'markdown');

        // Assert
        self::assertCount(2, $result);

        self::assertSame('# Extension 1 Details', $result[0]['content']);
        self::assertSame('ext1.md', $result[0]['filename']);
        self::assertSame('ext1', $result[0]['extension']);

        self::assertSame('# Extension 2 Details', $result[1]['content']);
        self::assertSame('ext2.md', $result[1]['filename']);
        self::assertSame('ext2', $result[1]['extension']);
    }

    public function testRenderExtensionReportsHtml(): void
    {
        // Arrange
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $context = [
            'installation' => new Installation('/test', new Version('12.0.0')),
            'target_version' => '13.0',
            'generated_at' => '2023-01-01T00:00:00+00:00',
            'extension_data' => [
                ['extension' => $extension, 'data' => 'test'],
            ],
        ];

        $this->twig->expects(self::once())
            ->method('render')
            ->with('html/extension-detail.html.twig', self::anything())
            ->willReturn('<h1>Extension Details</h1>');

        // Act
        $result = $this->subject->renderExtensionReports($context, 'html');

        // Assert
        self::assertCount(1, $result);
        self::assertSame('<h1>Extension Details</h1>', $result[0]['content']);
        self::assertSame('test_ext.html', $result[0]['filename']);
    }

    public function testRenderExtensionReportsJson(): void
    {
        // Arrange
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $context = [
            'installation' => new Installation('/test', new Version('12.0.0')),
            'target_version' => '13.0',
            'generated_at' => '2023-01-01T00:00:00+00:00',
            'extension_data' => [
                ['extension' => $extension, 'data' => 'test'],
            ],
        ];

        // Act
        $result = $this->subject->renderExtensionReports($context, 'json');

        // Assert
        self::assertCount(1, $result);
        self::assertSame('test_ext.json', $result[0]['filename']);
        self::assertSame('test_ext', $result[0]['extension']);

        // Verify JSON content
        $decodedContent = json_decode($result[0]['content'], true);
        self::assertIsArray($decodedContent);
        self::assertArrayHasKey('installation', $decodedContent);
        self::assertArrayHasKey('extension', $decodedContent);
    }

    public function testRenderExtensionReportsUnsupportedFormat(): void
    {
        // Arrange
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $context = [
            'extension_data' => [
                ['extension' => $extension],
            ],
        ];

        // Act
        $result = $this->subject->renderExtensionReports($context, 'unsupported');

        // Assert - Unsupported formats should be skipped
        self::assertEmpty($result);
    }

    public function testRenderExtensionReportsEmptyExtensionData(): void
    {
        // Arrange
        $context = ['extension_data' => []];

        // Act
        $result = $this->subject->renderExtensionReports($context, 'markdown');

        // Assert
        self::assertEmpty($result);
    }

    public function testTwigTemplateContextForExtensionReport(): void
    {
        // Arrange
        $installation = new Installation('/test', new Version('12.0.0'));
        $extension = new Extension('test_ext', 'Test Extension', new Version('1.0.0'), 'local');
        $extensionData = ['extension' => $extension, 'analysis_data' => 'test'];

        $context = [
            'installation' => $installation,
            'target_version' => '13.0',
            'generated_at' => '2023-01-01T00:00:00+00:00',
            'extension_data' => [$extensionData],
        ];

        // Assert that Twig is called with the correct extension context
        $this->twig->expects(self::once())
            ->method('render')
            ->with(
                'md/extension-detail.md.twig',
                self::callback(function (array $extensionContext) use ($installation, $extension, $extensionData): bool {
                    return $extensionContext['installation'] === $installation
                        && '13.0' === $extensionContext['target_version']
                        && $extensionContext['extension'] === $extension
                        && $extensionContext['extension_data'] === $extensionData
                        && '2023-01-01T00:00:00+00:00' === $extensionContext['generated_at'];
                }),
            )
            ->willReturn('# Extension Content');

        // Act
        $this->subject->renderExtensionReports($context, 'markdown');
    }
}
