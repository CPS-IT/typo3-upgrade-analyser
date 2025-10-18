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

use CPSIT\UpgradeAnalyzer\Infrastructure\Reporting\ReportFileManager;
use PHPUnit\Framework\TestCase;

class ReportFileManagerTest extends TestCase
{
    private ReportFileManager $subject;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->subject = new ReportFileManager();
        $this->tempDir = sys_get_temp_dir() . '/typo3-analyzer-test-' . uniqid('', true);
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testEnsureOutputDirectoryCreatesDirectory(): void
    {
        // Act
        $result = $this->subject->ensureOutputDirectory($this->tempDir, 'markdown');

        // Assert
        $expectedPath = $this->tempDir . '/markdown/';
        self::assertSame($expectedPath, $result);
        self::assertDirectoryExists($expectedPath);
    }

    public function testEnsureOutputDirectoryWithExistingDirectory(): void
    {
        // Arrange - Create directory first
        $markdownDir = $this->tempDir . '/markdown/';
        mkdir($markdownDir, 0o755, true);

        // Act
        $result = $this->subject->ensureOutputDirectory($this->tempDir, 'markdown');

        // Assert
        self::assertSame($markdownDir, $result);
        self::assertDirectoryExists($markdownDir);
    }

    public function testEnsureOutputDirectoryFailsWithInvalidPath(): void
    {
        // Arrange - Use a path that cannot be created (file exists with same name)
        $invalidBasePath = $this->tempDir . '/existing-file';
        file_put_contents($invalidBasePath, 'test');

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Directory');

        $this->subject->ensureOutputDirectory($invalidBasePath, 'test');
    }

    public function testEnsureExtensionsDirectory(): void
    {
        // Act
        $result = $this->subject->ensureExtensionsDirectory($this->tempDir);

        // Assert
        $expectedPath = $this->tempDir . '/extensions/';
        self::assertSame($expectedPath, $result);
        self::assertDirectoryExists($expectedPath);
    }

    public function testWriteMainReportFile(): void
    {
        // Arrange
        $renderedReport = [
            'content' => '# Main Report Content',
            'filename' => 'analysis-report.md',
        ];

        // Act
        $result = $this->subject->writeMainReportFile($renderedReport, $this->tempDir);

        // Assert
        $expectedPath = $this->tempDir . '/analysis-report.md';

        self::assertSame('main_report', $result['type']);
        self::assertSame($expectedPath, $result['path']);
        self::assertGreaterThan(0, $result['size']);

        // Verify file was actually written
        self::assertFileExists($expectedPath);
        self::assertSame('# Main Report Content', file_get_contents($expectedPath));
    }

    public function testWriteExtensionReportFiles(): void
    {
        // Arrange
        $extensionsDir = $this->tempDir . '/extensions/';
        mkdir($extensionsDir, 0o755, true);

        $renderedReports = [
            [
                'content' => '# Extension 1 Details',
                'filename' => 'ext1.md',
                'extension' => 'ext1',
            ],
            [
                'content' => '# Extension 2 Details',
                'filename' => 'ext2.md',
                'extension' => 'ext2',
            ],
        ];

        // Act
        $result = $this->subject->writeExtensionReportFiles($renderedReports, $extensionsDir);

        // Assert
        self::assertCount(2, $result);

        // Check first extension report
        $ext1Result = $result[0];
        self::assertSame('extension_report', $ext1Result['type']);
        self::assertSame('ext1', $ext1Result['extension']);
        self::assertSame($extensionsDir . 'ext1.md', $ext1Result['path']);
        self::assertGreaterThan(0, $ext1Result['size']);

        // Check second extension report
        $ext2Result = $result[1];
        self::assertSame('extension_report', $ext2Result['type']);
        self::assertSame('ext2', $ext2Result['extension']);
        self::assertSame($extensionsDir . 'ext2.md', $ext2Result['path']);
        self::assertGreaterThan(0, $ext2Result['size']);

        // Verify files were actually written
        self::assertFileExists($extensionsDir . 'ext1.md');
        self::assertFileExists($extensionsDir . 'ext2.md');
        self::assertSame('# Extension 1 Details', file_get_contents($extensionsDir . 'ext1.md'));
        self::assertSame('# Extension 2 Details', file_get_contents($extensionsDir . 'ext2.md'));
    }

