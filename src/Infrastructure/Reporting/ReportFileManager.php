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

        if (!is_dir($formatOutputPath)) {
            if (!mkdir($formatOutputPath, 0o755, true) && !is_dir($formatOutputPath)) {
                throw new \RuntimeException(\sprintf('Directory "%s" was not created', $formatOutputPath));
            }
        }

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

        if (!is_dir($extensionsPath)) {
            mkdir($extensionsPath, 0o755, true);
        }

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

        file_put_contents($filename, $renderedReport['content']);

        return [
            'type' => 'main_report',
            'path' => $filename,
            'size' => filesize($filename) ?: 0,
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

            file_put_contents($filename, $renderedReport['content']);

            $files[] = [
                'type' => 'extension_report',
                'extension' => $renderedReport['extension'],
                'path' => $filename,
                'size' => filesize($filename) ?: 0,
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

        if (!is_dir($rectorPath)) {
            mkdir($rectorPath, 0o755, true);
        }

        return $rectorPath;
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
            file_put_contents($filename, $page['content']);

            $files[] = [
                'type' => 'rector_detail_page',
                'extension' => $page['extension'],
                'path' => $filename,
                'size' => filesize($filename) ?: 0,
            ];
        }

        return $files;
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
        string $outputPath,
    ): array {
        // Use existing method for main and extension reports
        $files = $this->writeReportFiles($mainReport, $extensionReports, $outputPath);

        // Add Rector detail pages
        $rectorFiles = $this->writeRectorDetailPages($rectorDetailPages, $outputPath);

        return array_merge($files, $rectorFiles);
    }
}
