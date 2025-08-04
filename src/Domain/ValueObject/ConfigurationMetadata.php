<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Domain\ValueObject;

/**
 * Immutable value object representing configuration file metadata.
 *
 * Contains information about configuration files, their structure,
 * parsing details, and analysis-relevant metadata for TYPO3 installations.
 */
final class ConfigurationMetadata
{
    /**
     * @param string               $filePath          Absolute path to configuration file
     * @param string               $fileName          Base filename
     * @param string               $format            Configuration format (php, yaml, packagestates, etc.)
     * @param int                  $fileSize          File size in bytes
     * @param \DateTimeImmutable   $lastModified      File last modification time
     * @param \DateTimeImmutable   $parsedAt          When file was parsed
     * @param string               $parser            Parser class name that processed the file
     * @param array<string, mixed> $parseStatistics   Statistics from parsing
     * @param array<string>        $configurationKeys Top-level configuration keys found
     * @param string|null          $typo3Version      TYPO3 version if detectable from config
     * @param string|null          $phpVersion        PHP version requirement if specified
     * @param array<string, mixed> $customData        Custom metadata specific to configuration type
     */
    public function __construct(
        private readonly string $filePath,
        private readonly string $fileName,
        private readonly string $format,
        private readonly int $fileSize,
        private readonly \DateTimeImmutable $lastModified,
        private readonly \DateTimeImmutable $parsedAt,
        private readonly string $parser,
        private readonly array $parseStatistics = [],
        private readonly array $configurationKeys = [],
        private readonly ?string $typo3Version = null,
        private readonly ?string $phpVersion = null,
        private readonly array $customData = [],
    ) {
    }

    /**
     * Get file path.
     *
     * @return string Absolute file path
     */
    public function getFilePath(): string
    {
        return $this->filePath;
    }

    /**
     * Get filename.
     *
     * @return string Base filename
     */
    public function getFileName(): string
    {
        return $this->fileName;
    }

    /**
     * Get configuration format.
     *
     * @return string Format identifier
     */
    public function getFormat(): string
    {
        return $this->format;
    }

    /**
     * Get file size.
     *
     * @return int File size in bytes
     */
    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    /**
     * Get last modified timestamp.
     *
     * @return \DateTimeImmutable File modification time
     */
    public function getLastModified(): \DateTimeImmutable
    {
        return $this->lastModified;
    }

    /**
     * Get parsed timestamp.
     *
     * @return \DateTimeImmutable When file was parsed
     */
    public function getParsedAt(): \DateTimeImmutable
    {
        return $this->parsedAt;
    }

    /**
     * Get parser class name.
     *
     * @return string Parser that processed this file
     */
    public function getParser(): string
    {
        return $this->parser;
    }

    /**
     * Get parse statistics.
     *
     * @return array<string, mixed> Parsing statistics
     */
    public function getParseStatistics(): array
    {
        return $this->parseStatistics;
    }

    /**
     * Get configuration keys.
     *
     * @return array<string> Top-level configuration keys
     */
    public function getConfigurationKeys(): array
    {
        return $this->configurationKeys;
    }

    /**
     * Get TYPO3 version if detected.
     *
     * @return string|null TYPO3 version or null if not detected
     */
    public function getTypo3Version(): ?string
    {
        return $this->typo3Version;
    }

    /**
     * Get PHP version requirement.
     *
     * @return string|null PHP version requirement or null if not specified
     */
    public function getPhpVersion(): ?string
    {
        return $this->phpVersion;
    }

    /**
     * Get custom metadata.
     *
     * @return array<string, mixed> Custom metadata
     */
    public function getCustomData(): array
    {
        return $this->customData;
    }

    /**
     * Check if configuration has specific key.
     *
     * @param string $key Configuration key to check
     *
     * @return bool True if key exists
     */
    public function hasConfigurationKey(string $key): bool
    {
        return \in_array($key, $this->configurationKeys, true);
    }

    /**
     * Get parse statistic value.
     *
     * @param string $key     Statistic key
     * @param mixed  $default Default value
     *
     * @return mixed Statistic value or default
     */
    public function getParseStatistic(string $key, $default = null)
    {
        return $this->parseStatistics[$key] ?? $default;
    }

    /**
     * Get custom data value.
     *
     * @param string $key     Custom data key
     * @param mixed  $default Default value
     *
     * @return mixed Custom data value or default
     */
    public function getCustomDataValue(string $key, $default = null)
    {
        return $this->customData[$key] ?? $default;
    }

    /**
     * Check if file is considered large.
     *
     * @param int $threshold Size threshold in bytes (default: 1MB)
     *
     * @return bool True if file is larger than threshold
     */
    public function isLargeFile(int $threshold = 1048576): bool
    {
        return $this->fileSize > $threshold;
    }

    /**
     * Check if file was recently modified.
     *
     * @param \DateInterval $interval Time interval to check (default: 1 day)
     *
     * @return bool True if file was modified within interval
     */
    public function isRecentlyModified(?\DateInterval $interval = null): bool
    {
        $interval ??= new \DateInterval('P1D');
        $threshold = (new \DateTimeImmutable())->sub($interval);

        return $this->lastModified >= $threshold;
    }

    /**
     * Get file age in days.
     *
     * @return int File age in days
     */
    public function getFileAgeInDays(): int
    {
        $now = new \DateTimeImmutable();
        $diff = $now->diff($this->lastModified);

        return (int) $diff->days;
    }