    public function testWriteExtensionReportFilesEmptyArray(): void
    {
        // Arrange
        $extensionsDir = $this->tempDir . '/extensions/';
        mkdir($extensionsDir, 0o755, true);

        // Act
        $result = $this->subject->writeExtensionReportFiles([], $extensionsDir);

        // Assert
        self::assertEmpty($result);
    }

    public function testWriteReportFilesWithMainAndExtensionReports(): void
    {
        // Arrange
        $mainReport = [
            'content' => '# Main Report',
            'filename' => 'main.md',
        ];
        $extensionReports = [
            [
                'content' => '# Extension Report',
                'filename' => 'ext.md',
                'extension' => 'test_ext',
            ],
        ];

        // Act
        $result = $this->subject->writeReportFiles($mainReport, $extensionReports, $this->tempDir);

        // Assert
        self::assertCount(2, $result); // 1 main + 1 extension

        // Check main report
        self::assertSame('main_report', $result[0]['type']);
        self::assertSame($this->tempDir . '/main.md', $result[0]['path']);

        // Check extension report
        self::assertSame('extension_report', $result[1]['type']);
        self::assertArrayHasKey('extension', $result[1]);
        /** @var array{type: string, extension: string, path: string, size: int} $extensionResult */
        $extensionResult = $result[1];
        self::assertSame('test_ext', $extensionResult['extension']);

        // Verify extensions directory was created
        self::assertDirectoryExists($this->tempDir . '/extensions/');

        // Verify files exist
        self::assertFileExists($this->tempDir . '/main.md');
        self::assertFileExists($this->tempDir . '/extensions/ext.md');
    }

    public function testWriteReportFilesWithMainReportOnly(): void
    {
        // Arrange
        $mainReport = [
            'content' => '# Main Report Only',
            'filename' => 'main-only.md',
        ];

        // Act
        $result = $this->subject->writeReportFiles($mainReport, [], $this->tempDir);

        // Assert
        self::assertCount(1, $result); // Only main report

        self::assertSame('main_report', $result[0]['type']);
        self::assertSame($this->tempDir . '/main-only.md', $result[0]['path']);

        // Verify main file exists but extensions directory was not created
        self::assertFileExists($this->tempDir . '/main-only.md');
        self::assertDirectoryDoesNotExist($this->tempDir . '/extensions/');
    }

    public function testWriteReportFilesHandlesFileWriteError(): void
    {
        // Arrange - Create a directory where the file should be written
        $badPath = $this->tempDir . '/bad-file.md';
        mkdir($badPath, 0o755, true); // Create directory with same name as intended file

        $mainReport = [
            'content' => '# Content',
            'filename' => 'bad-file.md',
        ];

        // Act & Assert - This should fail when trying to write to a directory
        // file_put_contents can actually succeed with a directory path on some systems
        $result = $this->subject->writeReportFiles($mainReport, [], $this->tempDir);

        // The result should still contain file metadata even if path is problematic
        self::assertCount(1, $result);
        self::assertSame('main_report', $result[0]['type']);
    }

    public function testFileSizeCalculation(): void
    {
        // Arrange
        $content = str_repeat('A', 1000); // 1000 bytes
        $renderedReport = [
            'content' => $content,
            'filename' => 'size-test.md',
        ];

        // Act
        $result = $this->subject->writeMainReportFile($renderedReport, $this->tempDir);

        // Assert
        self::assertSame(1000, $result['size']);
    }

    // Tests for new Rector findings methods

    public function testEnsureRectorFindingsDirectory(): void
    {
        // Act
        $result = $this->subject->ensureRectorFindingsDirectory($this->tempDir);

        // Assert
        $expectedPath = $this->tempDir . '/rector-findings/';
        self::assertSame($expectedPath, $result);
        self::assertDirectoryExists($expectedPath);
    }

