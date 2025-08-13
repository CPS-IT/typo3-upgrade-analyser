<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 Upgrade Analyzer.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License or any later version.
 */

namespace CPSIT\UpgradeAnalyzer\Shared\Utility;

/**
 * Utility class for resolving the project root directory across different deployment scenarios.
 */
class ProjectRootResolver
{
    /**
     * Find the project root directory, handling both standalone and composer installations.
     */
    public static function findProjectRoot(): string
    {
        // Try to use Composer's installed packages info to find the root
        if (class_exists('\\Composer\\InstalledVersions')) {
            try {
                $rootPackage = \Composer\InstalledVersions::getRootPackage();
                return $rootPackage['install_path'];
            } catch (\Throwable) {
                // Fall back to manual detection if Composer info is not available
            }
        }

        // Fallback: traverse up from current directory to find composer.json or project root
        $searchDir = __DIR__;
        $maxLevels = 10; // Prevent infinite loops

        for ($i = 0; $i < $maxLevels; ++$i) {
            // Check if we found a composer.json (indicates project root)
            if (file_exists($searchDir . '/composer.json')) {
                return $searchDir;
            }

            // Check if we found our own project structure
            if (file_exists($searchDir . '/bin/typo3-analyzer') && file_exists($searchDir . '/src')) {
                return $searchDir;
            }

            $parentDir = \dirname($searchDir);
            if ($parentDir === $searchDir) {
                // Reached filesystem root
                break;
            }
            $searchDir = $parentDir;
        }

        // Ultimate fallback: use the old behavior
        return \dirname(__DIR__, 3);
    }
}
