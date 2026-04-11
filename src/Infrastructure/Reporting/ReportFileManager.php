<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Reporting;

/**
 * Service for managing file system operations during report generation.
 *
 * This service handles directory creation, file writing, and metadata collection
 * for report files. It focuses solely on file I/O operations.
 */
class ReportFileManager
{
    /**
     * Ensure output directory structure exists for the specified format.
     *
     * @param string $baseOutputPath Base output directory path
     * @param string $format         Report format (html, md, etc.)
     *
     * @throws \RuntimeException If directory creation fails
     *
     * @return string The format-specific output path
     */
    public function ensureOutputDirectory(string $baseOutputPath, string $format): string
    {
        $baseOutputPath = rtrim($baseOutputPath, '/') . '/';
        $formatOutputPath = $baseOutputPath . $format . '/';

        $this->ensureDirectoryExists($formatOutputPath);

        return $formatOutputPath;
    }

    /**
     * Ensure extensions subdirectory exists within the output path.
     *
     * @param string $outputPath Output path where extensions directory should be created
     *
     * @return string The extensions subdirectory path
     */
    public function ensureExtensionsDirectory(string $outputPath): string
    {
        $outputPath = rtrim($outputPath, '/') . '/';
        $extensionsPath = $outputPath . 'extensions/';

        $this->ensureDirectoryExists($extensionsPath);

        return $extensionsPath;
    }

    /**
     * Write main report content to file and return file metadata.
     *
     * @param array{content: string, filename: string} $renderedReport Rendered report data
     * @param string                                   $outputPath     Output directory path
     *
     * @return array{type: string, path: string, size: int} File metadata
     */
    public function writeMainReportFile(array $renderedReport, string $outputPath): array
    {
        $outputPath = rtrim($outputPath, '/') . '/';
        $filename = $outputPath . $renderedReport['filename'];

        $this->writeFile($filename, $renderedReport['content']);

        return [
            'type' => 'main_report',
            'path' => $filename,
            'size' => $this->getFileSize($filename),
        ];
    }

    /**
     * Write extension report files and return their metadata.
     *
     * @param array<int, array{content: string, filename: string, extension: string}> $renderedReports Extension report data
     * @param string                                                                  $extensionsPath  Extensions directory path
     *
     * @return array<int, array{type: string, extension: string, path: string, size: int}> File metadata array
     */
    public function writeExtensionReportFiles(array $renderedReports, string $extensionsPath): array
    {
        $files = [];

        foreach ($renderedReports as $renderedReport) {
            $filename = $extensionsPath . $renderedReport['filename'];

            $this->writeFile($filename, $renderedReport['content']);

            $files[] = [
                'type' => 'extension_report',
                'extension' => $renderedReport['extension'],
                'path' => $filename,
                'size' => $this->getFileSize($filename),
            ];
        }

        return $files;
    }

    /**
     * Extended file writing that includes Rector detail pages.
     *
     * @param array{content: string, filename: string}                                $mainReport        Main report data
     * @param array<int, array{content: string, filename: string, extension: string}> $extensionReports  Extension reports data
     * @param array<int, array{content: string, filename: string, extension: string}> $rectorDetailPages Rector detail pages data
     * @param string                                                                  $outputPath        Output directory path
     *
     * @return array<int, array{type: string, extension?: string, path: string, size: int}> All file metadata
     */
    public function writeReportFilesWithRectorPages(
        array $mainReport,
        array $extensionReports,
        array $rectorDetailPages,
        array $fractorDetailPages,
        string $outputPath,
    ): array {
        // Use existing method for main and extension reports
        $files = $this->writeReportFiles($mainReport, $extensionReports, $outputPath);

        // Add Rector detail pages
        $rectorFiles = $this->writeRectorDetailPages($rectorDetailPages, $outputPath);

        // Add Rector detail pages
        $fractorFiles = $this->writeFractorDetailPages($fractorDetailPages, $outputPath);

        return array_merge($files, $rectorFiles, $fractorFiles);
    }