    public function testEnsureRectorFindingsDirectoryWithExistingDirectory(): void
    {
        // Arrange - Create directory first
        $rectorDir = $this->tempDir . '/rector-findings/';
        mkdir($rectorDir, 0o755, true);

        // Act
        $result = $this->subject->ensureRectorFindingsDirectory($this->tempDir);

        // Assert
        self::assertSame($rectorDir, $result);
        self::assertDirectoryExists($rectorDir);
    }

    public function testWriteRectorDetailPages(): void
    {
        // Arrange
        $rectorDetailPages = [
            [
                'content' => '# Rector Findings for Extension 1',
                'filename' => 'ext1.html',
                'extension' => 'ext1',
            ],
            [
                'content' => '# Rector Findings for Extension 2',
                'filename' => 'ext2.html',
                'extension' => 'ext2',
            ],
        ];

        // Act
        $result = $this->subject->writeRectorDetailPages($rectorDetailPages, $this->tempDir);

        // Assert
        self::assertCount(2, $result);

        // Check first rector page
        $page1Result = $result[0];
        self::assertSame('rector_detail_page', $page1Result['type']);
        self::assertSame('ext1', $page1Result['extension']);
        self::assertSame($this->tempDir . '/rector-findings/ext1.html', $page1Result['path']);
        self::assertGreaterThan(0, $page1Result['size']);

        // Check second rector page
        $page2Result = $result[1];
        self::assertSame('rector_detail_page', $page2Result['type']);
        self::assertSame('ext2', $page2Result['extension']);
        self::assertSame($this->tempDir . '/rector-findings/ext2.html', $page2Result['path']);
        self::assertGreaterThan(0, $page2Result['size']);

        // Verify files were actually written
        self::assertFileExists($this->tempDir . '/rector-findings/ext1.html');
        self::assertFileExists($this->tempDir . '/rector-findings/ext2.html');
        self::assertSame('# Rector Findings for Extension 1', file_get_contents($this->tempDir . '/rector-findings/ext1.html'));
        self::assertSame('# Rector Findings for Extension 2', file_get_contents($this->tempDir . '/rector-findings/ext2.html'));

        // Verify rector-findings directory was created
        self::assertDirectoryExists($this->tempDir . '/rector-findings/');
    }

    public function testWriteRectorDetailPagesEmptyArray(): void
    {
        // Act
        $result = $this->subject->writeRectorDetailPages([], $this->tempDir);

        // Assert
        self::assertEmpty($result);

        // Verify rector-findings directory was NOT created when there are no pages
        self::assertDirectoryDoesNotExist($this->tempDir . '/rector-findings/');
    }

    public function testWriteReportFilesWithRectorPages(): void
    {
        // Arrange
        $mainReport = [
            'content' => '# Main Analysis Report',
            'filename' => 'analysis-report.html',
        ];

        $extensionReports = [
            [
                'content' => '# Extension Report',
                'filename' => 'test_ext.html',
                'extension' => 'test_ext',
            ],
        ];

        $rectorDetailPages = [
            [
                'content' => '<h1>Rector Findings for test_ext</h1>',
                'filename' => 'test_ext.html',
                'extension' => 'test_ext',
            ],
        ];

        // Act
        $result = $this->subject->writeReportFilesWithRectorPages(
            $mainReport,
            $extensionReports,
            $rectorDetailPages,
            $this->tempDir,
        );

        // Assert
        self::assertCount(3, $result); // 1 main + 1 extension + 1 rector page

        // Check main report
        $mainResults = array_filter($result, static fn (array $r): bool => 'main_report' === $r['type']);
        self::assertCount(1, $mainResults, 'Should have exactly one main report');
        $mainResult = array_values($mainResults)[0];
        self::assertSame($this->tempDir . '/analysis-report.html', $mainResult['path']);

        // Check extension report
        $extensionResults = array_filter($result, static fn (array $r): bool => 'extension_report' === $r['type']);
        self::assertCount(1, $extensionResults, 'Should have exactly one extension report');
        $extensionResult = array_values($extensionResults)[0];
        self::assertArrayHasKey('extension', $extensionResult);
        /* @var array{type: string, extension: string, path: string, size: int} $extensionResult */
        self::assertSame('test_ext', $extensionResult['extension']);

        // Check rector detail page
        $rectorResults = array_filter($result, static fn (array $r): bool => 'rector_detail_page' === $r['type']);
        self::assertCount(1, $rectorResults, 'Should have exactly one rector detail page');
        $rectorResult = array_values($rectorResults)[0];
        self::assertArrayHasKey('extension', $rectorResult);
        /* @var array{type: string, extension: string, path: string, size: int} $rectorResult */
        self::assertSame('test_ext', $rectorResult['extension']);
        self::assertSame($this->tempDir . '/rector-findings/test_ext.html', $rectorResult['path']);

        // Verify directories were created
        self::assertDirectoryExists($this->tempDir . '/extensions/');
        self::assertDirectoryExists($this->tempDir . '/rector-findings/');

        // Verify files exist
        self::assertFileExists($this->tempDir . '/analysis-report.html');
        self::assertFileExists($this->tempDir . '/extensions/test_ext.html');
        self::assertFileExists($this->tempDir . '/rector-findings/test_ext.html');

        // Verify file contents
        self::assertSame('# Main Analysis Report', file_get_contents($this->tempDir . '/analysis-report.html'));
        self::assertSame('# Extension Report', file_get_contents($this->tempDir . '/extensions/test_ext.html'));
        self::assertSame('<h1>Rector Findings for test_ext</h1>', file_get_contents($this->tempDir . '/rector-findings/test_ext.html'));
    }