    /**
     * Get parse duration if available.
     *
     * @return float|null Parse duration in seconds or null if not available
     */
    public function getParseDuration(): ?float
    {
        return $this->getParseStatistic('parse_duration_seconds');
    }

    /**
     * Get relative file path from installation root.
     *
     * Only returns a relative path if the installation root is an exact parent directory.
     * For TYPO3 installations, this typically means the directory containing config/, public/, etc.
     *
     * @param string $installationRoot Installation root path
     *
     * @return string Relative file path or absolute path if not within installation root
     */
    public function getRelativePath(string $installationRoot): string
    {
        $installationRoot = rtrim($installationRoot, '/');
        $filePath = $this->filePath;

        // Check if the file path starts exactly with the installation root + '/'
        if (!str_starts_with($filePath, $installationRoot . '/')) {
            return $filePath;
        }

        // Extract the relative part
        $relativePath = substr($filePath, \strlen($installationRoot) + 1);

        // For TYPO3 installations, we expect certain directory structures
        // If the relative path starts with another directory level that looks like an installation
        // (e.g., typo3/, public/, config/), we should not treat the parent as the installation root
        $firstSegment = explode('/', $relativePath)[0];

        // Common TYPO3 installation directory names that might indicate nested installations
        $typo3InstallationDirs = ['typo3', 'public', 'web', 'htdocs', 'www'];

        if (\in_array($firstSegment, $typo3InstallationDirs, true)) {
            // This might be a nested installation, return full path
            return $filePath;
        }

        return $relativePath;
    }

    /**
     * Check if this is a core TYPO3 configuration file.
     *
     * @return bool True if this is a core configuration file
     */
    public function isCoreConfiguration(): bool
    {
        $coreFiles = [
            'LocalConfiguration.php',
            'AdditionalConfiguration.php',
            'PackageStates.php',
            'Services.yaml',
        ];

        return \in_array($this->fileName, $coreFiles, true);
    }

    /**
     * Check if this is a site configuration file.
     *
     * @return bool True if this is a site configuration
     */
    public function isSiteConfiguration(): bool
    {
        return str_starts_with($this->filePath, '/config/sites/')
               && str_ends_with($this->fileName, '.yaml');
    }

    /**
     * Check if this is an extension configuration.
     *
     * @return bool True if this is extension configuration
     */
    public function isExtensionConfiguration(): bool
    {
        return str_contains($this->filePath, '/ext/')
               || str_ends_with($this->fileName, 'ext_localconf.php')
               || str_ends_with($this->fileName, 'ext_tables.php');
    }

    /**
     * Get configuration category.
     *
     * @return string Configuration category (core, site, extension, custom)
     */
    public function getCategory(): string
    {
        if ($this->isCoreConfiguration()) {
            return 'core';
        }

        if ($this->isSiteConfiguration()) {
            return 'site';
        }

        if ($this->isExtensionConfiguration()) {
            return 'extension';
        }

        return 'custom';
    }

    /**
     * Create new instance with additional custom data.
     *
     * @param array<string, mixed> $additionalData Additional custom data
     *
     * @return self New instance with merged custom data
     */
    public function withCustomData(array $additionalData): self
    {
        return new self(
            $this->filePath,
            $this->fileName,
            $this->format,
            $this->fileSize,
            $this->lastModified,
            $this->parsedAt,
            $this->parser,
            $this->parseStatistics,
            $this->configurationKeys,
            $this->typo3Version,
            $this->phpVersion,
            array_merge($this->customData, $additionalData),
        );
    }

    /**
     * Create new instance with updated configuration keys.
     *
     * @param array<string> $configurationKeys Updated configuration keys
     *
     * @return self New instance with updated keys
     */
    public function withConfigurationKeys(array $configurationKeys): self
    {
        return new self(
            $this->filePath,
            $this->fileName,
            $this->format,
            $this->fileSize,
            $this->lastModified,
            $this->parsedAt,
            $this->parser,
            $this->parseStatistics,
            $configurationKeys,
            $this->typo3Version,
            $this->phpVersion,
            $this->customData,
        );
    }

    /**
     * Convert to array for serialization.
     *
     * @return array<string, mixed> Array representation
     */
    public function toArray(): array
    {
        return [
            'file_path' => $this->filePath,
            'file_name' => $this->fileName,
            'format' => $this->format,
            'file_size' => $this->fileSize,
            'file_size_human' => $this->formatFileSize(),
            'last_modified' => $this->lastModified->format(\DateTimeInterface::ATOM),
            'parsed_at' => $this->parsedAt->format(\DateTimeInterface::ATOM),
            'parser' => $this->parser,
            'parse_statistics' => $this->parseStatistics,
            'configuration_keys' => $this->configurationKeys,
            'typo3_version' => $this->typo3Version,
            'php_version' => $this->phpVersion,
            'custom_data' => $this->customData,
            'file_analysis' => [
                'is_large_file' => $this->isLargeFile(),
                'is_recently_modified' => $this->isRecentlyModified(),
                'file_age_days' => $this->getFileAgeInDays(),
                'category' => $this->getCategory(),
                'is_core_configuration' => $this->isCoreConfiguration(),
                'is_site_configuration' => $this->isSiteConfiguration(),
                'is_extension_configuration' => $this->isExtensionConfiguration(),
            ],
        ];
    }

    /**
     * Format file size in human-readable format.
     *
     * @return string Formatted file size
     */
    private function formatFileSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = $this->fileSize;

        for ($i = 0; $i < \count($units) && $size >= 1024; ++$i) {
            $size /= 1024;
        }

        return number_format($size, 2) . ' ' . $units[$i];
    }
}
