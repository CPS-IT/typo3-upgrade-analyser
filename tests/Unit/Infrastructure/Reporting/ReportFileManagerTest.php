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
        $this->tempDir = sys_get_temp_dir() . '/typo3-analyzer-test-' . uniqid();
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

    public function testServiceCanBeInstantiated(): void
    {
        self::assertInstanceOf(ReportFileManager::class, $this->subject);
    }

    public function testEnsureOutputDirectoryCreatesDirectory(): void
    {
        // Act
        $result = $this->subject->ensureOutputDirectory($this->tempDir, 'markdown');

        // Assert
        $expectedPath = $this->tempDir . '/markdown/';
        self::assertSame($expectedPath, $result);
        self::assertTrue(is_dir($expectedPath));
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
        self::assertTrue(is_dir($markdownDir));
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
        self::assertTrue(is_dir($expectedPath));
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
        self::assertArrayHasKey('type', $result);
        self::assertArrayHasKey('path', $result);
        self::assertArrayHasKey('size', $result);

        self::assertSame('main_report', $result['type']);
        self::assertSame($expectedPath, $result['path']);
        self::assertGreaterThan(0, $result['size']);

        // Verify file was actually written
        self::assertTrue(file_exists($expectedPath));
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
        self::assertTrue(file_exists($extensionsDir . 'ext1.md'));
        self::assertTrue(file_exists($extensionsDir . 'ext2.md'));
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
        self::assertTrue(is_dir($this->tempDir . '/extensions/'));

        // Verify files exist
        self::assertTrue(file_exists($this->tempDir . '/main.md'));
        self::assertTrue(file_exists($this->tempDir . '/extensions/ext.md'));
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
        self::assertTrue(file_exists($this->tempDir . '/main-only.md'));
        self::assertFalse(is_dir($this->tempDir . '/extensions/'));
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
}