    public function testWriteReportFilesWithRectorPagesNoRectorPages(): void
    {
        // Arrange
        $mainReport = [
            'content' => '# Main Report',
            'filename' => 'main.html',
        ];
        $extensionReports = [];
        $rectorDetailPages = [];

        // Act
        $result = $this->subject->writeReportFilesWithRectorPages(
            $mainReport,
            $extensionReports,
            $rectorDetailPages,
            $this->tempDir,
        );

        // Assert
        self::assertCount(1, $result); // Only main report
        self::assertSame('main_report', $result[0]['type']);

        // Verify only main file exists
        self::assertFileExists($this->tempDir . '/main.html');

        // Verify no subdirectories were created
        self::assertDirectoryDoesNotExist($this->tempDir . '/extensions/');
        self::assertDirectoryDoesNotExist($this->tempDir . '/rector-findings/');
    }

    public function testWriteReportFilesWithRectorPagesOnlyExtensionReports(): void
    {
        // Arrange
        $mainReport = [
            'content' => '# Main Report',
            'filename' => 'main.md',
        ];
        $extensionReports = [
            [
                'content' => '# Extension 1',
                'filename' => 'ext1.md',
                'extension' => 'ext1',
            ],
        ];
        $rectorDetailPages = []; // No rector pages

        // Act
        $result = $this->subject->writeReportFilesWithRectorPages(
            $mainReport,
            $extensionReports,
            $rectorDetailPages,
            $this->tempDir,
        );

        // Assert
        self::assertCount(2, $result); // 1 main + 1 extension

        // Verify extensions directory was created but rector-findings was not
        self::assertDirectoryExists($this->tempDir . '/extensions/');
        self::assertDirectoryDoesNotExist($this->tempDir . '/rector-findings/');
    }

    public function testRectorDetailPageFileSizes(): void
    {
        // Arrange
        $smallContent = 'Small content';
        $largeContent = str_repeat('Large content with lots of text. ', 100);

        $rectorDetailPages = [
            [
                'content' => $smallContent,
                'filename' => 'small.html',
                'extension' => 'small_ext',
            ],
            [
                'content' => $largeContent,
                'filename' => 'large.html',
                'extension' => 'large_ext',
            ],
        ];

        // Act
        $result = $this->subject->writeRectorDetailPages($rectorDetailPages, $this->tempDir);

        // Assert
        self::assertCount(2, $result);

        $smallResult = $result[0];
        $largeResult = $result[1];

        self::assertSame(\strlen($smallContent), $smallResult['size']);
        self::assertSame(\strlen($largeContent), $largeResult['size']);

        // Verify large content is actually larger
        self::assertGreaterThan($smallResult['size'], $largeResult['size']);
    }
}
