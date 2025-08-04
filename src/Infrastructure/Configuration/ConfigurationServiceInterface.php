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