    /**
     * Write rector detail pages to rector-findings subdirectory.
     *
     * @param array<int, array{content: string, filename: string, extension: string}> $rectorDetailPages Rendered Rector detail pages
     * @param string                                                                  $outputPath        Output directory path
     *
     * @return array<int, array{type: string, extension: string, path: string, size: int}> File metadata array
     */
    public function writeRectorDetailPages(array $rectorDetailPages, string $outputPath): array
    {
        if (empty($rectorDetailPages)) {
            return [];
        }

        $rectorPath = $this->ensureRectorFindingsDirectory($outputPath);
        $files = [];

        foreach ($rectorDetailPages as $page) {
            $filename = $rectorPath . $page['filename'];
            $this->writeFile($filename, $page['content']);

            $files[] = [
                'type' => 'rector_detail_page',
                'extension' => $page['extension'],
                'path' => $filename,
                'size' => $this->getFileSize($filename),
            ];
        }

        return $files;
    }

    /**
     * Ensure rector-findings subdirectory exists within the output path.
     *
     * @param string $outputPath Output path where rector-findings directory should be created
     *
     * @return string The rector-findings subdirectory path
     */
    public function ensureRectorFindingsDirectory(string $outputPath): string
    {
        $outputPath = rtrim($outputPath, '/') . '/';
        $rectorPath = $outputPath . 'rector-findings/';

        $this->ensureDirectoryExists($rectorPath);

        return $rectorPath;
    }

    /**
     * Write rector detail pages to fractor-findings subdirectory.
     *
     * @param array<int, array{content: string, filename: string, extension: string}> $fractorDetailPages Rendered Fractor detail pages
     * @param string                                                                  $outputPath         Output directory path
     *
     * @return array<int, array{type: string, extension: string, path: string, size: int}> File metadata array
     */
    public function writeFractorDetailPages(array $fractorDetailPages, string $outputPath): array
    {
        if (empty($fractorDetailPages)) {
            return [];
        }

        $rectorPath = $this->ensureFractorFindingsDirectory($outputPath);
        $files = [];

        foreach ($fractorDetailPages as $page) {
            $filename = $rectorPath . $page['filename'];
            $this->writeFile($filename, $page['content']);

            $files[] = [
                'type' => 'fractor_detail_page',
                'extension' => $page['extension'],
                'path' => $filename,
                'size' => $this->getFileSize($filename),
            ];
        }

        return $files;
    }

    /**
     * Ensure fractor-findings subdirectory exists within the output path.
     *
     * @param string $outputPath Output path where fractor-findings directory should be created
     *
     * @return string The rector-findings subdirectory path
     */
    public function ensureFractorFindingsDirectory(string $outputPath): string
    {
        $outputPath = rtrim($outputPath, '/') . '/';
        $fractorPath = $outputPath . 'fractor-findings/';

        $this->ensureDirectoryExists($fractorPath);

        return $fractorPath;
    }

    /**
     * Write all report files and return combined metadata.
     *
     * @param array{content: string, filename: string}                                $mainReport       Main report data
     * @param array<int, array{content: string, filename: string, extension: string}> $extensionReports Extension reports data
     * @param string                                                                  $outputPath       Output directory path
     *
     * @return array<int, array{type: string, extension?: string, path: string, size: int}> All file metadata
     */
    public function writeReportFiles(array $mainReport, array $extensionReports, string $outputPath): array
    {
        // Write main report
        $mainReportFiles = [$this->writeMainReportFile($mainReport, $outputPath)];

        // Write extension reports if any exist
        $extensionReportFiles = [];
        if (!empty($extensionReports)) {
            $extensionsPath = $this->ensureExtensionsDirectory($outputPath);
            $extensionReportFiles = $this->writeExtensionReportFiles($extensionReports, $extensionsPath);
        }

        return array_merge($mainReportFiles, $extensionReportFiles);
    }

    private function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0o755, true) && !is_dir($path)) {
            throw new \RuntimeException(\sprintf('Directory "%s" was not created', $path));
        }
    }

    private function writeFile(string $path, string $content): void
    {
        @file_put_contents($path, $content);
    }

    private function getFileSize(string $path): int
    {
        if (!is_file($path)) {
            return 0;
        }

        return filesize($path) ?: 0;
    }
}
