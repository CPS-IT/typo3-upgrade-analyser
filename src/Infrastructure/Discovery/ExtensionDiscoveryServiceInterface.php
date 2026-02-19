<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Infrastructure\Discovery;

/**
 * Interface for extension discovery services.
 */
interface ExtensionDiscoveryServiceInterface
{
    /**
     * Discovers extensions in a TYPO3 installation.
     *
     * @param string        $installationPath Path to the TYPO3 installation
     * @param array|null    $customPaths      Custom paths configuration for the installation
     * @param array<string> $extensionsToSkip Array of extension keys to skip from discovery
     *
     * @return ExtensionDiscoveryResult Discovery result with extensions or failure information
     */
    public function discoverExtensions(string $installationPath, ?array $customPaths = null, array $extensionsToSkip = []): ExtensionDiscoveryResult;
}
