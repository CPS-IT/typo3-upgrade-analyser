<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Configuration;

/**
 * Interface for configuration services.
 */
interface ConfigurationServiceInterface
{
    // Configuration keys as constants
    public const string CONFIG_ANALYSIS_INSTALLATION_PATH = 'analysis.installationPath';
    public const string CONFIG_ANALYSIS_TARGET_VERSION = 'analysis.targetVersion';
    public const string CONFIG_ANALYSIS_RESULT_CACHE_ENABLED = 'analysis.resultCache.enabled';
    public const string CONFIG_ANALYSIS_RESULT_CACHE_TTL = 'analysis.resultCache.ttl';
    public const string CONFIG_ANALYSIS_PHP_VERSIONS = 'analysis.phpVersions';
    public const string CONFIG_REPORTING_FORMATS = 'reporting.formats';
    public const string CONFIG_REPORTING_OUTPUT_DIRECTORY = 'reporting.output_directory';
    public const string CONFIG_REPORTING_INCLUDE_CHARTS = 'reporting.includeCharts';
    public const string CONFIG_ANALYZERS_VERSION_AVAILABILITY_ENABLED = 'analysis.analyzers.version_availability.enabled';
    public const string CONFIG_ANALYZERS_VERSION_AVAILABILITY_SOURCES = 'analysis.analyzers.version_availability.sources';
    public const string CONFIG_ANALYZERS_VERSION_AVAILABILITY_TIMEOUT = 'analysis.analyzers.version_availability.timeout';
    public const string CONFIG_EXTERNAL_TOOLS_RECTOR_BINARY = 'externalTools.rector.binary';
    public const string CONFIG_EXTERNAL_TOOLS_RECTOR_CONFIG = 'externalTools.rector.config';
    public const string CONFIG_EXTERNAL_TOOLS_FRACTOR_BINARY = 'externalTools.fractor.binary';
    public const string CONFIG_EXTERNAL_TOOLS_FRACTOR_CONFIG = 'externalTools.fractor.config';
    public const string CONFIG_EXTERNAL_TOOLS_TYPOSCRIPT_LINT_BINARY = 'externalTools.typoscript_lint.binary';
    public const string CONFIG_EXTERNAL_TOOLS_TYPOSCRIPT_LINT_CONFIG = 'externalTools.typoscript_lint.config';

    /**
     * Creates a new configuration service instance with a different config path.
     *
     * @param string $configPath Path to the configuration file
     *
     * @return self New configuration service instance
     */
    public function withConfigPath(string $configPath): self;

    /**
     * Gets the installation path from configuration.
     *
     * @return string|null Installation path or null if not configured
     */
    public function getInstallationPath(): ?string;

    /**
     * Gets the target TYPO3 version from configuration.
     *
     * @return string Target version (defaults to '12.4')
     */
    public function getTargetVersion(): string;

    /**
     * Checks if result caching is enabled.
     *
     * @return bool True if result caching is enabled
     */
    public function isResultCacheEnabled(): bool;

    /**
     * Gets the result cache TTL in seconds.
     *
     * @return int Cache TTL in seconds
     */
    public function getResultCacheTtl(): int;
}
