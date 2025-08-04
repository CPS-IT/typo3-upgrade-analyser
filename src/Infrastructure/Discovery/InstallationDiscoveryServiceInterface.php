<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Discovery;

/**
 * Interface for installation discovery services.
 */
interface InstallationDiscoveryServiceInterface
{
    /**
     * Discovers a TYPO3 installation at the given path.
     *
     * @param string $installationPath     Path to the TYPO3 installation
     * @param bool   $validateInstallation Whether to run validation rules
     *
     * @return InstallationDiscoveryResult Discovery result with installation details or failure information
     */
    public function discoverInstallation(string $installationPath, bool $validateInstallation = true): InstallationDiscoveryResult;
}
